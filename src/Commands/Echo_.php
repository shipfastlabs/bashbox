<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Echo_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'echo';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $noNewline = false;
        $interpretEscapes = false;
        $i = 0;

        while ($i < count($args)) {
            if ($args[$i] === '-n') {
                $noNewline = true;
                $i++;
            } elseif ($args[$i] === '-e') {
                $interpretEscapes = true;
                $i++;
            } elseif ($args[$i] === '-E') {
                $interpretEscapes = false;
                $i++;
            } elseif ($args[$i] === '-ne' || $args[$i] === '-en') {
                $noNewline = true;
                $interpretEscapes = true;
                $i++;
            } elseif (str_starts_with($args[$i], '-') && preg_match('/^-[neE]+$/', $args[$i])) {
                if (str_contains($args[$i], 'n')) {
                    $noNewline = true;
                }

                if (str_contains($args[$i], 'e')) {
                    $interpretEscapes = true;
                }

                if (str_contains($args[$i], 'E')) {
                    $interpretEscapes = false;
                }

                $i++;
            } else {
                break;
            }
        }

        $output = implode(' ', array_slice($args, $i));

        if ($interpretEscapes) {
            $output = $this->interpretEscapes($output);
        }

        if (! $noNewline) {
            $output .= "\n";
        }

        return $this->success($output);
    }

    private function interpretEscapes(string $str): string
    {
        $result = '';
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            if ($str[$i] === '\\' && $i + 1 < $len) {
                $next = $str[$i + 1];
                $result .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    'a' => "\x07",
                    'b' => "\x08",
                    'f' => "\x0C",
                    'v' => "\x0B",
                    '\\' => '\\',
                    '0' => $this->parseOctal($str, $i + 1),
                    default => '\\'.$next,
                };

                if ($next === '0') {
                    while ($i + 2 < $len && $str[$i + 2] >= '0' && $str[$i + 2] <= '7') {
                        $i++;
                    }
                }

                $i++;
            } else {
                $result .= $str[$i];
            }
        }

        return $result;
    }

    private function parseOctal(string $str, int $pos): string
    {
        $octal = '';
        $pos++; // Skip the '0'

        for ($j = 0; $j < 3 && $pos + $j < strlen($str); $j++) {
            $ch = $str[$pos + $j];

            if ($ch >= '0' && $ch <= '7') {
                $octal .= $ch;
            } else {
                break;
            }
        }

        if ($octal === '') {
            return "\0";
        }

        $codepoint = (int) octdec($octal) & 0xFF;

        /** @var int<0, 255> $codepoint */
        return chr($codepoint);
    }
}
