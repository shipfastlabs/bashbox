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
        [$contents, $stderr] = $this->readFiles($commandContext, $args, "rev: cannot open %s: %s\n", null);

        $output = (string) preg_replace_callback(
            '/[^\n]+/',
            fn (array $m): string => implode('', array_reverse(mb_str_split($m[0]))),
            implode('', $contents),
        );

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }
}
