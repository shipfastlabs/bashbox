<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Uniq_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'uniq';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        $parsed = $this->parseFlags($args, [
            'c' => false,
            'd' => false,
            'u' => false,
        ]);

        $flags = $parsed['flags'];
        $files = $parsed['args'];

        $input = '';

        if ($files !== []) {
            $path = $this->resolvePath($ctx, $files[0]);

            try {
                $input = $ctx->fs->readFile($path);
            } catch (RuntimeException) {
                return $this->failure("uniq: {$files[0]}: No such file or directory\n");
            }
        } else {
            $input = $ctx->stdin;
        }

        if ($input === '') {
            return $this->success('');
        }

        ['lines' => $lines] = $this->splitLines($input);

        // Group consecutive identical lines
        /** @var list<array{line: string, count: int}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            if ($groups !== [] && $groups[count($groups) - 1]['line'] === $line) {
                $groups[count($groups) - 1]['count']++;
            } else {
                $groups[] = ['line' => $line, 'count' => 1];
            }
        }

        $output = '';

        foreach ($groups as $group) {
            if ($flags['d'] && $group['count'] < 2) {
                continue;
            }

            if ($flags['u'] && $group['count'] > 1) {
                continue;
            }

            if ($flags['c']) {
                $output .= sprintf('%7d %s', $group['count'], $group['line'])."\n";
            } else {
                $output .= $group['line']."\n";
            }
        }

        return $this->success($output);
    }
}
