/**
 * ================================================================================
 * THE WEBAUTHN CEREMONIES. Two round trips each, and the base64url is the whole job.
 * ================================================================================
 *
 * `§4.2`: *"Login (passkey-first)"*. Task 5 built the four endpoints; this is the browser
 * half, and it is a module rather than inline code in a screen because **WebAuthn speaks
 * ArrayBuffers and JSON does not** — every challenge, credential id and signature crosses
 * the wire as base64url and has to be converted in both directions. That conversion is the
 * only thing in this file that can be subtly wrong, so it is in one place with one test.
 *
 * The wire shapes are not invented here: they are the ones Task 5's own harness page
 * (`apps/api/resources/views/passkey-harness.blade.php`) sends, read from that file rather
 * than guessed, so a screen built on this module and the `R-100` proof exercise the same
 * contract.
 *
 * ================================================================================
 * WHAT THIS MODULE DELIBERATELY DOES NOT DO
 * ================================================================================
 *
 * **It does not retry.** A ceremony is a user gesture — the browser shows a system prompt
 * for a fingerprint or a PIN — and re-running one the person did not ask for is a prompt out
 * of nowhere. A failure returns a result the screen renders; the person decides.
 *
 * **It does not report WHY it failed to anybody but the screen.** A rejection from
 * `navigator.credentials.get()` can name the relying party, the origin and the available
 * credentials; `R-100`'s own failure was a `SecurityError` about a relying party id. Useful
 * in a gate log, and on a shared kitchen screen it is an enumeration oracle, so the screen
 * gets one answer and the detail is dropped rather than rendered.
 */

export type PasskeyOutcome =
  /** Signed in. */
  | { readonly kind: 'ok' }
  /** The ceremony could not run at all: no platform authenticator, no secure context. */
  | { readonly kind: 'unsupported' }
  /** The person cancelled, or the authenticator refused. Not an error. */
  | { readonly kind: 'cancelled' }
  /** The server refused the assertion, or the network did not answer. */
  | { readonly kind: 'refused' }

/** Whether this browser can run a ceremony at all. */
export function passkeysAvailable(): boolean {
  // ⚠ READ THROUGH AN INDEX SIGNATURE, like `browser/passkey.spec.ts` does and for the same
  // reason: `lib.dom.d.ts` declares `navigator.credentials` as always present, and it is
  // ABSENT outside a secure context -- which is the condition this function exists to
  // detect, so a guard written against the declared type is a check TypeScript correctly
  // calls impossible.
  if (typeof navigator === 'undefined') return false

  const probe = (navigator as unknown as Record<string, unknown>).credentials

  if (typeof probe !== 'object' || probe === null) return false

  const api = probe as Record<string, unknown>

  return typeof api.create === 'function' && typeof api.get === 'function'
}

/* ------------------------------------------------------------------ base64url <-> bytes */

/**
 * base64url → bytes.
 *
 * ⚠ THE PADDING IS RE-ADDED, AND IT IS NOT OPTIONAL. `atob` throws on an unpadded string of
 * certain lengths and silently truncates on others, so a challenge whose length happens to
 * be a multiple of four would work and the next one would not — the worst shape of bug
 * available, because it is intermittent and looks like the authenticator.
 */
export function fromBase64Url(value: string): Uint8Array {
  const padded = value.replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4))

  return Uint8Array.from(raw, (character) => character.charCodeAt(0))
}

/** bytes → base64url, unpadded, which is what the server's decoder expects. */
export function toBase64Url(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  let raw = ''

  // A loop rather than `String.fromCharCode(...bytes)`: the spread form passes one argument
  // per byte and blows the call-stack argument limit on a large attestation object. An
  // attestation is a few hundred bytes today, which is exactly the size that works until the
  // day an authenticator sends a larger one.
  for (const byte of bytes) raw += String.fromCharCode(byte)

  return btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/* ------------------------------------------------------------------------- the options */

interface WireDescriptor {
  readonly id: string
  readonly type: string
  readonly transports?: string[]
}

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : null
}

function descriptors(value: unknown): PublicKeyCredentialDescriptor[] {
  if (!Array.isArray(value)) return []

  const out: PublicKeyCredentialDescriptor[] = []

  for (const entry of value) {
    const row = asRecord(entry) as unknown as WireDescriptor | null

    if (row === null || typeof row.id !== 'string') continue

    out.push({
      id: fromBase64Url(row.id) as unknown as BufferSource,
      type: 'public-key',
      ...(Array.isArray(row.transports)
        ? { transports: row.transports as AuthenticatorTransport[] }
        : {}),
    })
  }

  return out
}

