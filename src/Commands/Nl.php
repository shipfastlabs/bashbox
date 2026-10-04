<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Regex\PosixRegex;
use BashBox\Regex\SafePcreRegex;
use InvalidArgumentException;

final class Nl extends AbstractCommand
{
    private const array LONG = [
        'header-numbering' => ['h', true],
        'body-numbering' => ['b', true],
        'footer-numbering' => ['f', true],
        'starting-line-number' => ['v', true],
        'line-increment' => ['i', true],
        'no-renumber' => ['p', false],
        'join-blank-lines' => ['l', true],
        'number-separator' => ['s', true],
        'number-width' => ['w', true],
        'number-format' => ['n', true],
        'section-delimiter' => ['d', true],
    ];

    private const array FORMATS = ['ln' => '%-', 'rn' => '%', 'rz' => '%0'];

    public function getName(): string
    {
        return 'nl';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $files] = Getopt::parse($args, 'h:b:f:v:i:pl:s:w:n:d:', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $styles = ['h' => 'n', 'b' => 't', 'f' => 'n'];
        $numbers = ['v' => 1, 'i' => 1, 'l' => 1, 'w' => 6];
        $renumber = true;
        $separator = "\t";
        $format = 'rn';
        $delimiter = '\\:';
        $errors = '';

        // Like GNU, a bad style or format is reported after all options are read; a bad number stops at once.
        foreach ($options as [$option, $value]) {
            switch ($option) {
                case 'h':
                case 'b':
                case 'f':
                    if (! in_array($value[0] ?? '', ['a', 't', 'n', 'p'], true)) {
                        $errors .= sprintf("nl: invalid %s numbering style: '%s'\n", ['h' => 'header', 'b' => 'body', 'f' => 'footer'][$option], $value);
                    } elseif ($value[0] === 'p' && ($error = PosixRegex::error($this->regex(substr($value, 1)))) !== null) {
                        return $this->failure("nl: {$error}\n");
                    }

                    $styles[$option] = $value;
                    break;
                case 'v':
                case 'i':
                case 'l':
                case 'w':
                    $what = ['v' => 'starting line number', 'i' => 'line number increment', 'l' => 'line number of blank lines', 'w' => 'line number field width'][$option];

                    if (preg_match('/^\s*[-+]?\d+$/', $value) !== 1) {
                        return $this->failure(sprintf("nl: invalid %s: '%s'\n", $what, $value));
                    }

                    $number = filter_var(ltrim($value), FILTER_VALIDATE_INT);

                    if ($number === false || ($option === 'w' && $number > 2147483647)) {
                        return $this->failure(sprintf("nl: invalid %s: '%s': Value too large to be stored in data type\n", $what, $value));
                    }

                    if ($number < ['v' => PHP_INT_MIN, 'i' => PHP_INT_MIN, 'l' => 0, 'w' => 1][$option]) {
                        return $this->failure(sprintf("nl: invalid %s: '%s': Result too large\n", $what, $value));
                    }

                    $numbers[$option] = $number;
                    break;
                case 'n':
                    if (! isset(self::FORMATS[$value])) {
                        $errors .= sprintf("nl: invalid line numbering format: '%s'\n", $value);
                    }

                    $format = $value;
                    break;
                case 'p':
                    $renumber = false;
                    break;
                case 's':
                    $separator = $value;
                    break;
                default:
                    // POSIX: a one-character delimiter keeps the default second character.
                    $delimiter = strlen($value) === 1 ? $value.':' : $value;
            }
        }

        if ($errors !== '') {
            return $this->failure($errors."Try 'nl --help' for more information.\n");
        }

        ['v' => $start, 'i' => $increment, 'l' => $join, 'w' => $width] = $numbers;
        [$contents, $stderr] = $this->readFiles($commandContext, $files, "nl: %s: %s\n");
        $sections = strlen($delimiter) < 2 ? [] : [str_repeat($delimiter, 3) => 'h', str_repeat($delimiter, 2) => 'b', $delimiter => 'f'];
        $blank = str_repeat(' ', $width + strlen($separator));
        $number = $start;
        $style = $styles['b'];
        $blanks = 0;
        $output = '';

        foreach ($contents as $content) {
            // A last line without a newline gets one.
            foreach ($this->splitLines($content)['lines'] as $line) {
                if (isset($sections[$line])) {
                    $style = $styles[$sections[$line]];
                    $number = $renumber ? $start : $number;
                    $output .= "\n";

                    continue;
                }

                $numbered = match ($style[0]) {
                    'a' => $line !== '' || $join < 2 || ++$blanks === $join,
                    't' => $line !== '',
                    'n' => false,
                    default => SafePcreRegex::match($this->regex(substr($style, 1)), $line),
                };

                if ($numbered) {
                    $output .= sprintf(self::FORMATS[$format].$width.'d', $number).$separator;
                    $number += $increment;
                    $blanks = 0;
                } else {
                    $output .= $blank;
                }

                $output .= $line."\n";
            }
        }

        return $stderr === '' ? $this->success($output) : $this->failure($stderr, 1, $output);
    }

    private function regex(string $basic): string
    {
        return '/'.PosixRegex::toPcre($basic, false, ignoreLeadingOps: false).'/';
    }
}
