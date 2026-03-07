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
    private FileSystemInterface $fs;

    private CommandRegistry $registry;

    private ?SecureHttpClient $httpClient;

    public function __construct(private BashOptions $options = new BashOptions)
    {
        $this->fs = $this->options->fs ?? new InMemoryFs($this->options->initialFiles);
        $this->registry = new CommandRegistry;
        $this->registry->registerDefaults();

        if ($this->options->network !== null) {
            $this->httpClient = new SecureHttpClient($this->options->network);
            $this->registry->register(new Curl_);
        } else {
            $this->httpClient = null;
        }

        // Ensure cwd and standard directories exist
        if (! $this->fs->exists($this->options->cwd)) {
            $this->fs->mkdir($this->options->cwd, ['recursive' => true]);
        }

        if (! $this->fs->exists('/tmp')) {
            $this->fs->mkdir('/tmp', ['recursive' => true]);
        }
    }

    public function exec(string $script, ?ExecOptions $options = null): BashExecResult
    {
        $env = array_merge($this->options->env, $options->env ?? []);
        $cwd = $options->cwd ?? $this->options->cwd;
        $limits = $options->limits ?? $this->options->limits;
        $stdin = $options->stdin ?? '';

        $state = new InterpreterState(
            env: $env,
            cwd: $cwd,
            limits: $limits,
        );

        $interpreter = new Interpreter($state, $this->fs, $this->registry, $this->httpClient);

        $parser = new Parser;
        $ast = $parser->parse($script);

        $result = $interpreter->executeScript($ast, $stdin);

        return new BashExecResult(
            stdout: $result->stdout,
            stderr: $result->stderr,
            exitCode: $result->exitCode,
            env: $state->env,
        );
    }

    public function registerCommand(CommandInterface $command): void
    {
        $this->registry->register($command);
    }

    public function readFile(string $path): string
    {
        return $this->fs->readFile($path);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->fs->writeFile($path, $content);
    }

    public function getFilesystem(): FileSystemInterface
    {
        return $this->fs;
    }
}
