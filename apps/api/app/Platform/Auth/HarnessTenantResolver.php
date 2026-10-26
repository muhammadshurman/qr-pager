<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Platform\Http\TenantResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The pre-Task-5 tenant resolver (B5). It exists so the rest of this task can be built
 * and tested, and it is designed to be impossible to deploy.
 *
 * IT READS A CONTAINER BINDING AND NOTHING ELSE. Never a header, never a route
 * parameter, never a query string, never a cookie -- so NO HTTP INPUT REACHES IT. That
 * is the whole point: a stub that read a header would be an unauthenticated
 * tenant-switching parameter, which is the most dangerous shape this application could
 * ship, and it would read as perfectly ordinary code. The $request argument is accepted
 * because the interface has it and ignored because the stub must not use it.
 *
 * AND IT REFUSES TO RUN OUTSIDE local/testing. B5 asks for the stub surviving Task 5 to
 * be a mechanical failure rather than a reviewer's job, and this is stronger than a CI
 * check: it cannot serve a production request at all. Since every /v1 route is behind
 * RequireTenantScope, a production deployment carrying this class denies every API
 * request loudly -- which is deny-by-default working, not a regression. routes/v1.php
 * is empty at Task 2 by design, so there is nothing to deny yet.
 *
 * deptrac keeps App\Platform\Auth out of reach of every feature module, so a feature
 * cannot be built on it and then break when the real resolver arrives.
 */
final class HarnessTenantResolver implements TenantResolver
{
    /** The container key the harness binds. Not a config value: nothing outside a test may set it. */
    public const BINDING = 'tenancy.harness_tenant';

    public function __construct(private readonly Application $app) {}

    public function resolve(Request $request): ?string
    {
        if (! $this->app->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'The pre-Task-5 tenant resolver cannot run in ['.$this->app->environment().']. '
                .'It resolves a tenant from a container binding a test sets, which is not '
                .'authentication -- Task 5 (QRPAGER-12) owns the real resolver. A deployment '
                .'reaching this line is a deployment that would have served API requests with no '
                .'authentication at all.'
            );
        }

        if (! $this->app->bound(self::BINDING)) {
            return null;
        }

        /** @var mixed $tenant */
        $tenant = $this->app->make(self::BINDING);

        // Shape-checked here as well as at the caller: a binding is PHP state, and the
        // value becomes the Redis namespace and every tenant scope downstream.
        return is_string($tenant) && Str::isUuid($tenant) ? $tenant : null;
    }
}
