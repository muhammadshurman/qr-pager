<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Landing\MediaReferences;

/**
 * A MediaReferences that answers from a fixed list.
 *
 * This is NOT a mock of database behaviour, which §6 forbids: the real implementation,
 * App\Features\Landing\TenantMediaReferences, is driven against real Postgres by
 * MediaIsolationTest and by the publish tests. What this stubs is the ANSWER, so that the
 * validator's own cases -- which are about a document's shape, not about a query -- do
 * not each need a tenant, an asset row and an object in the store.
 *
 * The distinction matters and is the one R-059 is about: a test that mocks the thing under
 * test proves nothing. Here the thing under test is LandingDocument, and the port is its
 * input.
 */
final class StubMediaReferences implements MediaReferences
{
    /** @param list<string> $known */
    public function __construct(private readonly array $known = []) {}

    public function has(string $mediaId): bool
    {
        return in_array(strtolower($mediaId), array_map('strtolower', $this->known), true);
    }
}
