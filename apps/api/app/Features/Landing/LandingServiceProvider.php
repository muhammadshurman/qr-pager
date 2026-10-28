<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\CertificateIssuer;
use App\Domain\Landing\DnsVerifier;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * This feature's own container wiring -- a FEATURE PROVIDER, and deptrac is why.
 *
 * The obvious home for these two bindings was AppServiceProvider, and deptrac refuses it
 * correctly: `app/Providers/.*` is in the Platform layer and rule 2 of the three named
 * architecture guards is "the platform layer imports no feature modules".
 * NotificationsServiceProvider set the precedent at Task 4 and carries the full argument.
 *
 * ⚠ THE `aws` DRIVERS ARE ABSENT RATHER THAN STUBBED, and that is deliberate. A binding
 * that resolved to a class which did nothing would be the dead-control shape §4a records
 * four instances of -- it reads as capability and is not. Asking for one fails loudly and
 * names the task that owns it (SR-2: certificate infrastructure is Task 13's).
 */
final class LandingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DnsVerifier::class, static function (): DnsVerifier {
            return match ((string) config('landing.dns_driver')) {
                'local' => new FixtureDnsVerifier,
                'system' => new SystemDnsVerifier,
                default => throw new RuntimeException(
                    'landing.dns_driver is ['.config('landing.dns_driver').']; this application '
                    .'has `local` and `system`. The AWS driver is Task 13 (QRPAGER-14 SR-2).'
                ),
            };
        });

        $this->app->bind(CertificateIssuer::class, static function (): CertificateIssuer {
            return match ((string) config('landing.certificate_driver')) {
                'local' => new LocalCertificateIssuer,
                default => throw new RuntimeException(
                    'landing.certificate_driver is ['.config('landing.certificate_driver').']; this '
                    .'application has `local`. ACM issuance is Task 13 (QRPAGER-14 SR-2).'
                ),
            };
        });
    }
}
