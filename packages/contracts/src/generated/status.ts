/*
 * GENERATED FILE -- DO NOT EDIT.
 *
 * The TypeScript mirror of the PHP status enums, emitted from
 * App\Domain\DatabaseEnums::map() by `php artisan contracts:export`.
 * `composer contracts:check` byte-diffs this file against the PHP source and
 * fails the gate when they disagree.
 *
 * The PHP enum is the ONE source (§6.2, ADR-003). A hand-written copy here would
 * be a second source wearing a disguise: it agrees on the day it is written and
 * drifts silently after, and the drift reaches a customer as the wrong status on
 * the screen they are watching.
 *
 * Mirrored types: pager_status, outbox_status, tenant_status, tenant_role, audit_actor_type, provisioning_status, landing_page_status, domain_status, tenant_language
 *
 * The transition tables below are the PHP tables, not a re-derivation. Where an
 * enum declares no transitions it has none -- that is a fact about the status
 * machine, not a gap in this file.
 */

/** Postgres enum type `pager_status`, mirrored from PagerStatus. */
export const PAGER_STATUS = [
  'waiting',
  'ready',
  'completed',
  'cancelled',
  'expired',
] as const

export type PagerStatus = (typeof PAGER_STATUS)[number]

/**
 * Every transition the domain permits, and nothing else.
 *
 * An empty list is a TERMINAL status. Read from the PHP table rather than
 * re-derived, so the two cannot disagree.
 */
export const PAGER_STATUS_TRANSITIONS: Readonly<Record<PagerStatus, readonly PagerStatus[]>> = {
  'waiting': ['ready', 'completed', 'cancelled', 'expired'],
  'ready': ['completed', 'cancelled', 'expired'],
  'completed': [],
  'cancelled': [],
  'expired': [],
}

/** Whether `from` may move to `to`. The TypeScript side of the same table. */
export function PagerStatusCanMoveTo(from: PagerStatus, to: PagerStatus): boolean {
  return PAGER_STATUS_TRANSITIONS[from].includes(to)
}

/** Postgres enum type `outbox_status`, mirrored from OutboxStatus. */
export const OUTBOX_STATUS = [
  'pending',
  'sent',
  'dead',
] as const

export type OutboxStatus = (typeof OUTBOX_STATUS)[number]

/** Postgres enum type `tenant_status`, mirrored from TenantStatus. */
export const TENANT_STATUS = [
  'active',
  'suspended',
] as const

export type TenantStatus = (typeof TENANT_STATUS)[number]

/** Postgres enum type `tenant_role`, mirrored from TenantRole. */
export const TENANT_ROLE = [
  'owner',
  'manager',
  'staff',
] as const

export type TenantRole = (typeof TENANT_ROLE)[number]

/** Postgres enum type `audit_actor_type`, mirrored from AuditActorType. */
export const AUDIT_ACTOR_TYPE = [
  'user',
  'system',
  'platform',
] as const

export type AuditActorType = (typeof AUDIT_ACTOR_TYPE)[number]

/** Postgres enum type `provisioning_status`, mirrored from ProvisioningStatus. */
export const PROVISIONING_STATUS = [
  'pending',
  'running',
  'completed',
  'failed',
] as const

export type ProvisioningStatus = (typeof PROVISIONING_STATUS)[number]

/**
 * Every transition the domain permits, and nothing else.
 *
 * An empty list is a TERMINAL status. Read from the PHP table rather than
 * re-derived, so the two cannot disagree.
 */
export const PROVISIONING_STATUS_TRANSITIONS: Readonly<Record<ProvisioningStatus, readonly ProvisioningStatus[]>> = {
  'pending': ['running', 'failed'],
  'running': ['completed', 'failed', 'pending'],
  'completed': [],
  'failed': ['running', 'pending'],
}

/** Whether `from` may move to `to`. The TypeScript side of the same table. */
export function ProvisioningStatusCanMoveTo(from: ProvisioningStatus, to: ProvisioningStatus): boolean {
  return PROVISIONING_STATUS_TRANSITIONS[from].includes(to)
}

