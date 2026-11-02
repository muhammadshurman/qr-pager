import { afterEach, describe, expect, it, vi, type Mock } from 'vitest'

import {
  fromBase64Url,
  passkeysAvailable,
  registerPasskey,
  signInWithPasskey,
  toBase64Url,
} from '../src/staff/passkey.ts'

/**
 * ================================================================================
 * THE WEBAUTHN CEREMONIES, AND THE base64url THAT IS THE WHOLE JOB
 * ================================================================================
 *
 * `browser/staff-passkey.spec.ts` drives the real ceremony against a virtual authenticator
 * over CDP, in one engine, on a real stack. **What it cannot do is enumerate the failure
 * branches**: a browser cannot be made to return a malformed challenge, and an authenticator
 * cannot be made to reject in four distinguishable ways on demand.
 *
 * So the split is: the browser proves the ceremony WORKS, and this file proves every other
 * answer is distinguishable — because `StaffApp` renders a different screen for each, and a
 * branch no test reaches is a screen state no event can produce.
 *
 * ⚠ AND THE base64url PAIR IS THE PART MOST LIKELY TO BE SUBTLY WRONG, which is why it is
 * tested on its own rather than only through a ceremony. `atob` throws on an unpadded string
 * of some lengths and silently truncates others, so a challenge whose length happens to be a
 * multiple of four would work and the next one would not — intermittent, and it looks like
 * the authenticator.
 */

function withCredentials(api: unknown): void {
  Object.defineProperty(globalThis, 'navigator', {
    value: { credentials: api },
    writable: true,
    configurable: true,
  })
}

function respond(status: number, body?: unknown): Response {
  return {
    status,
    ok: status >= 200 && status < 300,
    json: () => Promise.resolve(body),
  } as unknown as Response
}

/**
 * The credential body a recorded `fetch` posted.
 *
 * ⚠ A HELPER RATHER THAN AN INLINE SUBSCRIPT, because `vi.fn()`'s `mock.calls` is `any[][]` --
 * so `calls[1]?.[1]?.body` is an unsafe member access on `any`, which the lint rule is right
 * about: a typo in `body` would read as `undefined` and `JSON.parse('undefined')` would throw
 * inside the assertion rather than failing it. Narrowed once, here.
 */
function postedCredential(mock: Mock): {
  credential: { id: string; rawId: string; type: string; response: Record<string, string | null> }
} {
  const calls = mock.mock.calls as unknown as [unknown, RequestInit | undefined][]
  const body = calls[1]?.[1]?.body

  expect(typeof body).toBe('string')

  return JSON.parse(typeof body === 'string' ? body : '{}') as ReturnType<typeof postedCredential>
}

/** A minimal credential of the shape the browser hands back. */
function fakeCredential(response: Record<string, ArrayBuffer | null>): unknown {
  return {
    id: 'credential-id',
    rawId: new Uint8Array([1, 2, 3]).buffer,
    type: 'public-key',
    response,
  }
}

const LOGIN_OPTIONS = {
  options: {
    challenge: 'AQID',
    rpId: 'localhost',
    timeout: 60_000,
    userVerification: 'required',
    allowCredentials: [{ id: 'BAUG', type: 'public-key', transports: ['internal'] }],
  },
}

