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

    /**
     * The character class a presented token is made of, as a regex fragment.
     *
     * ⚠ EXTRACTED FROM isWellFormed()'s OWN PATTERN AT TASK 9, AND IT IS A DE-DUPLICATION
     * RATHER THAN A NEW FACT. The class was already written there, inline; it is a constant
     * now because the CLIENT has to apply the same test -- Task 9 validates `location.hash`
     * before it sends anything, so a malformed fragment costs no request at all -- and a
     * literal `22` plus a typed character class in TypeScript would be the second source
     * `§6.2` forbids: it agrees on the day it is written and drifts silently after.
     *
     * So `App\Domain\Contracts\StatusMirror` emits this constant and LENGTH into
     * `packages/contracts`, `composer contracts:check` byte-diffs the result, and the two
     * languages cannot disagree about what a pager token looks like. isWellFormed() below
     * builds its pattern from both, so PHP still has exactly one source and it is this one.
     *
     * Unpadded base64url, per `encode()`: `-` and `_` rather than `+` and `/`, no `=`.
     */
    public const ALPHABET = 'A-Za-z0-9_-';

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
        return preg_match(self::pattern(), $presented) === 1;
    }

    /**
     * The anchored shape test, built from the two constants above and from nothing else.
     *
     * A METHOD RATHER THAN A THIRD CONSTANT, because the delimiters and the anchors are
     * PCRE's and are not part of the contract: the mirror emits LENGTH and ALPHABET, and
     * the TypeScript side builds its own `RegExp` from them with its own anchors. A
     * constant carrying `/^[...]{22}$/` would export PHP's regex dialect into a contract
     * that has to hold in JavaScript too.
     */
    public static function pattern(): string
    {
        return '/^['.self::ALPHABET.']{'.self::LENGTH.'}$/';
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
     * THIS WAS A PRECEDENCE CONFLICT AND IT IS NOW A RULING. `§4 › Data` (rank 2) forbids
     * the token in a URL QUERY STRING; `PLAN.md §3` row 3 (rank 3) widened that to ANY
     * URL. A fragment is part of a URL (RFC 3986 §3.5), so the two could not both be
     * satisfied -- and an earlier version of this docblock argued the wider wording away
     * on its own authority, which `§0` forbids in exactly those terms: **stop and report,
     * never silently resolve.** That was the defect, not the choice of fragment, and it
     * is recorded as such in `PLAN.md §1` under 2026-10-27.
     *
     * RESOLVED BY THE USER on 2026-10-27, in favour of the fragment, and `§3` row 3 and
     * `§3.1` row 3 are amended to say so: "never in a log, a URL path or query string, an
     * error body, or anywhere a server receives or logs it". The reasoning is in `§1`,
     * not re-argued here -- a camera must open the URL the QR encodes, so the token is in
     * a URL by construction and the only question is where, and the path or the query
     * would also defeat `§4 › Edge & ops` by making every scan its own CDN cache entry.
     *
     * WHAT THE FRAGMENT DOES AND DOES NOT BUY, stated plainly because the second half is
     * easy to forget. It keeps the token from every SERVER: no request line, no access
     * log, no `Referer`, no cache key. It does NOT hide it from the client -- it is in
     * `location.hash`, in `document.URL`, in browser history (which syncs), and readable
     * by any script the landing page loads. Those are real and they are bounded by Task 9
     * gate clauses, not by this comment: strip the fragment with `history.replaceState`
     * on first read, no third-party script on the landing origin, and a CSP that makes
     * one impossible. And over `http://` the whole property is void, which is why
     * `BootChecks::landingUrlIsSound()` refuses a non-https landing URL outside
     * local/testing (security S-5).
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
