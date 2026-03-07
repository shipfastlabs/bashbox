<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Printenv extends AbstractCommand
{
    public function getName(): string
    {
        return 'printenv';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        if ($args === []) {
            // Print all environment variables
            $output = '';

            foreach ($commandContext->env as $key => $value) {
                $output .= sprintf('%s=%s%s', $key, $value, PHP_EOL);
            }

            return $this->success($output);
        }

        // Print specific variable(s)
        $output = '';
        $found = false;

        foreach ($args as $arg) {
            if (array_key_exists($arg, $commandContext->env)) {
                $output .= $commandContext->env[$arg]."\n";
                $found = true;
            }
        }

        if (! $found) {
            return $this->failure('', exitCode: 1);
        }

        return $this->success($output);
    }
}
