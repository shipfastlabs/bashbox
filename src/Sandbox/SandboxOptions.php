<?php

declare(strict_types=1);

namespace BashBox\Sandbox;

use BashBox\Limits;

final readonly class SandboxOptions
{
    /**
     * @param  array<string, string>  $env
     * @param  array<string, string>  $initialFiles
     */
    public function __construct(
        public string $cwd = '/home/user',
        public array $env = [],
        public Limits $limits = new Limits,
        public array $initialFiles = [],
    ) {}
}
