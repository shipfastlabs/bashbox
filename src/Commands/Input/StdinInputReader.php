<?php

declare(strict_types=1);

namespace BashBox\Commands\Input;

use BashBox\Commands\CommandContext;

final class StdinInputReader implements InputReaderInterface
{
    public function read(array $files, CommandContext $commandContext): InputContent
    {
        $content = $commandContext->stdin;

        return new InputContent(
            content: $content,
            files: [
                ['name' => '-', 'content' => $content],
            ],
            source: InputSource::STDIN,
        );
    }
}
