# ADR 002 — Object storage and transactional mail

- Status: accepted · **the LOCAL object-storage row is superseded by `docs/adr/004-local-object-store.md` (2026-10-28)**
- Date: 2026-10-25
- Extends: `PROJECT_INSTRUCTIONS.md §1` (two new rows)

## Context

Two pieces of locked scope have no component in the stack, and each blocks a task outright.

1. **S2 — landing page builder (Task 7)** includes a media library. Tenants upload
   logos, hero images and gallery photos. `PROJECT_INSTRUCTIONS.md §1` named no
   object storage, and `PLAN.md §3` row 13 listed no S3 bucket. Task 7 cannot be
   built without deciding where a file goes.

2. **S1 — tenant provisioning wizard (Task 6)** has a staff step. Adding a staff
   member means inviting them, which means sending mail. Passkey and 2FA recovery
   in Task 5 also need it. No mail provider was named.

The Blueprint's local stack (§8) listed MinIO and MailHog, so both were intended
from the start. They were lost when §1 was written.

## Decision

| Need | Production | Local |
|---|---|---|
| Object storage | **S3**, via `league/flysystem-aws-s3-v3` (Laravel's first-party S3 driver) | ⚠ **SUPERSEDED — see `docs/adr/004-local-object-store.md`.** This cell read "**MinIO** in `compose.yaml`, S3-compatible" |
| Transactional mail | **Amazon SES** | **Mailpit** in `compose.yaml` |

> **The local object-storage cell only.** `ADR-004` (2026-10-28) replaces it with
> `chrislusf/seaweedfs:4.51`, because MinIO publishes no image a fresh clone holding no
> credentials can pull — `minio/minio` answers `pull access denied` and the Docker Hub API
> answers `404`, while `minio/warp` in the same namespace answers `200`. **The production
> column is untouched**, and so is everything else here. The rest of this ADR is left exactly
> as it was written, including the Consequences below: where they say *MinIO*, read the service
> `ADR-004` names. `PROJECT_INSTRUCTIONS.md §1` still points here, which is the chain
> §1 → ADR-002 → ADR-004.

Both are chosen because the infrastructure is already AWS (`§1 › IaC`), so they
need no new vendor, no new secret store and no new egress path. The same IAM and
KMS posture covers them.

Laravel's filesystem and mail abstractions mean the local and production drivers
are a config change, so tests run against MinIO and Mailpit with no code
difference — the same rule the project already applies to Postgres and Redis:
**real services, never mocks, for behaviour that belongs to the service.**

## Consequences

- `compose.yaml` gains MinIO and Mailpit. Both bind to `127.0.0.1` only, like
  Postgres and Redis.
- `PLAN.md §3` row 13 (Terraform) gains the S3 bucket and SES identity.
- Task 6's gate gains: a staff invite is actually delivered, asserted against the
  local mail catcher.
- Task 7's gate gains: an uploaded file is validated by type, size and content —
  not by extension — and is served from a path that cannot be guessed across
  tenants.
- Task 12 must cover both in the ASVS L2 mapping: upload handling, and mail
  header injection.
- Bucket policy is **private by default**. Public read on a tenant's published
  assets is an explicit, per-path decision in Task 7, never a bucket-wide setting.

## Rejected

- **Local disk storage.** ECS Fargate tasks are ephemeral and horizontally
  scaled; a file written to one task does not exist on the next.
- **A third-party mail API (Postmark, Resend).** They are good products, but each
  adds a vendor, a secret and a data-processing relationship for a system that
  already has SES inside the same account and the same IAM boundary.
- **Deferring both to Task 13.** Tasks 6 and 7 come first and cannot be built
  without them. Deferring would mean building against an undecided interface,
  which is what `§8 ANTI-HALLUCINATION` forbids.
