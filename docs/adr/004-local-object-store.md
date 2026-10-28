# ADR 004 — The local object store

- Status: accepted
- Date: 2026-10-28
- Supersedes: **`ADR-002`'s Decision row for LOCAL object storage only** — the cell that read
  "MinIO in `compose.yaml`, S3-compatible". Nothing else in ADR-002 is touched: production is
  still **S3 via `league/flysystem-aws-s3-v3`**, mail is still SES with Mailpit locally, and
  ADR-002's Consequences, Rejected list and private-by-default bucket posture all stand.
- Extends: `PROJECT_INSTRUCTIONS.md §1`'s local stand-in clause
- Closes: `PLAN.md §5` `R-125`'s object-store half

## Context

`R-125` measured on 2026-10-28 that `compose.yaml` had neither of the two local services
ADR-002 promised. The mail half was closed the same day (`439e8d3`: Mailpit, plus
`LocalMailCatcherTest`, which goes red when the service is absent). The object-store half
could not be closed, for a reason that is not a scheduling one: **MinIO's image cannot be
pulled.**

`PROJECT_INSTRUCTIONS.md §1` now carries a clause under the locked-stack table, and it is the
clause this ADR is measured against. In summary — the byte-for-byte text is mirrored into the
repo copy in its own commit, and the canonical copy is rank 2 — a local stand-in:

1. is **locked to the production service's API**;
2. must be **anonymously pullable by a fresh clone holding no credentials**;
3. must **run from a pinned tag**;
4. is **proven by a round trip through the production client library**, not by a port check.

Condition 2 is what MinIO fails. Condition 3 is what the one remaining current MinIO packaging
fails. That is the whole of this ADR's reason to exist.

### The measurement that forced it

Every figure below was taken with `DOCKER_CONFIG` pointing at a directory whose `config.json`
is `{}` — no `credsStore`, no `auths`. That is condition 2's own wording turned into an
instrument: **the fresh clone holding no credentials**. It also removes this machine's variable,
which mattered: Docker Desktop's credential helper (`credsStore: desktop.exe`) failed with
`error getting credentials - err: exit status 1, out: ``` on 7 of 8 pulls in the first run, which
is a property of this laptop and of no candidate. Under the credential-free config that error
disappears and every pull below is the registry's own answer.

```
docker pull minio/minio:latest
  Error response from daemon: pull access denied for minio/minio,
  repository does not exist or may require 'docker login'
docker pull minio/mc:latest                            -> the same
docker pull minio/minio:RELEASE.2025-04-22T22-12-26Z   -> the same
GET https://hub.docker.com/v2/repositories/minio/minio/  -> HTTP 404 {"message":"object not found"}
GET https://hub.docker.com/v2/repositories/minio/mc/     -> HTTP 404
docker manifest inspect quay.io/minio/minio:latest           -> 401 UNAUTHORIZED
docker manifest inspect ghcr.io/minio/minio:latest           -> denied
docker manifest inspect registry.min.io/minio/minio:latest   -> no such manifest
docker manifest inspect public.ecr.aws/bitnami/minio:latest  -> no such manifest
```

**THE CONTROLS, which are what make this a finding about MinIO rather than about this machine
or this network:**

| Control | Answer |
|---|---|
| `minio/warp:latest` — the **same Docker Hub namespace** | manifest resolves; Hub API **HTTP 200** |
| the `minio` namespace as a whole | **20 public repositories** (`mint`, `warp`, `sidekick`, `k8s-operator`, …) |
| `redis:7-alpine`, `axllent/mailpit:v1.32.0` — this project's own images | pull from the same shell, same credential-free config |

So the namespace is public and most of it is reachable; the **server** image and the **client**
are the two that are gone. `439e8d3` and `R-125` recorded this as `denied: requested access to
the resource is denied / unauthorized: authentication required` — that was the reading through
this machine's credential helper. The credential-free reading is stronger and simpler: **the
repository is not there.**

## The candidates, measured

Instruments, one per condition, plus two that `§1` implies rather than states:

