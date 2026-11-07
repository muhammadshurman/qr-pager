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
        $this->app->bind(DnsVerifier::class, function (): DnsVerifier {
            return match ((string) config('landing.dns_driver')) {
                'local' => $this->fixtureDriver('landing.dns_driver', new FixtureDnsVerifier),
                'system' => new SystemDnsVerifier,
                default => throw new RuntimeException(
                    'landing.dns_driver is ['.config('landing.dns_driver').']; this application '
                    .'has `local` and `system`. The AWS driver is Task 13 (QRPAGER-14 SR-2).'
                ),
            };
        });

        $this->app->bind(CertificateIssuer::class, function (): CertificateIssuer {
            return match ((string) config('landing.certificate_driver')) {
                'local' => $this->fixtureDriver('landing.certificate_driver', new LocalCertificateIssuer),
                default => throw new RuntimeException(
                    'landing.certificate_driver is ['.config('landing.certificate_driver').']; this '
                    .'application has `local`. ACM issuance is Task 13 (QRPAGER-14 SR-2).'
                ),
            };
        });
    }

    /**
     * A fixture driver, or a refusal if this is not a developer's machine.
     *
     * ⚠ BOTH DRIVERS DEFAULT TO `local`, AND NOTHING STOPPED THAT REACHING PRODUCTION.
     * `FixtureDnsVerifier` reads a zone out of `storage/app/landing/dns.json` -- a file
     * on the application's own writable disk -- and `LocalCertificateIssuer` fabricates
     * an identifier on request and calls it issued. That identifier is what satisfies
     * `tenant_domains_active_is_earned`, the CHECK that SR-2 calls the falsifiable half.
     *
     * The all-default case failed closed only by accident (nothing writes the zone file
     * outside tests, so verification always failed), but `LANDING_DNS_DRIVER=system`
     * with the certificate driver left alone gave REAL DNS verification followed by a
     * FABRICATED certificate -- a hostname the product treats as TLS-enabled with
     * nothing behind it. Found by both §11 reviews on 2026-10-28.
     *
     * This is the pattern Task 2 already set for the auth stub: refuse outright outside
     * local and testing, rather than trusting an environment variable to be set.
     *
     * @template TDriver of object
     *
     * @param  TDriver  $driver
     * @return TDriver
     */
    private function fixtureDriver(string $setting, object $driver): object
    {
        $environment = (string) $this->app->environment();

        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new RuntimeException(
                $setting.' is [local], which is a FIXTURE driver: it verifies DNS from a '
                .'file on this disk and fabricates certificate identifiers. It is refused '
                .'in the ['.$environment.'] environment. Set the production driver '
                .'(QRPAGER-14 decision D7, SR-2 -- the AWS drivers are Task 13).'
            );
        }

        return $driver;
    }
}
