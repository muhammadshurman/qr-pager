<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Domain\Pager\PagerDelivery;
use App\Features\Pager\PushSubscriptions;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One send to one device, and what the answer means. The only caller of minishlink/web-push.
 *
 * C5: "the outbox queue worker sends Web Push, never the HTTP request." This class is
 * reachable from the worker and from nowhere else -- there is no route, no controller and
 * no middleware that constructs it -- and PagerRouteSurfaceTest's successor pins that.
 *
 * ===================================================================================
 * THE EDGE IS AN INJECTED PSR-18 CLIENT, WHICH IS WHY "MOCKED EDGE ONLY" IS REAL
 * ===================================================================================
 *
 * minishlink/web-push v11 takes a ClientInterface. The suite passes an object that
 * records the request and returns a status, so "push sent to a mocked edge only in the
 * automated suite" is an INJECTION rather than a hostname nobody resolves -- there is no
 * socket to leak through and no DNS lookup to go wrong. v10.1.0 reaches for Guzzle
 * directly, which is why v11 was chosen over it.
 *
 * ===================================================================================
 * FOUR MEASUREMENTS THIS CLASS IS BUILT ON (2026-10-27, against the real library)
 * ===================================================================================
 *
 *  1. MessageSentReport::isSubscriptionExpired() is true for EXACTLY 404 and 410, and
 *     false for 500, 429 and 413. So the pruning clause reads the library's own
 *     definition instead of a second copy of the status codes.
 *  2. Subscription::create() DEFAULTS TO 'aesgcm' -- which the library's own docblock
 *     calls "Not recommended. Outdated historic encoding." RFC 8291's encoding is
 *     aes128gcm and it must be passed explicitly. A default taken on trust here would
 *     have shipped the legacy encoding to every device.
 *  3. The payload is encrypted before it leaves: a marker string placed in the cleartext
 *     does not appear in the request body.
 *  4. A real P-256 public key is 87 base64url characters and an auth secret is 22, so
 *     push_subscriptions' varchar(255) columns fit both and no migration is needed.
 *
 * ===================================================================================
 * THE Topic HEADER IS A MECHANISM, NOT DECORATION
 * ===================================================================================
 *
 * The gate says "nothing lost, nothing duplicated". The worker's claim-and-lease gets
 * both at the database, and leaves one window no outbox design can close: a crash AFTER
 * the push edge accepted a message and BEFORE the row is marked sent re-sends that one
 * message. The Web Push protocol has no idempotency key -- but RFC 8030 §5.4 gives a
 * push service `Topic`, and a service REPLACES an undelivered message that carries the
 * same topic rather than queueing a second one. So the duplicate is collapsed whenever
 * the device has not yet come online, which is most of the window. The remainder -- a
 * duplicate after delivery -- is closed on the client by the service worker's
 * notification tag, which is Task 9's, and it is written into PLAN.md §5 as R-075 with
 * that owner rather than left to be discovered there.
 *
 * The topic is derived from the row's dedupe_key, so it is the SAME single source that
 * UNIQUE (tenant_id, dedupe_key) enforces (PagerEvent owns both). A topic must be
 * base64url and at most 32 characters, which a dedupe key is not -- it is up to 60 and
 * contains colons -- so it is hashed down, in one place, below.
 *
 * ===================================================================================
 * WHAT NEVER REACHES A LOG
 * ===================================================================================
 *
 * A push endpoint URL is a bearer capability for one device: anyone holding it can
 * notify that device until it is revoked. So it is treated exactly as §4 › Data treats
 * the QR token -- never logged, never in a response body -- and where a log line must
 * name a device it names PushSubscriptions::fingerprint(), twelve hex characters of a
 * sha256. The VAPID private key is never logged either, and that is why the catch below
 * keeps the exception CLASS and discards its message: a VAPID validation failure's
 * message can carry key material, and this log is the same log every other line goes to.
 */
