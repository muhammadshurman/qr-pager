{{--
    THE WEBAUTHN HARNESS PAGE. Deliberately unstyled, and that is in the gate.

    PLAN.md §3 row 5's task column: "A minimal unstyled harness page is built here purely to
    exercise the WebAuthn browser API -- the designed login screen is Task 10." The board's
    own comment of 2026-10-25 added it to resolve a contradiction inside the ticket: the gate
    required passkey registration and login proven end to end, while the out-of-scope table
    assigned login screens to Task 10, five tasks later.

    WHY A PAGE AT ALL. WebAuthn cannot be proven without a real browser: the credential
    ceremony lives in navigator.credentials, not in HTTP. No amount of posting JSON exercises
    an authenticator. So this is not a workaround -- it is the only honest way to test this
    at Task 5.

    WHY IT HAS NO DESIGN. §12 forbids a customer- or staff-facing component before the Task 8
    token approval, and this page is not that component: no tokens, no colours, no layout, no
    framework. A button, a name field and a <pre> for the result. If this ever starts looking
    like a login screen, it has become Task 10's work in the wrong task.

    IT IS SERVED IN `local` AND `testing` AND NOWHERE ELSE. bootstrap/app.php mounts it on
    that allowlist, and RouteSurfaceTest drives six environment names as real child processes
    to prove it rather than reading the condition.

    ⚠ IT SAID "NOT SERVED IN PRODUCTION ... mounts it only outside `production`" UNTIL
    2026-11-03, and that was true and insufficient: measured, `APP_ENV=staging` served this
    page 200. A page with a password field is a credential surface wherever it answers, and
    this one answers to anyone who can reach the host. `QRPAGER-31`.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passkey harness</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
<h1>Passkey harness</h1>

<p>
    Not a login screen. This exists to drive <code>navigator.credentials</code> against the
    Task&nbsp;5 endpoints, because the WebAuthn ceremony cannot be exercised from HTTP alone.
</p>

<h2>1. Sign in with a password first</h2>
<form id="login">
    <label>Email <input name="email" type="email" required></label>
    <label>Password <input name="password" type="password" required></label>
    <button type="submit">Sign in</button>
</form>

<h2>2. Register a passkey</h2>
<form id="register">
    <label>Name for this device <input name="name" value="harness" required></label>
    <button type="submit">Register passkey</button>
</form>

<h2>3. Sign in with the passkey</h2>
<button id="passkey-login" type="button">Sign in with passkey</button>

<h2>Result</h2>
<pre id="out">nothing yet</pre>

<script>
    const out = document.getElementById('out');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const show = (label, value) => {
        out.textContent = label + '\n' + JSON.stringify(value, null, 2);
    };

    const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        credentials: 'same-origin',
        body: JSON.stringify(body ?? {}),
    });

    const get = (url) => fetch(url, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    });

    // base64url <-> ArrayBuffer. WebAuthn speaks ArrayBuffers and JSON does not, which is
    // the whole reason a page is needed rather than a curl script.
    const decode = (value) => {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(padded + '='.repeat((4 - padded.length % 4) % 4));
        return Uint8Array.from(raw, (c) => c.charCodeAt(0));
    };

    const encode = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

    document.getElementById('login').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = new FormData(event.target);
        const response = await post('/v1/auth/login', {
            email: form.get('email'),
            password: form.get('password'),
        });
        show('login -> ' + response.status, await response.json().catch(() => null));
    });

    document.getElementById('register').addEventListener('submit', async (event) => {
        event.preventDefault();
        const name = new FormData(event.target).get('name');

        const optionsResponse = await get('/v1/auth/passkeys/options');
        if (!optionsResponse.ok) {
            show('options -> ' + optionsResponse.status, await optionsResponse.json().catch(() => null));
            return;
        }

        const { options } = await optionsResponse.json();

        options.challenge = decode(options.challenge);
        options.user.id = decode(options.user.id);
        (options.excludeCredentials ?? []).forEach((c) => { c.id = decode(c.id); });

        const credential = await navigator.credentials.create({ publicKey: options });

        const response = await post('/v1/auth/passkeys', {
            name,
            credential: {
                id: credential.id,
                rawId: encode(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: encode(credential.response.clientDataJSON),
                    attestationObject: encode(credential.response.attestationObject),
                },
            },
        });

        show('register -> ' + response.status, await response.json().catch(() => null));
    });

    document.getElementById('passkey-login').addEventListener('click', async () => {
        const optionsResponse = await get('/v1/auth/passkeys/login/options');
        const { options } = await optionsResponse.json();

        options.challenge = decode(options.challenge);
        (options.allowCredentials ?? []).forEach((c) => { c.id = decode(c.id); });

        const assertion = await navigator.credentials.get({ publicKey: options });

        const response = await post('/v1/auth/passkeys/login', {
            credential: {
                id: assertion.id,
                rawId: encode(assertion.rawId),
                type: assertion.type,
                response: {
                    clientDataJSON: encode(assertion.response.clientDataJSON),
                    authenticatorData: encode(assertion.response.authenticatorData),
                    signature: encode(assertion.response.signature),
                    userHandle: assertion.response.userHandle ? encode(assertion.response.userHandle) : null,
                },
            },
        });

        show('passkey login -> ' + response.status, await response.json().catch(() => null));
    });
</script>
</body>
</html>
