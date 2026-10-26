<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use App\Platform\Database\DriverError;
use App\Platform\Database\UnsafeDatabaseRoleException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Redaction at the producer, not only at the sink.
 *
 * The RedactingProcessor tap covers every channel in config/logging.php, which is the
 * sink side. It does not cover Laravel's EMERGENCY logger: LogManager::get() falls back
 * to createEmergencyLogger() when a channel cannot be resolved, and that logger is
 * built directly rather than through the manager, so no tap reaches it. A
 * misconfigured channel would therefore log the record Laravel built -- which for a
 * query failure is the statement with its bindings interpolated.
 *
 * Registered as the reportable callback and returning false, which stops Laravel's own
 * reporting (Handler::reportThrowable), this builds the record itself and hands the
 * logger text that is already redacted. Whichever logger ends up receiving it, there is
 * nothing left in it to strip.
 *
 * It keeps what an operator acts on. For R-021 specifically: the database role and the
 * reasons it was refused are in the log and absent from the response body, which is
 * that row's acceptance condition stated exactly.
 */
final class ReportRedactedException
{
    public function __construct(private readonly Redactor $redactor = new Redactor) {}

    public function __invoke(Throwable $error): bool
    {
        $context = [
            'exception_class' => $error::class,
            'sqlstate' => DriverError::sqlState($error),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];

        // R-021's "the role and reasons present in the log": carried as typed
        // properties on the exception precisely so a handler can log them and render
        // nothing. The role names are part of the data contract (§2.1), not secrets.
        if ($error instanceof UnsafeDatabaseRoleException) {
            $context['database_role'] = $error->role;
            $context['refusal_reasons'] = $error->reasons;
        }

        Log::error($this->redactor->message($error->getMessage()), $this->redactor->context($context));

        return false;
    }
}
