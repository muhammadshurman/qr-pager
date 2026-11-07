/**
 * The public surface of @qrpager/contracts.
 *
 * Everything here is GENERATED from a PHP source, and there is nothing hand-written in this
 * package. `composer contracts:check` fails the gate when either generated file is stale.
 *
 *   - `generated/status.ts`    from App\Domain\DatabaseEnums, by `contracts:export`
 *   - `generated/site-keys.ts` from App\Features\Landing\PublishedSite, by
 *                              `contracts:export-site-keys` -- the published-site key layout
 *                              and the hostname rule, read by `apps/site` (ADR-005)
 *
 * Adding a status by hand here would be the second source ADR-003 describes: it agrees on
 * the day it is written and drifts silently after, and the drift reaches a customer as the
 * wrong status on the screen they are watching.
 */
export * from './generated/status.ts'
export * from './generated/site-keys.ts'
