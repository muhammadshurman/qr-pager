<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Tenancy\TenantLanguage;
use Illuminate\Support\Facades\DB;

/**
 * Which language a PERSON reads the PANEL in. `QRPAGER-48`.
 *
 * ================================================================================
 * ⚠⚠ THE TWO LANGUAGES ARE DIFFERENT FACTS, AND THIS CLASS EXISTS TO KEEP THEM APART
 * ================================================================================
 *
 * Muhammad's ruling of 2026-11-13, in `PLAN.md §2.4` and `§1`:
 *
 *   "The PANEL's language is a property of the PERSON signed in. Two staff at the same
 *    restaurant can want different ones. It belongs on the user. `tenants.language` keeps
 *    exactly its current meaning: the language of the PUBLISHED page."
 *
 * So `TenantPageLanguage` answers *what do my CUSTOMERS read*, and this answers *what do I
 * read*. Before this ticket there was one column answering both, and a topbar toggle over it
 * would have let an owner change the first by choosing the second.
 *
 * ================================================================================
 * `NULL` IS A THIRD ANSWER, NOT A MISSING ONE
 * ================================================================================
 *
 * `users.panel_language` is nullable with no default, and `NULL` means **not chosen** — a
 * different fact from either language. Resolution is `panel_language ?? tenants.language`,
 * so a person who has never touched the toggle reads the panel in the restaurant's language,
 * which is **exactly the behaviour that shipped before this column existed**. Nothing was
 * backfilled, and this class is the only place that ordering is written down.
 *
 * ⚠ **NO `withTenant` ANYWHERE IN THIS FILE, DELIBERATELY.** `users` is a GLOBAL table —
 * `§2.4`, no `tenant_id` and no RLS — so a scope here would be cargo-culted from
 * `TenantPageLanguage` rather than required by anything. The tenant id is still needed, but
 * only to ask `TenantPageLanguage` for the fallback, which opens its own scope.
 */
final class PanelLanguage
{
    public function __construct(private readonly TenantPageLanguage $pages) {}

    /**
     * The language to render the panel in for this person, in this restaurant.
     *
     * The person's own choice wins; absent one, the restaurant's language is used. A row the
     * app role cannot see resolves the same way as an unset preference, because both mean
     * "nothing chosen here" and the panel must still render either way — `TenantPageLanguage`
     * takes the same position on a missing tenant row, for the same reason.
     */
    public function current(string $userId, string $tenantId): TenantLanguage
    {
        /** @var string|null $chosen */
        $chosen = DB::table('users')->where('id', $userId)->value('panel_language');

        if ($chosen !== null) {
            return TenantLanguage::from($chosen);
        }

        return $this->pages->current($tenantId);
    }

    /**
     * Record this person's choice, or clear it back to "follow the restaurant".
     *
     * ⚠ THE ROW COUNT IS CHECKED even though `users` carries no RLS. The reason is narrower
     * here than in `TenantPageLanguage` and worth stating rather than copying: an UPDATE that
     * matches no row means the id does not exist, which can only happen if the signed-in
     * user was deleted mid-session. Reporting success for that would leave the person
     * looking at a toggle that does nothing.
     */
    public function set(string $userId, ?TenantLanguage $language): void
    {
        $affected = DB::table('users')->where('id', $userId)->update([
            'panel_language' => $language?->value,
            'updated_at' => now(),
        ]);

        if ($affected !== 1) {
            throw new LanguageNotChanged;
        }
    }
}
