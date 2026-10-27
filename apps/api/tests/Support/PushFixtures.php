<?php

declare(strict_types=1);

namespace Tests\Support;

use Minishlink\WebPush\VAPID;

/**
 * Keys for the push tests, GENERATED AT RUNTIME, never committed.
 *
 * THE RULE THIS FILE EXISTS TO OBEY: no VAPID key reaches the repository, not even a test
 * one. A committed "test" key is a committed key -- it is indistinguishable from a real one
 * in a diff and in a secret scan, and the next person who needs a working local setup
 * copies it into their .env. So config/webpush.php ships three empty names, .env.example
 * ships the same three names with no values, and every test that needs a keypair makes one
 * here.
 *
 * MADE ONCE PER PROCESS AND REUSED. VAPID::createVapidKeys() generates a P-256 keypair,
 * which is not free, and several tests need a pair that merely works rather than a fresh
 * one. A test that cares about rotation makes its own.
 *
 * THE SUBSCRIBER KEYPAIR IS REAL TOO, and it has to be: the encrypter performs a real ECDH
 * agreement against the p256dh it is given, so a made-up 65-byte string fails with "Unable
 * to compute the agreement key" rather than producing an unencrypted payload. Measured
 * while writing these tests -- the first version used random bytes and every send raised.
 */
final class PushFixtures
{
    /** @var array{subject: string, publicKey: string, privateKey: string}|null */
    private static ?array $vapid = null;

    /**
     * @return array{subject: string, publicKey: string, privateKey: string}
     */
    public static function vapid(): array
    {
        if (self::$vapid === null) {
            $keys = VAPID::createVapidKeys();

            self::$vapid = [
                'subject' => 'mailto:ops@qrpager.test',
                'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey'],
            ];
        }

        return self::$vapid;
    }

    /** Put a generated pair into config, as a deployment's environment would. */
    public static function configureVapid(): void
    {
        $vapid = self::vapid();

        config([
            'webpush.vapid.subject' => $vapid['subject'],
            'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'],
        ]);
    }

    /**
     * A browser's subscription: a real P-256 public point and a real 16-byte auth secret.
     *
     * @return array{endpoint: string, p256dh: string, auth: string}
     */
    public static function subscription(?string $endpoint = null): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new \RuntimeException('openssl could not generate a P-256 key for the test subscription.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('openssl returned no EC coordinates for the test subscription.');
        }

        // Uncompressed point: 0x04 then X and Y, each left-padded to 32 bytes. The padding
        // matters -- openssl trims a leading zero byte, and an unpadded 64-byte-minus-one
        // point is rejected by the encrypter.
        $point = "\x04"
            .str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return [
            'endpoint' => $endpoint ?? 'https://push.example.test/'.bin2hex(random_bytes(8)),
            'p256dh' => self::base64url($point),
            'auth' => self::base64url(random_bytes(16)),
        ];
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
