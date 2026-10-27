<?php

declare(strict_types=1);

use App\Domain\Pager\PagerDelivery;
use App\Domain\Pager\PagerEvent;
use App\Features\Notifications\PushMessage;
use App\Features\Notifications\WebPushDispatcher;
use Tests\Support\PushFixtures;
use Tests\Support\RecordingPushEdge;

/*
 * The library contract, measured rather than remembered.
 *
 * §8: "Do not claim a library API exists without verifying it in this session." These
 * cases ARE that verification, kept as tests rather than pasted into a ticket, so that a
 * `composer update` that changes minishlink/web-push's behaviour fails the gate instead of
 * changing what this product sends. Four of the five properties below were measured by
 * hand on 2026-10-27 before a line of the dispatcher was written; this is where they live
 * now.
 *
 * EVERY CASE DRIVES THE REAL LIBRARY -- the real encrypter, the real VAPID signer, the real
 * request construction -- against a recorded PSR-18 edge. Nothing of ours is mocked.
 */

function dispatcherFor(RecordingPushEdge $edge): WebPushDispatcher
{
    PushFixtures::configureVapid();

    return new WebPushDispatcher($edge, app(Psr\Log\LoggerInterface::class));
}

function readyMessage(): PushMessage
{
    return new PushMessage(PagerEvent::Ready, 'A-42', 'session:11111111-2222-3333-4444-555555555555:ready');
}

it('treats exactly 404 and 410 as a dead subscription, and nothing else', function (): void {
    // THE PRUNING CLAUSE'S WHOLE BASIS. The gate says "dead subscriptions pruned on
    // 404/410"; the dispatcher asks the library rather than re-deriving the codes, so this
    // is the case that pins the library's own definition. A change here would silently
    // stop the pruning or start pruning live devices.
    $expectations = [
        404 => PagerDelivery::Gone,
        410 => PagerDelivery::Gone,
        500 => PagerDelivery::Retry,
        502 => PagerDelivery::Retry,
        // A 429 is a 4xx that means "later", so it is the one 4xx that stays retryable.
        429 => PagerDelivery::Retry,
        // The rest of the 4xx family is us building the request wrong, which no number of
        // retries fixes: a payload over the 4 KB limit, a key the service rejects, a VAPID
        // token it will not accept.
        400 => PagerDelivery::Permanent,
        401 => PagerDelivery::Permanent,
        403 => PagerDelivery::Permanent,
        413 => PagerDelivery::Permanent,
    ];

    $subscription = PushFixtures::subscription();

    foreach ($expectations as $status => $expected) {
        $edge = new RecordingPushEdge($status);

        $outcome = dispatcherFor($edge)->send(
            readyMessage(),
            $subscription['endpoint'],
            $subscription['p256dh'],
            $subscription['auth'],
        );

        expect($outcome)->toBe($expected, "HTTP {$status} should map to {$expected->name}");
    }
});

it('sends aes128gcm and never the library default, which is the legacy encoding', function (): void {
    // MEASURED 2026-10-27: Subscription::create() defaults to 'aesgcm', which the library's
    // own docblock calls "Not recommended. Outdated historic encoding." RFC 8291's encoding
    // is aes128gcm and it has to be passed explicitly. A default taken on trust would have
    // shipped the legacy encoding to every device, and nothing would have failed.
    $edge = new RecordingPushEdge(201);
    $subscription = PushFixtures::subscription();

    dispatcherFor($edge)->send(readyMessage(), $subscription['endpoint'], $subscription['p256dh'], $subscription['auth']);

    expect($edge->headerOn(0, 'Content-Encoding'))->toBe('aes128gcm');
});

