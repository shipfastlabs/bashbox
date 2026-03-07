<?php

declare(strict_types=1);

namespace BashBox;

use BashBox\Commands\CommandInterface;
use BashBox\Commands\CommandRegistry;
use BashBox\Commands\Curl_;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Filesystem\InMemoryFs;
use BashBox\Interpreter\Interpreter;
use BashBox\Interpreter\InterpreterState;
use BashBox\Network\SecureHttpClient;
use BashBox\Parser\Parser;

final readonly class Bash
{
    private FileSystemInterface $fileSystem;

    private CommandRegistry $commandRegistry;

    private ?SecureHttpClient $secureHttpClient;

    public function __construct(private BashOptions $bashOptions = new BashOptions)
    {
        $this->fileSystem = $this->bashOptions->fs ?? new InMemoryFs($this->bashOptions->initialFiles);
        $this->commandRegistry = new CommandRegistry;
        $this->commandRegistry->registerDefaults();

        if ($this->bashOptions->network instanceof \BashBox\Network\NetworkConfig) {
            $this->secureHttpClient = new SecureHttpClient($this->bashOptions->network);
            $this->commandRegistry->register(new Curl_);
        } else {
            $this->secureHttpClient = null;
        }

        // Ensure cwd and standard directories exist
        if (! $this->fileSystem->exists($this->bashOptions->cwd)) {
            $this->fileSystem->mkdir($this->bashOptions->cwd, ['recursive' => true]);
        }

        if (! $this->fileSystem->exists('/tmp')) {
            $this->fileSystem->mkdir('/tmp', ['recursive' => true]);
        }
    }

    public function exec(string $script, ?ExecOptions $execOptions = null): BashExecResult
    {
        $env = array_merge($this->bashOptions->env, $execOptions->env ?? []);
        $cwd = $execOptions->cwd ?? $this->bashOptions->cwd;
        $limits = $execOptions->limits ?? $this->bashOptions->limits;
        $stdin = $execOptions->stdin ?? '';

        $interpreterState = new InterpreterState(
            env: $env,
            cwd: $cwd,
            limits: $limits,
        );

        $interpreter = new Interpreter($interpreterState, $this->fileSystem, $this->commandRegistry, $this->secureHttpClient);

        $parser = new Parser;
        $scriptNode = $parser->parse($script);

        $execResult = $interpreter->executeScript($scriptNode, $stdin);

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
