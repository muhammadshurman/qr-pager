/**
 * The public surface of @qrpager/contracts.
 *
 * Everything here is GENERATED from the PHP enum registry (App\Domain\DatabaseEnums) by
 * `php artisan contracts:export`. There is nothing hand-written in this package, and
 * `composer contracts:check` fails the gate when the generated file is stale.
 *
 * Adding a status by hand here would be the second source ADR-003 describes: it agrees on
 * the day it is written and drifts silently after, and the drift reaches a customer as the
 * wrong status on the screen they are watching.
 */
export * from './generated/status.ts'
