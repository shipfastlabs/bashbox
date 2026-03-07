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

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        if ($args === []) {
            return $this->failure("dirname: missing operand\n");
        }

        $path = $args[0];

        // Remove trailing slashes (but not if path is just "/")
        $path = rtrim($path, '/');

        if ($path === '') {
            return $this->success("/\n");
        }

        $lastSlash = strrpos($path, '/');

        if ($lastSlash === false) {
            // No slash at all - directory is "."
            return $this->success(".\n");
        }

        if ($lastSlash === 0) {
            // Slash at position 0 means root
            return $this->success("/\n");
        }

        $dir = substr($path, 0, $lastSlash);

        // Remove trailing slashes from the result
        $dir = rtrim($dir, '/');

        if ($dir === '') {
            $dir = '/';
        }

        return $this->success($dir."\n");
    }
}
