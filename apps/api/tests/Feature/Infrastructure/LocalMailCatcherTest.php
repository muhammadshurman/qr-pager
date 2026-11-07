<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE LOCAL MAIL CATCHER IS PART OF THE STACK, AND THIS FAILS IF IT IS ABSENT
 * ================================================================================
 *
 * `R-125`: ADR-002's Decision table has said "Mailpit in `compose.yaml`" since 2026-10-25, its
 * Consequences say "`compose.yaml` gains MinIO and Mailpit", and `PROJECT_INSTRUCTIONS.md §1`
 * (rank 2) points at that ADR as the reason it need not say more. **Neither service was ever
 * there.** Measured 2026-10-28: `compose.yaml`'s services were `postgres`, `redis` and
 * `frankenphp`, and `grep -ci minio compose.yaml` returned 0.
 *
 * It blocked nothing for five tasks, because nothing sent mail or stored an object. Then it
 * made a GATE CLAUSE UNPROVABLE: `§3` row 6's fourth clause is "a staff invite is actually
 * delivered, **asserted against the local mail catcher**", and the instrument it names did not
 * exist — so Task 6 could not have met that clause on this machine even with an invite to send.
 *
 * THIS FILE IS THE SAME SHAPE AS `RealDatabaseTest`, deliberately. That file asserts the suite
 * runs against the real composed Postgres and never SQLite; this one asserts the suite runs
 * against the real composed Mailpit and never `array` or `log`. ADR-002's own words: "tests run
 * against MinIO and Mailpit with no code difference — the same rule the project already applies
 * to Postgres and Redis: **real services, never mocks, for behaviour that belongs to the
 * service**." Delivery belongs to the service.
 *
 * ⚠ IT DOES NOT SKIP WHEN THE SERVICE IS DOWN. A skipping test would restore exactly the
 * condition `R-125` is about — a clause with no instrument and nothing going red to say so.
 * `docker compose up -d` starts Mailpit, and a fresh clone needs no extra step.
 *
 * ⚠ AND MinIO IS STILL MISSING, which is `R-125`'s other half and is NOT closed by this file.
 * The official images answer `denied: authentication required` from this environment, and
 * `league/flysystem-aws-s3-v3` is not a dependency at all — two decisions that belong to
 * Muhammad, recorded in `compose.yaml` beside where the service would go.
 */

/** Mailpit's HTTP API, which is the half that can prove a message EXISTS. */
function catcher(string $path = ''): string
{
    // From config rather than hard-coded, so a clone that moves the port moves this with it.
    $base = rtrim((string) env('MAIL_CATCHER_URL', 'http://127.0.0.1:8025'), '/');

    return $base.$path;
}

it('reaches the local mail catcher, and FAILS rather than skipping when it is absent', function (): void {
    // The §8 question this file exists for: is the instrument there at all? A connection error
    // here is the correct outcome when Mailpit is down -- it is the red that was missing.
    $response = Http::timeout(5)->get(catcher('/readyz'));

    expect($response->status())->toBe(200, 'Mailpit is not answering: run `docker compose up -d mailpit`');

    $info = Http::timeout(5)->get(catcher('/api/v1/info'))->json();

    expect($info)->toBeArray()
        ->and($info['Version'] ?? null)->toBeString();
});

it('uses the REAL transport, not array and not log', function (): void {
    // RealDatabaseTest's assertion, one service over. `array` was the value in phpunit.xml
    // until 2026-10-28, which is what made ADR-002's "tests run against Mailpit" false.
    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('127.0.0.1')
        ->and((int) config('mail.mailers.smtp.port'))->toBe(1025);
});

