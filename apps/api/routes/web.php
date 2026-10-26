<?php

declare(strict_types=1);

/*
 * No web routes.
 *
 * The Laravel skeleton's `/` welcome page was removed at Task 2: the gate requires
 * every public route to be under /v1, and a framework splash screen is a public route
 * that is not. The two probes are the named exception and live in routes/probes.php.
 *
 * The file and the 'web' group stay because Filament registers its panel routes
 * through them at Tasks 6-7, and because the session and CSRF middleware the panel
 * needs are the 'web' group's.
 */
