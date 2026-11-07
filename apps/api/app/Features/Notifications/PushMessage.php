<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Domain\Pager\PagerEvent;

/**
 * What one notification says, and the Topic that lets a push service collapse a duplicate.
 *
 * WHAT IT DELIBERATELY DOES NOT CARRY. No tenant id, no session id, no token, no
 * restaurant name, no customer detail. The payload is encrypted end-to-end to the device,
 * so this is not about interception -- it is about what a stolen device or a compromised
 * service worker yields, and about the 4 KB the Push API allows. Two fields: what
 * happened, and which order it happened to. The order reference is the one thing a
 * customer with two orders needs to tell two buzzes apart, and it is the same string
 * already printed on their ticket.
 *
 * THE TEXT IS NOT HERE. A notification's title and body are copy, they are per-locale --
 * Arabic is a launch language (§12) -- and §4.1 requires the Order ready screen's wording
 * to be specified before it is coded. All of that is Task 9's. What crosses the wire is a
 * machine-readable event the service worker renders; shipping English strings from the
 * backend would put copy in a layer that cannot be translated and would make a text
 * change a backend deploy.
 */
final class PushMessage
{
    private const VERSION = 1;

    public function __construct(
        public readonly PagerEvent $event,
        public readonly string $orderReference,
        public readonly string $dedupeKey,
    ) {}

    public function encode(): string
    {
        return json_encode([
            'v' => self::VERSION,
            'event' => $this->event->value,
            'order_reference' => $this->orderReference,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The `Topic` header: RFC 8030 §5.4's collapse key.
     *
     * A push service REPLACES an undelivered message carrying the same topic instead of
     * queueing a second one, which is what bounds the one duplicate window the outbox
     * cannot close (WebPushDispatcher's docblock has the full argument).
     *
     * DERIVED FROM dedupe_key, so it is the same single source UNIQUE (tenant_id,
     * dedupe_key) enforces and PagerEvent owns -- two outbox rows that are the same
     * logical event collapse, and two that are not (a renotify carries its attempt
     * number) do not.
     *
     * HASHED, because the header's alphabet is not the key's. RFC 8030 says a topic is at
     * most 32 characters from the URL-safe base64 alphabet; a dedupe key is up to 60
     * characters and contains colons. Base64url of a truncated sha256 is 22 characters
     * and satisfies both, and doing it HERE means there is one place that knows the rule.
     */
    public function topic(): string
    {
        return rtrim(strtr(base64_encode(substr(hash('sha256', $this->dedupeKey, true), 0, 16)), '+/', '-_'), '=');
    }
}