| Instrument | What it is |
|---|---|
| **pull** | `docker pull` with `DOCKER_CONFIG` = an empty `config.json` (condition 2). The sizes quoted below are `docker image inspect --format '{{.Size}}'`, so they are comparable **with each other** and not with the `SIZE` column of `docker images`, which prints a larger number for the same image on this host — 399 MB against inspect's 99,351,173 bytes for `bitnamilegacy/minio` (Docker 29.6.1). Size decided nothing here; it is quoted because a 1.6 GB stand-in is a cost a fresh clone pays |
| **pinned tag** | the Docker Hub / `cgr.dev` tag listing, looking for a version tag of the kind every other image here already uses — `postgres:16-alpine`, `redis:7-alpine`, `axllent/mailpit:v1.32.0` (condition 3) |
| **round trip** | PUT, GET **with the bytes compared by sha256**, LIST, DELETE through `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` — the production client library, against the running container (conditions 1 and 4). 4KB of `random_bytes` plus a NUL, a `0xff` and a PNG signature, because a stand-in that mangles non-UTF-8 content passes a "hello world" probe and fails a logo upload |
| **negative control** | the same round trip with a **wrong secret**, which MUST fail. A stand-in that accepts any signature makes a green round trip prove nothing — the vacuous-pass shape this project has paid for before (`R-059`, and `R-054`'s "the control never failed") |
| **feature probe** | the twelve S3 behaviours Task 7's media library will actually use: ContentType round trip, `mimeType()`, `fileSize`/`lastModified`, a **20MB multipart** `writeStream`, `readStream` of it, prefix listing with a delimiter, `copy`, `move`, a **presigned GET** fetched over HTTP, `setVisibility`/`visibility`, an **unsigned** GET being refused, and `deleteDirectory` |

| Candidate | Anonymous pull | Pinned tag | Round trip | Wrong secret refused | Feature probe |
|---|---|---|---|---|---|
| `minio/minio` — ADR-002's name | ✗ **denied; Hub API 404** | — | — | — | — |
| `quay.io/minio/minio` | ✗ 401 UNAUTHORIZED | — | — | — | — |
| `ghcr.io/minio/minio` | ✗ denied | — | — | — | — |
| `registry.min.io/minio/minio` | ✗ no such manifest | — | — | — | — |
| `bitnamilegacy/minio` | ✓ 99 MB | ⚠ `2025.7.23-debian-12-r5` — pinned, but a **frozen archive** | ✓ | ✓ `SignatureDoesNotMatch` | **11/12** — `PutObjectAcl` → `NotImplemented` |
| `chainguard/minio` | ✓ 64 MB | ✗ **`latest` and `latest-dev` only** — 0 version tags of 2954, on Docker Hub and on `cgr.dev` | ✓ | — | — |
| `localstack/localstack` | ⚠ image pulls (348 MB) but **will not run** | ✓ `2026.10.4` | ✗ **never served a request** | — | — |
| `adobe/s3mock` | ✓ 85 MB | ✓ `5.2.3` | ✓ | ✗ **a wrong secret passes** | — (disqualified) |
| `chrislusf/seaweedfs` — default | ✓ 197 MB | ✓ `4.51` | ✓ | ✗ **a wrong secret passes** | — |
| **`chrislusf/seaweedfs` + credentials** | ✓ | ✓ `4.51` | ✓ | ✓ `SignatureDoesNotMatch` | **12/12** |
| `dxflrs/garage` | ✓ 27 MB | ✓ `v2.5.0` | ⚠ only after four admin steps, and its **credentials are minted at runtime** | — | — |
| `scireum/s3-ninja` | ✓ 219 MB | ✓ `8.7.3` | ✓ | ✓ | **10/12** — two **silent** divergences |
| `versity/versitygw` | ✓ 36 MB | ✓ `v1.10.0` | ✓ | ✓ | **9/12** — `copy` and `move` fail |
| `rustfs/rustfs` | ✓ 115 MB | ✓ `1.0.4` | ✓ | ✓ | **11/12** — one **silent** divergence |

The exact failures, because "failed" is not a finding:

- **`localstack/localstack:2026.10.4`** exits 55 before binding a port:
  `License activation failed! 🔑❌ Reason: No credentials were found in the environment. Please
  set the LOCALSTACK_AUTH_TOKEN variable to a valid auth token.` Measured again with
  `ACTIVATE_PRO=0`, the documented community switch: **the same exit**. A stand-in that needs an
  account is condition 2's exact prohibition.
- **`adobe/s3mock:5.2.3`** and **`chrislusf/seaweedfs:4.51` with no credentials configured**
  both completed the *entire* round trip — bucket, put, get with bytes compared, list, delete —
  while signing with `definitely-not-the-secret`. Neither verifies a signature by default. This
  is why the negative control exists: against either of those two, a green round trip is
  evidence of nothing, and `ADR-002`'s "real services, never mocks" stops being a preference
  and becomes the difference between an instrument and a decoration.
- **`scireum/s3-ninja:8.7.3`** returns `image/png, image/png` from `mimeType()` — it emits the
  header twice — and accepts `setVisibility(PUBLIC)` with a 200, after which `visibility()`
  answers `private`. Both are **silent**: the call succeeds and the value is wrong.
- **`rustfs/rustfs:1.0.4`** has the same silent visibility divergence (accepted, then
  `private`). It also needs `--tmpfs /data:mode=1777`: with a plain `--tmpfs /data` it dies with
  `[FATAL] Server runtime failed: Io error: Permission denied (os error 13)`, because the image
  runs as the `rustfs` user.
- **`versity/versitygw:v1.10.0`** fails `copy` (`League\Flysystem\UnableToCopyFile`) and
  therefore `move`, which a media library does. It also needs its POSIX backend directory to
  exist before it starts: `failed to init posix backend: chdir /tmp/gw: no such file or
  directory`.
- **`dxflrs/garage:v2.5.0`** passed the round trip, but only after `garage.toml` with a
  generated `rpc_secret`, `garage layout assign -z dc1 -c 1G <node-id>`, `garage layout apply
  --version 1`, `garage key create` and `garage bucket allow --read --write --owner`. The key
  create is the disqualifier: **the credentials are minted when the container runs**
  (`GK7c9c2117d45fb4ddf66c3820`, different every time), so no `.env.example` a fresh clone
  copies can contain them. Condition 2 is not only about the registry.
- **`bitnamilegacy/minio`** passes all three conditions as measured today, and is the one
  candidate that is **the software ADR-002 named**. Its own publisher disqualifies it:
  *"⚠️ This repository is no longer updated … a backup of all existing container images, which
  will receive no further updates or support and should only be used for temporary migration
  purposes … this repository may be removed in the future."* Last updated **2025-08-19**; the
  newest MinIO in it is **2025.7.23**. The live `bitnami/minio` says *"This image is no longer
  available for free through Docker Hub"*.
- **`chainguard/minio`** is current MinIO, rebuilt the morning of this measurement
  (`last_updated 2026-10-28T11:43:34Z`), and it passes the round trip — it is MinIO. It
  publishes no version tag to pin: `latest` and `latest-dev` are the only two non-digest tags in
  2954, on Docker Hub and on `cgr.dev` alike. A floating tag rebuilt daily is condition 3's
  opposite, and a digest pin of it is a different instrument from a version tag — it pins to a
  build that the free tier does not keep.

## Decision

**The local object store is `chrislusf/seaweedfs:4.51` — SeaweedFS's own image — with its S3
gateway enabled and credentials required.**

```yaml
objectstore:
  image: chrislusf/seaweedfs:4.51
  command: ["server", "-s3", "-dir=/data"]
  environment:
    AWS_ACCESS_KEY_ID: qrpager
    AWS_SECRET_ACCESS_KEY: local_dev_only
  ports:
    - "127.0.0.1:8333:8333"
```

Why this one, against the measurement and not against taste:

1. **It is the only candidate that passes all four conditions and has no divergence left to
   find.** 12 of 12 on the feature probe, including the 20MB multipart upload, the presigned
   GET and the unsigned GET being refused.
2. **Its credentials come from the environment**, so `compose.yaml` and `.env.example` can name
   them the way they already name Postgres's — and with them set, a wrong secret is refused
   (`SignatureDoesNotMatch`) and an unsigned request gets `403`. Without them it is one of the
   two candidates that accept anything, which is recorded above and is asserted against in
   `LocalObjectStoreTest` rather than trusted.
3. **It is the project's own current image with pinned version tags**, which is the property
   every other image in `compose.yaml` has and the property MinIO can no longer offer from any
   source: the one current MinIO packaging publishes no pinned tag, and the one pinned MinIO
   packaging is an archive its publisher says may be deleted. **A stand-in that disappears from
   under a fresh clone is `R-125` happening again**, which is the row this ADR closes.
4. It needs **no volume, no mounted config file and no post-start admin step**: one image, one
   command, two environment variables, one published port.

**MinIO is not rejected on merit.** It was the right choice when ADR-002 was written and it is
still the better reference for one behaviour — see the ACL trap below. It is rejected because
it is **no longer obtainable** under `§1`'s own conditions, which is a registry fact rather than
an engineering judgement, and that is why this supersedes a row rather than re-litigating a
decision (`§3`: do not re-litigate).

## Consequences

- `compose.yaml` gains **`objectstore`**, named for the role rather than the product: the
  product here is now a measured choice that can change, and the next change should not rename
  a service. `mailpit` keeps its product name, because ADR-002's mail row is untouched.
- **Bound to `127.0.0.1:8333` only** and **with no volume**, both for ADR-002's stated reasons.
  Only the S3 port is published; SeaweedFS's master, volume and filer ports stay inside the
  container.
- `phpunit.xml` and `.env.example` point the **`s3` disk** at it (`AWS_ENDPOINT`,
  `AWS_USE_PATH_STYLE_ENDPOINT=true`, `AWS_BUCKET=qrpager-local`). No second disk and no second
  driver: the local/production difference is configuration, which is ADR-002's own argument.
- `LocalObjectStoreTest` proves it **by round trip through `Storage::disk('s3')`** and **fails
  rather than skips** when the service is absent — `LocalMailCatcherTest`'s shape, one service
  over. It also carries the **negative control**: a client with a wrong secret must be refused.
- ⚠ **An ACL trap for Task 7, found by the feature probe.** SeaweedFS honours object ACLs, so
  `->setVisibility('public')` *works locally*. On MinIO it answers `NotImplemented`, and on a
  current S3 bucket with ownership enforced it answers `AccessControlListNotSupported`. So the
  stand-in is **more permissive than production** on exactly the path ADR-002 calls "an
  explicit, per-path decision in Task 7, never a bucket-wide setting". **Task 7 must not use
  object ACLs as the mechanism for a published asset** — the mechanism is the bucket policy and
  CloudFront. This is the one place where a green local test could mislead, and it is written
  down rather than left to be discovered.
- `PROJECT_INSTRUCTIONS.md §1`'s Object-storage row still reads "MinIO locally — see
  `docs/adr/002-storage-and-mail.md`". The pointer chain is **§1 → ADR-002 → ADR-004**, which is
  the mechanism ADRs exist for; `§1` is rank 2 and editing it is Muhammad's call, not a
  builder's.
- `PLAN.md §3` row 13 (Terraform) is unchanged: the S3 bucket and SES identity are production,
  and nothing here touches them. Task 12's ASVS mapping is unchanged for the same reason.
- **If this image ever becomes unpullable**, the instruments above are the replacement
  procedure, not a fresh opinion. The measured runners-up, in order: `rustfs/rustfs:1.0.4`
  (11/12, current, pinned — accept the silent visibility divergence and assert against it), then
  `bitnamilegacy/minio:2025.7.23-debian-12-r5` (11/12, and it is MinIO) while the archive still
  exists.

## Rejected

- **MinIO from a private mirror of our own.** Mirroring the image into a registry this project
  controls would keep the software, and it would need credentials a fresh clone does not have —
  condition 2, failed by construction, and the fresh-clone gate is the gate that would catch it.
- **`minio/minio:latest` left in `compose.yaml` as documentation.** It breaks
  `docker compose up -d` for every clone. A documented gap is better than a broken `up`, which
  is the call `439e8d3` already made for this same service.
- **`chainguard/minio:latest`, unpinned.** `§1`'s condition 3, and the project's own practice
  since `postgres:16-alpine`.
- **A pinned digest of `chainguard/minio`.** It is not a version tag, it freezes a daily rebuild
  chosen for no reason but the hour it was fetched, and Chainguard's free tier does not keep old
  builds — so it is a pin that expires silently.
- **`adobe/s3mock` or an unauthenticated SeaweedFS.** Both let a wrong secret through; ADR-002's
  "real services, never mocks, for behaviour that belongs to the service" is exactly this.
- **A port-reachability check instead of a round trip.** `§1` forbids it, and the two candidates
  above are why: both answer on their port, and neither proves an object was stored.
- **Deferring to Task 7 again.** Task 7 would then introduce its own test instrument inside its
  own diff — the reason the mail half landed on this branch too.
