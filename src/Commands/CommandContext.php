<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Interpreter\StdinStream;
use BashBox\Limits;
use BashBox\Network\SecureHttpClient;
use Closure;

final class CommandContext
{
    /** Drained from the shared stream on first access, so commands that never read stdin leave it for the next one. */
    public string $stdin {
        get => $this->stdinData ??= $this->stdinStream->readAll();
    }

    private readonly StdinStream $stdinStream;

    private ?string $stdinData = null;

    /**
     * @param  array<string, string>  $env
     * @param  Closure(string, array<string, string>|null=, string|StdinStream|null=): ExecResult  $exec
     */
    public function __construct(
        public readonly FileSystemInterface $fs,
        public readonly string $cwd,
        public readonly array $env,
        string|StdinStream $stdin,
        public readonly Limits $limits,
        public readonly Closure $exec,
        public readonly ?SecureHttpClient $fetch = null,
        public readonly ?CommandRegistry $registry = null,
        public readonly int $umask = 0o022,
    ) {
        $this->stdinStream = is_string($stdin) ? new StdinStream($stdin) : $stdin;
    }
}
