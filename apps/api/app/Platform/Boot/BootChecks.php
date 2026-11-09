<?php

declare(strict_types=1);

namespace App\Platform\Boot;

use App\Platform\Database\DatabaseRoles;
use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\UnsafeDatabaseRoleException;
use Filament\Facades\Filament;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Env;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * The ordered boot checks from `§6.4 › config validation at boot`.
 *
 * One class, six clauses, returning REASONS rather than throwing: the caller decides
 * what refusing means -- a worker exits 1, a console command returns 1 -- and a check
 * that returns a list can be tested one clause at a time without catching anything.
 *
 * Between them they close H-C's two remaining Task 2 clauses (calling
 * assertSafeRuntimeRole() at boot, and widening it so a serving process cannot resolve
 * owner or root credentials), D8, D9, R-015 and R-020.
 *
 * NOTHING HERE IS A PRECONDITION ON A REQUEST. These run once, when a process starts
 * serving, and the cost is two round trips per worker. Making any of them per request
 * would put the cost on every request and still not be stronger: a role cannot change
 * under a live connection.
 */
final class BootChecks
{
    public const UNSAFE_RUNTIME_ROLE = 'unsafe-runtime-role';

    public const PRIVILEGED_CREDENTIALS_RESOLVABLE = 'privileged-credentials-resolvable';

    public const WEAK_SSLMODE = 'weak-sslmode';

    public const SESSION_SCOPED_TENANT_SETTING = 'session-scoped-tenant-setting';

    public const DEBUG_ENABLED = 'debug-enabled';

    public const UNTRUSTED_PROXY_CONFIGURATION = 'untrusted-proxy-configuration';

    public const UNSAFE_LANDING_URL = 'unsafe-landing-url';

    /** `R-253`: the address a signed-out panel visitor is redirected to. */
    public const UNSAFE_SIGNIN_URL = 'unsafe-signin-url';

    /**
     * No usable VAPID keypair, so every Web Push notification would fail (Task 4).
     *
     * FOUND BY A PROOF RATHER THAN BY REVIEW, which is worth recording: bin/outbox-kill-proof
     * ran with no VAPID configured and every row came back `retried` with the edge never
     * contacted at all. The failure is INSIDE the dispatcher -- VAPID::validate() raises
     * on an empty key -- so it is caught, logged at warning, and retried to the
     * max_attempts cap, after which the row dead-letters. A deployment that forgot
     * VAPID_PRIVATE_KEY would therefore serve every customer-facing request perfectly,
     * accept every subscription, and silently fail to deliver a single notification,
     * discovering it six attempts later in a log nobody is reading.
     *
     * §4 › Edge & ops: "Every dependency has a declared fail-open or fail-closed posture,
     * chosen deliberately and tested by taking the dependency down." The push edge's
     * posture is FAIL CLOSED AT BOOT, because the alternative is a pager that looks
     * healthy and never buzzes -- and this product is the pager.
     */
    public const UNUSABLE_VAPID_KEYS = 'unusable-vapid-keys';

    /**
     * Values that make X-Forwarded-For attacker-controlled rather than trustworthy.
     *
     * Laravel maps '*' and '**' to the catch-all 0.0.0.0/0 and ::/0, so every peer is a
     * trusted proxy and the forwarded header is whatever the caller sent.
     *
     * 'REMOTE_ADDR' JOINED THEM ON 2026-10-27 and it is the one that reads like a
     * restriction: setTrustedProxyIpAddressesToSpecificIps() resolves the token to the
     * CURRENT peer, so the trusted list is always whoever is connecting,
     * isFromTrustedProxy() is always true, and the header is honoured from anywhere.
     * It was accepted here -- and documented as valid in config/trustedproxy.php, and
     * asserted as acceptable in BootChecksTest -- for one round. Measured with peer
     * 198.51.100.77 and X-Forwarded-For 203.0.113.9: ip() was 203.0.113.9 under all
     * three of these, and 198.51.100.77 under a real CIDR the peer is outside of.
     *
     * The ground this clause gives for refusing '*' is "it trusts every peer", and that
     * is a property REMOTE_ADDR shares. The single-ingress assumption that would make
     * REMOTE_ADDR sound is the same one that would make '*' sound, it is written down
     * in config/trustedproxy.php as Task 13's to establish, and it is not true today.
     *
     * @var list<string>
     */
    private const CATCH_ALL_PROXIES = ['*', '**', 'REMOTE_ADDR'];

