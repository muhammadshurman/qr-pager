<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use App\Platform\Database\DriverError;

/**
 * The redaction rules, with no Monolog in sight so they can be reasoned about and
 * tested on their own.
 *
 * `§4 › Edge & ops`: "No secrets in logs, or in an error response body, at any
 * APP_DEBUG setting. Redaction tested against captured log output and against captured
 * response bodies. Stack traces never carry SQL or bound parameters."
 *
 * Measured at `7f1c8f6`, which is what this class exists for: one
 * `Log::error($e->getMessage(), ['exception' => $e])` for a real QueryException wrote
 * **22 lines** containing the statement and the bound uuid interpolated into it.
 * Laravel builds that message itself, in Connection::run()'s exception path, so there
 * is no way to log a query failure without redaction in between.
 *
 * What is kept, deliberately: the SQLSTATE, the driver's own description, and the
 * relation name inside it. None of those is a secret -- §2 publishes the schema -- and
 * an operator with the SQLSTATE and no statement can still act. What goes is the
 * statement text and anything bound into it.
 */
final class Redactor
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * Context and extra keys whose VALUE is dropped whatever it looks like.
     *
     * Matched as a substring of the lower-cased key, so `db_password`,
     * `DB_OWNER_PASSWORD` and `passwordConfirmation` are all covered.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = [
        'password', 'secret', 'token', 'credential', 'verifier', 'authorization',
        'api_key', 'apikey', 'private_key', 'dsn', 'bindings', 'sql', 'query',
    ];

    public function message(string $message): string
    {
        // 1. Laravel's own tail. Connection::run() appends
        //    " (Connection: x, Host: h, Port: p, Database: d, SQL: <statement with the
        //    bindings interpolated>)" -- the statement AND the bound values, in one
        //    clause, to the end of the message.
        $message = (string) preg_replace('/\s*\(Connection:.*$/s', ' '.self::PLACEHOLDER, $message);

        // 2. Postgres's own DETAIL, HINT and CONTEXT lines. This is the one that is
        //    easy to miss and the worst of them: a constraint violation's DETAIL
        //    carries "Failing row contains (...)" -- EVERY COLUMN VALUE of the row,
        //    including the ones that were bound. Measured against the real
        //    users_email_is_lowercase constraint: the uuid, the name and the email all
        //    came back in the driver's own description, BEFORE the (Connection: ...)
        //    clause above. Stripping only that clause left a full row dump in the log,
        //    which this file's own test caught.
        $message = (string) preg_replace('/\R?\s*(DETAIL|HINT|CONTEXT):.*$/s', ' '.self::PLACEHOLDER, $message);

        // 3. Postgres echoes the offending statement back as "LINE n: <statement>",
        //    followed by a caret line pointing into it. That echo is SQL even when the
        //    clause above is absent -- it arrives inside the driver's own message.
        $message = (string) preg_replace('/\R\s*LINE \d+:.*$/s', ' '.self::PLACEHOLDER, $message);

        // 4. The SCRAM verifier. Not a password, but the complete pg_authid secret:
        //    ServerKey completes a handshake AS THE SERVER and StoredKey allows offline
        //    recovery (R-009). It can only arrive here through a statement that was
        //    already withheld, which makes this the backstop rather than the control.
        $message = (string) preg_replace('/SCRAM-SHA-256\$[^\s\'"]+/', self::PLACEHOLDER, $message);

        // 5. A connection string's password, in either spelling libpq accepts.
        $message = (string) preg_replace('/\b(password|pgpassword)\s*=\s*\S+/i', '$1='.self::PLACEHOLDER, $message);

        return $message;
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public function context(array $context, int $depth = 0): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $safe[$key] = self::PLACEHOLDER;

                continue;
            }

            $safe[$key] = $this->value($value, $depth);
        }

        return $safe;
    }

    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SECRET_KEYS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if ($value instanceof \Throwable) {
            return $this->throwable($value);
        }

        if (is_string($value)) {
            return $this->message($value);
        }

        if (is_array($value) && $depth < 6) {
            return $this->context($value, $depth + 1);
        }

        if (is_array($value)) {
            return self::PLACEHOLDER;
        }

        return $value;
    }

    /**
     * A throwable becomes a flat summary, and the trace is DROPPED.
     *
     * Monolog's normaliser renders a Throwable in `context` as its message plus its
     * trace, which is how one log call produced 22 lines carrying the statement twice.
     * The summary keeps what an operator acts on -- class, SQLSTATE, file and line, and
     * the redacted message -- and the chain of previous exceptions, each the same
     * shape.
     *
     * @return array<string, mixed>
     */
    private function throwable(\Throwable $error): array
    {
        $summary = [
            'class' => $error::class,
            'message' => $this->message($error->getMessage()),
            'sqlstate' => DriverError::sqlState($error),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];

        $previous = $error->getPrevious();

        if ($previous !== null) {
            $summary['previous'] = [
                'class' => $previous::class,
                'message' => $this->message($previous->getMessage()),
            ];
        }

        return $summary;
    }
}
