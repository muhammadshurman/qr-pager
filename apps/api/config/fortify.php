<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | FORTIFY REGISTERS NO ROUTES OF ITS OWN
    |--------------------------------------------------------------------------
    |
    | THE MEASUREMENT THAT DECIDED THIS. Installing Fortify and Sanctum with their
    | defaults published TWENTY-SEVEN unversioned routes outside /v1, and
    | RouteSurfaceTest -- which asserts "exactly the two unversioned probes and nothing
    | else" -- failed with the whole list: /register, /login, /logout,
    | /forgot-password, /reset-password, /reset-password/{token}, /email/verify,
    | /two-factor-challenge, /user/password, /user/two-factor-secret-key,
    | /passkeys/login, /user/passkeys and the rest, plus Sanctum's /sanctum/csrf-cookie.
    |
    | Two things were wrong with that, and neither is cosmetic:
    |
    |   1. PROJECT_INSTRUCTIONS.md §6 requires every public route to be under /v1, and
    |      §11.3 requires public routes to be "explicitly marked and justified". A
    |      package publishing 27 endpoints nobody enumerated satisfies neither.
    |   2. /register is SELF-SERVICE ACCOUNT CREATION, which this project does not have
    |      at any tier. §4 › Data forbids customer accounts outright, and a staff account
    |      is created by a tenant owner's invite -- Task 6's wizard (S1). An open
    |      /register would let anyone mint a user row; it would then resolve no tenant and
    |      be refused, so it is not a tenant breach, but it is an unbounded writer into a
    |      global table with no RLS behind it.
    |
    | So `Fortify::$registersRoutes = false` in AppServiceProvider, and this task mounts
    | the endpoints it actually wants, by hand, under /v1/auth -- enumerated in
    | routes/v1.php, in the generated OpenAPI spec, and in RouteSurfaceTest's own named
    | sets. Fortify stays as the ACTIONS and the pipeline behind those routes, which is
    | what it is being used for.
    |
    | 'prefix' and 'domain' are left at their defaults deliberately: with route
    | registration off they are dead settings, and setting them would suggest the routes
    | exist somewhere.
    */

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    /*
    | No redirects: this is an API. Fortify's responses are JSON because every request
    | reaching them is an XHR, and a `home` path would be a redirect target that no
    | client follows. Left null rather than pointed at '/' so that a response which tries
    | to redirect fails loudly in a test instead of quietly 302-ing to a page that does
    | not exist until Task 10.
    */
    'home' => null,

    'middleware' => ['api'],

    'auth_middleware' => 'auth',

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Features — only the ones this task ships, each with its reason
    |--------------------------------------------------------------------------
    |
    | ENABLED:
    |
    |   twoFactorAuthentication  Task 5's scope names 2FA. `confirm: true` so that a
    |                            secret is not live until the user has proven they can
    |                            read a code from it -- an unconfirmed secret that already
    |                            gates login locks people out of their own account, which
    |                            is QRPAGER-12 §10.6(b)'s failure case.
    |   passkeys                 Task 5's scope names WebAuthn passkeys, and Fortify
    |                            v1.41 ships them through laravel/passkeys.
    |   updatePasswords          A staff member rotating their own password is the one
    |                            credential write this task must support: §2.7 grants
    |                            UPDATE on users.password and R-006 calls an unaudited
    |                            write of it "a direct authentication bypass", so the
    |                            supported path has to exist rather than being improvised.
    |
    | DISABLED, each deliberately and each with an owner rather than a TODO:
    |
    |   registration             No self-service accounts, ever. See the route comment
    |                            above. Staff accounts come from Task 6's invite wizard.
    |   resetPasswords           Needs a delivered email and a template. ADR-002 puts mail
    |                            behind SES/Mailpit and Task 6's gate is the first that
    |                            requires "a staff invite is actually delivered, asserted
    |                            against the local mail catcher". Enabling it here would
    |                            publish an endpoint whose delivery nothing asserts --
    |                            which is the shape R-047 and R-077 are about. Task 6.
    |   emailVerification        Same dependency, same owner. And a staff member who was
    |                            invited by their own tenant owner has already been
    |                            vouched for by someone with authority in that tenant,
    |                            which is a stronger signal than a click-through.
    |   updateProfileInformation Task 6's staff management, which owns the screen.
    |
    | A disabled feature is not a missing one: `password_reset_tokens` is already in the
    | data contract (§2.4) with its grants, so Task 6 adds a route and a mail template
    | rather than a migration.
    */
    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
        Features::passkeys(),
        Features::updatePasswords(),
    ],

];
