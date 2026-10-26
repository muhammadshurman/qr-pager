<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Creates the database and the three application roles, and applies the per-role
 * ALTER ROLE timeouts (PLAN.md §2.1).
 *
 * This is the one piece that needs the cluster superuser, which is why it is a
 * separate command and not a migration: qrpager_owner is deliberately
 * NOCREATEROLE, and migrations run as qrpager_owner.
 *
 * Idempotent, and serialised with pg_advisory_lock because role bootstrap races
 * when suites run in parallel (PROJECT_INSTRUCTIONS.md §4 › Postgres traps).
 */
class RoleBootstrapper
{
    /** Settings this bootstrapper is allowed to ALTER ROLE ... SET. */
    private const SETTINGS = ['statement_timeout', 'lock_timeout', 'idle_in_transaction_session_timeout'];

    /** Role key => the connection whose credentials belong to that role. */
    private const CREDENTIALS = ['owner' => 'owner', 'app' => 'app', 'worker' => 'worker'];

    /** @return list<string> */
    public function run(): array
    {
        $bootstrap = DB::connection(DatabaseRoles::connection('bootstrap'));
        $key = $this->lockKey();

        $bootstrap->select('select pg_advisory_lock(?)', [$key]);

        try {
            $report = [];

            foreach (array_keys(self::CREDENTIALS) as $role) {
                $report[] = $this->ensureRole($bootstrap, $role);
            }

            foreach ($this->applyTimeouts($bootstrap) as $line) {
                $report[] = $line;
            }

            $report[] = $this->ensureDatabase($bootstrap);

            foreach ($this->grantAccess() as $line) {
                $report[] = $line;
            }

            return $report;
        } finally {
            $bootstrap->select('select pg_advisory_unlock(?)', [$key]);
        }
    }

    private function ensureRole(ConnectionInterface $bootstrap, string $roleKey): string
    {
        $name = DatabaseRoles::name($roleKey);
        $limit = DatabaseRoles::connectionLimit($roleKey);
        $verifier = $this->scramVerifier($this->password($roleKey));

        $exists = $this->exists($bootstrap, 'select exists(select 1 from pg_roles where rolname = ?) as present', $name);

        // The limit is an int straight from config, so it is interpolated rather than
        // quoted: CONNECTION LIMIT takes an integer and %L would hand it a string.
        $template = ($exists ? 'ALTER' : 'CREATE')
            .' ROLE %I WITH LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT'
            ." CONNECTION LIMIT {$limit} PASSWORD %L";

        // ALTER on every run, so the attributes converge: a role that was granted
        // SUPERUSER or BYPASSRLS by hand loses it the next time this runs.
        $this->withoutLeakingCredentials(
            $name,
            fn (): string => $this->format($bootstrap, $template, [$name, $verifier]),
            fn (string $statement): bool => $bootstrap->statement($statement),
        );

        // Re-asserted on every run, so a role that was tampered with converges back.
        return ($exists ? 'role updated: ' : 'role created: ').$name;
    }

    /**
     * The SCRAM-SHA-256 verifier Postgres would have built from this password, built
     * here instead so the plaintext never crosses the wire (F4, security review of
     * task-1). ALTER ROLE ... PASSWORD '<plaintext>' puts the password in the
     * statement text, which log_statement captures, and in a bound parameter, which
     * Postgres logs on error and Laravel interpolates into QueryException::getMessage()
     * -- two breaches of "no secrets in logs" (PROJECT_INSTRUCTIONS.md §4).
     *
     * Shape and defaults follow Postgres itself:
     * SCRAM-SHA-256$<iterations>:<salt>$<StoredKey>:<ServerKey>, 4096 iterations,
     * 16-byte salt. The verifier is what pg_authid stores either way.
     */
    private function scramVerifier(string $password): string
    {
        // libpq applies SASLprep to the password at authentication time, and SASLprep
        // is the identity only for printable ASCII. Rather than ship a partial
        // SASLprep, refuse what it would rewrite: a verifier built from an
        // unnormalised password authenticates nobody. The password is never echoed.
        if (preg_match('/\A[\x20-\x7E]+\z/', $password) !== 1) {
            throw new RuntimeException(
                'A database role password must be printable ASCII. The SCRAM verifier is '
                .'computed locally so the plaintext never reaches the server, and that is '
                .'only sound for the range where SASLprep normalisation is a no-op.'
            );
        }

        $iterations = 4096;
        $salt = random_bytes(16);

        $saltedPassword = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $saltedPassword, true);
        $storedKey = hash('sha256', $clientKey, true);
        $serverKey = hash_hmac('sha256', 'Server Key', $saltedPassword, true);