final class WebPushDispatcher
{
    /**
     * The container key the PSR-18 edge client is bound under.
     *
     * A NAME RATHER THAN A TYPE HINT, so that what this class talks to is decided by
     * AppServiceProvider and not by php-http/discovery picking whatever implementation
     * happens to be in the vendor directory. It is also the one line a test overrides to
     * install a recording client, which is how "push sent to a mocked edge only in the
     * automated suite" is enforced rather than intended.
     */
    public const EDGE = 'webpush.edge';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Send one message to one device.
     *
     * Returns what the worker owes the row afterwards, never throws. A dispatcher that
     * threw would make one unreachable edge abandon the rest of a batch.
     */
    public function send(PushMessage $message, string $endpoint, string $p256dh, string $auth): PagerDelivery
    {
        try {
            $push = new WebPush(
                ['VAPID' => $this->vapid()],
                [
                    'TTL' => (int) config('webpush.ttl'),
                    'urgency' => (string) config('webpush.urgency'),
                    'topic' => $message->topic(),
                ],
                $this->client,
            );

            // Padding hides the payload's length from an observer of the TLS stream. It
            // costs bytes on every send and the content here is a fixed-shape JSON object
            // of two short fields, so the length tells an observer nothing they do not
            // already know from the endpoint being hit at all.
            $push->setAutomaticPadding(false);

            $report = $push->sendOneNotification(
                Subscription::create([
                    'endpoint' => $endpoint,
                    'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
                    // Measurement 2 above. Never the library's default.
                    'contentEncoding' => ContentEncoding::from((string) config('webpush.content_encoding')),
                ]),
                $message->encode(),
            );

            return $this->dispositionOf($report, $endpoint);
        } catch (Throwable $failure) {
            // The class only, never the message. A malformed key raises from inside the
            // encrypter with the key in hand, and a VAPID failure raises with the private
            // key in hand.
            $this->log->warning('web push send failed before it reached the edge', [
                'exception' => $failure::class,
                'device' => PushSubscriptions::fingerprint($endpoint),
            ]);

            // Before the edge means we never found out, so it is retryable. A key this
            // broken will fail the same way six times and dead-letter, which is the
            // honest end for it.
            return PagerDelivery::Retry;
        }
    }

    /**
     * What one report means for the row and for the subscription.
     *
     * The ORDER matters: expiry is asked first, because a 404 and a 410 are also "not a
     * success" and a status-code check that ran first would file them as retryable and
     * keep a dead device in the table for six more attempts.
     */
    private function dispositionOf(MessageSentReport $report, string $endpoint): PagerDelivery
    {
        if ($report->isSuccess()) {
            return PagerDelivery::Delivered;
        }

        if ($report->isSubscriptionExpired()) {
            return PagerDelivery::Gone;
        }

        $status = $report->getResponse()?->getStatusCode();

        $this->log->warning('web push edge refused a notification', [
            'status' => $status,
            'device' => PushSubscriptions::fingerprint($endpoint),
        ]);

        // 4xx that is not 404/410 means WE built it wrong -- a bad key (400), a payload
        // over the 4 KB limit (413), a VAPID token the service rejected (401/403). None
        // of those gets better by being sent again, so the row dead-letters now instead
        // of consuming six attempts and the backoff between them. 429 is the exception:
        // it is a 4xx that says "later" and means exactly that.
        if ($status !== null && $status >= 400 && $status < 500 && $status !== 429) {
            return PagerDelivery::Permanent;
        }

        return PagerDelivery::Retry;
    }

    /**
     * The VAPID trio, from config and from nowhere else.
     *
     * No default and no fallback: an empty key must fail loudly at the first send rather
     * than quietly produce unsigned requests that every push service refuses. The values
     * come from the environment (§4 › Edge & ops: no secret in the repo), and
     * config/webpush.php records why not even a test key is committed.
     *
     * @return array{subject: string, publicKey: string, privateKey: string}
     */
    private function vapid(): array
    {
        return [
            'subject' => (string) config('webpush.vapid.subject'),
            'publicKey' => (string) config('webpush.vapid.public_key'),
            'privateKey' => (string) config('webpush.vapid.private_key'),
        ];
    }
}
