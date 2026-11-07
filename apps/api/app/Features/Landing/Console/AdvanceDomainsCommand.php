<?php

declare(strict_types=1);

namespace App\Features\Landing\Console;

use App\Features\Landing\DomainRefused;
use App\Features\Landing\TenantDomains;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Advance one tenant's domains through the lifecycle (§12.3, SR-3).
 *
 * ================================================================================
 * IT TAKES A TENANT ARGUMENT, AND THAT IS THE WHOLE POINT OF THE SIGNATURE
 * ================================================================================
 *
 * A command that swept EVERY tenant's domains would be a fifth cross-tenant read, and
 * `§4 › Tenancy` names four and says adding one "requires editing this file first" -- a
 * rank-2 edit, which is a stop rather than a build step. So this one is per tenant and
 * runs inside that tenant's own scope, which is SR-3's resolution and R-130's residual.
 *
 * ⚠ IT IS NOT SCHEDULED, and that is NOT R-058's shape repeating. R-058 is about a
 * command registered and scheduled nowhere, enforcing nothing. This one cannot be
 * scheduled: a scheduler entry would have to name a tenant, and there is no such thing
 * as "the tenant" at schedule time. What drives it in the product is the /studio/domains
 * screen, which is a tenant acting inside their own scope; this exists so the same
 * lifecycle can be driven from a terminal, and so a developer can exercise it locally
 * without a browser (R-043's shape: a demonstration that lives only in a session).
 */
class AdvanceDomainsCommand extends Command
{
    protected $signature = 'domains:advance {tenant : the tenant id or slug whose domains to advance}';

    protected $description = "Advance one tenant's custom domains one lifecycle step each";

    public function handle(TenantDomains $domains): int
    {
        $argument = (string) $this->argument('tenant');

        // ⚠ THE ARGUMENT IS ONLY COMPARED AGAINST `id` WHEN IT IS A UUID, and the first
        // version of this method was not -- `where('id', $argument)->orWhere('slug', …)`
        // with a slug raises 22P02, "invalid input syntax for type uuid", before the slug
        // branch is ever evaluated. So `domains:advance my-restaurant` died with a driver
        // error instead of resolving the tenant.
        //
        // That is R-110's shape exactly, one task later: an operator-supplied string cast
        // straight into a uuid column, turning a perfectly ordinary input into an
        // exception from the driver. R-110 was an attacker-controlled bearer token
        // answering 422 instead of 401; this is a slug answering a stack trace instead of
        // a tenant. Same cause, and worth the same guard.
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $argument) === 1;

        // ONE cross-tenant read, and it is the named mechanism in `§2.10` rather than a
        // new path: the OWNER connection with an audited bypass, resolving an operator-
        // supplied identifier to a tenant id before any tenant scope exists.
        $tenantId = withoutTenantScope(
            'domains:advance resolves the tenant named on the command line',
            static fn (): ?string => DB::connection('pgsql_owner')->table('tenants')
                ->where(static function ($query) use ($argument, $isUuid): void {
                    $query->where('slug', $argument);

                    if ($isUuid) {
                        $query->orWhere('id', $argument);
                    }
                })
                ->value('id'),
            'pgsql_owner',
        );

        if (! is_string($tenantId)) {
            $this->components->error("No tenant matches [{$argument}].");

            return self::FAILURE;
        }

        $rows = $domains->all($tenantId);

        if ($rows === []) {
            $this->line('No custom domains on this tenant.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $before = $row->status->value;

            try {
                $after = $domains->advance($tenantId, $row->id)->value;
            } catch (DomainRefused $e) {
                $this->components->error($row->hostname.': '.$e->reason);

                continue;
            }

            $this->line(sprintf(
                '%s: %s',
                $row->hostname,
                $before === $after ? $after.' (unchanged)' : $before.' -> '.$after,
            ));
        }

        return self::SUCCESS;
    }
}
