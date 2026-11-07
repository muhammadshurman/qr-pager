<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use App\Domain\Http\ProblemType;
use App\Models\User;
use App\Platform\Auth\TwoFactorSecrets;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemDocument;
use App\Platform\Http\ValidationProblemDocument;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/*
 * INTERNAL NOTES -- not a docblock, because a class docblock here is PUBLISHED (R-052).
 *
 * WHY THESE ARE THIS PROJECT'S CONTROLLERS AND NOT FORTIFY'S. Fortify ships its own
 * TwoFactorAuthenticationController, TwoFactorQrCodeController, TwoFactorSecretKeyController
 * and RecoveryCodeController, and Fortify::$registersRoutes = false means none of them is
 * mounted. They are not used here for two reasons, both of them about this project's own
 * rules rather than about their quality:
 *
 *   - they answer with redirects and view responses for a browser flow, and every /v1
 *     response in this project is JSON with a documented shape;
 *   - and their responses carry no Scramble attributes, so the published contract would
 *     be whatever Scramble inferred -- which §6's "every endpoint added is in the generated
 *     OpenAPI spec" would then be satisfied by in name only.
 *
 * What IS used is Fortify's ACTIONS -- Enable, Confirm, Disable -- and its
 * TwoFactorAuthenticationProvider. That is the whole of the cryptography and the recovery
 * code handling, which is the part worth taking from a maintained package.
 *
 * CONFIRMATION IS REQUIRED, which config/fortify.php sets with `confirm: true`. An
 * unconfirmed secret that already gates login locks people out of their own account, which
 * is QRPAGER-12 §10.6(b)'s failure case: enabling and PROVING you can read a code from the
 * secret are two steps, and only the second one arms it.
 */

/**
 * Two-factor authentication for a staff account.
 */
final class TwoFactorController
{
    public function __construct(private readonly TwoFactorSecrets $secrets) {}

    /**
     * Begin enrolment.
     *
     * Returns the shared secret and a QR code to scan. Two-factor is not in force until it
     * is confirmed with a code from the authenticator.
     */
    #[DocResponse(200, 'The secret, an otpauth URL to render as a QR code, and the recovery codes.')]
    #[DocResponse(409, 'Two-factor is already in force. Disable it before enrolling again.', type: ProblemDocument::class)]
    #[DocResponse(500, 'The secret could not be read back.', type: ProblemDocument::class)]
    #[DocResponse(401, 'No session.', type: ProblemDocument::class)]
    public function enable(
        Request $request,
        EnableTwoFactorAuthentication $enable,
        TwoFactorAuthenticationProvider $provider,
    ): Response {
        $user = $this->user($request);

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        /*
         * A CONFIRMED FACTOR IS NOT RE-KEYED. Refused before anything is written.
         *
         * `$enable(force: true)` below overwrites whatever secret is already stored, and
         * `force` is there for a real reason: the app role holds no SELECT on
         * `two_factor_secret` (`§2.7`), so Fortify's own "is one already set?" test reads a
         * null it is not allowed to see and would proceed regardless. Making that explicit
         * was right; leaving it unconditional was not.
         *
         * The consequence measured on the open version: a caller with a session -- or,
         * before `RefuseDeviceToken`, a `pager:read` device token presented alongside a live
         * session cookie and its CSRF token, which is what a browser-based kitchen screen
         * holds -- called `enable` on an account whose factor was already CONFIRMED, got a
         * different secret back, and `two_factor_confirmed_at` stayed set. (A bearer-ONLY
         * caller was refused 419 by CSRF before reaching this method; the `§11` review of
         * the fix diff corrected that over-claim.) The legitimate authenticator stops working
         * and the secret in force is the caller's. That is a LOCKOUT, not a disclosure, and
         * it is silent.
         *
         * Changing an authenticator is still expressible: disable, then enable.
         *
         * ⚠ AND THE REASON THIS DOCBLOCK GAVE FOR THAT BEING SAFER WAS FALSE. It said "the
         * first of them is visible to the account's owner, which re-keying in place is not".
         * It is not visible to anyone: the `§11` review of the fix diff measured
         * `select count(*) from audit_log where action like 'two_factor%'` -> **0** after a
         * full enrol/confirm/disable/re-enrol cycle, and the only audited actions in the
         * whole application are the device-token and membership ones. There is no
         * notification either.
         *
         * So what this 409 actually buys is narrower than the first version claimed: it stops
         * the SILENT re-key -- a secret replaced under a working authenticator, which is a
         * lockout the owner cannot attribute -- and it does not stop a caller who already
         * holds a session from walking around it in one extra request (`disable`, then
         * `enable`). That walk-around is `R-104`, which now also carries the missing audit
         * rows, and it needs a re-authentication rule this task is not the place to decide.
         *
         * NOT CONFIRMED is deliberately still allowed to re-enrol. Enrolment is two steps
         * and the secret exists between them, so a caller who abandoned the first step --
         * closed the tab, lost the QR code -- has to be able to start again. Refusing that
         * would strand an account with a pending secret it can neither use nor replace.
         *
         * Read through the function rather than off the model, for the same grant reason as
         * the read-back below.
         */
        $existing = $this->secrets->for((string) $user->getKey());

        if ($existing !== null && $existing['confirmed']) {
            return (new Problem(ProblemType::Conflict, $request->getPathInfo()))->toResponse();
        }

        $enable($user, force: true);

        // READ BACK THROUGH THE FUNCTION, not off the model. The app role holds no SELECT
        // on either 2FA column (§2.7), so Fortify's own twoFactorQrCodeSvg() and
        // recoveryCodes() -- which read $this->two_factor_secret -- decrypt a null and
        // throw. TwoFactorSecrets and the migration that creates
        // public.user_two_factor(uuid) carry the full argument and the two rejected
        // alternatives.
        $state = $this->secrets->for((string) $user->getKey());

        if ($state === null || $state['secret'] === null) {
            // The write succeeded and the read came back empty, which means the grant or
            // the function is wrong rather than the request. Reported, and answered as a
            // server fault because it is one.
            report(new \RuntimeException('two-factor enrolment wrote a secret that could not be read back'));

            return (new Problem(ProblemType::ServerError, $request->getPathInfo()))->toResponse();
        }

        return response()->json([
            'two_factor' => [
                'secret' => $state['secret'],
                // The otpauth:// URL rather than Fortify's SVG, for the same reason: the SVG
                // helper reads the column off the model. A client renders the QR from this,
                // and the harness page does not need one at all.
                'qr_code_url' => $provider->qrCodeUrl(
                    (string) config('app.name'),
                    (string) $user->getAttribute('email'),
                    $state['secret'],
                ),
                'recovery_codes' => $state['recovery_codes'],
                'confirmed' => false,
            ],
        ]);
    }

