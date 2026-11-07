/**
 * Sign one S3 GET and print the status code it gets. Nothing else.
 *
 * ⚠ **THIS EXISTS FOR A NEGATIVE CONTROL, AND THE NEGATIVE CONTROL IS THE POINT.**
 * `apps/site` signs its own reads (`apps/site/src/store.ts`) rather than carrying
 * `@aws-sdk/client-s3`, and a signer is only meaningful if the store actually VERIFIES — a
 * store that waved signatures through would make a broken signer look perfect, and every 200
 * in `site-isolation-proof` would be uninterpretable.
 *
 * So the proof runs this twice: once with the real secret (expecting 200) and once with a
 * wrong one (expecting 403). Measured 2026-11-03 against the composed SeaweedFS: exactly
 * that. The pair is what makes the rest of the proof mean something.
 *
 * It deliberately does NOT import `apps/site/src/store.ts`. If it did, a signer that was
 * wrong in the same way in both places would agree with itself and the control would prove
 * nothing — the same reason `docs/contracts/hostnames.json` is driven by two engines rather
 * than by one with a helper.
 *
 * USAGE:  node tools/gate/lib/sign-s3-get.mjs <key>
 * PRINTS: the HTTP status, e.g. `200` or `403`
 * ENV:    AWS_ENDPOINT, AWS_BUCKET, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY,
 *         AWS_DEFAULT_REGION
 */

import { createHash, createHmac } from 'node:crypto'

const sha256 = (value) => createHash('sha256').update(value).digest('hex')
const hmac = (key, value) => createHmac('sha256', key).update(value).digest()

const key = process.argv[2]

if (key === undefined || key === '') {
  process.stderr.write('usage: node sign-s3-get.mjs <key>\n')
  process.exit(2)
}

const endpoint = process.env.AWS_ENDPOINT
const bucket = process.env.AWS_BUCKET
const accessKeyId = process.env.AWS_ACCESS_KEY_ID
const secretAccessKey = process.env.AWS_SECRET_ACCESS_KEY
const region = process.env.AWS_DEFAULT_REGION ?? 'us-east-1'

for (const [name, value] of Object.entries({ endpoint, bucket, accessKeyId, secretAccessKey })) {
  if (value === undefined || value === '') {
    process.stderr.write(`sign-s3-get: ${name} is not set\n`)
    process.exit(2)
  }
}

const canonicalPath = `/${bucket}/${key}`
const now = new Date()
const amzDate = now.toISOString().replace(/[:-]|\.\d{3}/g, '')
const dateStamp = amzDate.slice(0, 8)
const payloadHash = sha256('')
const host = new URL(endpoint).host

const headers = {
  host,
  'x-amz-content-sha256': payloadHash,
  'x-amz-date': amzDate,
}
const names = Object.keys(headers).sort()
const signedHeaders = names.join(';')
const canonicalHeaders = names.map((name) => `${name}:${headers[name]}\n`).join('')

const canonicalRequest = [
  'GET',
  canonicalPath,
  '',
  canonicalHeaders,
  signedHeaders,
  payloadHash,
].join('\n')

const scope = `${dateStamp}/${region}/s3/aws4_request`
const stringToSign = ['AWS4-HMAC-SHA256', amzDate, scope, sha256(canonicalRequest)].join('\n')

let signing = hmac(`AWS4${secretAccessKey}`, dateStamp)
signing = hmac(signing, region)
signing = hmac(signing, 's3')
signing = hmac(signing, 'aws4_request')

const signature = createHmac('sha256', signing).update(stringToSign).digest('hex')

const response = await fetch(`${endpoint}${canonicalPath}`, {
  headers: {
    ...headers,
    authorization:
      `AWS4-HMAC-SHA256 Credential=${accessKeyId}/${scope}, ` +
      `SignedHeaders=${signedHeaders}, Signature=${signature}`,
  },
  redirect: 'manual',
})

process.stdout.write(String(response.status))
