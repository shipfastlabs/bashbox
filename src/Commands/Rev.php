<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Rev extends AbstractCommand
{
    public function getName(): string
    {
        return 'rev';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $input = $commandContext->stdin;

        if ($input === '') {
            return $this->success();
        }

        $lines = explode("\n", $input);
        $output = '';
        $lastIndex = count($lines) - 1;

        foreach ($lines as $i => $line) {
            $output .= strrev($line);

            if ($i < $lastIndex) {
                $output .= "\n";
            }
        }

        return $this->success($output);
    }
}
