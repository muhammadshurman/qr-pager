import { createHash, createHmac } from 'node:crypto'

/**
 * ================================================================================
 * READING ONE OBJECT FROM THE PUBLISHED-SITE BUCKET — Task 11 (QRPAGER-18), ADR-005
 * ================================================================================
 *
 * `apps/site` is the local stand-in for the CDN's `Host` -> prefix mapping. The CDN reads
 * the bucket; so does this. The only thing it ever does is **one GET of one key**: no list,
 * no write, no delete, and no second request derived from the first.
 *
 * ⚠ **SigV4 IS SIGNED HERE BY HAND, IN ~40 LINES OF `node:crypto`, AND NOT WITH
 * `@aws-sdk/client-s3`. THAT IS A DELIBERATE TRADE AND IT WAS COSTED.** The SDK is ~40
 * packages, and `docs/contracts/dependency-licences.json` is a GATE-CHECKED, exact-count
 * licence inventory that `§10`'s *Transfer Ownership* hands to the client as the record of
 * what they inherit. Adding forty packages to that record, for a stand-in that exists so a
 * mapping has a local proof, is a large permanent cost for a small temporary gain. One GET
 * is the whole S3 surface this file needs, and SigV4 for one GET with no payload is a short,
 * closed problem.
 *
 * ⚠ **AND THE SIGNER IS PROVEN BY A NEGATIVE CONTROL RATHER THAN BY ITS OWN SUCCESS**, which
 * matters because a store that ignored signatures would make a broken signer look perfect.
 * Measured 2026-11-03 against the composed SeaweedFS: a correctly signed `ListObjectsV2`
 * answered **200**, and the SAME request signed with the wrong secret answered **403**. So
 * the store verifies, and a 200 here means the signature was right. `site-store.test.ts`
 * keeps both halves, because the day the store stops verifying is the day this file's
 * success stops meaning anything.
 *
 * In production this process does not exist: the CDN serves the bytes and nothing in `apps/`
 * holds a bucket credential. That is `PLAN.md §6.3`, and it is why this file is small.
 */

const sha256 = (value: string): string => createHash('sha256').update(value).digest('hex')
const hmac = (key: Buffer | string, value: string): Buffer =>
  createHmac('sha256', key).update(value).digest()

/** The empty-payload hash, which every GET here signs. Constant, so it is named once. */
const EMPTY_PAYLOAD = sha256('')

export interface StoreConfig {
  readonly endpoint: string
  readonly bucket: string
  readonly region: string
  readonly accessKeyId: string
  readonly secretAccessKey: string
}

export interface StoredObject {
  /**
   * The bytes, as an `ArrayBuffer` rather than a `Uint8Array`.
   *
   * `Response` accepts an `ArrayBuffer` directly, and a `Uint8Array<ArrayBufferLike>` -- what
   * `new Uint8Array(await response.arrayBuffer())` is typed as -- is not assignable to
   * `BodyInit` under the pinned TypeScript. Handing back the buffer avoids both a copy and a
   * cast, and `TextDecoder` is what a test uses to read it.
   */
  readonly body: ArrayBuffer
  readonly contentType: string | null
}

/** A reader of one key. The handler takes this, so a test can drive it without a bucket. */
export type ObjectReader = (key: string) => Promise<StoredObject | null>

/**
 * The store could not be reached at all — a different answer from "no page on this host".
 *
 * ⚠ **THIS CLASS EXISTS BECAUSE A TEST NAME CONTRADICTED ITS OWN ASSERTION.** The reader's
 * docblock said *"a 404 and a 403 both answer null"*, which was true of HTTP ANSWERS and
 * silent about there being no answer: a refused connection makes `fetch` reject, nothing
 * caught it, and the route handler would have returned a 500 with a stack trace in the log
 * for what is simply a store outage. The case was being driven as `rejects.toThrow()` under a
 * name claiming the opposite, which is the shape worth catching.
 *
 * A 503 is the honest answer: the page exists, this process cannot fetch it, and a visitor
 * should be told to come back rather than told there is nothing here. **Telling them "not
 * found" would be worse than unhelpful** — a CDN may cache a 404 and will not cache a 503, so
 * conflating the two can persist an outage past its own end.
 */
export class StoreUnreachable extends Error {
  public constructor(cause: unknown) {
    super('the published-site store could not be reached')
    this.name = 'StoreUnreachable'
    this.cause = cause
  }
}

/**
 * Read the store's configuration from the environment, or return null if it is incomplete.
 *
 * ⚠ **NULL RATHER THAN DEFAULTS, AND NULL RATHER THAN A THROW AT IMPORT TIME.** A default
 * endpoint would make a misconfigured deployment read *something* — the wrong bucket is
 * worse than no bucket on this surface. A throw at import time would take the process down
 * at build, when Next evaluates modules, which turns a missing variable into a failed build
 * with a stack trace instead of a 503 with a reason.
 */