const CREATE_OPTIONS = {
  options: {
    challenge: 'AQID',
    rp: { id: 'localhost', name: 'QR Pager' },
    user: { id: 'BAUG', name: 'hiba', displayName: 'Hiba' },
    pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
    excludeCredentials: [],
    timeout: 60_000,
    authenticatorSelection: { userVerification: 'required' },
  },
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('base64url', () => {
  it('round-trips every length, so a challenge cannot work only sometimes', () => {
    // ⚠ EVERY LENGTH 0..16, which is what pins the padding. `atob` needs `=` restored and the
    // amount needed cycles with length mod 4 -- so a test on one length proves one quarter of
    // the behaviour, and the quarter that works is the one anybody would pick.
    for (let length = 0; length <= 16; length += 1) {
      const bytes = new Uint8Array(length).map((_, index) => (index * 37) % 256)
      const encoded = toBase64Url(bytes.buffer)

      expect(encoded, `length ${String(length)} produced padding`).not.toContain('=')
      expect(encoded, `length ${String(length)} produced a + or /`).not.toMatch(/[+/]/)
      expect([...fromBase64Url(encoded)], `length ${String(length)} did not round-trip`).toEqual([
        ...bytes,
      ])
    }
  })

  it('round-trips a buffer too large for a spread, which is the argument-limit trap', () => {
    // `String.fromCharCode(...bytes)` passes one argument per byte and blows the call-stack
    // limit on a large attestation. 200,000 bytes is comfortably past it on every engine.
    const bytes = new Uint8Array(200_000).map((_, index) => index % 256)

    expect([...fromBase64Url(toBase64Url(bytes.buffer))]).toEqual([...bytes])
  })

  it('decodes the url-safe alphabet, which is not base64’s', () => {
    // `-` and `_` stand in for `+` and `/`. A decoder that forgot the substitution would
    // throw or return different bytes for exactly the values that carry them.
    const withBoth = toBase64Url(new Uint8Array([251, 255, 191]).buffer)

    expect(withBoth).toMatch(/[-_]/)
    expect([...fromBase64Url(withBoth)]).toEqual([251, 255, 191])
  })
})

describe('availability', () => {
  it('is false when navigator.credentials is absent, which is what an insecure context gives', () => {
    withCredentials(undefined)
    expect(passkeysAvailable()).toBe(false)

    // And false when it exists but is not the API -- a shape a polyfill or a stub can produce.
    withCredentials({})
    expect(passkeysAvailable()).toBe(false)

    withCredentials({ create: 'not a function', get: 'not a function' })
    expect(passkeysAvailable()).toBe(false)
  })

  it('is true only when both halves of the ceremony are callable', () => {
    withCredentials({ create: () => undefined, get: () => undefined })
    expect(passkeysAvailable()).toBe(true)

    // One half alone is not enough: a register-only or assert-only API cannot complete the
    // flow, and reporting it as available would show the passkey button and then fail.
    withCredentials({ get: () => undefined })
    expect(passkeysAvailable()).toBe(false)
  })
})

describe('signing in with a passkey', () => {
  it('answers `unsupported` without making a single request', async () => {
    withCredentials(undefined)

    const fetchMock = vi.fn()

    vi.stubGlobal('fetch', fetchMock)

    expect((await signInWithPasskey('')).kind).toBe('unsupported')
    // Not one request: a ceremony that cannot run must not cost a round trip, and the screen
    // shows the password form instead.
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('answers `refused` when the options cannot be read, and never calls the authenticator', async () => {
    const get = vi.fn()

    withCredentials({ create: vi.fn(), get })

    for (const options of [respond(500), respond(200, {}), respond(200, { options: {} })]) {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue(options))

      expect((await signInWithPasskey('')).kind).toBe('refused')
    }

    // ⚠ THE AUTHENTICATOR IS NEVER PROMPTED on a bad options response. A system prompt that
    // appears and then fails for a server reason is the worst available answer: the person
    // has touched a fingerprint reader for nothing.
    expect(get).not.toHaveBeenCalled()
  })

  it('answers `cancelled` for every rejection the ceremony can produce', async () => {
    // One answer for all of them, deliberately: a `NotAllowedError` timeout, the person
    // dismissing the prompt, and a `SecurityError` about the relying party id are the same
    // answer to a shared kitchen screen. The screen must not become an oracle for which.
    for (const rejection of [
      new DOMException('The operation either timed out', 'NotAllowedError'),
      new DOMException('relying party id is not a registrable suffix', 'SecurityError'),
      new Error('something else entirely'),
    ]) {
      withCredentials({ create: vi.fn(), get: vi.fn().mockRejectedValue(rejection) })
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(200, LOGIN_OPTIONS)))

      expect((await signInWithPasskey('')).kind).toBe('cancelled')
    }

    // A null credential is the same answer: the ceremony produced nothing.
    withCredentials({ create: vi.fn(), get: vi.fn().mockResolvedValue(null) })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(200, LOGIN_OPTIONS)))

    expect((await signInWithPasskey('')).kind).toBe('cancelled')
  })

  it('posts the assertion in the shape the server reads, and only a 200 is `ok`', async () => {
    const assertion = fakeCredential({
      clientDataJSON: new Uint8Array([10, 11]).buffer,
      authenticatorData: new Uint8Array([12, 13]).buffer,
      signature: new Uint8Array([14, 15]).buffer,
      userHandle: new Uint8Array([16]).buffer,
    })

    withCredentials({ create: vi.fn(), get: vi.fn().mockResolvedValue(assertion) })

    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(respond(200, LOGIN_OPTIONS))
      .mockResolvedValueOnce(respond(200, { ok: true }))

    vi.stubGlobal('fetch', fetchMock)

    expect((await signInWithPasskey('')).kind).toBe('ok')

    const posted = postedCredential(fetchMock)

    // The exact keys `apps/api/resources/views/passkey-harness.blade.php` sends, which is the
    // shape Task 5's endpoint reads. Asserted by value, so a renamed key is a failure here
    // rather than a 422 in a browser.
    expect(Object.keys(posted.credential.response).sort()).toEqual([
      'authenticatorData',
      'clientDataJSON',
      'signature',
      'userHandle',
    ])
    expect(posted.credential.id).toBe('credential-id')
    expect(posted.credential.rawId).toBe(toBase64Url(new Uint8Array([1, 2, 3]).buffer))
    expect(posted.credential.response.userHandle).toBe(toBase64Url(new Uint8Array([16]).buffer))
  })

  it('sends `userHandle: null` rather than omitting it, because the server reads the key', async () => {
    const assertion = fakeCredential({
      clientDataJSON: new Uint8Array([1]).buffer,
      authenticatorData: new Uint8Array([2]).buffer,
      signature: new Uint8Array([3]).buffer,
      userHandle: null,
    })

    withCredentials({ create: vi.fn(), get: vi.fn().mockResolvedValue(assertion) })

    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(respond(200, LOGIN_OPTIONS))
      .mockResolvedValueOnce(respond(200, {}))

    vi.stubGlobal('fetch', fetchMock)
    await signInWithPasskey('')

    const posted = postedCredential(fetchMock)

    expect('userHandle' in posted.credential.response).toBe(true)
    expect(posted.credential.response.userHandle).toBeNull()
  })

  it('answers `refused` when the server rejects the assertion, and when the network dies', async () => {
    const assertion = fakeCredential({
      clientDataJSON: new Uint8Array([1]).buffer,
      authenticatorData: new Uint8Array([2]).buffer,
      signature: new Uint8Array([3]).buffer,
      userHandle: null,
    })

    withCredentials({ create: vi.fn(), get: vi.fn().mockResolvedValue(assertion) })

    for (const second of [respond(403), respond(422), respond(500)]) {
      vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValueOnce(respond(200, LOGIN_OPTIONS)).mockResolvedValueOnce(second),
      )

      expect((await signInWithPasskey('')).kind).toBe('refused')
    }

    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValueOnce(respond(200, LOGIN_OPTIONS))
        .mockRejectedValueOnce(new TypeError('Failed to fetch')),
    )

    expect((await signInWithPasskey('')).kind).toBe('refused')
  })
})

