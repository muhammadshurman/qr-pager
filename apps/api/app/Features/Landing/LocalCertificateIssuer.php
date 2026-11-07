<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\CertificateIssuer;
use Illuminate\Support\Facades\Storage;

/**
 * The LOCAL driver for the certificate port (decision D7, SR-2).
 *
 * ================================================================================
 * WHAT THIS IS HONESTLY NOT
 * ================================================================================
 *
 * It does not issue a certificate. There is no key, no CSR, no CA and no chain. It is a
 * recorded, re-readable ISSUANCE RECORD with a delay, and its whole purpose is to let
 * the state machine be driven end to end -- requested, pending, issued, activated --
 * where the real thing cannot run.
 *
 * SR-2 is the ruling that makes that the right scope: an ACM certificate existing in an
 * AWS account is Task 13's, and this task owns the application-side lifecycle. What the
 * suite proves with this driver is the falsifiable half -- that no domain reaches
 * `active` without a verified DNS challenge AND a recorded certificate -- which is a
 * property of this application's code and is true whichever driver is bound.
 *
 * THE DELAY IS WHY THERE IS A `certificate_pending` STATE AT ALL. §12.3 has a "still
 * issuing, {minutes} minutes so far" screen, and a driver that issued instantly would
 * make that state unreachable and its copy untestable. The default is 0 seconds so the
 * suite is not slow; the tests that need the pending state set it.
 */
final class LocalCertificateIssuer implements CertificateIssuer
{
    public const LEDGER = 'landing/certificates.json';

    public function request(string $hostname): void
    {
        $ledger = self::ledger();

        // Idempotent: asking twice does not restart the clock, which is what makes
        // `domains:advance` safe to re-run and what §6.6 means by "both are keyed per
        // domain and re-runnable".
        if (isset($ledger[$hostname])) {
            return;
        }

        $ledger[$hostname] = [
            'requested_at' => time(),
            'identifier' => 'local-cert-'.bin2hex(random_bytes(8)),
        ];

        self::write($ledger);
    }

    public function issued(string $hostname): ?string
    {
        $record = self::ledger()[$hostname] ?? null;

        if (! is_array($record)) {
            return null;
        }

        $delay = (int) config('landing.local_certificate_delay', 0);

        // Null is "not yet", never "failed" -- the port's contract. A domain that stays
        // in certificate_pending keeps the OLD domain serving, which is the fail-closed
        // direction (§6.6).
        if (time() - (int) $record['requested_at'] < $delay) {
            return null;
        }

        return is_string($record['identifier']) ? $record['identifier'] : null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function ledger(): array
    {
        $contents = Storage::disk('local')->get(self::LEDGER);

        if (! is_string($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true, 8, JSON_INVALID_UTF8_SUBSTITUTE);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $ledger */
    private static function write(array $ledger): void
    {
        Storage::disk('local')->put(self::LEDGER, (string) json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
