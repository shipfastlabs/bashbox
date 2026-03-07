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

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        $user = $ctx->env['USER'] ?? 'root';

        return $this->success($user."\n");
    }
}
