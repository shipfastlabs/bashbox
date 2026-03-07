<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class True_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'true';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        return $this->success();
    }
}