    /**
     * The widest single range a TRUSTED_PROXIES entry may name, per family.
     *
     * NOT A ROUND NUMBER PICKED HERE: /8 and /32 are the widest values this project
     * already documents as legitimate -- `10.0.0.0/8` in .env.example's guidance and in
     * BootChecksTest's accepted set, `2001:db8::/32` beside it. An AWS VPC IPv4 CIDR is
     * between /16 and /28, so Task 13's real value sits well inside this floor, and a
     * value outside it is a decision rather than a CIDR: one edit here, with a reason.
     *
     * The old floor was 1, which excluded nothing -- a /1 is half of every address there
     * is, and `0.0.0.0/1` was accepted AND pinned as acceptable in the test.
     */
    private const NARROWEST_V4_PREFIX = 8;

    private const NARROWEST_V6_PREFIX = 32;

    /**
     * sslmode values that do not guarantee an encrypted session.
     *
     * `prefer` is the dangerous one, and it is also libpq's default: it silently
     * downgrades to plaintext when the server does not offer TLS, which puts the role
     * passwords and every tenant row on the wire with nothing in any log (F7, security
     * review of task-1). `allow` and `disable` are obvious. Anything else -- require,
     * verify-ca, verify-full -- is accepted here; which of those is required is Task
     * 13's, where sslmode=verify-full is already owned.
     */
    private const WEAK_SSLMODES = ['disable', 'allow', 'prefer'];

