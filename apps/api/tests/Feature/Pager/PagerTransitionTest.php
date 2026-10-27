<?php

declare(strict_types=1);

use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerTransitionDenied;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * §3 gate, clause 3: "invalid state transitions rejected".
 *
 * EVERY BRANCH OF THE STATE MACHINE, which §11's QA checklist asks for by name, and
 * driven FROM the enum rather than from a list written beside it: the matrix below is
 * built by iterating PagerStatus::cases() against itself, so a case added to the enum
 * without a decision about it fails here rather than passing silently.
 */

/**
 * A session created directly in $status, so a transition FROM it can be attempted.
 *
 * It inserts rather than going through PagerSessions, deliberately: a test that could
 * only reach the states the service already produces could never attempt a transition out
 * of a terminal one, which is half of what this file is for. The owner connection and a
 * tenant scope, which is the §2.10 pattern -- this is test setup placing a precondition,
 * not an application path.
 *
 * Self-contained rather than calling TestCase::makeSession(), which is protected and so
 * unreachable from a global function in a Pest file.
 */
function sessionIn(string $tenantId, PagerStatus $status): string
{
    $id = (string) Str::uuid7();

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->insert(
        'insert into pager_sessions (id, tenant_id, token_hash, order_reference, status, expires_at)
         values (?, ?, decode(?, \'hex\'), ?, ?::pager_status, now() + interval \'2 hours\')',
        [$id, $tenantId, bin2hex(random_bytes(32)), 'A-'.random_int(100, 999), $status->value],
    ), 'pgsql_owner');

    return $id;
}

it('agrees with the enum about every from/to pair — 5 statuses x 3 operations', function (): void {
    $tenant = $this->makeTenant();
    $sessions = app(PagerSessions::class);

    // The four operations the service exposes that change status, each with the status it
    // targets. Renotify is absent because it changes none; it has its own cases below.
    $operations = [
        PagerStatus::Ready->value => fn (string $s) => $sessions->markReady($tenant, $s),
        PagerStatus::Completed->value => fn (string $s) => $sessions->complete($tenant, $s),
        PagerStatus::Cancelled->value => fn (string $s) => $sessions->cancel($tenant, $s),
    ];

    $checked = 0;

    foreach (PagerStatus::cases() as $from) {
        foreach ($operations as $toValue => $operation) {
            $to = PagerStatus::from($toValue);
            $session = sessionIn($tenant, $from);
            $allowed = $from->canTransitionTo($to);
            $idempotent = $from === $to;
            $checked++;

            if ($idempotent) {
                // Asking for the state it is already in is the idempotent no-op, not a
                // refusal -- covered in depth by PagerIdempotencyTest.
                expect($operation($session)->changed)->toBeFalse(
                    "{$from->value} -> {$to->value} should have been the idempotent no-op",
                );

                continue;
            }

            if ($allowed) {
                expect($operation($session)->status)->toBe(
                    $to,
                    "the enum allows {$from->value} -> {$to->value} and the service refused it",
                );

                continue;
            }

            expect(fn () => $operation($session))->toThrow(
                PagerTransitionDenied::class,
                null,
                "the enum forbids {$from->value} -> {$to->value} and the service allowed it",
            );

            // And nothing was written for the refused attempt.
            $status = withTenant($tenant, fn () => DB::table('pager_sessions')->where('id', $session)->value('status'));
            $outbox = withTenant($tenant, fn (): int => DB::table('outbox')->where('pager_session_id', $session)->count());

            expect($status)->toBe($from->value)->and($outbox)->toBe(0);
        }
    }

    // 5 statuses x 3 status-changing operations. Pinned so that a case removed from the
    // enum cannot quietly shrink this test to nothing.
    expect($checked)->toBe(15);
});

it('lets nothing out of a terminal status, which is what isTerminal means', function (): void {
    foreach ([PagerStatus::Completed, PagerStatus::Cancelled, PagerStatus::Expired] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue()
            ->and($terminal->allows())->toBe([])
            ->and($terminal->isLive())->toBeFalse();
    }

    foreach ([PagerStatus::Waiting, PagerStatus::Ready] as $live) {
        expect($live->isTerminal())->toBeFalse()
            ->and($live->isLive())->toBeTrue()
            ->and($live->allows())->not->toBe([]);
    }
});

it('allows waiting straight to completed, which is the one judgement call in the table', function (): void {
    // Recorded as a decision in §2.3 and QRPAGER-10 §6.2 rather than left implicit: a
    // customer who collects at the counter without waiting to be paged is a real path,
    // and refusing it would leave staff only `cancel`, which records something that did
    // not happen. If this is ever reversed, this test is the one to change.
    expect(PagerStatus::Waiting->canTransitionTo(PagerStatus::Completed))->toBeTrue();

    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    $state = app(PagerSessions::class)->complete($tenant, $session);

    expect($state->status)->toBe(PagerStatus::Completed)->and($state->changed)->toBeTrue();
});

it('lets both live statuses expire, which the partial index and the worker policy both assume', function (): void {
    // §2.5's partial index is ON (expires_at) WHERE status IN ('waiting','ready') and the
    // worker's narrowed UPDATE policy uses the same pair, so the enum has to agree with
    // both or the expiry job has rows it can see and may not move.
    expect(PagerStatus::Waiting->canTransitionTo(PagerStatus::Expired))->toBeTrue()
        ->and(PagerStatus::Ready->canTransitionTo(PagerStatus::Expired))->toBeTrue();

    $live = array_values(array_filter(
        PagerStatus::cases(),
        static fn (PagerStatus $s): bool => $s->canTransitionTo(PagerStatus::Expired),
    ));

    expect($live)->toBe([PagerStatus::Waiting, PagerStatus::Ready]);
});

it('refuses a renotify from every status except ready', function (): void {
    $tenant = $this->makeTenant();
    $sessions = app(PagerSessions::class);

    foreach (PagerStatus::cases() as $status) {
        $session = sessionIn($tenant, $status);

        if ($status === PagerStatus::Ready) {
            expect($sessions->renotify($tenant, $session)->renotifyCount)->toBe(1);

            continue;
        }

        expect(fn () => $sessions->renotify($tenant, $session))
            ->toThrow(PagerTransitionDenied::class, null, "renotify was allowed from {$status->value}");
    }
});
