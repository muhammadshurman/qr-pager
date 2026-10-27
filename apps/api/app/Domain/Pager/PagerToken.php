<?php

declare(strict_types=1);

namespace App\Domain\Pager;

use Closure;
use JsonSerializable;
use SensitiveParameter;

/**
 * The QR token: 128-bit CSPRNG, stored only as a hash, and UNLOGGABLE BY CONSTRUCTION.
 *
 * `PROJECT_INSTRUCTIONS.md §4 › Data` and `PLAN.md §3` row 3 both require that the raw
 * token never reaches a log, a URL or an error body. Redacting it at the logger is the
 * weaker half of that -- it only covers the loggers we remember to route through -- so
 * this object is built so that the common ways a value leaks cannot reach the token:
 *
 *   - there is deliberately NO __toString(), so string interpolation is a TypeError at
 *     the point of the mistake rather than a token in a message;
 *   - __debugInfo() returns the marker, so var_dump() and anything built on it cannot
 *     print it;
 *   - JsonSerializable returns the marker, so json_encode() -- which otherwise walks
 *     public properties, and which is how Monolog serialises context -- cannot either;
 *   - AND THE TOKEN IS HELD IN A CLOSURE rather than in a string property, which is what
 *     closes the last two. Measured while writing this class, because the first version
 *     claimed more than it delivered: with the token in a private string property,
 *     var_dump, print_r and json_encode were all clean and `serialize()` and
 *     `var_export()` BOTH STILL PRINTED IT -- a private property is private to callers,
 *     not to the serialisers, and var_export has no magic method to override. Behind a
 *     closure, `serialize()` is refused outright ("Serialization of 'Closure' is not
 *     allowed") and `var_export()` emits an empty \Closure::__set_state. A refusal is the
 *     right answer for a secret: it fails at the call site instead of succeeding quietly.
 *
 * Getting at the token therefore takes one named method, reveal(), which greps as an
 * intentional act. It is called in exactly one place: the create response, which is the
 * only moment the token can reach the QR code. PagerTokenTest asserts all six sinks.
 *
 * WHAT THE HASH IS OF (§2.5). The token is 16 bytes of random_bytes() rendered as
 * unpadded base64url -- 22 characters -- and token_hash is the sha256 of THAT STRING,
 * not of the 16 raw bytes. The string is what a client presents, so hashing it needs no
 * decode step and admits no second encoding of the same token. Unpadded base64url rather
 * than hex because a QR code's scan reliability falls off with payload length and 22
 * characters beat 32 for nothing.
 *
 * In App\Domain on purpose: random_bytes(), hash() and the encoders are PHP core, so this
 * needs no framework and no database, which deptrac enforces.
 */
final class PagerToken implements JsonSerializable
{
    /** What every serialisation of this object yields instead of the token. */
    public const REDACTED = '[redacted pager token]';

    /** 128 bits, per §4 › Data. Not a tuning knob: the value is specified. */
    private const BYTES = 16;

    /** Unpadded base64url of 16 bytes is always exactly this long. */
    public const LENGTH = 22;

    /** @var Closure(): string */
    private readonly Closure $token;

    private function __construct(#[SensitiveParameter] string $token)
    {
        // `static`, so the closure binds no $this and the object is not reachable
        // through it. Captured by value, which is the whole mechanism.
        $this->token = static fn (): string => $token;
    }

    /** A fresh token. The only producer. */
    public static function issue(): self
    {
        return new self(self::encode(random_bytes(self::BYTES)));
    }

    /**
     * A token a client presented, or null when it is not even the right shape.
     *
     * Shape-checked BEFORE it is hashed, so a malformed token costs one regex and no
     * database round trip -- which is what bounds an attacker's loop on the lookup path.
     * Returning null rather than throwing because a bad token is an ordinary client
     * error on a public surface, not an exceptional condition.
     */
    public static function fromPresented(#[SensitiveParameter] string $presented): ?self
    {
        return self::isWellFormed($presented) ? new self($presented) : null;
    }

    public static function isWellFormed(#[SensitiveParameter] string $presented): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{'.self::LENGTH.'}$/', $presented) === 1;
    }

    /**
     * The sha256 of the token, hex-encoded for binding into `decode(?, 'hex')`.
     *
     * Hex rather than raw bytes because PDO will not bind binary to a bytea column, which
     * §2.5 records as inherited by this task.
     */
    public function hashHex(): string
    {
        return hash('sha256', $this->reveal());
    }

    /**
     * The token itself. ONE caller: the create response.
     *
     * Named so that reaching for it is visible in a diff and in a grep. Everything else
     * in this application works with hashHex().
     */
    public function reveal(): string
    {
        return ($this->token)();
    }

    /**
     * The QR payload: the token in the URL FRAGMENT, never the path or the query.
     *
     * Two invariants land on this one choice, which is why it is here and not in a view.
     *
     * `§4 › Data` forbids the token in a URL query string and `§3` row 3 widens that to
     * any URL. A fragment is not transmitted to a server (RFC 3986 §3.5 -- it is resolved
     * by the client), so it reaches no access log, no `Referer` header and no CDN cache
     * key. Putting it in the path would satisfy the narrower wording and defeat the
     * clause's whole purpose: every scan would be a line in an access log.
     *
     * And `§4 › Edge & ops` requires the landing page to be static and CloudFront-cached,
     * because "a scan burst must never reach origin". A token in the path makes every
     * scan a distinct URL and therefore a distinct cache entry -- a guaranteed miss and a
     * guaranteed origin hit, per scan. In the fragment the path is constant, so the burst
     * is one cache entry.
     *
     * Task 9 reads it with location.hash and sends it in a request BODY; Task 4 owns the
     * endpoint that receives it. Neither exists yet, which is why this method only builds
     * the string.
     */
    public function qrPayload(string $landingUrl): string
    {
        return rtrim($landingUrl, '#').'#'.$this->reveal();
    }

    /** Unpadded base64url. */
    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array{token: string} */
    public function __debugInfo(): array
    {
        return ['token' => self::REDACTED];
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }
}
