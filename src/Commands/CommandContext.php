<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Filesystem\FileSystemInterface;
use BashBox\Limits;
use BashBox\Network\SecureHttpClient;
use Closure;

final readonly class CommandContext
{
    /**
     * @param  array<string, string>  $env
     * @param  Closure(string): \BashBox\ExecResult  $exec
     */
    public function __construct(
        public FileSystemInterface $fs,
        public string $cwd,
        public array $env,
        public string $stdin,
        public Limits $limits,
        public Closure $exec,
        public ?SecureHttpClient $fetch = null,
    ) {}
}
