<?php

namespace App\Providers;

use App\Models\Passkey;
use App\Models\PersonalAccessToken;
use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\AuthenticatedMembership;
use App\Platform\Auth\AuthenticatedTenantResolver;
use App\Platform\Auth\Memberships;
use App\Platform\Auth\PasskeyOrigins;
use App\Platform\Auth\RequestPrincipal;
use App\Platform\Auth\TwoFactorSecrets;
use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\TenantContext;
use App\Platform\Database\TenantScopeGuard;
use App\Platform\Http\ActiveMembership;
use App\Platform\Http\PacedProblemDocument;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemDocument;
use App\Platform\Http\TenantResolver;
use App\Platform\Http\ValidationProblemDocument;
use App\Platform\Readiness\PressureGauge;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response as GeneratedResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /** The name `components.securitySchemes` publishes the staff credential under. */
    public const STAFF_SECURITY_SCHEME = 'staffBearer';

    /**
     * The operations published as needing no authentication BECAUSE THEY ARE PUBLIC, and
     * they are the two unversioned probes.
     *
     * Not the only operations that publish an empty security requirement: the customer
     * surface publishes one too, for an entirely different reason — see
     * `customerSurfacePaths()` below. `§4 › Tenancy` calls them "two authentication
     * surfaces, never confused", and a reader of the document has to be able to tell which
     * is which, so the two sets are built separately and the reason is attached to each.
     *
     * A LIST AND NOT A PREFIX RULE. `§11.3` requires public routes to be "explicitly marked
     * and justified", and a rule like "anything outside /v1" would mark a future route
     * public by where somebody put it. Two paths, named, and `routes/probes.php` carries the
     * justification: a load balancer's health check must not need a credential, and must not
     * be shed with everything else.
     *
     * @var list<string>
     */
    public const PUBLIC_PATHS = ['/healthz', '/readyz'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // scoped(), not singleton(), and D10's measurement is the reason. Three
        // requests through the real Octane worker resolved this class as three
        // different objects although it was bound singleton() -- not because the
        // binding says so, but because nothing warms it, so each request resolved it
        // inside `clone $this->app` and the sandbox flush discarded it. That is a
        // guarantee by accident: anything that resolves it during boot moves it into
        // the root application and it becomes shared for the worker's life, carrying
        // $openScopes between requests. scoped() says what is meant, and
        // FlushTemporaryContainerInstances forgets it on OperationTerminated, so the
        // guarantee survives someone warming it.
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(RuntimeRoleGuard::class);

        // One guard for the worker's life, deliberately: it holds the armed flag that
        // says a request is in flight, and it has to be the same object the global
        // middleware arms and the connection listener consults. Its state is a single
        // boolean about THIS process, never about a tenant, so sharing it across
        // requests carries nothing between them -- and the request boundary disarms it
        // in both of the two ways a request can end (R-022).
        $this->app->singleton(TenantScopeGuard::class);

        // THE REAL RESOLVER. Task 5 (QRPAGER-12) replaced the pre-Task-5 stub here, and
        // the stub is DELETED rather than disabled -- PROJECT_INSTRUCTIONS.md §4 ›
        // Tenancy calls one that survives Task 5 a Critical finding, and R-035 is the row
        // saying nothing mechanical would have caught it. StubRemovedTest is now that
        // mechanism: three assertions, each of which fails wherever the stub lands.
        //
        // One binding, exactly as the interface's own docblock promised ("Task 5 replaces
        // the implementation and nothing else"), which is why RequireTenantScope, the rate
        // limiter's per-tenant key and the tenant-scope guard all needed no change.
        $this->app->bind(TenantResolver::class, AuthenticatedTenantResolver::class);

        // Task 7's tenant-scoped panel needs the ROLE as well as the tenant, because D1
        // restricts editing to `owner` and `manager`. Same resolution, same
        // re-verification, one more field -- and a second INTERFACE rather than a widened
        // one, so the four surfaces built on TenantResolver's shape are untouched.
        $this->app->bind(ActiveMembership::class, AuthenticatedMembership::class);

        // Request-scoped, never singleton, and QRPAGER-12 §10.5 is the answer to "what
        // does the auth layer cache": the membership set for ONE user for ONE request.
        // Octane keeps workers alive between requests, so a singleton here would carry one
        // user's memberships into the next request -- a cross-tenant read with nothing
        // behind it. scoped() says what is meant and the request boundary forgets it; the
        // measurement that makes this a rule rather than a preference is in the
        // TenantContext comment above.
        $this->app->scoped(Memberships::class, static fn ($app): Memberships => new Memberships(
            $app->make('db')->connection(),
        ));

        // Same reasoning, same lifetime: it reads and writes ONE key in the session, and
        // it holds the membership reader so that selecting a tenant is verified rather
        // than merely recorded.
        $this->app->scoped(ActiveTenant::class);

        // Scoped too, although it holds no state of its OWN: it holds the membership
        // reader, which does. Binding it scoped keeps one uniform rule for the auth layer
        // -- every piece of per-request auth state is forgotten at the boundary -- rather
        // than a rule with an exception a reader has to check each time.
        // AuthOctaneIsolationTest asserts all three together, and it found this one
        // missing: it was being rebuilt on every resolve, which was harmless and was also
        // not the rule.
        $this->app->scoped(RequestPrincipal::class);

        // The second-factor reader. Scoped like the rest of the auth layer; it caches
        // nothing, but the rule is uniform rather than a rule with an exception.
        $this->app->scoped(TwoFactorSecrets::class, static fn ($app): TwoFactorSecrets => new TwoFactorSecrets(
            $app->make('db')->connection(),
        ));

        // laravel/passkeys' own seam for swapping its model. App\Models\Passkey is the
        // vendor model plus HasUuids: every id in this schema is a uuid (PLAN.md §2.5) and
        // the vendor migration -- which this project does not publish -- declares
        // $table->id(). Without this, the first registration inserts no id into a uuid
        // column that has no default.
        Passkeys::usePasskeyModel(Passkey::class);

        // FORTIFY REGISTERS NO ROUTES. config/fortify.php carries the measurement: with
        // the defaults, Fortify and Sanctum published 27 unversioned endpoints outside
        // /v1 -- /register among them -- and RouteSurfaceTest rejected the lot. §6
        // requires /v1 and §11.3 requires every public route to be explicitly marked and
        // justified, so this task mounts the ones it wants by hand in routes/v1.php.
        // Fortify stays as the actions and the pipeline behind them.
        Fortify::$registersRoutes = false;

        // THE SAME MISMATCH AS THE PASSKEY MODEL ABOVE, and the second instance of it.
        // Sanctum's migration declares $table->id(); this project's ids are uuids and its
        // migration is its own, so the vendor model inserts no id into a column with no
        // default. Measured: the first device-token issue answered 500 with a 23502 null
        // violation on `id`.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // One gauge for the worker's life, because CPU throttling is a cumulative
        // counter and the signal is its DELTA over a window: a per-request instance
        // would have no previous reading and could never report throttling at all.
        // It holds two integers about this process and nothing about any tenant.
        $this->app->singleton(PressureGauge::class);

        // Scramble registers GET docs/api, GET docs/api.json and a dev-tools asset
        // route by default. The spec this project ships is a committed file generated
        // by `scramble:export` and diffed in CI, so nothing needs to serve it over
        // HTTP -- and an endpoint listing every route is not a surface to leave open
        // and then gate. Called in register(), which runs before Scramble's
        // bootingPackage() reads the flag.
        Scramble::ignoreDefaultRoutes();

        // THE PUBLISHED CONTRACT SAYS WHICH ROUTES ARE AUTHENTICATED (security M-1).
        //
        // It said nothing: docs/openapi.json had no `components.securitySchemes` and no
        // `security` member on any operation, so all five state-changing staff endpoints
        // were published as requiring no authentication. §11.3 asks that "public routes are
        // explicitly marked and justified", and in that document an authenticated route with
        // no scheme and a deliberately public one were indistinguishable -- while the spec is
        // what Tasks 8-9 generate clients from (§6: "any REST endpoint added is in the
        // generated OpenAPI spec").
        //
        // EVERY OPERATION CARRIES AN EXPLICIT `security`, including the probes, which carry
        // an EMPTY one. OpenAPI's inheritance would let a top-level requirement cover the
        // staff routes and an empty per-operation override exempt the probes, and that is
        // one fewer thing written down: a reader would have to know the inheritance rule to
        // see that /healthz is public on purpose. Explicit everywhere means
        // OpenApiSpecTest can require the member on every operation, so a route added
        // without one fails rather than silently inheriting.
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi): void {
            $openApi->secure(
                SecurityScheme::http('bearer')
                    ->as(self::STAFF_SECURITY_SCHEME)
                    ->setDescription(
                        'Staff and admin endpoints require an authenticated user. The credential is '
                        ."issued by Task 5's auth surface (Fortify + Sanctum), which is not built yet: "
                        .'before then the only resolver is a test harness that reads a container '
                        .'binding and refuses to run outside local and testing, so no credential of '
                        .'any kind is accepted in a deployed environment. These endpoints are '
                        .'therefore NOT public, and a client that omits authentication receives 401.'
                    )
            );

            // Then EXPLICITLY per operation, because secure() above only adds the
            // document-level requirement and OpenAPI's inheritance would leave every
            // operation's own `security` member absent. The probes get an EMPTY
            // requirement, which is the spelling for "no authentication required" --
            // deliberately public, and `routes/probes.php` carries the justification §11.3
            // asks for: a load balancer's health check must not need a credential.
            $moved = 0;
            $customerPaths = self::customerSurfacePaths();
            $authPaths = self::authSurfacePaths();

            foreach ($openApi->paths as $path) {
                foreach ($path->operations as $operation) {
                    $operation->security = [];

                    // THREE SURFACES, NOT TWO, and the rebase onto `task-3` is what made
                    // that necessary (2026-10-27). This step was written when only the
                    // probes and the five staff routes existed, so it said "a probe, or
                    // else staff" -- and the moment Task 4's CUSTOMER routes arrived it
                    // published all four of them as requiring a STAFF BEARER TOKEN. That is
                    // a contract saying a customer needs a staff credential, on the one
                    // surface `§4 › Tenancy` insists must never be confused with it: a
                    // customer authenticates by presenting the QR token in the request
                    // BODY, has no account, and resolves no tenant.
                    //
                    // An empty requirement for both of the tokenless surfaces, because
                    // OpenAPI has no way to say "the credential is in the body" -- `apiKey`
                    // covers header, query and cookie only. So the DOCUMENT cannot
                    // distinguish them and the generator must: the probes come from
                    // PUBLIC_PATHS with `routes/probes.php`'s justification, the customer
                    // routes from the router itself.
                    $uri = '/'.ltrim($path->path, '/');

                    $operation->addSecurity(
                        in_array($uri, self::PUBLIC_PATHS, true)
                        || in_array($uri, $customerPaths, true)
                        || in_array($uri, $authPaths, true)
                            ? []
                            : new SecurityRequirement([self::STAFF_SECURITY_SCHEME => []])
                    );

                    // EVERY PROBLEM DOCUMENT IS PUBLISHED UNDER THE MEDIA TYPE IT IS SENT
                    // WITH. Problem::toResponse() has always set
                    // `Content-Type: application/problem+json` and six test files assert it
                    // against real responses, but the generated document published all four
                    // error codes under `application/json` -- so a client generated from it
                    // is told the wrong media type for every error the API can answer with.
                    //
                    // DECIDED HERE AND NOWHERE ELSE. The attribute's own `mediaType`
                    // argument cannot do it: measured on 2026-10-27, Scramble looks the
                    // schema up under that key in the response it built from `type:`, which
                    // the transformer stores under `application/json`, so a non-default
                    // value publishes `{"type": "string"}` and loses the schema. Keyed off
                    // the SCHEMA and not the status code, because /readyz answers 503 with a
                    // readiness body that is not a problem document and must stay
                    // application/json.
                    foreach ($operation->responses ?? [] as $response) {
                        // A response can be a Reference into `components.responses` rather
                        // than an inline object. None of this API's is -- that map is empty
                        // -- and OpenApiSpecTest pins the number of published problem
                        // responses at 26, so a response that became a shared reference
                        // turns this skip into a RED TEST rather than a silent miss.
                        // (It read 14 until 2026-10-27: the number moved to 26 when Task
                        // 3's validation extension and media-type step reached Task 4's
                        // routes, and this sentence was left behind.)
                        if (! $response instanceof GeneratedResponse) {
                            continue;
                        }

                        if (! isset($response->content['application/json'])) {
                            continue;
                        }

                        if (! self::isProblemDocument($response->getContent('application/json'))) {
                            continue;
                        }

                        $response->content[Problem::CONTENT_TYPE] = $response->content['application/json'];
                        unset($response->content['application/json']);
                        $moved++;
                    }
                }
            }

            // Nothing in the spec would show a post-processing step that silently stopped
            // matching, so the export says what it did. R-029's shape: a pass reported for
            // files never opened. STDERR and not STDOUT, deliberately: `laravel/pao` prints
            // the whole test run as a single JSON line inside an agent session (CLAUDE.md),
            // that line goes to stdout, and the generator runs during the suite as well as
            // during `scramble:export` — so this cannot corrupt it either way.
            fwrite(STDERR, "openapi: {$moved} problem response(s) published as ".Problem::CONTENT_TYPE."\n");
        });
    }

    /**
     * The published paths of the customer surface, read from the router.
     *
     * FROM THE ROUTER AND NOT FROM A SECOND CONSTANT, which is the whole point: a list
     * would have to be edited by whoever adds a customer route, and the failure mode of
     * forgetting is a route published as requiring a staff credential it does not use.
     * The `customer` middleware group IS the surface -- `bootstrap/app.php` mounts
     * `routes/customer.php` under it precisely so a route "cannot drift onto the wrong one
     * by being added in the wrong file" -- so membership of that group is the question, and
     * `gatherMiddleware()` answers it after the group has been expanded.
     *
     * Measured 2026-10-27: `yes` for the four `/v1/pager/*` routes, `no` for the five
     * `/v1/pager-sessions*` ones.
     *
     * @return list<string>
     */
    private static function customerSurfacePaths(): array
    {
        return self::pathsOnGroup('customer');
    }

    /**
     * The published paths of the AUTH surface, read from the router (Task 5).
     *
     * THE FOURTH SURFACE, and it needed adding for the same reason the customer set did:
     * the generator classified all four `/v1/auth/*` routes as requiring a **staff bearer
     * token**, which is a contract telling a client to present the credential that the
     * login it is calling has not issued yet. `OpenApiSpecTest` caught it -- and the catch
     * is the same shape as the one at Task 4's rebase, where the customer routes were
     * published as needing a staff credential.
     *
     * These routes are tokenless in the OpenAPI sense even though `auth.me` genuinely needs
     * a session: a SESSION COOKIE is not expressible as a security scheme here any more
     * than the customer's QR-token-in-the-body is, and declaring the bearer scheme on them
     * would be worse than declaring nothing, because it names a credential that does not
     * apply.
     *
     * @return list<string>
     */
    private static function authSurfacePaths(): array
    {
        return self::pathsOnGroup('auth-surface');
    }

    /**
     * Published paths whose route is on a named middleware group.
     *
     * One helper for both tokenless surfaces, so that adding a third cannot accidentally
     * get a different reading of "is this route on that group".
     *
     * @return list<string>
     */
    private static function pathsOnGroup(string $group): array
    {
        $paths = [];

        // ->getRoutes()->getRoutes(): the collection's ARRAY accessor, not the collection
        // itself. RouteCollectionInterface does not extend Traversable, so iterating it
        // directly is only valid for the concrete class -- true today and not stated by
        // the type, which is exactly the kind of thing this project's static analysis is
        // configured to refuse.
        /** @var list<\Illuminate\Routing\Route> $routes */
        $routes = Route::getRoutes()->getRoutes();

        foreach ($routes as $route) {
            if (in_array($group, $route->gatherMiddleware(), true)) {
                $paths[] = '/'.ltrim($route->uri(), '/');
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Is this response's schema one of the two problem documents?
     *
     * Both shapes the generator produces are accepted: a bare Reference, and a Schema
     * whose type is one. Matched on the component name rather than the status code --
     * see the caller.
     */
    private static function isProblemDocument(mixed $schema): bool
    {
        $reference = match (true) {
            $schema instanceof Reference => $schema,
            $schema instanceof Schema && $schema->type instanceof Reference => $schema->type,
            default => null,
        };

        if ($reference === null || $reference->referenceType !== 'schemas') {
            return false;
        }

        // ALL THREE, and a new one has to be added here or it publishes as
        // application/json while the API sends application/problem+json.
        // PacedProblemDocument arrived on 2026-10-27 with QA M-6 (the polling endpoint's
        // 404 and 503 carry `poll_after_seconds`), and the first export after it reported
        // 24 rather than 26 moved responses -- which is exactly the signal this counter
        // and OpenApiSpecTest's pinned number exist to give.
        return in_array(
            class_basename((string) $reference->fullName),
            [
                class_basename(ProblemDocument::class),
                class_basename(ValidationProblemDocument::class),
                class_basename(PacedProblemDocument::class),
            ],
            true,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Attach the tenant-scope guard to every database connection the manager
        // builds from here on, including the ones built after an Octane purge. It is
        // registered unconditionally and armed per request, so the registration cannot
        // be the thing that is missing -- see TenantScopeGuard for why the attachment
        // is beforeExecuting() and not DB::listen().
        $this->app->make(TenantScopeGuard::class)->listen($this->app->make(Dispatcher::class));

        /*
         * ================================================================================
         * THE PASSKEY ORIGIN ALLOW-LIST, SET HERE AND NOT IN A CONFIG FILE
         * ================================================================================
         *
         * `App\Platform\Auth\PasskeyOrigins` carries the whole argument, including the
         * MEASUREMENT that a published `config/passkeys.php` is silently ignored for every key
         * the vendor file also has on `laravel/framework v13.38.0`. In one line: a published
         * file would look authoritative and do nothing.
         *
         * ⚠ IN `boot()` AND NOT IN `register()`, AND THAT IS THE WHOLE MECHANISM.
         * `PasskeysServiceProvider::register()` calls `mergeConfigFrom`, so its defaults are
         * in place by the time any provider boots; a `config()` call from a `register()` here
         * could run before it and be overwritten. `boot()` runs after every `register()`, so
         * this is the first point at which the application can have the last word.
         *
         * Task 10a is the first task with a WebAuthn ceremony on a page Laravel does not
         * serve, which is why the need appears now and not at Task 5.
         */
        config(['passkeys.allowed_origins' => PasskeyOrigins::allowed()]);
    }
}
