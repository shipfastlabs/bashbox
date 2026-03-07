<?php

declare(strict_types=1);

namespace BashBox\Sandbox;

final readonly class SandboxCommandFinished
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
    ) {}
}
