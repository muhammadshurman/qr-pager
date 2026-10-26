<?php

declare(strict_types=1);

namespace Guard;

final class Violation
{
    public function __construct(
        public readonly string $rule,
        public readonly string $file,
        public readonly int $line,
        public readonly string $detail,
    ) {}

    public function __toString(): string
    {
        return "{$this->file}:{$this->line}  [{$this->rule}] {$this->detail}";
    }
}
