<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * The QR token lookup: §4 › Tenancy's named cross-tenant read.
 *
 * It resolves a presented token to EXACTLY ONE SESSION IN EXACTLY ONE TENANT, which is
 * §4's own wording, and it is the only authentication a customer has. Built here, consumed
 * by Task 4 -- the polling and subscription endpoints are that task's, so there is no HTTP
 * route in front of this yet, deliberately.
 *
 * NO TENANT CONTEXT, and that is the point rather than an omission: the token is what
 * decides the tenant, so there is nothing to scope the lookup by. Everything downstream
 * takes the tenant from the result and opens its own scope with it.
 *
 * THE WORK IS A DATABASE FUNCTION BECAUSE NOTHING ELSE IS AVAILABLE. Measured through the
 * real stack: qrpager_app cannot reference token_hash at all -- `select token_hash ...`
 * raises 42501 and so does `where token_hash = decode(?, 'hex')`, because a column-level
 * SELECT grant is needed to name a column in a predicate and not only in a select list.
 * So the app role cannot write this query in any form. public.resolve_pager_token is a
 * SECURITY DEFINER function owned by qrpager_owner that turns on app.bypass_tenant for
 * its own body and restores it; the migration that creates it carries the rest of §2.8's
 * recipe and the measurements.
 *
 * SHAPE FIRST, THEN HASH, THEN THE DATABASE. A malformed token is refused by PagerToken
 * before anything is hashed or any statement runs, so an attacker's loop costs one regex
 * -- which is the answer to §11's "what does an attacker's loop cost us per request".
 * With 128 bits behind the hash, guessing is the only way in and there is no cheaper one.
 *
 * It answers null for an unknown token and a resolved session for a known one, including
 * an expired or cancelled one. That is deliberate: §4.1 and Task 9's gate both require the
 * PWA to handle "expired-token and invalid-token paths" as DIFFERENT paths, so the product
 * needs to tell them apart, and the holder of a token is the legitimate customer. The
 * migration records the same decision from the SQL side.
 */
final class PagerTokenLookup
{
    /**
     * @param  string  $presented  the raw token exactly as the client sent it
     */
    public function resolve(#[SensitiveParameter] string $presented): ?ResolvedPagerSession
    {
        $token = PagerToken::fromPresented($presented);

        if ($token === null) {
            return null;
        }

        return $this->resolveToken($token);
    }

    public function resolveToken(PagerToken $token): ?ResolvedPagerSession
    {
        // The hash, never the token. The raw value does not reach this statement, this
        // connection, or Postgres at all -- which is what makes "the raw token never
        // appears in any log" hold even with log_statement = 'all' on the cluster.
        $row = DB::selectOne(
            'select session_id, tenant_id, status, order_reference, expires_at, ready_at, last_notified_at
               from resolve_pager_token(decode(?, \'hex\'))',
            [$token->hashHex()],
        );

        if (! is_object($row)) {
            return null;
        }

        // The function's declared RETURNS TABLE is the shape, and it is in the migration
        // rather than in any PHP type -- so it is annotated here the way RuntimeRoleGuard
        // annotates its own catalog query. Narrow by construction: token_hash is not one
        // of the seven columns the function returns (§2.8 rule 1).
        /** @var object{session_id: string, tenant_id: string, status: string, order_reference: string, expires_at: string, ready_at: string|null, last_notified_at: string|null} $row */
        return new ResolvedPagerSession(
            $row->session_id,
            $row->tenant_id,
            PagerStatus::from($row->status),
            $row->order_reference,
            new DateTimeImmutable($row->expires_at),
            $row->ready_at === null ? null : new DateTimeImmutable($row->ready_at),
            $row->last_notified_at === null ? null : new DateTimeImmutable($row->last_notified_at),
        );
    }
}
