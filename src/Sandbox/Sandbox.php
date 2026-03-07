<?php

declare(strict_types=1);

namespace BashBox\Sandbox;

use BashBox\Bash;
use BashBox\BashOptions;

final readonly class Sandbox
{
    private function __construct(private Bash $bash) {}

    public static function create(SandboxOptions $sandboxOptions = new SandboxOptions): self
    {
        $bash = new Bash(new BashOptions(
            cwd: $sandboxOptions->cwd,
            env: $sandboxOptions->env,
            limits: $sandboxOptions->limits,
            initialFiles: $sandboxOptions->initialFiles,
        ));

        return new self($bash);
    }

    public function runCommand(string $command): SandboxCommandFinished
    {
        $bashExecResult = $this->bash->exec($command);

        return new SandboxCommandFinished(
            stdout: $bashExecResult->stdout,
            stderr: $bashExecResult->stderr,
            exitCode: $bashExecResult->exitCode,
        );
    }

    /**
     * @param  array<string, string>  $files
     */
    public function writeFiles(array $files): void
    {
        foreach ($files as $path => $content) {
            $this->bash->writeFile($path, $content);
        }
    }

    public function readFile(string $path): string
    {
        return $this->bash->readFile($path);
    }

    public function mkDir(string $path): void
    {
        $this->bash->getFilesystem()->mkdir($path, ['recursive' => true]);
    }
}