it('delivers a real message and reads it back OUT of the catcher', function (): void {
    // THE CLAUSE, in the form §3 row 6 asks for and the ticket spells out: "Mail::fake() proves
    // a job was dispatched; it does not prove an email exists." So nothing is faked here -- the
    // message goes over SMTP to Mailpit and is then fetched back over its HTTP API.
    //
    // A unique marker per run, because Mailpit's store is shared across runs and is not reset
    // between tests the way the database is. The message is deleted at the end, so the store
    // does not grow monotonically -- which is `R-016`'s lesson applied to a service instead of
    // a table.
    $marker = 'qrpager-probe-'.Str::lower(Str::random(16));
    $recipient = $marker.'@example.test';

    Mail::raw("This is the local mail catcher probe. Marker: {$marker}", function ($message) use ($recipient, $marker): void {
        $message->to($recipient)->subject("Mail catcher probe {$marker}");
    });

    // Mailpit accepts over SMTP and indexes asynchronously, so the read is polled rather than
    // immediate. Bounded: a fixed number of short attempts, so a down service fails in about a
    // second instead of hanging the suite.
    $found = null;

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $search = Http::timeout(5)->get(catcher('/api/v1/search'), ['query' => $marker])->json();

        if (($search['messages_count'] ?? 0) > 0) {
            $found = $search['messages'][0];

            break;
        }

        usleep(100_000);
    }

    expect($found)->not->toBeNull("the message never arrived in the catcher (marker {$marker})");

    try {
        // The full message, which is where the body lives -- the search result carries headers.
        $message = Http::timeout(5)->get(catcher('/api/v1/message/'.$found['ID']))->json();

        expect($message['Subject'] ?? null)->toBe("Mail catcher probe {$marker}")
            ->and(collect($message['To'] ?? [])->pluck('Address')->all())->toContain($recipient)
            ->and($message['Text'] ?? '')->toContain($marker)
            // The From comes from config, so this also proves the application's own mail
            // identity reached the wire rather than a default the transport invented.
            ->and($message['From']['Address'] ?? null)->toBe(config('mail.from.address'));
    } finally {
        // Clean up whatever was found, even if an assertion above failed.
        Http::timeout(5)->delete(catcher('/api/v1/messages'), ['IDs' => [$found['ID']]]);
    }
});

it('leaves the catcher empty of its own probe afterwards', function (): void {
    // The other half of not growing monotonically: the delete above actually deletes. Asserted
    // separately so a failure names which half broke.
    $marker = 'qrpager-cleanup-'.Str::lower(Str::random(12));

    Mail::raw('cleanup probe', function ($message) use ($marker): void {
        $message->to($marker.'@example.test')->subject("Cleanup probe {$marker}");
    });

    $id = null;

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $search = Http::timeout(5)->get(catcher('/api/v1/search'), ['query' => $marker])->json();

        if (($search['messages_count'] ?? 0) > 0) {
            $id = $search['messages'][0]['ID'];

            break;
        }

        usleep(100_000);
    }

    expect($id)->not->toBeNull();

    Http::timeout(5)->delete(catcher('/api/v1/messages'), ['IDs' => [$id]]);

    $after = Http::timeout(5)->get(catcher('/api/v1/search'), ['query' => $marker])->json();

    expect($after['messages_count'] ?? -1)->toBe(0, 'the probe message was not deleted');
});

it('is declared in compose.yaml, bound to 127.0.0.1 only', function (): void {
    // ADR-002 requires both local services to "bind to 127.0.0.1 only, like Postgres and
    // Redis", and a service reachable on 0.0.0.0 on a developer's laptop is a mail relay on
    // whatever network they are on. Read from the file, because that is what a clone gets.
    $compose = (string) file_get_contents(__DIR__.'/../../../../../compose.yaml');

    expect($compose)->toContain('mailpit:')
        ->and($compose)->toContain('127.0.0.1:1025:1025')
        ->and($compose)->toContain('127.0.0.1:8025:8025');

    // And no unbound publish anywhere in the file, which would be the same mistake made for
    // some other service later.
    expect(preg_match('/^\s+- "\d+:\d+"/m', $compose))
        ->toBe(0, 'a port is published without a 127.0.0.1 bind');
});
