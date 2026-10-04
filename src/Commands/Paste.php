<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use RuntimeException;

final class Paste extends AbstractCommand
{
    private const array ESCAPES = ['b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", '\\' => '\\', '0' => ''];

    public function getName(): string
    {
        return 'paste';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'd:sz', ['delimiters' => ['d', true], 'serial' => ['s', false], 'zero-terminated' => ['z', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $files] = $parsed;
        $list = ($flags['d'] ?? "\t") ?: '\\0';

        if (preg_match('/^(?:[^\\\\]|\\\\.)*\\\\$/s', $list) === 1) {
            return $this->failure("paste: delimiter list ends with an unescaped backslash: {$list}\n");
        }

        preg_match_all('/\\\\(.)|./s', $list, $m, PREG_SET_ORDER);
        /** @var non-empty-list<string> $delimiters */
        $delimiters = array_map(fn (array $d): string => isset($d[1]) ? self::ESCAPES[$d[1]] ?? $d[1] : $d[0], $m);
        $eol = isset($flags['z']) ? "\0" : "\n";
        $files = $files ?: ['-'];
        $stderr = '';
        $streams = [];

        foreach ($files as $i => $file) {
            // `-` operands share stdin; any other operand is opened anew, even a repeated name
            $key = $file === '-' ? '-' : $i;

            try {
                $streams[$key] ??= $this->readOperand($commandContext, $file);
            } catch (RuntimeException $runtimeException) {
                $error = $this->describeError($runtimeException);
                $message = sprintf("paste: %s: %s\n", $this->shellEscape($file), $error);

                // GNU opens every file before reading any, so a file that can't be opened is the only error then
                if ($error !== 'Is a directory' && ! isset($flags['s'])) {
                    return $this->failure($message);
                }

                $stderr .= $message;

                // A directory opens fine and only fails to read, like an empty file
                if ($error === 'Is a directory') {
                    $streams[$key] = '';
                }
            }
        }

        $output = isset($flags['s'])
            ? $this->serial($files, $streams, $delimiters, $eol)
            : $this->parallel($commandContext, $files, $streams, $delimiters, $eol);

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }

    /**
     * Each file becomes one line; an unopenable one is skipped, and a second `-` finds stdin used up.
     *
     * @param  list<string>  $files
     * @param  array<int|string, string>  $streams
     * @param  non-empty-list<string>  $delimiters
     * @param  non-empty-string  $eol
     */
    private function serial(array $files, array $streams, array $delimiters, string $eol): string
    {
        $output = '';

        foreach ($files as $i => $file) {
            $key = $file === '-' ? '-' : $i;

            if (! isset($streams[$key])) {
                continue;
            }

            $content = $streams[$key];
            $streams[$key] = '';
            $lines = explode($eol, str_ends_with($content, $eol) ? substr($content, 0, -1) : $content);

            foreach ($lines as $n => $line) {
                $output .= ($n === 0 ? '' : $delimiters[($n - 1) % count($delimiters)]).$line;
            }

            $output .= $eol;
        }

        return $output;
    }

    /**
     * Line N of every file joined into output line N; `-` operands take turns reading stdin.
     *
     * @param  list<string>  $files
     * @param  array<int|string, string>  $streams
     * @param  non-empty-list<string>  $delimiters
     * @param  non-empty-string  $eol
     */
    private function parallel(CommandContext $commandContext, array $files, array $streams, array $delimiters, string $eol): string
    {
        $count = count($files);
        $open = array_fill(0, $count, true);
        $offsets = array_fill_keys(array_keys($streams), 0);
        $output = '';

        while (in_array(true, $open, true)) {
            $somedone = false;
            $saved = '';
            $d = 0;

            for ($i = 0; $i < $count && in_array(true, $open, true); $i++) {
                $key = $files[$i] === '-' ? '-' : $i;
                $data = $streams[$key];
                $offset = $offsets[$key];
                $line = null;

                if ($open[$i] && $offset < strlen($data)) {
                    $end = strpos($data, $eol, $offset);
                    $line = substr($data, $offset, ($end === false ? strlen($data) : $end) - $offset);
                    $offsets[$key] = $end === false ? strlen($data) : $end + 1;
                }

                if ($line === null) {
                    $open[$i] = false;

                    // A finished file's delimiter is written only if a later file still has a line.
                    if ($i + 1 < $count) {
                        $saved .= $delimiters[$d++ % count($delimiters)];
                    } elseif ($somedone) {
                        $output .= $saved.$eol;
                    }

                    continue;
                }

                $somedone = true;
                $output .= $saved.$line.($i + 1 < $count ? $delimiters[$d++ % count($delimiters)] : $eol);
                $saved = '';
                $this->checkOutputSize($commandContext, strlen($output));
            }
        }

        return $output;
    }
}
