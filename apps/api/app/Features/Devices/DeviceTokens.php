<?php

declare(strict_types=1);

namespace App\Features\Devices;

use App\Domain\Audit\AuditActorType;
use App\Domain\Auth\Ability;
use App\Domain\Auth\TenantPin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issuing and revoking KDS device tokens.
 *
 * `QRPAGER-12 §5`: *"A KDS device token lives on a shared screen in a kitchen. Scope it to
 * exactly one tenant and one capability, and make expiry explicit."* Three bounds, and this
 * class is where all three are applied. Each is **refused at issue time** rather than
 * checked at use time, because a token that cannot be minted wrongly needs no check later.
 *
 * ================================================================================
 * ONE CAPABILITY, AND THE LIST IS CLOSED
 * ================================================================================
 *
 * `Ability::issuableToDevice()` is a list of exactly one — `PagerRead`. A kitchen screen
 * reads the board; it does not open sessions, manage staff or mint tokens. **`DevicesWrite`
 * is deliberately absent, and that is what makes "a device token cannot mint another device
 * token" structural**: it is not a check in the issuing controller that could be bypassed,
 * it is an ability a device may not hold, enforced here and again by `RoleMatrix`, which
 * grants `Principal::Device` the same single ability.
 *
 * A caller asking for anything outside that list is refused — not silently narrowed.
 * Narrowing would mean a client believing it holds a capability it does not, which surfaces
 * later as an inexplicable 403.
 *
 * ================================================================================
 * EXPLICIT EXPIRY, AND "FOREVER" IS NOT EXPRESSIBLE
 * ================================================================================
 *
 * `issue()` requires a `$ttlMinutes` and refuses a null, a zero and anything above the
 * configured ceiling. `config/sanctum.php` leaves the GLOBAL `expiration` null on purpose,
 * so a device token's lifetime is the one its issuer chose rather than one a config default
 * supplied — changing that default would otherwise silently re-date every token already
 * issued.
 *
 * ================================================================================
 * THE TENANT IS A CLAIM, NOT AN AUTHORITY
 * ================================================================================
 *
 * The tenant travels in the abilities as `tenant:<uuid>` because
 * `personal_access_tokens` has no `tenant_id` column — `PLAN.md §2.4` records why one is
 * not available. **So this class is not what keeps a token inside its tenant.** The
 * resolver is: it resolves the tenant from the holder's own memberships and then requires
 * the token's pin to equal it, so a token naming a tenant its holder has lost membership in
 * resolves nothing, the moment that membership goes.
 */
final class DeviceTokens
{
    /** The longest life a device token may be given, in minutes. */
    public const MAX_TTL_MINUTES = 60 * 24 * 30;

    /**
     * The ceiling on one `forTenant()` read, and therefore on one `GET /v1/devices`.
     *
     * A restaurant has kitchen screens, not a fleet: a number in the low tens is the real
     * shape and 200 is two orders of magnitude of headroom over it. It is here rather than
     * in `config/` because it bounds a QUERY and not a deployment -- a configurable
     * ceiling is a ceiling somebody raises instead of asking why a restaurant has 200
     * screens.
     */
    public const LIST_LIMIT = 200;

