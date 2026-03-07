<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Basename_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'basename';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        if ($args === []) {
            return $this->failure("basename: missing operand\n");
        }

        $path = $args[0];
        $suffix = $args[1] ?? '';

        // Remove trailing slashes
        $path = rtrim($path, '/');

        if ($path === '') {
            return $this->success("/\n");
        }

        // Get the last component
        $lastSlash = strrpos($path, '/');
        $base = $lastSlash !== false ? substr($path, $lastSlash + 1) : $path;

        // Remove suffix if specified and the name is not just the suffix
        if ($suffix !== '' && $base !== $suffix && str_ends_with($base, $suffix)) {
            $base = substr($base, 0, -strlen($suffix));
        }

        return $this->success($base."\n");
    }
}
