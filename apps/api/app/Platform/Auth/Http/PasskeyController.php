<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use App\Domain\Http\ProblemType;
use App\Models\User;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemDocument;
use App\Platform\Http\ValidationProblemDocument;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;
use Symfony\Component\HttpFoundation\Response;

/*
 * INTERNAL NOTES -- not a docblock, because a class docblock here is PUBLISHED (R-052).
 *
 * WHY THIS PROJECT'S CONTROLLER AND NOT laravel/passkeys'. Its own
 * PasskeyRegistrationController and PasskeyLoginController do exactly the right thing and
 * are not mounted, for the same reason Fortify's are not: Fortify::$registersRoutes = false,
 * their responses are built for a browser redirect flow, and they carry no Scramble
 * attributes -- so the published contract would be whatever Scramble inferred, which turns
 * §6's "every endpoint added is in the generated OpenAPI spec" into a formality.
 *
 * What IS used is the package's ACTIONS, which are the whole of the WebAuthn work:
 * GenerateRegistrationOptions, StorePasskey, GenerateVerificationOptions, VerifyPasskey --
 * over web-auth/webauthn-lib 5.3.9. None of the ceremony is reimplemented here.
 *
 * ⚠ THE CHALLENGE LIVES IN THE SESSION, which is why these routes are on the `auth-surface`
 * group and could not be on `api`: a WebAuthn ceremony is two round trips, and the options
 * issued by the first have to be remembered server-side to verify the second. A challenge
 * the client hands back to us is not a challenge.
 *
 * ⚠ AND THE CEREMONY CANNOT BE PROVEN WITHOUT A BROWSER, which is this task's one STOPPED
 * ON. `PLAN.md §3` row 5 requires "passkey registration + login proven end to end through
 * the browser against the harness page", and the credential ceremony lives in the browser
 * rather than in HTTP: there is no node, no Chromium and no Playwright on this machine, so
 * the half that needs a real authenticator is recorded in the handover rather than claimed.
 * What IS tested here is every server-side half -- options are issued and stored, a verify
 * with no stored challenge is refused, a malformed credential is refused, and the stored
 * record carries a public key and no secret.
 */

/**
 * Passkeys: registering one, and signing in with one.
 */
final class PasskeyController
{
    /** Where the registration challenge is parked. The package's own key. */
    public const REGISTRATION_KEY = 'passkey.registration_options';

    /** Where the sign-in challenge is parked. The package's own key. */
    public const VERIFICATION_KEY = 'passkey.verification_options';

    /**
     * Begin registering a passkey for the signed-in account.
     *
     * Pass the returned options to the browser's credential API.
     */
    #[DocResponse(200, 'WebAuthn creation options for the browser.')]
    #[DocResponse(401, 'No session.', type: ProblemDocument::class)]
    public function registrationOptions(Request $request, GenerateRegistrationOptions $generate): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        $options = $generate($user);

        // Stored server-side, because a challenge the client could choose is not a
        // challenge. The package's own key, so its StorePasskey action finds it.
        $request->session()->put(self::REGISTRATION_KEY, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /**
     * Finish registering a passkey.
     *
     * Send the credential the browser produced, with a name for the device.
     */
    #[DocResponse(201, 'The passkey that now exists.')]
    #[DocResponse(422, 'That credential was not accepted.', type: ValidationProblemDocument::class)]
    #[DocResponse(401, 'No session.', type: ProblemDocument::class)]
    public function register(PasskeyRegistrationRequest $request, StorePasskey $store): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        // The name is bounded at the COLUMN's length by this project's own
        // PasskeyRegistrationRequest, which narrows the vendor's `max:255` to `max:120`.
        // It is a validation RULE and not a check here, because a rule runs before the
        // vendor deserialises the credential -- a guard in this method was unreachable for
        // every payload a test can build, and its regression test passed with it deleted.
        // That class carries the full argument.
        try {
            // registrationOptions() PULLS the stored challenge -- single use, whatever the
            // outcome, which is what stops a failed attempt leaving a challenge to retry
            // against. It throws a ValidationException when there is none, and the
            // project's renderer maps that to a 422 problem document: a caller who posts a
            // credential of their own making with no ceremony behind it is refused by the
            // absence of a challenge rather than by a check in this method.
            $passkey = $store(
                $user,
                (string) $request->string('name'),
                $request->credential(),
                $request->registrationOptions(),
            );
        } catch (InvalidPasskeyException $refused) {
            // Reported so an operator sees WHY, and answered with a body that says nothing:
            // a browser that failed a ceremony cannot act on the reason, and the reason can
            // name the relying party and the challenge.
            report($refused);

            return (new Problem(ProblemType::Validation, $request->getPathInfo()))->toResponse();
        }

        return response()->json([
            'passkey' => [
                'id' => (string) $passkey->getKey(),
                'name' => (string) $passkey->getAttribute('name'),
            ],
        ], 201);
    }

    /**
     * Begin signing in with a passkey.
     *
     * Pass the returned options to the browser's credential API. No account is named, so
     * the response is the same whether or not any passkey exists.
     */
    #[DocResponse(200, 'WebAuthn request options for the browser.')]
    public function loginOptions(Request $request, GenerateVerificationOptions $generate): Response
    {
        $options = $generate();

        $request->session()->put(self::VERIFICATION_KEY, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /**
     * Finish signing in with a passkey.
     */
    #[DocResponse(200, 'Signed in.')]
    #[DocResponse(422, 'That credential was not accepted.', type: ValidationProblemDocument::class)]
    public function login(PasskeyVerificationRequest $request, VerifyPasskey $verify): Response
    {
        try {
            // verificationOptions() pulls the challenge, single use, and throws a
            // ValidationException when there is none -- same shape as registration.
            $passkey = $verify($request->credential(), $request->verificationOptions());
        } catch (InvalidPasskeyException $refused) {
            report($refused);

            return (new Problem(ProblemType::Validation, $request->getPathInfo()))->toResponse();
        }

        $user = $passkey->user;

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Validation, $request->getPathInfo()))->toResponse();
        }

        Auth::guard('web')->login($user);

        // Session fixation, on the third of the four paths that raise privilege. Login, the
        // tenant selection, the two-factor challenge and this one all move the id.
        $request->session()->regenerate();

        return response()->json(['user' => ['id' => (string) $user->getKey()]]);
    }
}