/** Postgres enum type `landing_page_status`, mirrored from LandingPageStatus. */
export const LANDING_PAGE_STATUS = [
  'draft',
  'published',
] as const

export type LandingPageStatus = (typeof LANDING_PAGE_STATUS)[number]

/**
 * Every transition the domain permits, and nothing else.
 *
 * An empty list is a TERMINAL status. Read from the PHP table rather than
 * re-derived, so the two cannot disagree.
 */
export const LANDING_PAGE_STATUS_TRANSITIONS: Readonly<Record<LandingPageStatus, readonly LandingPageStatus[]>> = {
  'draft': ['published'],
  'published': ['draft', 'published'],
}

/** Whether `from` may move to `to`. The TypeScript side of the same table. */
export function LandingPageStatusCanMoveTo(from: LandingPageStatus, to: LandingPageStatus): boolean {
  return LANDING_PAGE_STATUS_TRANSITIONS[from].includes(to)
}

/** Postgres enum type `domain_status`, mirrored from DomainStatus. */
export const DOMAIN_STATUS = [
  'pending_dns',
  'dns_verified',
  'certificate_pending',
  'active',
  'redirecting',
  'failed',
] as const

export type DomainStatus = (typeof DOMAIN_STATUS)[number]

/**
 * Every transition the domain permits, and nothing else.
 *
 * An empty list is a TERMINAL status. Read from the PHP table rather than
 * re-derived, so the two cannot disagree.
 */
export const DOMAIN_STATUS_TRANSITIONS: Readonly<Record<DomainStatus, readonly DomainStatus[]>> = {
  'pending_dns': ['dns_verified', 'failed'],
  'dns_verified': ['certificate_pending', 'failed'],
  'certificate_pending': ['active', 'failed'],
  'active': ['redirecting'],
  'redirecting': ['active'],
  'failed': ['pending_dns'],
}

/** Whether `from` may move to `to`. The TypeScript side of the same table. */
export function DomainStatusCanMoveTo(from: DomainStatus, to: DomainStatus): boolean {
  return DOMAIN_STATUS_TRANSITIONS[from].includes(to)
}

/** Postgres enum type `tenant_language`, mirrored from TenantLanguage. */
export const TENANT_LANGUAGE = [
  'ar',
  'en',
] as const

export type TenantLanguage = (typeof TENANT_LANGUAGE)[number]

/**
 * The shape of a presented QR pager token, from App\Domain\Pager\PagerToken.
 *
 * Unpadded base64url of 128 CSPRNG bits (§4 › Data), so the length is fixed rather
 * than a maximum. A client applies this BEFORE it sends anything, so a fragment
 * that is not a token costs no request -- the same reason the server checks the
 * shape before it hashes.
 */
export const PAGER_TOKEN_LENGTH = 22

/** The character class, as a regex fragment. Build the anchors locally. */
export const PAGER_TOKEN_ALPHABET = 'A-Za-z0-9_-'

/** Whether a string is shaped like a presented pager token. */
export function isWellFormedPagerToken(presented: string): boolean {
  return new RegExp(
    '^[' + PAGER_TOKEN_ALPHABET + ']{' + String(PAGER_TOKEN_LENGTH) + '}$',
  ).test(presented)
}

/**
 * Every mirrored Postgres enum type, keyed by its type name.
 *
 * Emitted from App\Domain\DatabaseEnums::map(), so a type added there appears here
 * without anyone remembering to add it -- and the staleness check fails until the
 * generated file is committed.
 */
export const DATABASE_ENUMS = {
  'pager_status': PAGER_STATUS,
  'outbox_status': OUTBOX_STATUS,
  'tenant_status': TENANT_STATUS,
  'tenant_role': TENANT_ROLE,
  'audit_actor_type': AUDIT_ACTOR_TYPE,
  'provisioning_status': PROVISIONING_STATUS,
  'landing_page_status': LANDING_PAGE_STATUS,
  'domain_status': DOMAIN_STATUS,
  'tenant_language': TENANT_LANGUAGE,
} as const

export type DatabaseEnumName = keyof typeof DATABASE_ENUMS
