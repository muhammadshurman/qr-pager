<?php

declare(strict_types=1);

use App\Domain\Landing\DnsChallenge;
use App\Domain\Landing\DomainStatus;
use App\Domain\Landing\LandingPageStatus;
use App\Domain\Landing\LandingSchema;
use App\Features\Landing\SystemDnsVerifier;
use Illuminate\Support\Facades\Artisan;

/*
 * The DNS challenge, the production resolver driver, and the two state machines.
 *
 * ⚠ WHY THE COMPARISON IS TESTED SEPARATELY FROM THE DRIVER. A TXT lookup needs a name
 * somebody controls, and this suite controls none -- so the "a record exists and carries
 * the right value" branch of SystemDnsVerifier cannot be reached from here. Rather than
 * leave the production driver's matching untested, the comparison was lifted into
 * DnsChallenge, which both drivers now call. What is driver-specific is WHERE the values
 * come from; what is security-relevant is the comparison, and that is tested directly.
 */

it('names the record under the hostname, never on it', function (): void {
    // On the hostname itself, a tenant would have to put a TXT record on a name that
    // already serves their site -- which some DNS providers make awkward and which is
    // not necessary.
    expect(DnsChallenge::recordName('olive.example.test'))
        ->toBe('_qrpager-challenge.olive.example.test')
        ->and(DnsChallenge::value('abc123'))->toBe('qrpager-verification=abc123');
});

it('matches only the exact challenge value', function (): void {
    $token = bin2hex(random_bytes(16));
    $value = DnsChallenge::value($token);

    expect(DnsChallenge::matches([$value], $token))->toBeTrue()
        // Among other records, which is the real case: a domain has SPF, DKIM and more.
        ->and(DnsChallenge::matches(['v=spf1 -all', $value, 'other'], $token))->toBeTrue();

    foreach ([
        [],
        [''],
        ['qrpager-verification='],
        [$token],                                   // the bare token, no prefix
        [DnsChallenge::value($token).' '],          // trailing space
        [' '.DnsChallenge::value($token)],          // leading space
        [strtoupper(DnsChallenge::value($token))],  // case changed
        [substr($value, 0, -1)],                    // one character short
        [DnsChallenge::value(bin2hex(random_bytes(16)))], // someone else's challenge
        [null],
        [123],
    ] as $values) {
        expect(DnsChallenge::matches($values, $token))
            ->toBeFalse('matched '.json_encode($values));
    }
});

it('fails closed in the production driver when the name does not resolve', function (): void {
    // The driver's own branches, against a name that CANNOT exist: `.invalid` is reserved
    // by RFC 2606 precisely so that it never resolves. Whether the resolver answers
    // NXDOMAIN (an empty record set) or is absent entirely (dns_get_record returns false),
    // the answer this class must give is the same one -- false, with a reason -- so the
    // case is robust to the machine it runs on, which is what §6's fresh-clone clause is
    // about.
    $verifier = new SystemDnsVerifier;

    $hostname = 'qrpager-'.bin2hex(random_bytes(8)).'.invalid';

    expect($verifier->recordName($hostname))->toBe('_qrpager-challenge.'.$hostname)
        ->and($verifier->verify($hostname, bin2hex(random_bytes(16))))->toBeFalse()
        ->and($verifier->lastFailure())->toBeString();

    // lastFailure() is CLEARED at the start of each verify, so a later success does not
    // read back an earlier failure's reason.
    expect($verifier->lastFailure())->not->toBeNull();
    $verifier->verify($hostname, bin2hex(random_bytes(16)));
    expect($verifier->lastFailure())->not->toBeNull();
});

it('states every transition of the landing page state machine', function (): void {
    expect(LandingPageStatus::Draft->allowsMoveTo(LandingPageStatus::Published))->toBeTrue()
        // Re-publishing is the common case, which is why published -> published is legal
        // and the status is not modelled as a one-way door.
        ->and(LandingPageStatus::Published->allowsMoveTo(LandingPageStatus::Published))->toBeTrue()
        ->and(LandingPageStatus::Published->allowsMoveTo(LandingPageStatus::Draft))->toBeTrue()
        ->and(LandingPageStatus::Draft->allowsMoveTo(LandingPageStatus::Draft))->toBeFalse()
        ->and(LandingPageStatus::Published->isLive())->toBeTrue()
        ->and(LandingPageStatus::Draft->isLive())->toBeFalse()
        ->and(LandingPageStatus::values())->toBe(['draft', 'published']);
});

it('states every transition of the domain state machine, and the ones it forbids', function (): void {
    // The whole table, asserted as a table rather than one arrow at a time, so a
    // transition ADDED to the enum fails here instead of arriving unexamined.
    $expected = [
        'pending_dns' => ['dns_verified', 'failed'],
        'dns_verified' => ['certificate_pending', 'failed'],
        'certificate_pending' => ['active', 'failed'],
        'active' => ['redirecting'],
        'redirecting' => ['active'],
        'failed' => ['pending_dns'],
    ];

    $actual = [];

    foreach (DomainStatus::allows() as $from => $tos) {
        $actual[$from] = array_map(static fn (DomainStatus $s): string => $s->value, $tos);
    }

    expect($actual)->toBe($expected);

    // The two derived questions the service and the schema both ask.
    foreach ([DomainStatus::DnsVerified, DomainStatus::CertificatePending,
        DomainStatus::Active, DomainStatus::Redirecting] as $earned) {
        expect($earned->hasVerifiedDns())->toBeTrue($earned->value);
    }

    foreach ([DomainStatus::PendingDns, DomainStatus::Failed] as $unearned) {
        expect($unearned->hasVerifiedDns())->toBeFalse($unearned->value);
    }

    expect(DomainStatus::Active->isServing())->toBeTrue()
        ->and(DomainStatus::Redirecting->isServing())->toBeTrue()
        ->and(DomainStatus::CertificatePending->isServing())->toBeFalse();
});

it('writes the contract to a path, which is the exporter half nothing else drives', function (): void {
    // landing:schema-export --check is driven by LandingSchemaContractTest. The WRITE
    // path is the half that produces the committed artifact, and it was covered by
    // nothing -- a generator nobody runs in a test is a generator that can break between
    // the day it was written and the day somebody needs it.
    $scratch = tempnam(sys_get_temp_dir(), 'qrpager-schema-write-');
    unlink($scratch);
    $target = $scratch.'/nested/landing.json';

    try {
        expect(Artisan::call('landing:schema-export', ['--path' => $target]))->toBe(0)
            // It creates the directory it is told to write into.
            ->and(is_file($target))->toBeTrue()
            ->and(file_get_contents($target))->toBe(LandingSchema::encode());

        // And what it wrote passes its own check.
        expect(Artisan::call('landing:schema-export', ['--check' => true, '--path' => $target]))
            ->toBe(0);
    } finally {
        @unlink($target);
        @rmdir(dirname($target));
        @rmdir($scratch);
    }
});
