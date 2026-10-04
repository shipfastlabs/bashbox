<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Yes extends AbstractCommand
{
    public function getName(): string
    {
        return 'yes';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $line = ($args === [] ? 'y' : implode(' ', $args))."\n";

        // Limitation: pipeline stages run to completion, not streamed, so the "infinite" stream stops
        // at the loop-iteration limit or the output-size limit, whichever comes first.
        $count = min($commandContext->limits->maxLoopIterations, intdiv($commandContext->limits->maxOutputSize, strlen($line)));

        return $this->success(str_repeat($line, $count));
    }
}
