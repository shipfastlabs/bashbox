<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Dirname_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'dirname';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'z', ['zero' => ['z', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $names] = $parsed;

        if ($names === []) {
            return $this->usageError('missing operand');
        }

        $end = isset($flags['z']) ? "\0" : "\n";

        return $this->success(implode('', array_map(fn (string $name): string => $this->directory($name).$end, $names)));
    }

    private function directory(string $path): string
    {
        $trimmed = rtrim($path, '/');
        $lastSlash = strrpos($trimmed, '/');

        if ($lastSlash === false) {
            // "/" itself trims to "", a bare name has no directory part.
            return $trimmed === '' && $path !== '' ? '/' : '.';
        }

        return rtrim(substr($trimmed, 0, $lastSlash), '/') ?: '/';
    }
}
