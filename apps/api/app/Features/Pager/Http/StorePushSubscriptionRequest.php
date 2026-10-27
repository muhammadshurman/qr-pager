<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

// INTERNAL NOTES -- LINE COMMENTS ON PURPOSE, SO THEY ARE NOT PUBLISHED (R-052's
// mechanism; see ResolvePagerSessionRequest for the full note).
//
// EVERY BOUND HERE IS §2.5'S OWN, not a guess, so a value that would have come back from
// Postgres as a 23514 or a 22001 is a 422 with the field named instead:
//
//   endpoint  text, CHECK (endpoint LIKE 'https://%' AND length(endpoint) <= 2048)
//   p256dh    varchar(255)
//   auth      varchar(255)
//
// The https:// rule is the database's own constraint restated at the edge, and it is the
// one that matters: an http:// endpoint would send the encrypted payload AND the VAPID JWT
// over a channel anyone can read, and that JWT is a bearer credential for this
// application's identity to that push service.
//
// MEASURED SIZES, 2026-10-27, from a real P-256 keypair generated with openssl and a real
// 16-byte auth secret: p256dh is 87 characters and auth is 22, both unpadded base64url. So
// varchar(255) fits both with room to spare and NO MIGRATION IS NEEDED -- checked rather
// than assumed, because "it fits" is exactly the kind of claim §4a records this project
// getting wrong.
//
// The alphabet rule is base64url WITH OPTIONAL PADDING: the Push API's own
// arrayBufferToBase64url helpers vary across browsers and some emit '=' padding. Both are
// accepted and neither is normalised, because the value is opaque to us -- it goes
// straight to the encrypter, which does its own decoding.

/**
 * The body for registering a device: the code from the QR scan, plus the endpoint and keys
 * from the browser's own push subscription.
 */
final class StorePushSubscriptionRequest extends PresentedTokenRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return parent::rules() + [
            'endpoint' => ['required', 'string', 'max:2048', 'starts_with:https://', 'url'],
            'p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'endpoint.starts_with' => 'The push endpoint must be an https:// URL.',
            'p256dh.regex' => 'The p256dh key must be base64url.',
            'auth.regex' => 'The auth secret must be base64url.',
        ];
    }

    public function endpoint(): string
    {
        /** @var string $endpoint */
        $endpoint = $this->validated()['endpoint'];

        return $endpoint;
    }

    public function p256dh(): string
    {
        /** @var string $key */
        $key = $this->validated()['p256dh'];

        return $key;
    }

    public function authSecret(): string
    {
        /** @var string $secret */
        $secret = $this->validated()['auth'];

        return $secret;
    }
}
