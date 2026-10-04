<?php

declare(strict_types=1);

namespace BashBox;

use BashBox\Commands\CommandInterface;
use BashBox\Commands\CommandRegistry;
use BashBox\Commands\Curl_;
use BashBox\Filesystem\DevFs;
use BashBox\Filesystem\DiskQuota;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Filesystem\InMemoryFs;
use BashBox\Interpreter\Interpreter;
use BashBox\Interpreter\InterpreterState;
use BashBox\Network\SecureHttpClient;

final readonly class Bash
{
    private FileSystemInterface $fileSystem;

    private CommandRegistry $commandRegistry;

    private ?SecureHttpClient $secureHttpClient;

    public function __construct(private BashOptions $bashOptions = new BashOptions)
    {
        $limits = $this->bashOptions->limits;
        $diskQuota = new DiskQuota($limits->maxFilesystemBytes, $limits->maxFilesystemFiles);
        $this->fileSystem = new DevFs($this->bashOptions->fs ?? new InMemoryFs($this->bashOptions->initialFiles, $diskQuota), $diskQuota);
        $this->commandRegistry = new CommandRegistry;
        $this->commandRegistry->registerDefaults();

        if ($this->bashOptions->network instanceof \BashBox\Network\NetworkConfig) {
            $this->secureHttpClient = new SecureHttpClient($this->bashOptions->network);
            $this->commandRegistry->register(new Curl_);
        } else {
            $this->secureHttpClient = null;
        }

        $this->ensureDirectory($this->bashOptions->cwd);
        $this->ensureDirectory('/tmp');
    }

    private function ensureDirectory(string $path): void
    {
        if (! $this->fileSystem->exists($path)) {
            $this->fileSystem->mkdir($path, ['recursive' => true]);
        }
    }

    public function exec(string $script, ?ExecOptions $execOptions = null): BashExecResult
    {
        $env = array_merge($this->bashOptions->env, $execOptions->env ?? []);
        $cwd = $execOptions->cwd ?? $this->bashOptions->cwd;
        $limits = $execOptions->limits ?? $this->bashOptions->limits;
        $stdin = $execOptions->stdin ?? '';
        $this->ensureDirectory($cwd);

        $interpreterState = new InterpreterState(
            env: $env,
            cwd: $cwd,
            limits: $limits,
        );

        $interpreter = new Interpreter($interpreterState, $this->fileSystem, $this->commandRegistry, $this->secureHttpClient);

        $execResult = $interpreter->executeScript($script, $stdin);

        return new BashExecResult(
            stdout: $execResult->stdout,
            stderr: $execResult->stderr,
            exitCode: $execResult->exitCode,
            env: $interpreterState->env,
        );
    }

    public function registerCommand(CommandInterface $command): void
    {
        $this->commandRegistry->register($command);
    }

    public function readFile(string $path): string
    {
        return $this->fileSystem->readFile($path);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->fileSystem->writeFile($path, $content);
    }

    public function getFilesystem(): FileSystemInterface
    {
        return $this->fileSystem;
    }
}
