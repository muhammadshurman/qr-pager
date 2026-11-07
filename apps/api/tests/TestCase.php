<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Audit\AuditActorType;
use App\Domain\Pager\PagerStatus;
use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\Memberships;
use App\Platform\Auth\RequestPrincipal;
use App\Platform\Database\DatabaseRoles;
use App\Platform\Readiness\ReadinessStore;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RetryingPostgresConnector;
use Throwable;

/**
 * ⚠⚠ A HARNESS DIVERGENCE FROM THE RUNTIME, STATED HERE DELIBERATELY — `R-102`, TASK 12.
 *
 * ================================================================================
 * WHAT IS DIFFERENT, AND THE CHOICE `R-102` PUT TO THIS TASK
 * ================================================================================
 *
 * **This suite runs every simulated request through ONE container.** Octane calls
 * `forgetScopedInstances()` at each request boundary — `RequestBoundaryTest` proves it on
 * the real `Laravel\Octane\Worker` — and under FPM each request is a new process. Laravel's
 * TEST client does neither, so a `scoped()` object built during one `$this->postJson()`
 * serves every later call in the same test.
 *
 * `R-102` offered Task 12 two ways to settle it: *"either `TestCase` forgets scoped
 * instances between HTTP calls — which makes every feature test run with production's
 * lifetime and would have caught this without a helper — or the divergence is stated in
 * `TestCase`'s own docblock so nobody reads a green feature test as evidence about request
 * isolation. The first is a one-line change with an UNKNOWN BLAST RADIUS across 700 tests,
 * which is why it is a Task 12 decision and not a Task 5 edit."*
 *
 * **This is the second option, and the first was measured before it was declined.**
 *
 * ================================================================================
 * ⚠ WHY THE FLUSH WAS DECLINED — THREE MEASUREMENTS, 2026-11-07
 * ================================================================================
 *
 * **(1) The blast radius is no longer unknown: 5 failures in 1,361 tests.** Four of those
 * were Task 12's own response-header work. **One was the flush**, and it is not a test
 * leaning on something it should not:
 *
 *     with forgetScopedInstances() before (or after) every call, over the rendered HTML
 *       /platform            200  links=[platform]
 *       /platform/audit-log  200  links=[platform]
 *       /studio              200  links=[platform]      <- WRONG
 *       /studio/domains      200  links=[platform]      <- WRONG
 *       /studio/media        200  links=[platform]      <- WRONG
 *
 * Filament binds its manager as `scoped('filament')` while `PanelRegistry` is a singleton,
 * so a forgotten manager resolves the DEFAULT panel's theme and the studio pages ship
 * 692 kB of the wrong stylesheet. `PanelThemeTest` is `QRPAGER-23`'s control on exactly
 * that and it caught it. **Both placements were tried** — before the call, and after it,
 * which is Octane's own order — and both produce the table above. So the flush does not
 * remove a divergence, it trades a DOCUMENTED one for an UNMEASURED one in panel
 * rendering. That is the wrong direction. The Filament-under-Octane question it raises
 * belongs to the task that owns the runtime: **`R-243`, Task 13.**
 *
 * **(2) A narrowed flush was then tried** — forgetting only `Memberships`, `ActiveTenant`
 * and `RequestPrincipal`, the three `AuthOctaneIsolationTest` enumerates — and **no
 * observable behaviour changed.** `MembersTest`'s second add still answered 201, and a
 * trace inside `UserAttachment::refusalFor()` showed the membership set still arriving
 * EMPTY on the second request. A change that cannot be shown to do anything is not worth
 * the risk of the one it demonstrably breaks, so it was reverted too.
 *
 * **(3) The divergence is real and it is worth knowing about, which is why this docblock
 * is long.** Measured inside one test, on one address, immediately after a successful
 * `POST /v1/members`:
 *
 *     UserAttachment::refusalFor(...)  with the warm reader  ->  null
 *     UserAttachment::refusalFor(...)  with a fresh reader   ->  "already_a_member"
 *
 * ⚠ **AND THE SECOND HALF OF THAT IS UNEXPLAINED AND IS NOT CLAIMED TO BE OTHERWISE.**
 * `public.user_memberships()` returns **1 row** for that user when called from a test, and
 * the reader inside the request sees **0**. `R-233`'s refusal demonstrably DOES bite on
 * the cases it was written for — `MemberConsentTest` drives beta's owner and a platform
 * admin over HTTP and both are refused — so the rule is not inert. What is not established
 * is why the same-tenant case answers differently on the request path. It is recorded as
 * **`R-244`** rather than diagnosed here, because `R-185` is this ledger's own row about a
 * diagnosis stated more firmly than its evidence.
 *
 * ================================================================================
 * SO: WHAT A GREEN FEATURE TEST IN THIS SUITE DOES *NOT* TELL YOU
 * ================================================================================
 *
 * It does not tell you that per-request state is re-derived per request. Anything this
 * application memoises in a `scoped()` object may be stale for a whole test and fresh in
 * production — the gap points the PERMISSIVE way for the suite, so a test can pass where
 * production fails. The instruments for that property are `AuthOctaneIsolationTest` (all
 * three auth bindings are forgotten and the rebuilt reader re-reads) and
 * `RequestBoundaryTest` (Octane really calls the flush), and a test that needs the
 * property mid-run calls `app()->forgetScopedInstances()` itself, as
 * `SuspendedTenantCustomerSurfaceTest::suspend()` does and explains.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The advisory lock this suite PROCESS holds for its whole life (R-049).
     *
     * Deliberately not tenancy.bootstrap_lock_key: db:bootstrap takes that one on the
     * same database from a connection of its own, and sharing the key would have this
     * lock deadlock against the bootstrap that setUp() is about to run.
     *
     * AND DELIBERATELY ABOVE 2_000_000_000, which is not decoration. RoleTimeoutTest's
     * lock_timeout firing proof draws its key from random_int(1, 2_000_000_000) and has
     * a second session take it first -- so a key inside that range could be drawn, and
     * because THIS lock is never released, that draw would not merely clash, it would
     * block that test forever. One in two billion, and the failure mode is a hung suite
     * with no output, which is the single most expensive shape this branch has paid for.
     * Out of the range by construction costs nothing and cannot be drawn.
     */
    private const SUITE_LOCK_KEY = 2_000_000_049;

    /**
     * The database every DEFAULT runner shares, mirroring `phpunit.xml`'s own `<env>` value.
     *
     * ⚠ A MIRROR, AND IT IS GATE-CHECKED RATHER THAN TRUSTED. `SuiteSerializationTest` parses
     * `phpunit.xml` and fails if the two disagree, which is the same shape `composer guard`
     * rule 11c uses (it parses `ThemeTokens` rather than copying it). A silent second copy of
     * this string is what `§6.2` refuses.
     */
    private const SHARED_TEST_DATABASE = 'qrpager_test';

    /** Redis indices a runner gets when nothing overrides them -- `config/database.php`. */
    private const SHARED_REDIS_DB = '0';

    private const SHARED_REDIS_CACHE_DB = '1';

    /**
     * The suite lock's key FOR THIS RUNNER'S OWN TEST DATABASE -- standing change (b) of three.
     *
     * ⚠ WHY THIS IS NO LONGER A CONSTANT. The lock above serialises every runner against every
     * other, which is right when they share a database and wrong when they do not. `§11` ran
     * its two reviewers SEQUENTIALLY for exactly this reason: both worktrees pointed at
     * `qrpager_test` and at Redis db 0, so the only safe order was one after the other, and a
     * review round therefore cost twice the wall clock it needed to. `R-200`'s fix made the
     * reds attributable; it did not make the runs independent.
     *
     * The lock exists to protect a DATABASE, so its key now names one. Two runners with
     * different `DB_DATABASE` take different keys and run at the same time; two runners with
     * the SAME database still queue, which is `R-049`'s fix and is untouched.
     *
     * ⚠ STILL ABOVE 2_000_000_000, BY CONSTRUCTION, and the constant's own docblock says why:
     * `RoleTimeoutTest` draws its key from `random_int(1, 2_000_000_000)` and THIS lock is
     * never released, so a key inside that range would not merely clash -- it would hang that
     * test forever. The offset is taken modulo 1,000,000, so every key this can produce lies
     * in **[2_000_000_049, 2_001_000_048]** (corrected 2026-11-02 from `2_000_999_048`, which was wrong
     * by 1,000 -- the scoped review measured the real bound over 200k synthetic names): out of the
     * drawable range whatever the database is
     * called.
     *
     * ⚠ AND A HASH COLLISION FAILS IN THE SAFE DIRECTION. Two differently-named databases that
     * land on the same bucket would QUEUE -- today's behaviour for everyone -- rather than
     * interleave. The unsafe direction would be two runners on ONE database getting two keys,
     * and that cannot happen: the key is a pure function of the name.
     */
    public static function suiteLockKey(): int
    {
        $database = config('database.connections.'.DatabaseRoles::connection('app').'.database');

        if (! is_string($database) || $database === '') {
            throw new RuntimeException(
                'No test database is configured, so the suite lock cannot be keyed to one. '.
                'Set DB_DATABASE, or run through tools/gate/bin/reviewer-slot.'
            );
        }

        self::refuseHalfIsolatedRunner($database);

        return self::SUITE_LOCK_KEY + (crc32($database) % 1_000_000);
    }

    /**
     * A runner with its OWN DATABASE but the SHARED REDIS is refused, loudly, before it runs.
     *
     * ⚠ THIS CLOSES A DOOR THE DERIVED LOCK KEY OPENED, and the scoped review of 2026-11-02
     * (finding B1) is what found it. Before the key was keyed by database, a reviewer who
     * exported `DB_DATABASE` alone QUEUED on the one constant key and was therefore safe. Now
     * they get a different key and run CONCURRENTLY with the main checkout while sharing Redis
     * db 0 -- which is `R-049`'s class of defect (an unattributable red in somebody else's run)
     * reopened through a door the constant key held shut.
     *
     * The failure is not hypothetical: `setUp()`'s own docblock records one runner's saturated
     * `ReadinessStore` snapshot (60 s TTL) making three `TenantResolutionTest` cases and two
     * `LogRedactionTest` cases answer 503, and `RateLimitTest` shares Redis counters the same
     * way. `clearPagerProjection()` deleting `t:*:pager:*` before every test is the other half.
     *
     * ⚠ AND THE REDIS INDICES ARE DELIBERATELY *NOT* FOLDED INTO THE LOCK KEY, which is the
     * obvious fix and the wrong one: it would hand two runners on ONE database two different
     * keys and let them truncate under each other. Isolation has to be all-or-nothing, so the
     * mismatch is REFUSED instead -- `tools/gate/bin/reviewer-slot` sets all three together and
     * is the supported way in.
     */
    private static function refuseHalfIsolatedRunner(string $database): void
    {
        if ($database === self::SHARED_TEST_DATABASE) {
            return;
        }

        /*
         * ⚠ `config()` AND NOT `env()`, which larastan refuses for a real reason rather than a
         * stylistic one: `env()` outside the config directory returns NULL once the config is
         * cached, so the refusal below would have compared against `''` and silently stopped
         * refusing exactly where a cached config is in play. Reading the CONFIG also reads the
         * value actually in force, which is what this check is about.
         */
        $redis = (string) config('database.redis.default.database', self::SHARED_REDIS_DB);
        $cache = (string) config('database.redis.cache.database', self::SHARED_REDIS_CACHE_DB);

        if ($redis !== self::SHARED_REDIS_DB && $cache !== self::SHARED_REDIS_CACHE_DB) {
            return;
        }

        throw new RuntimeException(
            "This runner has its own database ({$database}) but the SHARED Redis indices ".
            "(REDIS_DB={$redis}, REDIS_CACHE_DB={$cache}). That combination runs CONCURRENTLY with ".
            'every other runner while sharing Redis, so TestCase::setUp()\'s clearPagerProjection() '.
            "and the ReadinessStore snapshot corrupt each other -- R-049's defect through a door the ".
            'old constant lock key held shut. Isolation is all-or-nothing: run through '.
            'tools/gate/bin/reviewer-slot, which sets DB_DATABASE, REDIS_DB and REDIS_CACHE_DB together.'
        );
    }

    /**
     * Every pgsql connection this suite opens goes through R-191's retrying connector.
     *
     * ⚠ BOUND HERE AND NOT IN `bootstrap/app.php`, WHICH IS THE WHOLE POINT. The stall is a
     * property of THIS host's WSL2 + Docker loopback forward, measured and recorded in
     * `tests/Support/RetryingPostgresConnector.php`; a retry in the application would change
     * production failure semantics on the path `DatabaseErrorMapper`'s 08006 mapping and the
     * limiter's fail-open posture are both written against. `ConnectionFactory::createConnector()`
     * resolves `db.connector.{driver}` from the container when it is bound, which is the
     * framework's own extension point, so no production file changes at all.
     *
     * ⚠ AND IT IS BOUND IN `createApplication()` RATHER THAN IN `setUp()`: connections resolve
     * lazily, but `setUp()` already connects (the bootstrap, the migrate and the TRUNCATE), so a
     * binding made there would be made after the connects it exists to protect.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $app->bind('db.connector.pgsql', fn (): RetryingPostgresConnector => new RetryingPostgresConnector);

        return $app;
    }

    private static bool $databaseReady = false;

    private static ?string $runMarker = null;

    private static ?string $suiteStartedAt = null;

    /**
     * The handle holding SUITE_LOCK_KEY. Never released, never purged, never closed.
     *
     * @see serialiseThisRunnerAgainstEveryOther()
     */
    private static ?PDO $suiteLock = null;

    /**
     * Truncated between tests. audit_log is deliberately absent: it is append-only,
     * so the trigger refuses to truncate it. Tests scope their audit assertions
     * instead of resetting the table.
     *
     * TASK 6 ADDED THREE PLATFORM TABLES AND ONLY TWO JOIN THIS LIST.
     *
     * `tenant_provisionings` and `tenant_statistics` are here because they are ordinary
     * mutable state: without them a provisioning record written by one test is still
     * there for the next, and `select count(*) from tenant_statistics` stops meaning
     * anything. Measured while writing TenantStatisticsTest -- a case asserting one
     * aggregate row read TEN, because nine were left by the cases before it. That is the
     * same class of cross-test shared state as the readiness key cleared below, and it
     * fails as a confusing wrong number rather than as an error.
     *
     * `platform_audit_log` is deliberately ABSENT, for `audit_log`'s own reason: it is
     * append-only and its BEFORE TRUNCATE trigger is ENABLE ALWAYS, so including it here
     * would make every setUp() raise 42501. Tests scope their platform-audit assertions
     * by `subject_id` or by `action` instead, exactly as they already do for `audit_log`.
     */
    private const RESETTABLE = [
        'push_subscriptions', 'outbox', 'pager_sessions', 'tenant_users',
        'tenant_statistics', 'tenant_provisionings',
        // Task 7's three. All three carry an FK to `tenants`, so `TRUNCATE ... CASCADE`
        // below would reach them anyway -- they are named explicitly because this list is
        // read as the statement of what a test may assume is empty, and a table cleared
        // only as a side effect of another table's cascade is not something a reader
        // should have to derive.
        'landing_pages', 'media_assets', 'tenant_domains',
        'tenants', 'users',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // FIRST, before the bootstrap and before the TRUNCATE below (R-049). A second
        // `php artisan test` queues here instead of wiping this run's rows between its
        // statements.
        self::serialiseThisRunnerAgainstEveryOther();

        if (! self::$databaseReady) {
            // The suite runs from a fresh clone with nothing but `docker compose up`:
            // roles, the test database and the schema are all created here. The
            // bootstrap holds pg_advisory_lock, so parallel runners queue instead of
            // racing a duplicate CREATE ROLE (PROJECT_INSTRUCTIONS.md §4).
            Artisan::call('db:bootstrap');
            Artisan::call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);

            self::dropStaleProbeRoles();

            self::$databaseReady = true;
        }

        DB::connection('pgsql_owner')
            ->unprepared('TRUNCATE '.implode(', ', self::RESETTABLE).' RESTART IDENTITY CASCADE');

        // The readiness key is cross-test shared state in a REAL store, exactly like the
        // tables above, and it has a 60-second TTL -- so a saturated snapshot one test
        // publishes sheds every /v1 request in the tests after it. Measured: three
        // TenantResolutionTest cases and two LogRedactionTest cases started answering 503
        // the moment ShedLoadTest existed. Cleared here for the same reason the tables
        // are: a test must not inherit the state of the one before it.
        //
        // An absent key is the safe default, and that is not an accident -- shedding
        // fails OPEN on a missing snapshot while readiness fails closed, so clearing it
        // leaves /v1 served and /readyz refusing, which is each one's own posture.
        try {
            $this->app->make(ReadinessStore::class)->forget();
            self::clearRateLimitBuckets();
            self::clearPagerProjection();
        } catch (Throwable) {
            // A Redis that cannot be reached has no key to clear, and the tests that
            // care about Redis being down say so themselves.
        }
    }

    /**
     * Drop every rate-limit bucket, for the same reason the readiness key is dropped.
     *
     * THE FAILURE THIS EXISTS FOR, found at Task 4. The limiter keys on sha1($request->ip()),
     * which is 127.0.0.1 for every feature test in the suite, and nothing cleared them --
     * so the counts accumulated ACROSS TESTS and across files. The symptom is the worst
     * kind: a test that passes alone and fails in the suite, with a 429 where its own
     * subject should have answered, and the failure lands on whichever test happens to run
     * after the twentieth request rather than on the one that made them. Measured:
     * OutboxWorkerTest's eleventh case received 429 from the `pager-subscribe` bucket
     * (20/minute) because the ten before it had each registered a device.
     *
     * It did not bite before this task because Task 2 and Task 3's buckets are 60-120 per
     * minute and their suites are small; Task 4 adds three tighter customer buckets and a
     * file that registers a device per case. The coupling was always there.
     *
     * RateLimitTest still clears its own keys at the top of each case, which is right: it
     * is the suite that DRIVES the limiter, so it needs a known starting point within a
     * test rather than merely between tests.
     *
     * ON THE `cache` CONNECTION AND WITH A DOUBLE PREFIX, which is where the first version
     * of this method went wrong and found nothing. RequestLimiter deliberately runs on the
     * CACHE STORE rather than the raw connection -- its own docblock says why -- so the
     * keys carry two prefixes, not one: `database.redis.options.prefix` from the client
     * ("laravel-database-") and `cache.prefix` from the store ("laravel-cache-"), on the
     * connection `cache.stores.redis.connection` names. Measured:
     *
     *   default connection, keys("*ratelimit*")  -> nothing
     *   cache connection,   keys("*ratelimit*")  -> laravel-database-laravel-cache-ratelimit:...
     *
     * So a sweep of `ratelimit:*` on the default connection matches nothing and passes
     * quietly, which is exactly how a clean-up that does not clean up survives review.
     */
    private static function clearRateLimitBuckets(): void
    {
        $store = (string) config('cache.stores.redis.connection', 'cache');
        $cachePrefix = (string) config('cache.prefix', '');

        self::forgetKeysMatching($cachePrefix.'ratelimit:*', $store);
    }

    /**
     * Drop Task 4's Redis status projection and its token pointers.
     *
     * Same argument as the readiness key and the six tables: a projection one test warmed
     * is a live status the next test did not create. These keys carry a TTL of up to the
     * session TTL (two hours), so without this a polling test could read a projection
     * written by a test that ran before it -- and because the pointer is keyed by a token
     * hash, the two would have to collide by token to interfere, which is a bug that
     * appears once in a very long while and is unreproducible when it does.
     */
    private static function clearPagerProjection(): void
    {
        self::forgetKeysMatching('t:*:pager:*');
        self::forgetKeysMatching('pager:tok:*');
    }

    private static function forgetKeysMatching(string $pattern, ?string $connection = null): void
    {
        $connection = app('redis')->connection($connection);
        $prefix = (string) config('database.redis.options.prefix', '');

        /** @var mixed $found */
        $found = $connection->keys($pattern);

        if (! is_array($found) || $found === []) {
            return;
        }

        $bare = array_map(
            fn (mixed $key): string => $prefix !== '' && str_starts_with((string) $key, $prefix)
                ? substr((string) $key, strlen($prefix))
                : (string) $key,
            $found,
        );

        $connection->del(...$bare);
    }

    /**
     * One suite process at a time, for the life of the process (R-049).
     *
     * THE FAILURE THIS EXISTS FOR. setUp() truncates six tables on every test. The
     * bootstrap takes pg_advisory_lock so parallel runners queue instead of racing a
     * duplicate CREATE ROLE -- and that was the ONLY place concurrency was handled, so a
     * second `php artisan test`, even on one file, wiped the first run's rows between its
     * statements. Reproduced deterministically at `ae43e12` from two pest invocations on
     * two different files: OwnerAndBypassTest took 23503 on tenant_users_tenant_id_fkey
     * ("Key is not present in table tenants", TestCase.php:305) and RefusalDiagnosticsTest
     * reported "the premise of this test is gone: the write did not survive". Foreign-key
     * checks bypass row level security, so the row was genuinely gone.
     *
     * That is the identified cause of §4a's one red run -- the 363 recorded as
     * "unexplained" for a week -- and the failure DIRECTION is why it cost that long: a
     * lost write presents as a write made inside withTenant() that did not survive, which
     * is indistinguishable at a glance from H-B's family.
     *
     * A RAW PDO HANDLE, and that is the whole mechanism. An advisory lock is held by a
     * SESSION, and tearDown() purges every Laravel connection after every test -- so a
     * lock taken on DB::connection(...) would be released by the next purge and this
     * would serialise nothing. This handle is outside the connection manager, so nothing
     * purges it, and it is static so it outlives every test in the process.
     *
     * The bootstrap connection, because of WHEN this runs: it is the maintenance database
     * (DB_MAINTENANCE_DATABASE, `postgres` by default), which always exists. The test
     * database does not -- db:bootstrap creates it, two lines below the call to this
     * method -- so a lock taken on the test database could not be taken on a fresh
     * cluster at all. Advisory locks are allocated per database, so every runner has to
     * agree on which one, and the one that is always there is the right answer.
     *
     * RELEASED BY THE PROCESS ENDING, which is the property that matters for a suite that
     * can be killed: pg_advisory_lock is session-scoped, so a kill -9, an OOM or a CI
     * timeout drops the connection and the lock with it. There is deliberately no
     * unlock and no shutdown function -- the one thing worse than a serialised suite is
     * one whose lock survives a crash and blocks every later run.
     *
     * The connector is the framework's own, rather than a DSN assembled here: sslmode and
     * the rest of the pgsql options already live in config/database.php, and a second
     * copy of that assembly is the duplicated-configuration shape QA H-1 cost us.
     */
    private static function serialiseThisRunnerAgainstEveryOther(): void
    {
        if (self::$suiteLock !== null) {
            return;
        }

        $name = DatabaseRoles::connection('bootstrap');

        /** @var array<string, mixed> $config */
        $config = (array) config('database.connections.'.$name);

        // ⚠ THE RETRYING CONNECTOR, AND THIS IS THE CONNECT THAT MOST NEEDS IT (R-191).
        // It is the FIRST connect of the process and it holds the lock for the whole run, so a
        // stall here does not fail one test -- it fails the run before a single case has
        // executed, with the lock never taken. The framework's own connector is still what
        // does the connecting; the subclass only decides what to do when `pdo_pgsql`'s
        // 30-second default deadline expires against this host's 31-36 second SYN stall.
        $pdo = (new RetryingPostgresConnector)->connect($config);

        // Queue, never refuse. An advisory lock IS subject to lock_timeout, and the root
        // role is the one role tenancy.timeouts does not set one for -- so today this
        // session inherits the cluster default of 0 and waits. Pinned on the session
        // rather than relied on: the whole value of this lock is that the second runner
        // WAITS, and inheriting that from a role attribute nobody is looking at would
        // turn a queue into a 55P03 the first time someone gives root a lock_timeout.
        $pdo->exec('set lock_timeout = 0');
        $pdo->exec('select pg_advisory_lock('.self::suiteLockKey().')');

        // Assigned only after the lock is held: a handle in this property is the
        // claim that this process owns the lock, so it must not be set by a connect
        // that then failed to acquire.
        self::$suiteLock = $pdo;
    }

    /**
     * Drop anything withThrowawayRole() left behind.
     *
     * R-018's trade, made self-healing. The helper drops its role in a finally, and a
     * kill -9, an OOM or a CI timeout between the create and the drop leaves one
     * behind: a role named qrpager_probe_* with no privilege on any table and nothing
     * connecting as it -- which is a mess, not a hole, and exactly why the old
     * `alter role qrpager_app bypassrls` approach was worse.
     *
     * This exists because RoleNamingTest pins the cluster's login roles as an EXACT
     * set, so a leftover fails the gate for an unrelated reason the next time anyone
     * runs the suite. That assertion is a control worth keeping exact, so the leftover
     * is swept rather than the assertion loosened. Same shape as db:bootstrap
     * re-asserting NOSUPERUSER NOBYPASSRLS on every run: converge, do not tolerate.
     *
     * Found by RoleNamingTest failing with eight of them, from the runs it took to
     * discover that a new role has no CONNECT privilege on this database.
     */
    private static function dropStaleProbeRoles(): void
    {
        $root = DB::connection('pgsql_root');

        foreach ($root->select("select rolname from pg_roles where rolname like 'qrpager_probe_%'") as $row) {
            if (! is_object($row) || ! is_string($row->rolname ?? null)) {
                continue;
            }

            self::dropRoleAndItsGrants($root, $row->rolname);
        }
    }

    /**
     * DROP OWNED BY before DROP ROLE, which is not optional and was not obvious.
     *
     * Postgres refuses `drop role` with 2BP01 "Dependent objects still exist" while any
     * privilege is granted TO the role -- and one of these tests points
     * tenancy.roles.app at the throwaway and runs db:bootstrap against it, so it ends up
     * holding grants on schema public and CONNECT on the database. DROP OWNED BY revokes
     * privileges on objects in the current database and on shared objects (the database
     * itself included), which is exactly the set in the way.
     *
     * Found by the sweep failing on the leftovers from the runs before this existed.
     */
    private static function dropRoleAndItsGrants(Connection $root, string $role): void
    {
        // These names are generated here out of hex and matched by a literal pattern,
        // so nothing in them came from outside.
        $root->statement("drop owned by {$role}");
        $root->statement("drop role if exists {$role}");
    }

    protected function tearDown(): void
    {
        // Every test boots its own application, and each one opens up to five
        // connections (app, owner, worker, root, bootstrap). Left to the garbage
        // collector these accumulate until Postgres refuses with 08006 "remaining
        // connection slots are reserved for roles with the SUPERUSER attribute" --
        // which it did, at around test 100 of this suite.
        $this->purgeConnections();

        parent::tearDown();
    }

    protected function purgeConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge((string) $name);
        }
    }

    protected function asOwner(): Connection
    {
        return DB::connection('pgsql_owner');
    }

    protected function asWorker(): Connection
    {
        return DB::connection('pgsql_worker');
    }

    /** The cluster superuser. Only ever an observer in tests, never a code path. */
    protected function asRoot(): Connection
    {
        return DB::connection('pgsql_root');
    }

    protected function makeTenant(?string $slug = null, ?string $id = null): string
    {
        $id ??= (string) Str::uuid7();

        // The real tenant-creation path: context is set to the id about to be inserted,
        // so even this needs no bypass (PLAN.md §2.7).
        withTenant($id, fn () => DB::connection('pgsql_owner')->table('tenants')->insert([
            'id' => $id,
            'name' => 'Tenant '.substr($id, 0, 8),
            // The whole id, not a prefix: Laravel's uuid7 keeps its first 25 hex
            // digits stable inside one millisecond, so a prefix-based slug collided
            // with tenants_slug_unique whenever two were made back to back. Found by
            // the composition matrix, which makes two tenants in most of its tests.
            'slug' => $slug ?? 'tenant-'.str_replace('-', '', $id),
        ]), 'pgsql_owner');

        return $id;
    }

    /**
     * @param  array<string, string|int|null>  $overrides
     */
    protected function makeSession(string $tenantId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        $row = array_merge([
            'id' => $id,
            'status' => PagerStatus::Waiting->value,
            'expires_at' => now()->addHours(2)->toIso8601String(),
            'order_reference' => 'A-'.random_int(100, 999),
        ], $overrides);

        // token_hash is bytea, so it is bound as hex and decoded in SQL rather than
        // pushed through PDO as a binary string.
        withTenant($tenantId, fn () => DB::connection('pgsql_owner')->insert(
            'insert into pager_sessions (id, tenant_id, token_hash, order_reference, status, expires_at)
             values (?, ?, decode(?, \'hex\'), ?, ?::pager_status, ?)',
            [
                $row['id'], $tenantId, bin2hex(hash('sha256', (string) $row['id'], true)),
                $row['order_reference'], $row['status'], $row['expires_at'],
            ],
        ), 'pgsql_owner');

        return (string) $row['id'];
    }

    /**
     * Make requests as a real staff member of a real tenant.
     *
     * REWRITTEN AT TASK 5, when the pre-Task-5 stub was deleted (R-035). It used to bind a
     * bare uuid into the container; it now creates a user, gives that user ONE membership
     * in the tenant, and authenticates as them. So a feature test exercises the same
     * resolution path a real request does -- the guard, the §2.8 membership function, and
     * the suspended-tenant check -- rather than a harness that short-circuited all three.
     *
     * The resolver still reads NO HTTP input: a tenant comes from the server-side session
     * or from a token's own abilities, never from a header, a route parameter, a query
     * string, a cookie or a body field. A resolver that read a header would be an
     * unauthenticated tenant-switching parameter -- the most dangerous shape this
     * application could ship, looking like perfectly ordinary code -- and `PagerIdorTest`
     * drives five such shapes and requires 404 from each. This helper needs none of them,
     * because one user with one membership resolves through `Memberships::sole()`.
     *
     * TWO CONSEQUENCES WORTH KNOWING BEFORE A TEST SURPRISES YOU:
     *
     *   - Calling it twice in one test makes TWO users, each in their own tenant. That is
     *     deliberate and it is what `PagerIdorTest`'s tenant switch means: alpha's request
     *     really is a different principal from beta's, which is the thing being tested.
     *   - It writes rows. A `users` row always, a `tenant_users` row always, and a
     *     `tenants` row when the id it is given has none. Counting rows in those tables
     *     after calling it has to account for them.
     *
     * Every /v1 route is deny-by-default behind RequireTenantScope, so a feature test that
     * does not call this gets a 401 with no detail. That is the clause working.
     */
    protected function actingForTenant(?string $tenantId = null, TenantRole $role = TenantRole::Owner): string
    {
        $tenantId ??= $this->makeTenant();

        // A tenants ROW is now required, where the pre-Task-5 harness needed none: that
        // stub bound a bare uuid into the container, so every call before Task 5 returned
        // a tenant id with no row behind it. tenant_users carries an FK to tenants, so the
        // membership this method creates cannot exist without one. Inserted here when it
        // is missing, so that a caller passing an arbitrary uuid keeps working.
        $exists = withoutTenantScope(
            'tests: does this tenant exist, for actingForTenant',
            fn (): bool => DB::connection('pgsql_owner')->table('tenants')->where('id', $tenantId)->exists(),
        );

        if (! $exists) {
            $this->makeTenant(slug: 'tenant-'.str_replace('-', '', $tenantId), id: $tenantId);
        }

        $userId = (string) Str::uuid7();

        DB::connection('pgsql_owner')->table('users')->insert([
            'id' => $userId,
            'name' => 'Acting Staff',
            // Lower-cased: users_email_is_lowercase is a CHECK constraint, and a 23514
            // from a test helper is the least useful place to discover it (R-007).
            //
            // ⚠ THE WHOLE UUID, AND IT USED TO BE substr(…, 0, 12). THAT COLLIDED, AND IT
            // REDDENED 15 TESTS WITH ONE IDENTITY.
            //
            // A v7 UUID's first 48 bits are a MILLISECOND TIMESTAMP, and 12 hex characters
            // are exactly those 48 bits. ramsey/uuid's v7 is monotonic: inside one
            // millisecond it keeps the timestamp and increments a counter in the tail, so
            // two UUIDs generated in the same millisecond share their first twelve
            // characters EXACTLY. Measured: over 200 consecutive `Str::uuid7()` calls this
            // expression produced **2 distinct emails and 198 collisions**.
            //
            // It survived Tasks 5, 6 and 7 because each test takes ~0.2s, so consecutive
            // acting users usually land in different milliseconds. Under a loaded machine
            // the scheduling bunches them and `users_email_unique` raises 23505 — which is
            // what happened on 2026-10-31: 15 failures, every one of them this constraint
            // on one address, `acting-01a1932b11bf@example.test`, whose prefix decodes to
            // 18:13:55.775.
            //
            // The fix takes the WHOLE uuid, so the email's uniqueness rests on the UUID's
            // own uniqueness and on nothing about where its entropy sits. Taking the LAST
            // twelve would have worked too and is rejected deliberately: it would rest on
            // ramsey's counter living in the tail, which is an implementation detail this
            // repository has no business depending on. 19 call sites across 11 files had
            // the same expression; all 19 are changed, and `TestFixtureUniquenessTest`
            // asserts the property rather than the spelling.
            'email' => 'acting-'.str_replace('-', '', $userId).'@example.test',
        ]);

        withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role' => $role->value,
        ]), 'pgsql_owner');

        // ONE membership per user, which is what makes this work with no header and no
        // session key: AuthenticatedTenantResolver falls back to Memberships::sole() when
        // nothing is selected. A second call in the same test makes a SECOND user, in the
        // second tenant, which is exactly the semantics PagerIdorTest's tenant switch
        // wants -- alpha's request really is a different principal from beta's.
        $user = User::query()->findOrFail($userId);

        $this->actingAs($user);

        // The membership was created after this request-scoped reader may already have
        // answered for this user id, and it memoises per user (QRPAGER-12 §10.5). A test
        // that authenticates twice would otherwise read the first answer back.
        $this->app->forgetInstance(Memberships::class);

        // SELECTED EXPLICITLY, in the session, which is what a real staff client does.
        //
        // One membership would resolve through Memberships::sole() with nothing selected,
        // and most tests have exactly one -- but a test that gives its user a SECOND
        // membership then resolves no tenant at all, because sole() refuses to pick. That
        // is correct behaviour and it made DeviceTokenScopeTest's two-tenant case answer
        // 401 from the helper rather than from the thing it was testing. Setting the
        // selection makes the helper say which tenant it means, and leaves the sole()
        // fallback to TenantResolutionTest, which tests it deliberately.
        session()->put(ActiveTenant::SESSION_KEY, $tenantId);

        return $tenantId;
    }

    /**
     * A token unique to this suite run, for assertions that would otherwise filter on a
     * constant.
     *
     * R-016. audit_log is append-only -- the trigger refuses DELETE and TRUNCATE -- so
     * it is deliberately absent from RESETTABLE and grows monotonically across runs on
     * a developer machine. CI is immune by construction, because it builds from an
     * empty database every run. That asymmetry is the whole danger: the first
     * unfiltered audit_log count anyone adds PASSES ON CI AND FAILS LOCALLY, which is
     * the same "correct on a clean build, wrong on a machine carrying earlier state"
     * shape as the stale grant migration that cost a morning on 2026-10-26.
     *
     * Static, so every row and every assertion in one `php artisan test` agree, and
     * random, so two runs never do. Never a DELETE -- the trigger refuses it, and that
     * refusal is a gate clause of its own.
     */
    protected function runMarker(): string
    {
        return self::$runMarker ??= 'run-'.bin2hex(random_bytes(6));
    }

    /**
     * When this suite run began, for an assertion that reads an external log.
     *
     * Static and set on first use, which in practice is the first test that asks. A
     * per-test window was the first version and it was not good enough: `docker compose
     * logs --since` takes a second-resolution timestamp, so the window either missed a
     * leak from a neighbouring test or caught one at random depending on how fast the
     * suite happened to run that time. A window that starts at the run's start is
     * deterministic.
     *
     * Its limitation, stated rather than discovered: it can only see tests that ran
     * BEFORE the assertion. Pest's file order is deterministic and BootstrapLoggingTest
     * is early, so a leak introduced in a later file is invisible here. What closes that
     * is the same scan run as a CI step after the whole suite, which is where the
     * ordering stops mattering.
     */
    protected function suiteStartedAt(): string
    {
        return self::$suiteStartedAt ??= (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::RFC3339);
    }

    /** An action name no earlier run can have written. */
    protected function auditAction(string $action): string
    {
        return $action.'.'.$this->runMarker();
    }

    /**
     * The context column for a row the SUITE writes, carrying the run marker.
     *
     * @param  array<string, string>  $extra
     */
    protected function auditContext(array $extra = []): string
    {
        return json_encode(['run' => $this->runMarker()] + $extra, JSON_THROW_ON_ERROR);
    }

    /** Put one row in every tenant table, so a "no context sees nothing" claim means something. */
    protected function seedEveryTenantTable(string $tenantId): void
    {
        $session = $this->makeSession($tenantId);
        $this->makeOutboxRow($tenantId, $session);

        $userId = (string) Str::uuid7();

        DB::connection('pgsql_owner')->table('users')->insert([
            'id' => $userId,
            'name' => 'Seeded Staff',
            'email' => 'staff-'.str_replace('-', '', $userId).'@example.test',
        ]);

        withTenant($tenantId, function () use ($tenantId, $userId, $session): void {
            $owner = DB::connection('pgsql_owner');

            $owner->table('tenant_users')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role' => TenantRole::Owner->value,
            ]);

            $owner->table('push_subscriptions')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'pager_session_id' => $session,
                'endpoint' => 'https://push.example.test/'.Str::random(24),
                'p256dh' => Str::random(87),
                'auth' => Str::random(22),
            ]);

            $owner->table('audit_log')->insert([
                'tenant_id' => $tenantId,
                'actor_type' => AuditActorType::User->value,
                'actor_user_id' => $userId,
                'action' => $this->auditAction('seed.created'),
                'context' => $this->auditContext(),
            ]);

            // ---- Task 7's three. They are seeded here for the reason the six above are:
            // TenantIsolationTest asserts "0 rows with no tenant context" over EVERY tenant
            // table, and that assertion is vacuous for a table that has no rows to hide. A
            // table added to the registry without a row here would pass isolation by being
            // empty, which is the dead-assertion shape §4a records four of.
            $owner->table('landing_pages')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'draft_document' => '{}',
            ]);

            // The key has to satisfy media_assets_key_is_unguessable: 32 hex characters and
            // a 2-5 character extension, under this tenant's own prefix.
            $owner->table('media_assets')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'object_key' => 'media/'.$tenantId.'/'.bin2hex(random_bytes(16)).'.png',
                'original_name' => 'seed.png',
                'content_type' => 'image/png',
                'byte_size' => 1024,
                'checksum' => hash('sha256', 'seed'),
            ]);

            // Unique across every tenant (R-129), so the hostname carries the tenant id.
            $owner->table('tenant_domains')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'hostname' => 'seed-'.str_replace('-', '', $tenantId).'.example.test',
                'verification_token' => bin2hex(random_bytes(16)),
            ]);
        }, 'pgsql_owner');
    }

    protected function makeOutboxRow(string $tenantId, ?string $sessionId = null): string
    {
        $id = (string) Str::uuid7();

        withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('outbox')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'pager_session_id' => $sessionId,
            'type' => 'pager.ready',
            'dedupe_key' => 'session:'.($sessionId ?? $id).':ready',
        ]), 'pgsql_owner');

        return $id;
    }

    /**
     * Run $callback with a throwaway role holding $attributes, and drop it afterwards.
     *
     * R-018. Three tests used to issue `alter role qrpager_app bypassrls` (one of them
     * `superuser bypassrls`) against the live cluster and revert in a finally. Each
     * finally is correct and none of them survives a kill -9, an OOM, a Ctrl-C or a CI
     * timeout between the two statements -- and in that window row level security
     * narrows nothing for EVERY process on the cluster, including php artisan serve,
     * Filament and a plain psql, none of which go through TenantContext. Nothing
     * reports it either, because the next `php artisan test` silently repairs it:
     * TestCase::setUp runs db:bootstrap, which re-asserts NOSUPERUSER NOBYPASSRLS.
     *
     * The role here is created, granted, used and dropped, so qrpager_app is never
     * altered at all. An interrupted run leaves behind a role named qrpager_probe_*
     * that nothing connects as and that holds no privilege on any table -- which is a
     * mess rather than a hole, and `drop role` is the whole cleanup.
     *
     * $callback receives the connection NAME, because the point is to run the real code
     * path against a real session of that role: the role names are config
     * (tenancy.roles.app), so a caller that needs assertSafe() to treat the throwaway
     * as a runtime role points that config entry at the name this returns.
     *
     * @param  list<string>  $attributes  e.g. ['bypassrls'], ['superuser', 'bypassrls']
     * @param  callable(string, string): void  $callback  (connection name, role name)
     */
    protected function withThrowawayRole(array $attributes, callable $callback): void
    {
        $role = 'qrpager_probe_'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $connection = 'pgsql_probe';
        $root = $this->asRoot();

        // Quoted by Postgres, from a name this method generated out of hex -- nothing
        // here came from outside.
        $root->statement("create role {$role} with login noinherit password '{$password}'");

        try {
            if ($attributes !== []) {
                $root->statement("alter role {$role} ".implode(' ', $attributes));
            }

            // CONNECT has to be granted explicitly: the bootstrap revokes it from
            // PUBLIC (§2.1), so a new role cannot open a session at all and the
            // connection fails 08006 "permission denied for database" -- which is how
            // the first version of this helper failed, with the refusal under test
            // replaced by a connection error. Nothing else is granted: these tests need
            // a real SESSION of a role with an attribute, never access to a table.
            $database = (string) config('database.connections.pgsql.database');
            $root->statement("grant connect on database \"{$database}\" to {$role}");

            config(["database.connections.{$connection}" => array_merge(
                (array) config('database.connections.pgsql'),
                ['username' => $role, 'password' => $password],
            )]);

            $callback($connection, $role);
        } finally {
            // Order matters: the session has to be gone before the role can be dropped,
            // and a connection left in the manager would be reused by a later test
            // under a role that no longer exists.
            DB::purge($connection);

            try {
                self::dropRoleAndItsGrants($root, $role);
            } catch (Throwable) {
                // A role that cannot be dropped is a named leftover with no privileges,
                // which is the trade this helper makes deliberately. Reporting it here
                // would replace the test's own result with cleanup noise.
            }
        }
    }

    /**
     * The SQLSTATE a failed statement actually carried.
     *
     * Laravel wraps the driver error, so the code is read from the PDO exception
     * underneath rather than from the wrapper (PROJECT_INSTRUCTIONS.md §4 ›
     * Postgres traps: "read the real driver code").
     */
    protected function sqlState(Throwable $e): string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof \PDOException && is_array($previous->errorInfo)) {
            return (string) $previous->errorInfo[0];
        }

        return (string) $e->getCode();
    }

    /** Run $callback and return the SQLSTATE it failed with, or null if it succeeded. */
    protected function sqlStateOf(callable $callback): ?string
    {
        try {
            $callback();

            return null;
        } catch (QueryException $e) {
            return $this->sqlState($e);
        }
    }
}