    /**
     * The environments in which privileged credentials, a weak sslmode and a debug flag
     * are all legitimate.
     *
     * Local Postgres has no TLS and the suite has to be able to run migrations as the
     * owner and bootstrap roles as root, so these two environments are exempt by
     * design and the exemption is named in one place.
     */
    private const DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    public function __construct(
        private readonly Application $app,
        private readonly RuntimeRoleGuard $roles,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Every reason this process must not start serving, in the §6.4 order.
     *
     * @return list<string>
     */
    public function failures(?string $connection = null): array
    {
        $connection ??= $this->defaultConnection();

        return array_values(array_filter([
            ...$this->runtimeRoleIsSafe($connection),
            ...$this->privilegedCredentialsAreAbsent(),
            ...$this->sslmodeIsNotWeak(),
            ...$this->trustedProxiesAreSound(),
            ...$this->landingUrlIsSound(),
            ...$this->staffSignInUrlIsSound(),
            ...$this->vapidKeysAreUsable(),
            ...$this->connectionCarriesNoTenantSetting($connection),
            ...$this->debugIsOff(),
        ]));
    }

    /**
     * H-C, clause one: the half PLAN.md §0 records as still owed by Task 2.
     *
     * Task 1 built assertSafeRuntimeRole() and nothing called it at boot, so a process
     * could serve every request as a role row level security does not apply to and
     * every test would still pass. The per-call half landed on task-1; this is the
     * boot-time half.
     *
     * @return list<string>
     */
    public function runtimeRoleIsSafe(string $connection): array
    {
        try {
            $this->roles->assertSafe($connection);

            return [];
        } catch (UnsafeDatabaseRoleException $refusal) {
            // The role name and the reasons are kept: this reason goes to a LOG, never
            // to a response body, which is R-021's acceptance condition stated exactly
            // and which ProblemRenderer is what enforces on the other side.
            return [self::UNSAFE_RUNTIME_ROLE.': '.$refusal->getMessage()];
        } catch (Throwable $error) {
            // A connection failure is not a safe role. "Cannot tell" must never read as
            // "it is fine" -- the same rule readState()'s attribute defaults follow.
            return [self::UNSAFE_RUNTIME_ROLE.': the runtime role on connection '
                ."[{$connection}] could not be verified (".$error::class.')'];
        }
    }

    /**
     * D9, which is also H-C's second clause and R-015's real control.
     *
     * R-015's residue is that a callback inside a perfectly valid tenant scope can
     * query any OTHER connection directly, and pgsql_root and pgsql_owner are defined
     * unconditionally in every environment. PLAN.md §5 is explicit that the control is
     * not in the tenancy layer at all: a serving process must not be able to resolve
     * root or owner credentials. This is that control.
     *
     * READ FROM BOTH THE ENVIRONMENT AND THE RESOLVED CONFIG, and the second half is
     * not belt and braces. `config:cache` evaluates config/database.php while .env is
     * still loaded and bakes the result into the cache, after which env() returns null
     * at runtime -- so an environment-only check would pass while the credentials are
     * still resolvable through config. That is an unproven control, which is the one
     * shape §4a keeps recording. Reading the config closes it, and reading the
     * environment still matters because a container passes these as real process
     * variables that no config value has to mention.
     *
     * @return list<string>
     */
    public function privilegedCredentialsAreAbsent(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $resolvable = [];

        foreach (['DB_OWNER_PASSWORD', 'DB_ROOT_PASSWORD'] as $variable) {
            if ($this->isNonEmpty(Env::get($variable))) {
                $resolvable[] = $variable;
            }
        }

        foreach (['owner' => 'pgsql_owner', 'root' => 'pgsql_root', 'bootstrap' => 'pgsql_bootstrap'] as $name) {
            if ($this->isNonEmpty(config("database.connections.{$name}.password"))) {
                $resolvable[] = "database.connections.{$name}.password";
            }
        }

        if ($resolvable === []) {
            return [];
        }

        return [self::PRIVILEGED_CREDENTIALS_RESOLVABLE.': a serving process must not be able to connect as '
            .'the owner or the cluster superuser, because a tenant scope narrows one connection and not the '
            .'others (R-015). These resolve to a value: '.implode(', ', array_unique($resolvable))
            .'. Remove them from this task definition; migrations and db:bootstrap run as their own '
            .'processes with their own credentials (PLAN.md §2.1).'];
    }

    /**
     * D8. Named per connection, because one weak entry is enough to put credentials on
     * the wire and the default is shared by every pgsql connection in the file.
     *
     * @return list<string>
     */
    public function sslmodeIsNotWeak(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $weak = [];

        /** @var array<string, mixed> $connections */
        $connections = (array) config('database.connections', []);

        foreach ($connections as $name => $settings) {
            if (! is_array($settings) || ($settings['driver'] ?? null) !== 'pgsql') {
                continue;
            }

            $mode = is_string($settings['sslmode'] ?? null) ? strtolower($settings['sslmode']) : '';

            if (in_array($mode, self::WEAK_SSLMODES, true)) {
                $weak[] = (string) $name.'='.$mode;
            }
        }

        if ($weak === []) {
            return [];
        }

        return [self::WEAK_SSLMODE.': '.implode(', ', $weak).'. [prefer] is libpq\'s own default and '
            .'silently downgrades to an unencrypted session when the server does not offer TLS, with '
            .'nothing in any log (F7). Use require or stronger; Task 13 owns verify-full.'];
    }

    /**
     * Security S-5: the one config value that decides where a bearer credential is delivered.
     *
     * config('pager.landing_url') is the base of every QR payload, and PagerToken puts the
     * raw token in that URL's FRAGMENT. The fragment's entire protection is that the
     * CLIENT resolves it and no server receives it -- so the protection is a property of
     * the scheme, not of the token. Over http:// the page carrying the fragment is
     * modifiable in flight, and one injected script reading location.hash hands the token
     * to an active network attacker. That is the §3 row 3 clause failing by a route the
     * clause's own wording does not mention.
     *
     * Three refusals, each measured rather than imagined:
     *
     *   - NOT A URL AT ALL, or no host. The shipped default is https://localhost/p, which
     *     is right for local and is a silent misdelivery anywhere else -- and
     *     PAGER_LANDING_URL was absent from .env.example, so an unset variable was the
     *     likely case rather than the unlikely one.
     *   - NOT https. The clause above.
     *   - ALREADY CARRIES A FRAGMENT. Measured: qrPayload() is rtrim($url, '#').'#'.$token,
     *     so a configured 'https://h/p#x' yields 'https://h/p#x#TOKEN' and
     *     parse_url()'s fragment is 'x#TOKEN'. A client reading location.hash gets a value
     *     that is not the token, and the failure is silent on both sides.
     *
     * Exempt in local and testing, like every other clause here: the compose stack has no
     * TLS and the suite's own payload assertions build their base URL per test.
     *
     * @return list<string>
     */
    public function landingUrlIsSound(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $configured = config('pager.landing_url');

        if (! $this->isNonEmpty($configured)) {
            return [self::UNSAFE_LANDING_URL.': pager.landing_url is empty, so every QR payload '
                .'would be built from nothing. Set PAGER_LANDING_URL to the absolute https URL '
                .'of the scan landing page.'];
        }

        /** @var string $url */
        $url = $configured;
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || ! $this->isNonEmpty($parts['host'])) {
            return [self::UNSAFE_LANDING_URL.': pager.landing_url ['.$url.'] is not an absolute URL '
                .'with a host, so the QR payload would not resolve anywhere.'];
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return [self::UNSAFE_LANDING_URL.': pager.landing_url ['.$url.'] is not https. The QR '
                .'token travels in this URL\'s fragment, which is protected only by the client '
                .'being the one to resolve it -- over plaintext an injected script reads '
                .'location.hash and the token is the attacker\'s.'];
        }

        if (isset($parts['fragment'])) {
            return [self::UNSAFE_LANDING_URL.': pager.landing_url ['.$url.'] already carries a '
                .'fragment. The token is appended after a [#], so the client would read that '
                .'fragment and the token together and neither would be usable.'];
        }

        return [];
    }

