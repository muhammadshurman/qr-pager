import { describe, expect, it } from 'vitest'

import {
  DATABASE_ENUMS,
  DOMAIN_STATUS,
  DOMAIN_STATUS_TRANSITIONS,
  DomainStatusCanMoveTo,
  LANDING_PAGE_STATUS,
  PAGER_STATUS,
  PAGER_STATUS_TRANSITIONS,
  PagerStatusCanMoveTo,
  type PagerStatus,
} from '../src/index.ts'

/**
 * The consumer side of the generated mirror.
 *
 * The PHP side is pinned by StatusMirrorContractTest, which proves the bytes match the enum
 * registry and that `--check` bites. What THIS file proves is that the emitted shape is
 * usable and sound from TypeScript -- a mirror that type-checks but whose Record is
 * incomplete would be worse than no mirror, because a consumer would index it and get
 * `undefined` where the contract promises an array.
 */
describe('the generated status mirror', () => {
  it('carries every enum the registry names', () => {
    expect(Object.keys(DATABASE_ENUMS).sort()).toEqual([
      'audit_actor_type',
      'domain_status',
      'landing_page_status',
      'outbox_status',
      'pager_status',
      'provisioning_status',
      // QRPAGER-30. `tenants.language`, the restaurant's own language; `dir` is derived
      // from it on both sides and is stored on neither. This list is deliberately a
      // hand-written one -- it is the one place a type added to `DatabaseEnums::map()` has
      // to be acknowledged by a human, and it did its job: adding the type turned this case
      // red before anything consumed the mirror.
      'tenant_language',
      'tenant_role',
      'tenant_status',
    ])
  })

  it('has the pager statuses in the PHP declaration order', () => {
    expect(PAGER_STATUS).toEqual(['waiting', 'ready', 'completed', 'cancelled', 'expired'])
  })

  it('has a transition entry for EVERY status, so the Record is total', () => {
    for (const status of PAGER_STATUS) {
      expect(PAGER_STATUS_TRANSITIONS[status]).toBeInstanceOf(Array)
    }
    expect(Object.keys(PAGER_STATUS_TRANSITIONS).sort()).toEqual([...PAGER_STATUS].sort())
  })

  it('refers only to statuses that exist -- no dangling target', () => {
    for (const [, targets] of Object.entries(PAGER_STATUS_TRANSITIONS)) {
      for (const target of targets) expect(PAGER_STATUS).toContain(target)
    }
    for (const [, targets] of Object.entries(DOMAIN_STATUS_TRANSITIONS)) {
      for (const target of targets) expect(DOMAIN_STATUS).toContain(target)
    }
  })

  it('makes the three terminal pager statuses terminal', () => {
    expect(PAGER_STATUS_TRANSITIONS.completed).toEqual([])
    expect(PAGER_STATUS_TRANSITIONS.cancelled).toEqual([])
    expect(PAGER_STATUS_TRANSITIONS.expired).toEqual([])
  })

  it.each([
    ['waiting', 'ready', true],
    ['waiting', 'completed', true],
    ['ready', 'completed', true],
    ['ready', 'waiting', false],
    ['completed', 'ready', false],
    ['expired', 'waiting', false],
  ])('%s -> %s is %s', (from, to, allowed) => {
    expect(PagerStatusCanMoveTo(from as PagerStatus, to as PagerStatus)).toBe(allowed)
  })

  it('keeps the one path to an active domain, with nothing jumping the queue', () => {
    expect(DomainStatusCanMoveTo('pending_dns', 'active')).toBe(false)
    expect(DomainStatusCanMoveTo('dns_verified', 'active')).toBe(false)
    expect(DomainStatusCanMoveTo('certificate_pending', 'active')).toBe(true)
    expect(DomainStatusCanMoveTo('failed', 'active')).toBe(false)
    expect(DomainStatusCanMoveTo('redirecting', 'active')).toBe(true)
  })

  it('has exactly two landing page statuses, because there is no `publishing`', () => {
    // The absent third is the design (QRPAGER-14 D8): a publish is write-new-then-switch,
    // so there is no moment at which the row is a third thing.
    expect(LANDING_PAGE_STATUS).toEqual(['draft', 'published'])
  })
})
