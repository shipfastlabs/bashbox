<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Hostname_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'hostname';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $hostname = $commandContext->env['HOSTNAME'] ?? 'localhost';

        return $this->success($hostname."\n");
    }
}