async function optionsFrom(apiBase: string, path: string): Promise<Record<string, unknown> | null> {
  let response: Response

  try {
    response = await fetch(`${apiBase.replace(/\/$/, '')}${path}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      cache: 'no-store',
    })
  } catch {
    return null
  }

  if (!response.ok) return null

  try {
    return asRecord(asRecord(await response.json())?.options)
  } catch {
    return null
  }
}

function xsrf(): string | null {
  if (typeof document === 'undefined') return null

  for (const part of document.cookie.split(';')) {
    const [name, ...rest] = part.trim().split('=')

    if (name === 'XSRF-TOKEN') {
      try {
        return decodeURIComponent(rest.join('='))
      } catch {
        return null
      }
    }
  }

  return null
}

async function postCredential(apiBase: string, path: string, body: unknown): Promise<number> {
  const token = xsrf()
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  }

  if (token !== null) headers['X-XSRF-TOKEN'] = token

  try {
    const response = await fetch(`${apiBase.replace(/\/$/, '')}${path}`, {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify(body),
    })

    return response.status
  } catch {
    return 0
  }
}

/* -------------------------------------------------------------------------- sign in */

/** Sign in with a passkey. Two round trips: options out, assertion back. */
export async function signInWithPasskey(apiBase: string): Promise<PasskeyOutcome> {
  if (!passkeysAvailable()) return { kind: 'unsupported' }

  const options = await optionsFrom(apiBase, '/v1/auth/passkeys/login/options')

  if (options === null || typeof options.challenge !== 'string') return { kind: 'refused' }

  const request: PublicKeyCredentialRequestOptions = {
    challenge: fromBase64Url(options.challenge) as unknown as BufferSource,
    allowCredentials: descriptors(options.allowCredentials),
    ...(typeof options.rpId === 'string' ? { rpId: options.rpId } : {}),
    ...(typeof options.timeout === 'number' ? { timeout: options.timeout } : {}),
    ...(typeof options.userVerification === 'string'
      ? { userVerification: options.userVerification as UserVerificationRequirement }
      : {}),
  }

  let assertion: PublicKeyCredential | null

  try {
    assertion = (await navigator.credentials.get({
      publicKey: request,
    })) as PublicKeyCredential | null
  } catch {
    // ⚠ CANCELLED AND NOT REFUSED. Every rejection from this call -- the person dismissing
    // the prompt, a `NotAllowedError` timeout, a `SecurityError` about the relying party --
    // arrives here, and the screen's answer is the same for all of them because a shared
    // kitchen screen must not be told which. `cancelled` is the honest label for the common
    // case and the copy covers both ("try again, or use your password").
    return { kind: 'cancelled' }
  }

  if (assertion === null) return { kind: 'cancelled' }

  const response = assertion.response as AuthenticatorAssertionResponse

  const status = await postCredential(apiBase, '/v1/auth/passkeys/login', {
    credential: {
      id: assertion.id,
      rawId: toBase64Url(assertion.rawId),
      type: assertion.type,
      response: {
        clientDataJSON: toBase64Url(response.clientDataJSON),
        authenticatorData: toBase64Url(response.authenticatorData),
        signature: toBase64Url(response.signature),
        userHandle: response.userHandle === null ? null : toBase64Url(response.userHandle),
      },
    },
  })

  return status === 200 ? { kind: 'ok' } : { kind: 'refused' }
}

/* -------------------------------------------------------------------------- register */

/**
 * Add a passkey to this device. Requires an established session.
 *
 * ⚠ WHY THIS IS IN 10a AT ALL, since `§4.2` names only *login*: a passkey-first login is
 * unreachable until a passkey exists, and nothing in the product creates one outside Task
 * 5's test harness page. Without this, "passkey-first" would be a screen nobody could use,
 * which is the shape `§8` calls a claim rather than a feature.
 */
export async function registerPasskey(apiBase: string, name: string): Promise<PasskeyOutcome> {
  if (!passkeysAvailable()) return { kind: 'unsupported' }

  const options = await optionsFrom(apiBase, '/v1/auth/passkeys/options')
  const user = options === null ? null : asRecord(options.user)

  if (
    options === null ||
    user === null ||
    typeof options.challenge !== 'string' ||
    typeof user.id !== 'string'
  ) {
    return { kind: 'refused' }
  }

  const creation: PublicKeyCredentialCreationOptions = {
    challenge: fromBase64Url(options.challenge) as unknown as BufferSource,
    rp: (options.rp ?? {}) as PublicKeyCredentialRpEntity,
    user: {
      ...(user as unknown as PublicKeyCredentialUserEntity),
      id: fromBase64Url(user.id) as unknown as BufferSource,
    },
    pubKeyCredParams: (options.pubKeyCredParams ?? []) as PublicKeyCredentialParameters[],
    excludeCredentials: descriptors(options.excludeCredentials),
    ...(typeof options.timeout === 'number' ? { timeout: options.timeout } : {}),
    ...(asRecord(options.authenticatorSelection) !== null
      ? {
          authenticatorSelection: options.authenticatorSelection as AuthenticatorSelectionCriteria,
        }
      : {}),
  }

  let credential: PublicKeyCredential | null

  try {
    credential = (await navigator.credentials.create({
      publicKey: creation,
    })) as PublicKeyCredential | null
  } catch {
    return { kind: 'cancelled' }
  }

  if (credential === null) return { kind: 'cancelled' }

  const response = credential.response as AuthenticatorAttestationResponse

  const status = await postCredential(apiBase, '/v1/auth/passkeys', {
    name,
    credential: {
      id: credential.id,
      rawId: toBase64Url(credential.rawId),
      type: credential.type,
      response: {
        clientDataJSON: toBase64Url(response.clientDataJSON),
        attestationObject: toBase64Url(response.attestationObject),
      },
    },
  })

  return status === 201 ? { kind: 'ok' } : { kind: 'refused' }
}
