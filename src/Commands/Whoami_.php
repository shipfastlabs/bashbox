<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Whoami_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'whoami';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $user = $commandContext->env['USER'] ?? 'root';

        return $this->success($user."\n");
    }
}
