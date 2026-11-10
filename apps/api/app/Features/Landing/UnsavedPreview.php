<?php

declare(strict_types=1);

namespace App\Features\Landing;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RedisException;
use Throwable;

/**
 * The studio's UNSAVED preview state — `QRPAGER-45`.
 *
 * ================================================================================
 * WHAT THIS IS FOR, AND WHY IT IS NOT AUTOSAVE
 * ================================================================================
 *
 * `/studio/preview` renders the STORED draft, so until this existed the preview could only
 * move when the owner pressed Save. Muhammad's ruling of 2026-11-10 named the mechanism
 * before the work and ruled autosave OUT: *"the panel writes the current form state to a
 * short-TTL Redis key … this keeps 'draft' meaning what it means today, writes nothing to
 * Postgres on a keystroke."*
 *
 * So `landing_pages.draft_document` still changes only on an explicit Save or Publish. What
 * lives here is a view of what the owner is LOOKING AT, which expires on its own.
 *
 * ================================================================================
 * ⚠ REDIS CARRIES NO RLS — `§4`. THE KEY'S NAMESPACE IS THE WHOLE TENANT CONTROL.
 * ================================================================================
 *
 * Every Postgres read on this feature is inside `withTenant()` and a policy would catch a
 * mistake. Nothing catches a mistake here: a key built from the wrong id hands one tenant's
 * page to another, silently and with a 200.
 *
 * So the id is a PARAMETER and never a caller's convenience: both sides derive it from
 * `TenantPanelAccess::tenantId()`, which re-resolves it from the authenticated session on
 * every call, and this class refuses an id that is not a UUID rather than concatenating it
 * into a key. A `tenantId` of `*` or `a:*` would otherwise be a key pattern, not a key.
 *
 * ================================================================================
 * ⚠ AND THE VALUE IS A VALIDATED DOCUMENT, NEVER RAW FORM STATE
 * ================================================================================
 *
 * The caller passes `LandingDocument::toArray()`, which means the state has already been
 * through `LandingDocument::fromInput($input, new TenantMediaReferences($tenantId))` — the
 * same gate `LandingPages::saveDraft()` uses, and the one that refuses a media id belonging
 * to another tenant. Writing raw `$this->data` here would put client input one `GET` away
 * from the renderer with that check skipped.
 *
 * That is also why a mid-edit state that does not validate simply never arrives: there is
 * nothing here to refuse, which is what the ruling means by *"an unsaved preview has nothing
 * to refuse, so render it defensively."*
 *
 * ================================================================================
 * ⚠ REDIS DIRECTLY, NOT THROUGH THE CACHE ABSTRACTION — `ReadinessStore`'s reason verbatim
 * ================================================================================
 *
 * The suite runs with `CACHE_STORE=array` (`phpunit.xml:62`) and `config/cache.php` defaults
 * `CACHE_STORE` to `database`. A cache-backed store would therefore be exercised against an
 * in-process array in every test and against Postgres in a misconfigured deployment — the
 * "no mocks for behaviour that belongs to the service" rule, broken quietly. `ReadinessStore`
 * and `RequestLimiter` both already name this; this is the third.
 */
final class UnsavedPreview
{
    /**
     * ⚠ THE TTL IS SHORT BECAUSE OF WHAT IT HAS TO OUTLIVE, WHICH IS NOT THE EDIT.
     *
     * The write and the iframe's `GET` are two legs of one interaction: the panel writes,
     * dispatches a browser event, and the frame re-requests immediately. What this value
     * has to cover is that gap on a slow connection, not the length of an editing session —
     * every further change rewrites the key.
     *
     * 120 seconds is generous for the gap and still bounds an abandoned tab: a reader who
     * walks away leaves a key that is gone two minutes later, rather than a draft-shaped
     * object sitting in Redis until something evicts it.
     *
     * ⚠ AND IT MEASURES INACTIVITY RATHER THAN AGE, BECAUSE `get()` REFRESHES IT.
     * Muhammad's specification of 2026-11-11: as pure wall-clock this produced a real and
     * very confusing bug — edit, pause two minutes, switch to the theme tab and back, and
     * the pane reloads onto an expired key, so **the preview silently reverts to the last
     * save while the edits are still sitting in the form**. Nothing is lost and nothing says
     * anything, which is the worst shape a defect can take. Every render now pushes the
     * expiry out, so the key dies when the OWNER stops, not when the clock does.
     */
    public const TTL_SECONDS = 120;

    private const PREFIX = 'qrpager:studio:unsaved-preview:';

    public function __construct(private readonly RedisFactory $redis) {}

