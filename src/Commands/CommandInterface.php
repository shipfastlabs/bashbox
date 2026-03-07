<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

interface CommandInterface
{
    public function getName(): string;

    /**
     * @param  list<string>  $args
     */
    public function execute(array $args, CommandContext $commandContext): ExecResult;
}