export function configFromEnvironment(
  env: Readonly<Partial<Record<string, string>>> = process.env,
): StoreConfig | null {
  const endpoint = env.AWS_ENDPOINT
  const bucket = env.AWS_BUCKET
  const accessKeyId = env.AWS_ACCESS_KEY_ID
  const secretAccessKey = env.AWS_SECRET_ACCESS_KEY

  if (
    endpoint === undefined ||
    bucket === undefined ||
    accessKeyId === undefined ||
    secretAccessKey === undefined ||
    endpoint === '' ||
    bucket === ''
  ) {
    return null
  }

  return {
    endpoint,
    bucket,
    region: env.AWS_DEFAULT_REGION ?? 'us-east-1',
    accessKeyId,
    secretAccessKey,
  }
}

/**
 * Sign one GET, AWS Signature Version 4.
 *
 * Exported so the negative control can drive it with a wrong secret. `now` is a parameter so
 * the canonical request is reproducible in a test rather than depending on the clock.
 */
export function signGet(
  config: StoreConfig,
  canonicalPath: string,
  canonicalQuery: string,
  now: Date,
): Record<string, string> {
  const host = new URL(config.endpoint).host
  const amzDate = now.toISOString().replace(/[:-]|\.\d{3}/g, '')
  const dateStamp = amzDate.slice(0, 8)

  // Sorted, lower-cased, and the same three in the canonical request and in SignedHeaders --
  // a mismatch between those two lists is the classic SigV4 403 and is why they are derived
  // from one object rather than written twice.
  const headers: Record<string, string> = {
    host,
    'x-amz-content-sha256': EMPTY_PAYLOAD,
    'x-amz-date': amzDate,
  }
  const names = Object.keys(headers).sort()
  const signedHeaders = names.join(';')
  const canonicalHeaders = names.map((name) => `${name}:${headers[name] ?? ''}\n`).join('')

  const canonicalRequest = [
    'GET',
    canonicalPath,
    canonicalQuery,
    canonicalHeaders,
    signedHeaders,
    EMPTY_PAYLOAD,
  ].join('\n')

  const scope = `${dateStamp}/${config.region}/s3/aws4_request`
  const stringToSign = ['AWS4-HMAC-SHA256', amzDate, scope, sha256(canonicalRequest)].join('\n')

  let key = hmac(`AWS4${config.secretAccessKey}`, dateStamp)
  key = hmac(key, config.region)
  key = hmac(key, 's3')
  key = hmac(key, 'aws4_request')

  const signature = createHmac('sha256', key).update(stringToSign).digest('hex')

  return {
    ...headers,
    authorization:
      `AWS4-HMAC-SHA256 Credential=${config.accessKeyId}/${scope}, ` +
      `SignedHeaders=${signedHeaders}, Signature=${signature}`,
  }
}

/**
 * Percent-encode one path segment the way SigV4 canonicalisation requires.
 *
 * `encodeURIComponent` leaves `!'()*` alone and AWS wants them encoded; it also encodes `~`,
 * which AWS wants left alone. Both corrections are here rather than in the caller, because a
 * canonical path that disagrees with the request path by one character is a 403 with no clue
 * in it.
 */
function encodeSegment(segment: string): string {
  return encodeURIComponent(segment)
    .replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`)
    .replace(/%7E/g, '~')
}

/**
 * The real reader: one signed GET of one key, path-style.
 *
 * Path-style rather than virtual-hosted, because the local stand-in for the bucket is reached
 * by IP and a bucket name cannot be a subdomain of an IP literal -- the same reason
 * `AWS_USE_PATH_STYLE_ENDPOINT=true` is in `.env.example`.
 *
 * **A 404 and a 403 both answer `null`, deliberately.** The caller turns `null` into a 404
 * for the visitor, and the two cases are indistinguishable to a visitor anyway: a key that
 * does not exist and a key this process may not read are both "no page on this host". Giving
 * them different answers would make this surface report whether a host has ever published.
 */
export function objectReader(config: StoreConfig): ObjectReader {
  return async (key: string): Promise<StoredObject | null> => {
    const canonicalPath = `/${config.bucket}/${key.split('/').map(encodeSegment).join('/')}`
    const headers = signGet(config, canonicalPath, '', new Date())

    let response: Response

    try {
      response = await fetch(`${config.endpoint}${canonicalPath}`, {
        headers,
        // No redirect following: a store that answers 302 is a store answering something this
        // process did not ask for, and following it would be a request to an unsigned origin.
        redirect: 'manual',
      })
    } catch (cause) {
      // Reached, not answered. Distinguished from an answer of "no such key", because the two
      // deserve different statuses and different cache behaviour.
      throw new StoreUnreachable(cause)
    }

    if (!response.ok) return null

    return {
      body: await response.arrayBuffer(),
      contentType: response.headers.get('content-type'),
    }
  }
}
