<?php

namespace App\Providers;

use App\Platform\Auth\HarnessTenantResolver;
use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\TenantContext;
use App\Platform\Database\TenantScopeGuard;
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
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** The name `components.securitySchemes` publishes the staff credential under. */
    public const STAFF_SECURITY_SCHEME = 'staffBearer';

    /**
     * The only operations published as needing no authentication, and they are the two
     * unversioned probes.
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

        // The pre-Task-5 resolver (B5). One binding, replaced wholesale by Task 5 --
        // which is why RequireTenantScope, the rate limiter's per-tenant key and
        // everything downstream speak to the INTERFACE and not to this class. The stub
        // reads a container binding a test sets, never an HTTP input, and refuses to run
        // outside local/testing, so it cannot serve a production request at all.
        $this->app->bind(TenantResolver::class, HarnessTenantResolver::class);

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

            foreach ($openApi->paths as $path) {
                foreach ($path->operations as $operation) {
                    $operation->security = [];

                    $operation->addSecurity(
                        in_array('/'.ltrim($path->path, '/'), self::PUBLIC_PATHS, true)
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
                        // responses at 14, so a response that became a shared reference
                        // turns this skip into a RED TEST rather than a silent miss.
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
            // files never opened.
            fwrite(STDERR, "openapi: {$moved} problem response(s) published as ".Problem::CONTENT_TYPE."\n");
        });
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

        return in_array(
            class_basename((string) $reference->fullName),
            [class_basename(ProblemDocument::class), class_basename(ValidationProblemDocument::class)],
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
    }
}
