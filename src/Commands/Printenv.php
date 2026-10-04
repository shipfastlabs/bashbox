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
        $env = $commandContext->env;

        if ($args === []) {
            return (new Env_)->execute([], $commandContext);
        }

        $found = array_values(array_filter($args, fn (string $name): bool => array_key_exists($name, $env)));
        $output = implode('', array_map(fn (string $name): string => $env[$name]."\n", $found));

        // Exit status is 1 if any requested variable is unset
        return count($found) === count($args) ? $this->success($output) : $this->failure('', 1, $output);
    }
}
