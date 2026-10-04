<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

/** `head`; `tail` shares everything but which part of the input it keeps. */
class Head extends AbstractCommand
{
    private const array LONG = [
        'bytes' => ['c', true],
        'lines' => ['n', true],
        'quiet' => ['q', false],
        'silent' => ['q', false],
        'verbose' => ['v', false],
    ];

    public function getName(): string
    {
        return 'head';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $name = $this->getName();

        // Obsolete `-N` shorthand for `-n N`.
        if (preg_match('/^-\d+$/', $args[0] ?? '') === 1) {
            $args[0] = '-n'.substr($args[0], 1);
        }

        $parsed = $this->getopt($args, 'c:n:qv', self::LONG);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $files] = $parsed;
        $useBytes = isset($flags['c']);
        $count = $flags['c'] ?? $flags['n'] ?? '10';

        if (preg_match('/^[+-]?\d+$/', $count) !== 1) {
            return $this->failure(sprintf("%s: invalid number of %s: '%s'\n", $name, $useBytes ? 'bytes' : 'lines', $count));
        }

        $files = $files ?: ['-'];
        $headers = isset($flags['v']) || (count($files) > 1 && ! isset($flags['q']));
        [$contents, $stderr] = $this->readFiles($commandContext, $files, $name.": cannot open %s for reading: %s\n", true);
        $output = '';

        foreach ($contents as $i => $content) {
            if ($headers) {
                $output .= ($output === '' ? '' : "\n").sprintf("==> %s <==\n", $files[$i] === '-' ? 'standard input' : $files[$i]);
            }

            // Units keep their line terminators, so joining them reproduces the input exactly.
            $units = $useBytes ? str_split($content) : (preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $output .= implode('', $this->select($units, $count));
        }

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }

    /**
     * @param  list<string>  $units
     * @return list<string>
     */
    protected function select(array $units, string $count): array
    {
        // A negative count means "all but the last N".
        return array_slice($units, 0, (int) $count);
    }
}
