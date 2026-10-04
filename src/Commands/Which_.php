<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Which_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'which';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $found = array_filter($args, fn (string $name): bool => $commandContext->registry?->has($name) === true);
        $output = implode('', array_map(fn (string $name): string => '/usr/bin/'.$name."\n", $found));

        // Exit status is 1 if no operands were given or any of them was not found.
        return $args !== [] && count($found) === count($args) ? $this->success($output) : $this->failure('', 1, $output);
    }
}
