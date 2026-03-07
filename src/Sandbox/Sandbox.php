<?php

declare(strict_types=1);

namespace BashBox\Sandbox;

use BashBox\Bash;
use BashBox\BashOptions;

final readonly class Sandbox
{
    private function __construct(private Bash $bash) {}

    public static function create(SandboxOptions $options = new SandboxOptions): self
    {
        $bash = new Bash(new BashOptions(
            cwd: $options->cwd,
            env: $options->env,
            limits: $options->limits,
            initialFiles: $options->initialFiles,
        ));

        return new self($bash);
    }

    public function runCommand(string $command): SandboxCommandFinished
    {
        $result = $this->bash->exec($command);

        return new SandboxCommandFinished(
            stdout: $result->stdout,
            stderr: $result->stderr,
            exitCode: $result->exitCode,
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
