<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * "Does this media id belong to the tenant we are validating for?"
 *
 * An interface in Domain and an implementation in Features, because deptrac's rule 1 is
 * `Domain: ~` -- the domain layer depends on NOTHING, framework and database included.
 * The validator needs a database fact, so the fact is passed in rather than fetched.
 *
 * The contract has one clause and it is the one the named invariant rests on: `has()`
 * answers true ONLY for an asset of the tenant whose document is being validated. A
 * foreign id resolves to nothing and the publish is refused (§6.4).
 */
interface MediaReferences
{
    public function has(string $mediaId): bool;
}
