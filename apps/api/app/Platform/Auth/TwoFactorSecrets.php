<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * The second-factor material, read through `public.user_two_factor(uuid)`.
 *
 * ================================================================================
 * WHY THE MODEL IS NOT USED, which is the whole of this class's reason to exist
 * ================================================================================
 *
 * `qrpager_app` holds no `SELECT` on `two_factor_secret` or `two_factor_recovery_codes`
 * (`§2.7`), so `App\Models\User::READABLE` does not contain them and Fortify's own
 * `twoFactorQrCodeSvg()` / `recoveryCodes()` — which read `$this->two_factor_secret` —
 * decrypt a null. The migration
 * `2026_10_28_130000_create_two_factor_reader` carries the full argument, including the two
 * alternatives rejected.
 *
 * DECRYPTED WITH FORTIFY'S OWN ENCRYPTER, not with the `decrypt()` helper. Fortify writes
 * these columns with `Fortify::currentEncrypter()->encrypt(...)`, and the two happen to be
 * the same object today — they stop being the same the moment anybody calls
 * `Fortify::encryptUsing()`, and a credential that silently fails to decrypt is a lockout.
 *
 * AND NO CAST ON EITHER COLUMN. The model declared `'encrypted'` for both in its first
 * draft, which encrypted a second time on write and handed Fortify ciphertext to decrypt on
 * read: `DecryptException: The payload is invalid.`, measured through the real endpoint.
 * Fortify owns the encryption of these two columns end to end.
 */
final class TwoFactorSecrets
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * The user's second-factor state, or null when there is no such user.
     *
     * @return array{secret: ?string, recovery_codes: list<string>, confirmed: bool}|null
     */
    public function for(string $userId): ?array
    {
        if (! Str::isUuid($userId)) {
            return null;
        }

        $row = $this->connection->selectOne(
            'select two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at
               from public.user_two_factor(?)',
            [$userId],
        );

        if (! is_object($row)) {
            return null;
        }

        return [
            'secret' => $this->decrypt($row->two_factor_secret ?? null),
            'recovery_codes' => $this->codes($row->two_factor_recovery_codes ?? null),
            'confirmed' => ($row->two_factor_confirmed_at ?? null) !== null,
        ];
    }

    /** The plaintext secret, or null when there is none or it cannot be read. */
    public function secret(string $userId): ?string
    {
        return $this->for($userId)['secret'] ?? null;
    }

    /** @return list<string> */
    public function recoveryCodes(string $userId): array
    {
        return $this->for($userId)['recovery_codes'] ?? [];
    }

    private function decrypt(mixed $ciphertext): ?string
    {
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        try {
            return (string) Fortify::currentEncrypter()->decrypt($ciphertext);
        } catch (\Throwable) {
            // A column that cannot be decrypted is reported as absent rather than thrown:
            // the caller's honest answer is "there is no usable second factor", and a 500
            // here would lock the account rather than fall back to the password path.
            return null;
        }
    }

    /** @return list<string> */
    private function codes(mixed $ciphertext): array
    {
        $plain = $this->decrypt($ciphertext);

        if ($plain === null) {
            return [];
        }

        $decoded = json_decode($plain, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