describe('adding a passkey', () => {
  it('answers `unsupported`, then `refused` on unreadable options, without prompting', async () => {
    withCredentials(undefined)
    expect((await registerPasskey('', 'this device')).kind).toBe('unsupported')

    const create = vi.fn()

    withCredentials({ create, get: vi.fn() })

    for (const options of [
      respond(500),
      respond(200, {}),
      respond(200, { options: { challenge: 'AQID' } }),
      respond(200, { options: { user: { id: 'BAUG' } } }),
    ]) {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue(options))

      expect((await registerPasskey('', 'this device')).kind).toBe('refused')
    }

    expect(create).not.toHaveBeenCalled()
  })

  it('decodes the challenge AND the user handle, which are two separate buffers', async () => {
    // A ceremony that decoded one and passed the other as a string fails inside the browser
    // with a message about the wrong one, so both are asserted by value.
    const credential = fakeCredential({
      clientDataJSON: new Uint8Array([1]).buffer,
      attestationObject: new Uint8Array([2]).buffer,
    })

    const create = vi.fn().mockResolvedValue(credential)

    withCredentials({ create, get: vi.fn() })
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValueOnce(respond(200, CREATE_OPTIONS))
        .mockResolvedValueOnce(respond(201, {})),
    )

    expect((await registerPasskey('', 'this device')).kind).toBe('ok')

    const passed = create.mock.calls[0]?.[0] as { publicKey: PublicKeyCredentialCreationOptions }

    expect([...new Uint8Array(passed.publicKey.challenge as ArrayBuffer)]).toEqual([1, 2, 3])
    expect([...new Uint8Array(passed.publicKey.user.id as ArrayBuffer)]).toEqual([4, 5, 6])
  })

  it('treats only 201 as success, so a 200 is not mistaken for a stored credential', async () => {
    const credential = fakeCredential({
      clientDataJSON: new Uint8Array([1]).buffer,
      attestationObject: new Uint8Array([2]).buffer,
    })

    withCredentials({ create: vi.fn().mockResolvedValue(credential), get: vi.fn() })

    for (const [status, expected] of [
      [201, 'ok'],
      [200, 'refused'],
      [403, 'refused'],
      [500, 'refused'],
    ] as const) {
      vi.stubGlobal(
        'fetch',
        vi
          .fn()
          .mockResolvedValueOnce(respond(200, CREATE_OPTIONS))
          .mockResolvedValueOnce(respond(status, {})),
      )

      expect((await registerPasskey('', 'this device')).kind, `status ${String(status)}`).toBe(
        expected,
      )
    }
  })

  it('answers `cancelled` when the person dismisses the prompt, and sends nothing after', async () => {
    const fetchMock = vi.fn().mockResolvedValue(respond(200, CREATE_OPTIONS))

    withCredentials({
      create: vi.fn().mockRejectedValue(new DOMException('cancelled', 'NotAllowedError')),
      get: vi.fn(),
    })
    vi.stubGlobal('fetch', fetchMock)

    expect((await registerPasskey('', 'this device')).kind).toBe('cancelled')
    // One call: the options. No attestation was posted, because there is none.
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
})
