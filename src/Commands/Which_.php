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
        $found = array_filter(array_map(
            fn (string $name): ?string => $commandContext->registry?->resolve($name, $commandContext->fs, $commandContext->cwd, $commandContext->env),
            $args,
        ), fn (?string $path): bool => $path !== null);
        $output = implode('', array_map(fn (string $path): string => $path."\n", $found));

        // Exit status is 1 if no operands were given or any of them was not found.
        return $args !== [] && count($found) === count($args) ? $this->success($output) : $this->failure('', 1, $output);
    }
}
