<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Applies Redactor to every record, on every channel.
 *
 * `LogRecord::$message` and `$context` are readonly in Monolog 3, so the record is
 * rebuilt with `with()` -- verified against monolog/monolog 3.12.1 in the phase-1
 * research, along with the `__invoke(LogRecord): LogRecord` signature.
 */
final class RedactingProcessor implements ProcessorInterface
{
    public function __construct(private readonly Redactor $redactor = new Redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->message($record->message),
            context: $this->redactor->context($record->context),
            extra: $this->redactor->context($record->extra),
        );
    }
}