        return sprintf(
            'SCRAM-SHA-256$%d:%s$%s:%s',
            $iterations,
            base64_encode($salt),
            base64_encode($storedKey),
            base64_encode($serverKey),
        );
    }

    /**
     * A role statement carries that role's password verifier, so none of it may reach
     * an exception message: Laravel interpolates bindings into
     * QueryException::getMessage(), and a chained previous exception would carry the
     * statement into any rendered stack trace (PROJECT_INSTRUCTIONS.md §4). The
     * original is deliberately not chained -- the SQLSTATE is what survives.
     *
     * TWO PHASES, TWO MESSAGES (R-013). The old single message said "could not create
     * or alter role" for everything it caught, including failures where no role
     * statement was ever sent: format() runs a `select format(...)` of its own, and its
     * RuntimeException has code 0, which the formatter then suppressed -- so a
     * connectivity or configuration failure reported the role name and nothing else.
     * Building and executing are separated here because the distinction is the
     * diagnosis: "the statement was never built" sends an operator to the formatter and
     * the connection, "the statement failed" sends them to the role and the cluster.
     *
     * Building stays INSIDE the redaction, which is not an accident: format()'s
     * arguments include the verifier as a bound parameter, so a QueryException from
     * that select carries the credential in its own message. The phase is tracked by
     * structure rather than by inspecting the throwable, because a QueryException from
     * format()'s select and one from the ALTER ROLE are indistinguishable after the
     * fact.
     *
     * Both messages read the real SQLSTATE through DriverError. getCode() returns the
     * integer 7 for every connection-level failure, where the SQLSTATE is 08006.
     *
     * @template TReturn
     *
     * @param  Closure(): string  $build
     * @param  Closure(string): TReturn  $execute
     * @return TReturn
     */
    private function withoutLeakingCredentials(string $role, Closure $build, Closure $execute): mixed
    {
        try {
            $statement = $build();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not build the statement that creates or alters role [{$role}]"
                .self::sqlStateClause($e)
                .'. Nothing was sent to the cluster. The template and its arguments are withheld: '
                .'they carry the role password verifier.'
            );
        }

        try {
            return $execute($statement);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not create or alter role [{$role}]"
                .self::sqlStateClause($e)
                .'. The statement was sent and failed. Its text is withheld: it carries the role '
                .'password verifier.'
            );
        }
    }

    private static function sqlStateClause(Throwable $error): string
    {
        $state = DriverError::sqlStateForMessage($error);

        return $state === '' ? '' : " (SQLSTATE {$state})";
    }

    /** @return list<string> */
    private function applyTimeouts(ConnectionInterface $bootstrap): array
    {
        $report = [];

        foreach (DatabaseRoles::timeouts() as $roleKey => $settings) {
            $name = DatabaseRoles::name($roleKey);

            foreach ($settings as $setting => $value) {
                if (! in_array($setting, self::SETTINGS, true)) {
                    throw new RuntimeException("[{$setting}] is not a role setting this bootstrapper may change.");
                }

                // The setting name is checked against SETTINGS above, so it is one of
                // three known identifiers rather than anything that arrived from outside.
                $bootstrap->statement(
                    $this->format($bootstrap, "ALTER ROLE %I SET {$setting} = %L", [$name, $value]),
                );
                $report[] = "{$name}: {$setting} = {$value}";
            }
        }

        return $report;
    }

    private function ensureDatabase(ConnectionInterface $bootstrap): string
    {
        $database = $this->targetDatabase();

        if ($this->exists($bootstrap, 'select exists(select 1 from pg_database where datname = ?) as present', $database)) {
            return 'database present: '.$database;
        }

        // CREATE DATABASE cannot run inside a transaction block, which is why the
        // bootstrap connection points at the maintenance database.
        $bootstrap->statement($this->format($bootstrap, 'CREATE DATABASE %I', [$database]));

        return 'database created: '.$database;
    }

    /** @return list<string> */
    private function grantAccess(): array
    {
        $database = $this->targetDatabase();

        // Connected as root to the database being bootstrapped, which is not
        // necessarily the one the root connection is configured for: the grants
        // below are per-database, so landing them on the wrong one would leave the
        // application unable to connect while reporting success.
        $root = DB::connectUsing('pgsql_bootstrap_target', array_merge(
            $this->rootConfig(),
            ['database' => $database, 'url' => null],
        ), true);
        $owner = DatabaseRoles::name('owner');
        $app = DatabaseRoles::name('app');
        $worker = DatabaseRoles::name('worker');

        // Postgres builds each statement with its own identifier quoting, and each
        // one is executed on its own because a multi-statement string cannot be
        // prepared.
        $statements = [
            ['REVOKE ALL ON DATABASE %I FROM PUBLIC', [$database]],
            ['GRANT CONNECT ON DATABASE %I TO %I, %I, %I', [$database, $owner, $app, $worker]],
            ['REVOKE CREATE ON SCHEMA public FROM PUBLIC', []],
            ['GRANT USAGE ON SCHEMA public TO %I, %I, %I', [$owner, $app, $worker]],
            ['GRANT CREATE ON SCHEMA public TO %I', [$owner]],
        ];

        try {
            foreach ($statements as [$template, $arguments]) {
                $root->statement($this->format($root, $template, $arguments));
            }
        } finally {
            DB::purge('pgsql_bootstrap_target');
        }

        return [
            "database {$database}: CONNECT revoked from PUBLIC, granted to {$owner}, {$app}, {$worker}",
            "schema public: CREATE revoked from PUBLIC, granted to {$owner} only",
        ];
    }

    private function exists(ConnectionInterface $connection, string $sql, string $binding): bool
    {
        $row = $connection->selectOne($sql, [$binding]);

        return is_object($row) && (bool) ($row->present ?? false);
    }

    /**
     * Let Postgres do the identifier and literal quoting, so nothing -- least of all
     * a credential -- passes through string concatenation on the way to the server.
     *
     * @param  list<string>  $arguments
     */
    private function format(ConnectionInterface $connection, string $template, array $arguments): string
    {
        if ($arguments === []) {
            return $template;
        }

        $placeholders = implode(', ', array_fill(0, count($arguments), '?::text'));

        $row = $connection->selectOne("select format(?::text, {$placeholders}) as sql", [$template, ...$arguments]);

        if (! is_object($row) || ! is_string($row->sql ?? null)) {
            throw new RuntimeException('Postgres did not return a formatted statement.');
        }

        return $row->sql;
    }

    /** @return array<string, mixed> */
    private function rootConfig(): array
    {
        $config = config('database.connections.'.DatabaseRoles::connection('root'));

        if (! is_array($config)) {
            throw new RuntimeException('The root database connection is not configured.');
        }

        /** @var array<string, mixed> */
        return $config;
    }

    private function password(string $roleKey): string
    {
        $connection = DatabaseRoles::connection(self::CREDENTIALS[$roleKey]);
        $password = config("database.connections.{$connection}.password");

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                "No password is configured for role [{$roleKey}] (connection [{$connection}]). "
                .'Set it in the environment before bootstrapping.'
            );
        }

        return $password;
    }

    private function targetDatabase(): string
    {
        $database = config('database.connections.'.DatabaseRoles::connection('app').'.database');

        if (! is_string($database) || $database === '') {
            throw new RuntimeException('No target database is configured.');
        }

        return $database;
    }

    private function lockKey(): int
    {
        $key = config('tenancy.bootstrap_lock_key');

        return is_int($key) ? $key : (int) (is_numeric($key) ? $key : 0);
    }
}
