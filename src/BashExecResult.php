<?php

declare(strict_types=1);

namespace BashBox;

final readonly class BashExecResult
{
    /**
     * @param  array<string, string>  $env
     */
    public function __construct(
        public string $stdout = '',
        public string $stderr = '',
        public int $exitCode = 0,
        public array $env = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }
}
