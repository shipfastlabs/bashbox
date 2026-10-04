<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Mkdir_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'mkdir';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'p', ['parents' => ['p', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;

        if ($operands === []) {
            return $this->usageError('missing operand');
        }

        $stderr = '';

        foreach ($operands as $operand) {
            try {
                $commandContext->fs->mkdir($this->resolvePath($commandContext, $operand), ['recursive' => isset($flags['p'])]);
            } catch (RuntimeException $e) {
                $stderr .= sprintf("mkdir: cannot create directory '%s': %s\n", $operand, $this->describeError($e));
            }
        }

        return $stderr === '' ? $this->success() : $this->failure($stderr);
    }
}
