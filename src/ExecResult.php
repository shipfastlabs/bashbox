<?php

declare(strict_types=1);

namespace BashBox;

final readonly class ExecResult
{
    public function __construct(
        public string $stdout = '',
        public string $stderr = '',
        public int $exitCode = 0,
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }
}
