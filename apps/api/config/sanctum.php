<?php

declare(strict_types=1);
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | SANCTUM REGISTERS NO ROUTES EITHER
    |--------------------------------------------------------------------------
    |
    | Sanctum's one route is GET /sanctum/csrf-cookie, and it was in the list of 27
    | unversioned endpoints RouteSurfaceTest rejected (see config/fortify.php for the
    | measurement). `routes => false` is Sanctum's own documented off switch, checked in
    | SanctumServiceProvider::configureRoutes().
    |
    | Nothing is lost by turning it off. That route exists for a SPA on a different
    | origin that needs an XSRF-TOKEN cookie before its first stateful request. This
    | project's staff surface is same-origin (apps/web is served from the same host at
    | Task 8+, ADR-003), so Laravel's own `web` middleware sets the cookie on any GET;
    | and the KDS/device surface is stateless bearer-token traffic, where CSRF does not
    | apply at all. If a cross-origin SPA is ever wanted, the route comes back under /v1
    | with a test, rather than arriving unannounced with a package.
    */
    'routes' => false,

    /*
    | Stateful domains: EMPTY, and that is the decision rather than an omission.
    |
    | A domain on this list makes Sanctum authenticate a request from it using the
    | SESSION COOKIE instead of a bearer token. That is the right behaviour for a
    | first-party SPA and the wrong behaviour for everything this task builds: the staff
    | surface already authenticates by session through the `web` guard, and a KDS device
    | is a bearer token on a shared screen. Leaving it empty means a request is stateful
    | only if it genuinely carries a session, and a token request is never silently
    | upgraded to one.
    |
    | It also keeps the two authentication surfaces unconfused (§4 › Tenancy), because a
    | stateful domain is precisely a mechanism for letting one surface answer as the
    | other.
    */
    'stateful' => [],

    'guard' => ['web'],

    /*
    | Token expiry in minutes, GLOBALLY: null means "no global expiry", and the device
    | tokens this task issues each carry their OWN expires_at instead.
    |
    | QRPAGER-12 §5 requires a device token's expiry to be EXPLICIT. A global default
    | would make it implicit -- a token would expire because of a config value nobody
    | passed, and changing that value would silently re-date every token already issued.
    | The issuing path refuses to mint a device token without an expiry, so "forever" is
    | not expressible where it matters, and this stays null so that the expiry a token
    | carries is the one its issuer chose.
    */
    'expiration' => null,

    /*
    | Token prefix: empty. A prefix exists so that secret scanners can recognise a
    | leaked token by shape. gitleaks runs in pre-commit and in CI here (§10), and its
    | value for THIS credential is low -- the plaintext exists once, in the HTTP response
    | that issues it, and is never written to a file in this repository. Left empty
    | rather than invented, because a prefix is only useful if the scanner has a rule for
    | it, and adding both is Task 12's dependency-and-secret-scanning pass.
    */
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
