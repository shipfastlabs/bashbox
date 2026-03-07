<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class False_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'false';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        return $this->failure();
    }
}