    /**
     * Confirm enrolment with a code from the authenticator.
     *
     * Two-factor is in force only after this succeeds.
     */
    #[DocResponse(204, 'Confirmed.')]
    #[DocResponse(422, 'That code was not accepted.', type: ValidationProblemDocument::class)]
    #[DocResponse(401, 'No session.', type: ProblemDocument::class)]
    public function confirm(
        TwoFactorCodeRequest $request,
        TwoFactorAuthenticationProvider $provider,
    ): Response {
        $user = $this->user($request);

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        // NOT Fortify's ConfirmTwoFactorAuthentication action, for the same reason enable()
        // does not use its SVG helper: that action reads the secret off the model, and the
        // app role cannot select it. The verification itself is still Fortify's provider --
        // none of the cryptography is reimplemented here.
        $secret = $this->secrets->secret((string) $user->getKey());

        if ($secret === null || ! $provider->verify($secret, (string) $request->string('code'))) {
            return (new Problem(ProblemType::Validation, $request->getPathInfo()))->toResponse();
        }

        // The WRITE is granted (§2.7 gives the app role UPDATE on all three 2FA columns and
        // SELECT on none of the secrets), so this is an ordinary update.
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return response()->json(null, 204);
    }

    /**
     * Turn two-factor off.
     *
     * The secret and the recovery codes are discarded.
     */
    #[DocResponse(204, 'Disabled.')]
    #[DocResponse(401, 'No session.', type: ProblemDocument::class)]
    public function disable(Request $request, DisableTwoFactorAuthentication $disable): Response
    {
        $user = $this->user($request);

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        $disable($user);

        return response()->json(null, 204);
    }

    /**
     * Complete a sign-in that is waiting for a second factor.
     *
     * Accepts either a code from the authenticator or one unused recovery code. A recovery
     * code works once.
     */
    #[DocResponse(200, 'Signed in.')]
    #[DocResponse(422, 'That code was not accepted.', type: ValidationProblemDocument::class)]
    #[DocResponse(401, 'There is no sign-in waiting for a second factor.', type: ProblemDocument::class)]
    public function challenge(
        TwoFactorChallengeRequest $request,
        TwoFactorAuthenticationProvider $provider,
    ): Response {
        // THE PENDING LOGIN IS IN THE SESSION, put there by AuthController::login() when
        // the account has a CONFIRMED second factor. Nothing about the user is taken from
        // the request: the id comes from the server-side session, so a caller cannot
        // nominate whose challenge they are completing.
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_string($pending)) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        $user = User::query()->find($pending);

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        $code = (string) $request->string('code');
        $recovery = (string) $request->string('recovery_code');

        $accepted = false;

        $state = $this->secrets->for($pending);

        if ($code !== '' && ($state['secret'] ?? null) !== null) {
            $accepted = $provider->verify((string) $state['secret'], $code);
        } elseif ($recovery !== '') {
            $codes = $state['recovery_codes'] ?? [];

            if (in_array($recovery, $codes, true)) {
                // SINGLE USE, and it does NOT disable two-factor -- §10.6(b)'s clause. The
                // used code is replaced with a fresh one rather than removed, so the set
                // never shrinks and a user cannot run out by using them.
                $replacement = Str::random(10).'-'.Str::random(10);

                $remaining = array_map(
                    static fn (string $existing): string => $existing === $recovery ? $replacement : $existing,
                    $codes,
                );

                $user->forceFill([
                    'two_factor_recovery_codes' => Fortify::currentEncrypter()
                        ->encrypt(json_encode($remaining, JSON_THROW_ON_ERROR)),
                ])->save();

                $accepted = true;
            }
        }

        if (! $accepted) {
            return (new Problem(ProblemType::Validation, $request->getPathInfo()))->toResponse();
        }

        $request->session()->forget(self::PENDING_KEY);

        Auth::guard('web')->login($user);

        // SESSION FIXATION, on the path that is easiest to miss: completing a challenge is
        // the moment the session gains authority, so the id has to move here exactly as it
        // does on an ordinary login.
        $request->session()->regenerate();

        return response()->json(['user' => ['id' => (string) $user->getKey()]]);
    }

    /** Where a login waiting for a second factor parks the user id. */
    public const PENDING_KEY = 'auth.two_factor_pending';

    private function user(Request $request): ?object
    {
        return $request->user();
    }
}