    /**
     * Mint a device token for one tenant and one capability.
     *
     * Returns the PLAINTEXT, which exists exactly once and is never stored: Sanctum keeps
     * `hash('sha256', $plainTextToken)` in the `token` column, measured in
     * `vendor/laravel/sanctum/src/HasApiTokens.php:66`. The caller's response body is the
     * only place it ever appears, so a lost token is re-issued rather than recovered.
     *
     * @return array{id: string, token: string, expires_at: string}
     */
    public function issue(
        User $issuer,
        string $tenantId,
        string $name,
        Ability $ability,
        int $ttlMinutes,
        ?string $actorIp,
    ): array {
        if (! $ability->issuableToDevices()) {
            // Refused, not narrowed. See the class docblock.
            throw new InvalidArgumentException(
                'that capability may not be issued to a device token'
            );
        }

        if ($ttlMinutes < 1 || $ttlMinutes > self::MAX_TTL_MINUTES) {
            throw new InvalidArgumentException('a device token needs an explicit expiry within the ceiling');
        }

        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

        // The tenant pin and the one capability, in that order so that a reader of the
        // stored row sees the scope before the permission.
        $abilities = [
            TenantPin::for($tenantId),
            $ability->value,
        ];

        /*
         * THE CREDENTIAL AND ITS AUDIT ROW COMMIT TOGETHER OR NOT AT ALL.
         *
         * ⚠ THEY USED NOT TO. `createToken()` ran outside any transaction and the audit
         * insert then opened its own, so a failure between them left A LIVE CREDENTIAL WITH
         * NO AUDIT ROW -- and, because the plaintext is returned only on the response that
         * never arrived, a token nobody knows exists and nobody can find to revoke. Found by
         * Task 5's QA review against `§6`'s "crash mid-way" question, on the one act this
         * class's own comment calls "exactly the kind of act the log exists for".
         *
         * `§3` row 3's gate clause is the precedent and the wording: "the status change, the
         * outbox row and the audit row commit together or not at all". A credential and the
         * record of its issue are the same kind of pair.
         *
         * `withTenant()` IS the transaction -- it opens one and sets `app.tenant_id` for its
         * duration with `set_config(..., true)` (C2), so both writes are inside it. The
         * token row is global and RLS-free (`§2.4`), so being inside a tenant scope neither
         * narrows nor refuses it; the audit row is a tenant table and could not be written
         * outside one at all (42501, `§2.7`).
         */
        [$token, $id] = withTenant($tenantId, function () use (
            $issuer, $tenantId, $name, $abilities, $ability, $expiresAt, $actorIp
        ): array {
            $token = $issuer->createToken($name, $abilities, $expiresAt);

            $id = (string) $token->accessToken->getKey();

            DB::insert(
                'insert into audit_log
                    (tenant_id, actor_type, actor_user_id, action, subject_type, subject_id, context, ip_address)
                 values (?, ?::audit_actor_type, ?, ?, ?, ?, ?::jsonb, ?::inet)',
                [
                    $tenantId,
                    AuditActorType::User->value,
                    (string) $issuer->getKey(),
                    'device_token.issued',
                    'device_token',
                    $id,
                    json_encode([
                        // The ability and the expiry, never the plaintext and never the
                        // hash: §4 › Edge & ops keeps credential material out of anything
                        // readable, and audit_log is readable by both runtime roles.
                        'ability' => $ability->value,
                        'expires_at' => $expiresAt->toIso8601String(),
                        'name' => $name,
                    ], JSON_THROW_ON_ERROR),
                    $actorIp,
                ],
            );

            return [$token, $id];
        });

        return [
            'id' => $id,
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Revoke one device token belonging to this tenant.
     *
     * Returns false when the token does not exist, is not a device token, or is pinned to
     * another tenant — all three answer the same way, so a caller cannot use this endpoint
     * to discover which token ids exist in tenants that are not theirs.
     */
    public function revoke(User $actor, string $tenantId, string $tokenId, ?string $actorIp): bool
    {
        $row = DB::selectOne(
            'select id, abilities from personal_access_tokens where id = ?',
            [$tokenId],
        );

        if ($row === null) {
            return false;
        }

        $abilities = json_decode((string) ($row->abilities ?? '[]'), true);

        // The pin has to match THIS tenant. personal_access_tokens is global and RLS-free
        // (§2.4), so nothing in the database narrows this query -- the check has to be
        // here, and it is the reason this method takes the resolved tenant rather than
        // reading one from the request.
        if (! is_array($abilities) || ! in_array(TenantPin::for($tenantId), $abilities, true)) {
            return false;
        }

        // THE DELETE AND ITS AUDIT ROW, in one transaction, for the reason `issue()` states
        // at length. The failure here is the mirror image: a credential revoked with no
        // record of who revoked it, which is the question an audit log exists to answer.
        return withTenant($tenantId, function () use ($actor, $tenantId, $tokenId, $actorIp): bool {
            $deleted = DB::delete('delete from personal_access_tokens where id = ?', [$tokenId]);

            if ($deleted === 0) {
                return false;
            }

            DB::insert(
                'insert into audit_log
                    (tenant_id, actor_type, actor_user_id, action, subject_type, subject_id, context, ip_address)
                 values (?, ?::audit_actor_type, ?, ?, ?, ?, ?::jsonb, ?::inet)',
                [
                    $tenantId,
                    AuditActorType::User->value,
                    (string) $actor->getKey(),
                    'device_token.revoked',
                    'device_token',
                    $tokenId,
                    json_encode([], JSON_THROW_ON_ERROR),
                    $actorIp,
                ],
            );

            return true;
        });
    }

    /**
     * The device tokens this tenant holds, without anything secret.
     *
     * ⚠ BOUNDED IN SQL, AND THE BOUND WAS ADDED AT TASK 10a WHEN THIS GREW A CALLER.
     * Until then nothing in the application called this method -- only
     * `AuthUnitGapsTest` -- so an unbounded read cost nothing. `GET /v1/devices` is the
     * first caller, which makes the row count a per-request cost that a tenant's own
     * minting history decides, and `§6` Q5 wants that answer to be a number rather than
     * "however many they have made". `self::LIST_LIMIT` is it.
     *
     * ⚠ THE MATCH IS `jsonb @>` AND IT USED TO BE `like '%pin%'` -- `§11` SECURITY L-1.
     *
     * Two things were wrong with the pattern match, and the second is the one that mattered:
     *
     *   - a LEADING wildcard cannot use an index under any circumstances, so the read was a
     *     sequential scan of `personal_access_tokens` -- every tenant's rows, on a route a
     *     kitchen screen's settings page calls;
     *   - the limit applied to the PREFILTERED set rather than the checked one. The pattern
     *     is a substring test, the exact pin membership was re-tested in PHP below, so a row
     *     could satisfy the `LIKE`, fill a limit slot and then be dropped -- hiding a real
     *     row from the person reading the list. That needed `tenant:<uuid>` to appear as a
     *     substring of some other ability string without being an element, which nothing in
     *     this application writes; it was unreachable rather than impossible, and the
     *     paragraph explaining why was longer than the fix.
     *
     * `abilities::jsonb @> '["tenant:<uuid>"]'` is the exact element test, in SQL, so the
     * limit now applies to the set the method returns. Measured on the test database
     * (2026-11-02, Postgres 16, 10 rows): both forms plan a `Seq Scan`, so this is not yet
     * faster -- what it is, is exact, and indexable. The index that would make it cheap is a
     * GIN over `((abilities::jsonb))`, which is a migration on a Sanctum table and belongs to
     * the task that owns performance: `§5` `R-223`.
     *
     * The PHP re-check below stays. With `@>` it can no longer drop a row -- anything SQL
     * matches, it matches -- so it is redundant BY CONSTRUCTION rather than by argument, and
     * it is kept because the column is `text`: a row written by anything other than
     * `issue()` five lines up (a Sanctum upgrade, a manual repair) is JSON this method has
     * not seen, and the cheap exact test over a decoded array costs one `in_array` per row.
     *
     * @return list<array{id: string, name: string, expires_at: string|null, last_used_at: string|null}>
     */
    public function forTenant(string $tenantId, int $limit = self::LIST_LIMIT): array
    {
        $rows = DB::select(
            'select id, name, expires_at, last_used_at, abilities
               from personal_access_tokens
              where abilities::jsonb @> ?::jsonb
              order by created_at desc
              limit ?',
            [(string) json_encode([TenantPin::for($tenantId)]), max(1, $limit)],
        );

        $out = [];

        foreach ($rows as $row) {
            $abilities = json_decode((string) ($row->abilities ?? '[]'), true);

            // Redundant by construction now that the SQL match is `@>` (see the docblock),
            // and kept because `abilities` is a `text` column: JSON written by anything but
            // `issue()` is JSON this method has not seen.
            if (! is_array($abilities) || ! in_array(TenantPin::for($tenantId), $abilities, true)) {
                continue;
            }

            $out[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'expires_at' => $row->expires_at === null ? null : (string) $row->expires_at,
                'last_used_at' => $row->last_used_at === null ? null : (string) $row->last_used_at,
            ];
        }

        return $out;
    }
}
