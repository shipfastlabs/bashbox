<?php

declare(strict_types=1);

namespace BashBox\Commands\Input;

use BashBox\Commands\CommandContext;

interface InputReaderInterface
{
    /**
     * @param  list<string>  $files  File paths or '-' for stdin
     */
    public function read(array $files, CommandContext $commandContext): InputContent;
}
