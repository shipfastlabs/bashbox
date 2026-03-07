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
        if ($args === []) {
            return $this->failure();
        }

        $registry = $commandContext->registry;
        $lines = [];
        $notFound = false;

        foreach ($args as $arg) {
            if ($registry?->has($arg)) {
                $lines[] = sprintf('/usr/bin/%s', $arg);
            } else {
                $notFound = true;
            }
        }

        $output = implode(PHP_EOL, $lines);

        if ($output !== '') {
            $output .= PHP_EOL;
        }

        return $notFound ? $this->failure($output, 1) : $this->success($output);
    }
}