    /**
     * Refuse to serve if the panels would redirect a signed-out person somewhere useless.
     *
     * `R-253` sends a signed-out browser on `/studio` or `/platform` to
     * `surfaces.staff_signin_url`, because those panels have no login form of their own by
     * design (`§4 › Tenancy`, and each panel provider's docblock). That makes the value a
     * SERVING dependency rather than a convenience: unset or wrong, the one affordance a
     * locked-out restaurant owner has is a link to nowhere, and nothing else in the system
     * will ever notice -- a redirect to a dead host looks identical to a redirect that
     * works until somebody follows it.
     *
     * THE SAME THREE REFUSALS AS `landingUrlIsSound()`, FOR TWO OF THE SAME REASONS AND ONE
     * DIFFERENT ONE:
     *
     *   - NOT A URL WITH A HOST. The shipped default is `http://localhost:3000/staff`, which
     *     is right for `pnpm --filter web dev` and resolves to nothing anywhere else -- the
     *     exact shape `PAGER_LANDING_URL` was found in, and the reason that check exists.
     *   - NOT https. A sign-in page reached over plaintext is a credential form an active
     *     network attacker can rewrite, which is a worse failure than the dead end this
     *     whole feature replaces.
     *   - A PANEL PATH ON THIS HOST, which `landingUrlIsSound()` has no analogue for: that
     *     is the redirect loop, and `PanelSignInRedirect` already refuses to emit it at
     *     request time. This makes the misconfiguration loud at boot instead of silently
     *     restoring the dead-end page in production.
     *
     * Exempt in local and testing like every other clause here: the compose stack has no
     * TLS, and `config/surfaces.php` ships the local web dev server on purpose.
     *
     * @return list<string>
     */
    public function staffSignInUrlIsSound(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $configured = config('surfaces.staff_signin_url');

        if (! $this->isNonEmpty($configured)) {
            return [self::UNSAFE_SIGNIN_URL.': surfaces.staff_signin_url is empty, so a signed-out '
                .'visitor to /studio or /platform is told to sign in and given nowhere to do it. '
                .'Set STAFF_SIGNIN_URL to the absolute https URL of the staff sign-in screen.'];
        }

        /** @var string $url */
        $url = $configured;
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || ! $this->isNonEmpty($parts['host'])) {
            return [self::UNSAFE_SIGNIN_URL.': surfaces.staff_signin_url ['.$url.'] is not an '
                .'absolute URL with a host, so the redirect would not resolve anywhere.'];
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return [self::UNSAFE_SIGNIN_URL.': surfaces.staff_signin_url ['.$url.'] is not https. '
                .'A credential form reached over plaintext can be rewritten in flight, which is '
                .'worse than the dead-end 401 this redirect replaces.'];
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');

        /*
         * ⚠ DERIVED FROM THE REGISTERED PANELS, NEVER A LITERAL `['/studio', '/platform']`,
         * which is `PanelProblemPage`'s own rule for the same question -- a panel added later
         * is covered with no edit here, and a second hand-written copy of that list is the
         * thing this file has been bitten by (`R-136`'s shape: a control that is not where it
         * says it is).
         *
         * AND IT IS SKIPPED RATHER THAN GUESSED WHEN THE REGISTRY IS NOT THERE. This method
         * runs from `boot:check` and from the serving path, and `Filament::getPanels()` reads
         * a registry that a console context need not have booted. An empty or throwing
         * registry means this ONE clause cannot be stated -- the two above it still are --
         * and the loop itself is refused at request time by `PanelSignInRedirect`, which
         * reads the same registry when it is certainly populated. So the cost of the skip is
         * a late warning, not an open loop.
         */
        foreach ($this->panelPrefixes() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return [self::UNSAFE_SIGNIN_URL.': surfaces.staff_signin_url ['.$url.'] is itself '
                    .'a panel path, so a signed-out browser would be redirected back to the '
                    .'refusal it came from. The sign-in screen is served by apps/web.'];
            }
        }

