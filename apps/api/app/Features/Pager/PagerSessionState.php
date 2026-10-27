<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Pager\PagerStatus;

/**
 * What a caller is told about a session. Never token_hash, which the app role cannot read
 * anyway (§2.7, measured: it cannot even name the column in a predicate).
 *
 * `changed` is what makes an idempotent retry distinguishable from a first call WITHOUT
 * making it an error. markReady on a session that is already ready answers 200 with
 * changed = false and writes nothing -- no second outbox row, no second audit row. A 409
 * there would turn a dropped response into a failure the client has to interpret, which
 * is the opposite of idempotent.
 */
final class PagerSessionState
{
    public function __construct(
        public readonly string $id,
        public readonly PagerStatus $status,
        public readonly string $orderReference,
        public readonly int $renotifyCount,
        public readonly bool $changed,
    ) {}

    /** @return array{id: string, status: string, order_reference: string, renotify_count: int, changed: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'order_reference' => $this->orderReference,
            'renotify_count' => $this->renotifyCount,
            'changed' => $this->changed,
        ];
    }
}