it('carries a Topic, a TTL and high urgency, which is what bounds the duplicate window', function (): void {
    // The Topic is the mechanism behind the one duplicate the outbox cannot close: a push
    // service REPLACES an undelivered message with the same topic rather than queueing a
    // second one (RFC 8030 §5.4). It is derived from the row's dedupe_key, so two outbox
    // rows that are the same logical event collapse and two that are not do not.
    $edge = new RecordingPushEdge(201);
    $subscription = PushFixtures::subscription();
    $message = readyMessage();

    dispatcherFor($edge)->send($message, $subscription['endpoint'], $subscription['p256dh'], $subscription['auth']);

    expect($edge->headerOn(0, 'Topic'))->toBe($message->topic())
        ->and($edge->headerOn(0, 'Topic'))->toMatch('/^[A-Za-z0-9_-]{1,32}$/')
        ->and($edge->headerOn(0, 'TTL'))->toBe((string) config('webpush.ttl'))
        ->and($edge->headerOn(0, 'Urgency'))->toBe('high')
        ->and($edge->headerOn(0, 'Authorization'))->toStartWith('vapid ');
});

it('gives two different topics to a renotify and the ready it repeats', function (): void {
    // Two notifications the staff genuinely asked for must NOT collapse into one. The
    // dedupe key carries the attempt number for a renotify and does not for a ready
    // (PagerEvent owns both), so the topics differ by construction rather than by luck.
    $session = '11111111-2222-3333-4444-555555555555';

    $ready = new PushMessage(PagerEvent::Ready, 'A-42', PagerEvent::Ready->dedupeKey($session));
    $first = new PushMessage(PagerEvent::Renotify, 'A-42', PagerEvent::Renotify->dedupeKey($session, 1));
    $second = new PushMessage(PagerEvent::Renotify, 'A-42', PagerEvent::Renotify->dedupeKey($session, 2));

    expect([$ready->topic(), $first->topic(), $second->topic()])
        ->toHaveCount(3)
        ->and(array_unique([$ready->topic(), $first->topic(), $second->topic()]))->toHaveCount(3);
});

it('encrypts the payload, so the order reference never crosses the wire in clear', function (): void {
    $edge = new RecordingPushEdge(201);
    $subscription = PushFixtures::subscription();

    dispatcherFor($edge)->send(
        new PushMessage(PagerEvent::Ready, 'NEEDLE-IN-BODY', 'session:x:ready'),
        $subscription['endpoint'],
        $subscription['p256dh'],
        $subscription['auth'],
    );

    expect($edge->bodyOn(0))->not->toContain('NEEDLE-IN-BODY')
        ->and($edge->bodyOn(0))->not->toContain('pager.ready')
        ->and($edge->bodyOn(0))->not->toBe('');
});

it('treats an edge that cannot be reached as retryable rather than throwing', function (): void {
    // A dispatcher that threw would make one unreachable edge abandon the rest of a batch.
    $edge = new RecordingPushEdge(201, throwInstead: true);
    $subscription = PushFixtures::subscription();

    $outcome = dispatcherFor($edge)->send(readyMessage(), $subscription['endpoint'], $subscription['p256dh'], $subscription['auth']);

    expect($outcome)->toBe(PagerDelivery::Retry);
});

it('treats a key the encrypter cannot use as retryable, and leaks nothing about it', function (): void {
    // A malformed p256dh raises from INSIDE the encrypter, with the key in hand. The
    // dispatcher keeps the exception class and discards the message, the way
    // RequestLimiter does, because this log is the same log every other line goes to.
    $edge = new RecordingPushEdge(201);

    $outcome = dispatcherFor($edge)->send(readyMessage(), 'https://push.example.test/x', 'not-a-real-key', 'nope');

    expect($outcome)->toBe(PagerDelivery::Retry)
        ->and($edge->sentCount())->toBe(0);
});

it('posts to exactly the endpoint the subscription named and follows no redirect', function (): void {
    // A push service never legitimately redirects a send, and following one would re-send
    // the VAPID JWT and the encrypted payload to a host the subscription did not name.
    // allow_redirects is false on the bound client; this pins the destination.
    $edge = new RecordingPushEdge(201);
    $subscription = PushFixtures::subscription('https://push.example.test/exactly-this');

    dispatcherFor($edge)->send(readyMessage(), $subscription['endpoint'], $subscription['p256dh'], $subscription['auth']);

    expect($edge->endpointsCalled())->toBe(['https://push.example.test/exactly-this'])
        ->and($edge->requests[0]->getMethod())->toBe('POST');
});
