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

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        if ($args === []) {
            return $this->failure();
        }

        $output = '';

        foreach ($args as $name) {
            $output .= sprintf('/usr/bin/%s%s', $name, PHP_EOL);
        }

        return $this->success($output);
    }
}
