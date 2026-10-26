<?php

declare(strict_types=1);

/*
 * The public API surface, served under /v1 by bootstrap/app.php's apiPrefix.
 *
 * Empty on purpose at Task 2. Every route that belongs here is owned by a later task
 * and named in this ticket's out-of-scope table: pager sessions and the QR token
 * lookup are Task 3, the polling and subscription endpoints are Task 4, and
 * authentication is Task 5. Task 2 builds the surface, the guards, the error envelope
 * and the versioning policy -- not endpoints.
 *
 * The versioning policy for a breaking change is in PLAN.md §6. A published path is
 * never changed in place.
 */
