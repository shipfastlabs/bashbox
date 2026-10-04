<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Realpath extends AbstractCommand
{
    public function getName(): string
    {
        return 'realpath';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $mustExist = in_array('-e', $args, true);
        // -m: resolve without requiring any component to exist.
        $missingOk = in_array('-m', $args, true);
        $quiet = in_array('-q', $args, true);
        $paths = array_values(array_filter($args, fn (string $arg): bool => ! in_array($arg, ['-e', '-m', '-s', '-q', '--'], true)));

        if ($paths === []) {
            return $this->failure("realpath: missing operand\nTry 'realpath --help' for more information.\n");
        }

        $output = '';
        $stderr = '';
        $failed = false;

        foreach ($paths as $path) {
            $resolved = $this->resolvePath($commandContext, $path);

            try {
                $output .= $commandContext->fs->realpath($resolved)."\n";
            } catch (RuntimeException) {
                // GNU's default only requires the parent to exist.
                if ($missingOk || (! $mustExist && $commandContext->fs->exists(dirname($resolved)))) {
                    $output .= $resolved."\n";
                } else {
                    $stderr .= $quiet ? '' : "realpath: {$path}: No such file or directory\n";
                    $failed = true;
                }
            }
        }

        return $failed ? $this->failure($stderr, 1, $output) : $this->success($output);
    }
}
