<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

/** `fold`, counting as in the C locale: -c (characters) is the same as columns. */
final class Fold extends AbstractCommand
{
    private const array LONG = [
        'bytes' => ['b', false],
        'characters' => ['c', false],
        'spaces' => ['s', false],
        'width' => ['w', true],
    ];

    public function getName(): string
    {
        return 'fold';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        // Obsolete `-N` for `-w N`, which may follow other options in a bundle.
        foreach ($args as $i => $arg) {
            if (preg_match('/^-[bcs]*w$|^--w[a-z]*$/', $args[$i - 1] ?? '') !== 1) {
                $args[$i] = (string) preg_replace('/^-([bcs]*)(\d)/', '-$1w$2', $arg);
            }
        }
        $parsed = $this->getopt($args, 'bcsw:', self::LONG);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $files] = $parsed;
        $width = $flags['w'] ?? '80';

        if (preg_match('/^\s*\+?\d+$/', $width) !== 1) {
            return $this->failure("fold: invalid number of columns: '{$width}'\n");
        }

        $columns = filter_var(ltrim($width), FILTER_VALIDATE_INT);

        if ($columns === false || $columns < 1) {
            return $this->failure("fold: invalid number of columns: '{$width}': Result too large\n");
        }

        [$contents, $stderr] = $this->readFiles($commandContext, $files, "fold: %s: %s\n");
        $output = '';

        foreach ($contents as $content) {
            $output .= $this->fold($content, $columns, isset($flags['b']), isset($flags['s']));
            $this->checkOutputSize($commandContext, strlen($output));
        }

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }

    private function fold(string $content, int $width, bool $bytes, bool $spaces): string
    {
        $output = '';
        $line = '';
        $column = 0;

        foreach (str_split($content) as $char) {
            if ($char === "\n") {
                $output .= $line."\n";
                $line = '';
                $column = 0;

                continue;
            }

            while (($column = $this->advance($column, $char, $bytes)) > $width) {
                // With -s the line breaks after its last blank, and what follows starts the next one.
                if ($spaces && preg_match('/^.*[ \t]/s', $line, $m) === 1) {
                    $output .= $m[0]."\n";
                    $line = substr($line, strlen($m[0]));
                    $column = array_reduce(str_split($line), fn (int $col, string $c): int => $this->advance($col, $c, $bytes), 0);

                    continue;
                }

                // A character too wide for an empty line goes on it anyway.
                if ($line === '') {
                    break;
                }

                $output .= $line."\n";
                $line = '';
                $column = 0;
            }

            $line .= $char;
        }

        return $output.$line;
    }

    private function advance(int $column, string $char, bool $bytes): int
    {
        return match (true) {
            $bytes => $column + 1,
            $char === "\x08" => max(0, $column - 1),
            $char === "\r" => 0,
            $char === "\t" => $column + 8 - $column % 8,
            default => $column + 1,
        };
    }
}
