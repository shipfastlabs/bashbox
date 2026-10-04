<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use InvalidArgumentException;

final class Cat extends AbstractCommand
{
    /** Long options in GNU's table order, which ambiguity messages list them in. */
    private const array LONG = [
        'number-nonblank' => ['b', false],
        'number' => ['n', false],
        'squeeze-blank' => ['s', false],
        'show-nonprinting' => ['v', false],
        'show-ends' => ['E', false],
        'show-tabs' => ['T', false],
        'show-all' => ['A', false],
    ];

    /** What each option turns on, as the letters of the basic options. */
    private const array IMPLIES = ['A' => 'vET', 'e' => 'vE', 't' => 'vT', 'u' => ''];

    public function getName(): string
    {
        return 'cat';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $files] = Getopt::parse($args, 'AbeEnstTuv', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->failure("cat: {$invalidArgumentException->getMessage()}\nTry 'cat --help' for more information.\n");
        }

        $on = implode('', array_map(fn (array $option): string => self::IMPLIES[$option[0]] ?? $option[0], $options));
        [$contents, $stderr] = $this->readFiles($commandContext, $files, "cat: %s: %s\n");
        $output = implode('', $contents);

        if ($on !== '') {
            $output = $this->decorate($output, $on);
        }

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }

    /** Lines numbering and squeezing carry on across files, as GNU cat treats them as one stream. */
    private function decorate(string $output, string $on): string
    {
        $result = '';
        $lineNum = 0;
        $previousBlank = false;
        preg_match_all('/[^\n]*(?:\n|[^\n]\z)/', $output, $lines);

        foreach ($lines[0] as $line) {
            $blank = $line === "\n";

            if ($blank && $previousBlank && str_contains($on, 's')) {
                continue;
            }

            $previousBlank = $blank;

            if (str_contains($on, 'b') ? ! $blank : str_contains($on, 'n')) {
                $result .= sprintf("%6d\t", ++$lineNum);
            }

            $newline = str_ends_with($line, "\n");
            $body = $newline ? substr($line, 0, -1) : $line;

            if (str_contains($on, 'v')) {
                $body = (string) preg_replace_callback('/[^\t\x20-\x7e]/', fn (array $m): string => $this->visible(ord($m[0])), $body);
            }

            if (str_contains($on, 'T')) {
                $body = str_replace("\t", '^I', $body);
            }

            // GNU shows the carriage return of a CRLF ending even without -v.
            if ($newline && str_contains($on, 'E')) {
                $body = (str_ends_with($body, "\r") ? substr($body, 0, -1).'^M' : $body).'$';
            }

            $result .= $body.($newline ? "\n" : '');
        }

        return $result;
    }

    private function visible(int $byte): string
    {
        $prefix = $byte >= 128 ? 'M-' : '';
        $byte &= 127;

        return $prefix.match (true) {
            $byte < 32 => '^'.chr($byte + 64),
            $byte === 127 => '^?',
            default => chr($byte),
        };
    }
}