    /**
     * The session half of the key.
     *
     * ⚠ HASHED, AND THE RAW ID NEVER BECOMES PART OF A KEY. A Laravel session id IS the
     * credential in the cookie: anything that can list Redis keys would otherwise be reading
     * live session identifiers out of their names, which turns a store of draft text into a
     * store of session tokens. A truncated SHA-256 distinguishes two tabs — which is all
     * this has to do — and discloses nothing if the keyspace is ever dumped into a log.
     *
     * ⚠ 32 HEX CHARACTERS, WHICH IS 128 BITS OF THE DIGEST, AND THE LENGTH IS A DECISION
     * RATHER THAN A ROUND NUMBER. What a collision would cost is exactly the bug `§3` of
     * Muhammad's specification removes: two studio sessions sharing one key and showing each
     * other their in-flight edits. At 128 bits that needs ~2^64 concurrent sessions of ONE
     * tenant before it is an even bet — and the inputs are server-generated session ids, not
     * attacker-chosen text, so there is nothing to grind against. It is not a realistic route
     * back to that bug, and it is not a security boundary either way: the tenant half of the
     * key is what `§4` rests on, and it is not hashed.
     */
    public static function fingerprint(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 32);
    }

    /**
     * ⚠ A UUID OR NOTHING. `tenantId()` returns one, so this never fires in practice — which
     * is exactly why it is here: the control that only matters when something upstream has
     * already gone wrong is the one that has to exist before it is needed. Glob characters
     * in a Redis key turn a `GET` into a pattern in some clients and a wrong-tenant read in
     * any of them.
     */
    private function key(string $tenantId, string $sessionId): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId) !== 1) {
            throw new \InvalidArgumentException('an unsaved preview key needs a tenant uuid');
        }

        if ($sessionId === '') {
            throw new \InvalidArgumentException('an unsaved preview key needs a session');
        }

        return self::PREFIX.$tenantId.':'.self::fingerprint($sessionId);
    }

    /**
     * Store the document the owner is looking at.
     *
     * @param  array<string, mixed>  $document  `LandingDocument::toArray()`, already validated
     */
    public function put(string $tenantId, string $sessionId, array $document): void
    {
        $key = $this->key($tenantId, $sessionId);

        try {
            $this->redis->connection()->setex(
                $key,
                self::TTL_SECONDS,
                (string) json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        } catch (RedisException) {
            /*
             * ⚠ `RedisException` AND NOT `Throwable`, WHICH IS A CORRECTION. The broad catch
             * this replaced would have swallowed a genuine defect in the WRITER — a
             * `JsonException` from an unencodable document, a `TypeError` from a bad shape —
             * and left the feature green while the preview silently never moved. That is the
             * same shape as the `catch (Throwable)` that made this class's own uuid guard
             * unreachable an hour earlier, and as the vacuous assertions the `§11` round of
             * `QRPAGER-44` found three of. The READ path below stays broad on purpose; a
             * reader has somewhere correct to fall back to and a writer does not.
             *
             * `RedisException` is the right type rather than a guess: `RequestLimiter`'s
             * docblock records it measured, with `REDIS_PORT` on a dead socket — *"Redis::ping(),
             * Cache::store('redis')->get(), RateLimiter::attempt() and tooManyAttempts() ALL
             * threw RedisException (phpredis 6.3.0)"*.
             *
             * THE CONSEQUENCE IS NAMED: the preview shows the stored draft
             * instead of the unsaved state. Redis being down must not turn typing in the
             * studio into a 500 — `RequestLimiter`'s docblock records that `phpredis` throws
             * on every call against a dead socket, so this is a real path and not a
             * defensive flourish. Nothing is lost: the owner's work is in the form, and Save
             * writes Postgres.
             *
             * NOT LOGGED HERE. This runs on a debounce behind every keystroke, so a logged
             * warning would be a log flood during an outage rather than a signal, and the
             * outage is already visible through readiness.
             */
        }
    }

    /**
     * The document the owner is looking at, or null when there is nothing to show.
     *
     * Null covers every failure on purpose — no key, an expired key, a value that is not
     * JSON, a value that is not an object, Redis unreachable. The caller's fallback is the
     * stored draft, which is what the preview showed before this feature existed, so every
     * one of those cases degrades to the old behaviour rather than to an error.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $tenantId, string $sessionId): ?array
    {
        /*
         * ⚠ THE KEY IS BUILT BEFORE THE `try`, AND IT WAS INSIDE IT FOR ONE TEST RUN.
         * `catch (Throwable)` swallows `InvalidArgumentException` as readily as a dead
         * socket, so the uuid guard below returned `null` instead of refusing — a malformed
         * tenant id would have been reported as "nothing to show" and the control that
         * exists to stop a key pattern reaching Redis would have been unreachable. Caught by
         * `it refuses a tenant id that is not a uuid`, which is why that case is written
         * against all three methods rather than one.
         */
        $key = $this->key($tenantId, $sessionId);

        try {
            /** @var mixed $payload */
            $payload = $this->redis->connection()->get($key);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /*
         * ⚠ THE READ IS WHAT KEEPS IT ALIVE — see `TTL_SECONDS`. One `EXPIRE` per render,
         * which costs nothing next to rendering the page, and it is deliberately AFTER the
         * decode: a value this class would refuse should not have its life extended, or a
         * corrupt key would outlive every good one.
         */
        try {
            $this->redis->connection()->expire($key, self::TTL_SECONDS);
        } catch (Throwable) {
            // The value is already in hand; a failed refresh only shortens its life.
        }

        return $decoded;
    }

    /**
     * Drop it, so the SAVED state is what the preview shows again.
     *
     * ⚠ CALLED ON SAVE AND ON PUBLISH, and leaving it out would be the subtle bug this
     * feature is most likely to ship: after a Save the stored draft and the key hold the
     * same document, so nothing looks wrong — until the owner saves, edits, and reverts by
     * hand to what they saved. The key would then still hold the edit and the preview would
     * disagree with the database with no way to clear it but waiting.
     */
    public function forget(string $tenantId, string $sessionId): void
    {
        // Built before the `try`, for the reason `get()` records.
        $key = $this->key($tenantId, $sessionId);

        try {
            $this->redis->connection()->del($key);
        } catch (RedisException) {
            // Same posture and the same narrow type as put(): the key expires on its own
            // within TTL_SECONDS, and a defect that is not Redis being down still surfaces.
        }
    }
}
