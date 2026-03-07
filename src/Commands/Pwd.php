<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Pwd extends AbstractCommand
{
    public function getName(): string
    {
        return 'pwd';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        return $this->success($commandContext->cwd."\n");
    }
}