        return [];
    }

    /**
     * Every registered panel's path prefix, or an empty list when none can be read.
     *
     * @return list<string>
     */
    private function panelPrefixes(): array
    {
        try {
            $prefixes = [];

            foreach (Filament::getPanels() as $panel) {
                $prefix = '/'.trim($panel->getPath(), '/');

                if ($prefix !== '/') {
                    $prefixes[] = $prefix;
                }
            }

            return $prefixes;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Refuse to serve without a VAPID keypair the push library can actually use.
     *
     * WHY THIS IS A BOOT CHECK AND NOT A LOG LINE. Measured 2026-10-27 while building
     * bin/outbox-kill-proof: with the three VAPID values empty, `outbox:work` claimed every
     * row, contacted no edge at all, and reported `retried` for all of them --
     * VAPID::validate() raises inside WebPush's constructor, the dispatcher catches it
     * (correctly: it must not abandon a batch), and the row backs off. Six attempts later
     * it dead-letters. So the whole product fails SILENTLY and LATE: every page works,
     * every subscription is accepted, and no customer is ever paged.
     *
     * THE CHECK IS A REAL VALIDATION, NOT A PRESENCE TEST. A non-empty string that is not
     * a P-256 key fails exactly the same way at send time as an empty one, and "the
     * variable is set" is the kind of control that passes while the thing it stands for is
     * broken. So this hands the values to the library's own validator -- the same code the
     * dispatcher will call -- and refuses on whatever it refuses. One source for what
     * "usable" means.
     *
     * THE KEYS NEVER REACH THE MESSAGE. The refusal names the config path and nothing
     * else: this string is logged at critical and printed by `boot:check`, and §4 › Edge &
     * ops keeps secrets out of logs at any APP_DEBUG setting. The library's own exception
     * message can carry key material, so it is deliberately not included -- only the fact
     * that validation refused.
     *
     * Skipped in local/testing, like every other check here, because a developer who has
     * not set up push should still be able to boot the application and run the suite --
     * and the suite generates its own keypair per process, which is how no key reaches the
     * repository.
     *
     * @return list<string>
     */
    public function vapidKeysAreUsable(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $vapid = [
            'subject' => config('webpush.vapid.subject'),
            'publicKey' => config('webpush.vapid.public_key'),
            'privateKey' => config('webpush.vapid.private_key'),
        ];

        foreach ($vapid as $name => $value) {
            if (! $this->isNonEmpty($value)) {
                return [self::UNUSABLE_VAPID_KEYS.": webpush.vapid.{$name} is empty, so every Web "
                    .'Push notification would fail inside the dispatcher and dead-letter after '
                    .'its retries. Set VAPID_SUBJECT, VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.'];
            }
        }

        try {
            /** @var array{subject: string, publicKey: string, privateKey: string} $vapid */
            VAPID::validate($vapid);
        } catch (Throwable) {
            // The class and the message are both dropped: a validation failure raises with
            // the key in hand, and this string is logged at critical.
            return [self::UNUSABLE_VAPID_KEYS.': the configured VAPID keypair was refused by the '
                .'push library, so every notification would fail at send time. Check '
                .'VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY are a matching P-256 pair and that '
                .'VAPID_SUBJECT is a mailto: or https: URI.'];
        }

        return [];
    }

    /**
     * Security finding #2: what $request->ip() returns is what the per-IP limit counts.
     *
     * With no trusted proxy, ip() is the raw REMOTE_ADDR -- measured: REMOTE_ADDR
     * 10.0.0.7 plus X-Forwarded-For 203.0.113.9 gave 10.0.0.7. Behind the locked ALB
     * that is the balancer's own ENI, so the per-IP bucket becomes one bucket per ALB
     * node shared by every client, the per-IP limit §4 › Edge & ops requires does not
     * exist, and the limiter inverts into a shared-fate denial of service.
     *
     * THREE WAYS TO GET THIS WRONG, AND ALL THREE REFUSE HERE. Empty is the bug above.
     * Trusting every peer is worse: the forwarded header becomes whatever the caller
     * sent and the limit is bypassed by rotating it. And an entry that does not parse
     * is the quietest of the three -- Symfony's IpUtils simply never matches it, so a
     * typo in a CIDR silently restores the first case with nothing in any log. A check
     * that accepted a malformed value would be a control that reports success it has
     * not established, which is the shape this branch has paid for twice.
     *
     * THE MIDDLE CASE IS A SET OF VALUES AND NOT A PAIR OF LITERALS -- see
     * trustsEveryPeer(). Refusing '*' and '**' alone left three spellings of the same
     * thing accepted, one of them documented as valid, which is a control refusing the
     * mistake it can recognise rather than the mistake.
     *
     * AND IT IS A PROPERTY OF THE LIST, NOT ONLY OF AN ENTRY. Every refusal above reads
     * one entry at a time, and the trusted set is their UNION, so two entries that are
     * each half the internet are each accepted and together are '*'. Measured
     * 2026-10-27: TRUSTED_PROXIES=0.0.0.0/1,128.0.0.0/1 was ACCEPTED and $request->ip()
     * was the caller's own X-Forwarded-For from peer 198.51.100.77 and from 255.1.2.3;
     * ::/1,8000::/1 did the same for every v6 peer. Same bypass as the catch-all above,
     * spelled with two entries instead of one. unionCoversAFamily() closes it by
     * merging the entries and asking whether anything is left uncovered, and
     * isWiderThanABalancer() refuses a single range wider than this project's own widest
     * documented value -- because a /1 alone is half of every address there is, and the
     * old floor of 1 was placed where it excluded nothing.
     *
     * Exempt in local and testing: neither has a proxy in front of it, so the peer IS
     * the client and trusting nothing is correct there.
     *
     * The single-ingress assumption this depends on is written down in
     * config/trustedproxy.php and owned by Task 13 along with the CIDR itself.
     *
     * @return list<string>
     */
    public function trustedProxiesAreSound(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $entries = $this->configuredProxies();

        if ($entries === []) {
            return [self::UNTRUSTED_PROXY_CONFIGURATION.': no trusted proxy is configured, so '
                .'$request->ip() is the peer address. Behind a load balancer that is the balancer, '
                .'and the per-IP rate limit becomes one bucket shared by every client on this task '
                .'(§4 › Edge & ops requires a limit per IP). Set TRUSTED_PROXIES to the CIDR the '
                .'balancer connects from -- never to a catch-all; see config/trustedproxy.php.'];
        }

        foreach ($entries as $entry) {
            if ($this->trustsEveryPeer($entry)) {
                return [self::UNTRUSTED_PROXY_CONFIGURATION.': TRUSTED_PROXIES trusts every peer '
                    ."([{$entry}]), which makes X-Forwarded-For whatever the caller sent -- the per-IP "
                    .'rate limit is then bypassed by rotating one header, which is worse than having '
                    .'no value at all. Name the balancer\'s CIDR instead, with a prefix of at least 1.'];
            }
        }

        if ($this->unionCoversAFamily($entries)) {
            return [self::UNTRUSTED_PROXY_CONFIGURATION.': the TRUSTED_PROXIES entries trust every '
                .'peer BETWEEN THEM (['.implode('], [', $entries).']), which is the catch-all above '
                .'spelled as a list -- $request->ip() is whatever the caller sent from any address '
                .'of that family. Each entry is read on its own by every other check here; this one '
                .'merges them. Name the balancer\'s CIDR instead.'];
        }

        foreach ($entries as $entry) {
            if ($this->isWiderThanABalancer($entry)) {
                return [self::UNTRUSTED_PROXY_CONFIGURATION.": TRUSTED_PROXIES carries [{$entry}], a "
                    .'range far wider than any load balancer: the prefix floor here is /'
                    .self::NARROWEST_V4_PREFIX.' for IPv4 and /'.self::NARROWEST_V6_PREFIX.' for '
                    .'IPv6, which are the widest values this project documents. Below that the '
                    .'single-ingress assumption in config/trustedproxy.php cannot hold -- a /1 is '
                    .'half of every address there is, and it hands $request->ip() to any peer '
                    .'inside it exactly as a catch-all does.'];
            }
        }

        $invalid = array_values(array_filter(
            $entries,
            fn (string $entry): bool => ! $this->isProxyAddress($entry),
        ));

        if ($invalid !== []) {
            return [self::UNTRUSTED_PROXY_CONFIGURATION.': TRUSTED_PROXIES carries entries that are '
                .'not an address or a CIDR: ['.implode('], [', $invalid).']. Symfony never matches '
                .'such an entry, so it would be inert and $request->ip() would quietly go back to '
                .'being the peer address.'];
        }

        return [];
    }

    /**
     * The entries, from the sources TrustProxies will really use.
     *
     * ResolvedTrustedProxies, never config() directly: the middleware prefers the
     * trustProxies(at: ...) override and reading the config alone made this clause
     * blind to the one call that sets a catch-all in one line. That class carries the
     * measurement -- and names the THIRD source it does not see, a subclass's own
     * $proxies property, which is R-053 and latent while the global stack carries the
     * framework's own TrustProxies.
     *
     * @return list<string>
     */
    private function configuredProxies(): array
    {
        /** @var mixed $configured */
        $configured = ResolvedTrustedProxies::asTrustProxiesReadsThem();

        $raw = match (true) {
            is_string($configured) => explode(',', $configured),
            is_array($configured) => $configured,
            default => [],
        };

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? trim($entry) : '', $raw),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * Does this entry make every peer a trusted proxy?
     *
     * FOUR SPELLINGS, AND ONLY TWO OF THEM LOOK LIKE ONE. The literals and the
     * REMOTE_ADDR token are in CATCH_ALL_PROXIES above. The fourth is a /0 prefix,
     * which reads like a CIDR and is one -- measured against the pinned Symfony's
     * IpUtils: checkIp4() returns true for ANY v4 peer when the netmask is 0, whatever
     * the base address, so 10.0.0.0/0 is as total as 0.0.0.0/0; checkIp6() does the
     * same for every v6 peer. A /0 is '*' written so that it passes a CIDR check.
     *
     * The prefix is compared as a NUMBER rather than against the string '0', because
     * Symfony's v4 path reaches the same place by two routes: '0' short-circuits to
     * true, and '00' falls through to a zero-length substr_compare that also returns
     * true. Refusing on (int) 0 covers both and cannot be narrower than the library.
     *
     * A /0 whose address does not parse is a typo rather than a catch-all, and the
     * malformed clause says so more usefully, so it is left to fall through.
     */
    private function trustsEveryPeer(string $entry): bool
    {
        if (in_array($entry, self::CATCH_ALL_PROXIES, true)) {
            return true;
        }

        if (! str_contains($entry, '/')) {
            return false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        return ctype_digit($prefix)
            && (int) $prefix === 0
            && filter_var($address, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Do the entries, taken TOGETHER, leave no address of a family untrusted?
     *
     * THE UNION AND NOT THE WIDEST MEMBER. `0.0.0.0/1` and `128.0.0.0/1` are each half
     * the internet, so each passes every per-entry check here, and between them they are
     * `*`: measured at the previous head, ACCEPTED with ip() = the caller's own header
     * from 198.51.100.77 and from 255.1.2.3. The two halves do not overlap, so a check
     * that looked for a single total entry -- or that summed widths and compared against
     * the widest one -- would not see it.
     *
     * A SWEEP RATHER THAN ARITHMETIC, deliberately: the ranges are merged as packed
     * addresses and the question asked is "is any address left over", which is exact in
     * both families and needs no integer wider than a byte. A v6 family is 2^128
     * addresses, so counting them would mean bcmath or gmp -- a boot-path dependency a
     * fresh clone might not have, and §6 requires these gates to pass from one.
     *
     * Entries that are already refused above (a literal catch-all, a /0) and entries
     * that do not parse are skipped: each has its own clause with a better message, and
     * including them here would only change which message an operator reads.
     *
     * @param  list<string>  $entries
     */
    private function unionCoversAFamily(array $entries): bool
    {
        /** @var array<int, list<array{0: string, 1: string}>> $families */
        $families = [];

        foreach ($entries as $entry) {
            if ($this->trustsEveryPeer($entry)) {
                continue;
            }

            $range = $this->entryRange($entry);

            if ($range !== null) {
                $families[strlen($range[0])][] = $range;
            }
        }

        foreach ($families as $ranges) {
            if ($this->coversEveryAddress($ranges)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One family's ranges, sorted and swept for a gap.
     *
     * The cursor is the lowest address nothing has covered yet. Sorting by first address
     * makes one pass enough: a range that starts above the cursor proves a gap, and
     * otherwise the cursor moves to just past that range's last address. Running off the
     * top of the family is what total coverage looks like, and it is the only way out
     * with true -- ADJACENCY IS THE WHOLE POINT, since 0.0.0.0/1 and 128.0.0.0/1 touch
     * without overlapping, so merging on overlap alone would miss exactly this case.
     *
     * @param  list<array{0: string, 1: string}>  $ranges
     */
    private function coversEveryAddress(array $ranges): bool
    {
        if ($ranges === []) {
            return false;
        }

        usort($ranges, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $cursor = str_repeat("\x00", strlen($ranges[0][0]));

        foreach ($ranges as [$first, $last]) {
            if (strcmp($first, $cursor) > 0) {
                return false;
            }

            $next = $this->nextAddress($last);

            if ($next === null) {
                return true;
            }

            if (strcmp($next, $cursor) > 0) {
                $cursor = $next;
            }
        }

        return false;
    }

    /** The address one above this one, or null at the top of the family. */
    private function nextAddress(string $packed): ?string
    {
        for ($i = strlen($packed) - 1; $i >= 0; $i--) {
            $byte = ord($packed[$i]);

            if ($byte < 255) {
                $packed[$i] = chr($byte + 1);

                return $packed;
            }

            $packed[$i] = "\x00";
        }

        return null;
    }

    /**
     * An entry's first and last address, packed, or null if it is not a range at all.
     *
     * inet_pton rather than filter_var because the packed form is what the sweep needs,
     * and its LENGTH is the family -- 4 bytes or 16 -- which is also the per-family
     * width the prefix is read against.
     *
     * @return array{0: string, 1: string}|null
     */
    private function entryRange(string $entry): ?array
    {
        $slash = strpos($entry, '/');
        $address = $slash === false ? $entry : substr($entry, 0, $slash);
        $prefix = $slash === false ? null : substr($entry, $slash + 1);

        $packed = @inet_pton($address);

        if ($packed === false) {
            return null;
        }

        $width = strlen($packed) * 8;

        if ($prefix === null) {
            $bits = $width;
        } elseif (ctype_digit($prefix) && (int) $prefix <= $width) {
            $bits = (int) $prefix;
        } else {
            return null;
        }

        $mask = $this->packedMask($bits, strlen($packed));
        $first = $packed & $mask;

        return [$first, $first | ~$mask];
    }

    /** A prefix length as a packed netmask of the given byte width. */
    private function packedMask(int $bits, int $bytes): string
    {
        $mask = str_repeat("\xff", intdiv($bits, 8));

        if ($bits % 8 !== 0) {
            $mask .= chr((0xFF << (8 - $bits % 8)) & 0xFF);
        }

        return str_pad($mask, $bytes, "\x00");
    }

    /**
     * Is this entry a wider range than a load balancer could plausibly connect from?
     *
     * The single-entry half of the union bypass: a /1 is accepted by every other clause
     * here and hands ip() to any peer inside it, which is half the internet. Measured
     * 2026-10-27 before this clause: `0.0.0.0/1` with peer 10.0.0.7 gave ip()
     * 203.0.113.9. The floor is in NARROWEST_V4_PREFIX / NARROWEST_V6_PREFIX, with the
     * reason it is those two numbers and not others.
     *
     * A bare address is one host and is never too wide. An entry that does not parse
     * belongs to the malformed clause, which says something more useful about it.
     */
    private function isWiderThanABalancer(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        $packed = @inet_pton($address);

        if ($packed === false || ! ctype_digit($prefix)) {
            return false;
        }

        $floor = strlen($packed) === 4 ? self::NARROWEST_V4_PREFIX : self::NARROWEST_V6_PREFIX;

        return (int) $prefix < $floor;
    }

    /**
     * An address, or a CIDR whose prefix is between 1 and the family's width.
     *
     * The floor of 1 is deliberate duplication: a /0 is already refused above as a
     * catch-all, and repeating the bound here means the ORDER of the two clauses is not
     * the only thing standing between a /0 and a pass. REMOTE_ADDR is no longer
     * accepted anywhere -- it is in CATCH_ALL_PROXIES.
     */
    private function isProxyAddress(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        // The ceiling is per family: a /64 is ordinary for IPv6 and meaningless for v4.
        $bits = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return (int) $prefix >= 1 && (int) $prefix <= $bits;
    }

    /**
     * R-020. One round trip, and only sound at boot: there is no transaction open yet,
     * so anything app.tenant_id holds here is session-scoped by definition -- a
     * set_config(..., false), an operator's SET, or a pooled connection handed over
     * carrying one. C2 makes that a hard failure, and TenantContext refuses to adopt
     * such a value, so a process that starts on one would refuse every unit of work it
     * was given. Refusing at boot turns that from a stream of confusing per-request
     * failures into one reason, once.
     *
     * The REMEDY is in the message rather than only in §2.10, because the person
     * reading it is holding a container that will not start.
     *
     * @return list<string>
     */
    public function connectionCarriesNoTenantSetting(string $connection): array
    {
        $guc = DatabaseRoles::guc('tenant');

        try {
            $row = $this->db->connection($connection)->selectOne(
                'select coalesce(current_setting(?, true), ?) as value', [$guc, ''],
            );
        } catch (Throwable) {
            // Already reported by the role check above, which runs first on the same
            // connection. Reporting it twice would make one fault look like two.
            return [];
        }

        $value = is_object($row) && is_string($row->value ?? null) ? $row->value : '';

        if ($value === '') {
            return [];
        }

        // The value is NOT named. It is a tenant id belonging to whoever set it, this
        // process has no claim to it, and the refusal does not depend on what it is.
        return [self::SESSION_SCOPED_TENANT_SETTING." on connection [{$connection}]: a tenant setting is "
            .'in force outside any transaction, so it is session-scoped. Every tenant scope sets it with '
            .'the third argument true (C2), so nothing in this application can have produced it. Remedy: '
            .'reset it on the server side with ALTER ROLE ... RESET, or ALTER DATABASE ... RESET, and '
            .'restart -- see PLAN.md §2.10.'];
    }

    /** @return list<string> */
    public function debugIsOff(): array
    {
        if ($this->app->environment('local') || ! (bool) config('app.debug')) {
            return [];
        }

        return [self::DEBUG_ENABLED.": APP_DEBUG is true and the environment is [{$this->app->environment()}]. "
            .'§4 forbids it outside local. The error envelope redacts at any APP_DEBUG setting, so this is '
            .'not the only control -- but the debug page is 862 KB of this application\'s internals, '
            .'measured, and nothing should be one configuration flag away from serving it.'];
    }

    public function defaultConnection(): string
    {
        $name = config('database.default');

        return is_string($name) && $name !== '' ? $name : DatabaseRoles::connection('app');
    }

    private function isDevelopment(): bool
    {
        return $this->app->environment(self::DEVELOPMENT_ENVIRONMENTS);
    }

    private function isNonEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
