<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Printf_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'printf';
    }

    public function execute(array $args, CommandContext $ctx): ExecResult
    {
        if ($args === []) {
            return $this->failure("printf: usage: printf [-v var] format [arguments]\n");
        }

        $format = $args[0];
        $fmtArgs = array_slice($args, 1);

        $output = $this->formatString($format, $fmtArgs);

        return $this->success($output);
    }

    /**
     * @param  list<string>  $args
     */
    private function formatString(string $format, array $args): string
    {
        $result = '';
        $argIdx = 0;
        $len = strlen($format);

        for ($i = 0; $i < $len; $i++) {
            if ($format[$i] === '\\') {
                if ($i + 1 < $len) {
                    $result .= match ($format[$i + 1]) {
                        'n' => "\n",
                        't' => "\t",
                        'r' => "\r",
                        '\\' => '\\',
                        'a' => "\x07",
                        'b' => "\x08",
                        'f' => "\x0C",
                        'v' => "\x0B",
                        default => '\\'.$format[$i + 1],
                    };
                    $i++;
                } else {
                    $result .= '\\';
                }

                continue;
            }

            if ($format[$i] === '%') {
                if ($i + 1 < $len && $format[$i + 1] === '%') {
                    $result .= '%';
                    $i++;

                    continue;
                }

                // Parse format specifier
                $spec = '%';
                $i++;

                // Flags
                while ($i < $len && in_array($format[$i], ['-', '+', ' ', '0', '#'], true)) {
                    $spec .= $format[$i];
                    $i++;
                }

                // Width
                while ($i < $len && ctype_digit($format[$i])) {
                    $spec .= $format[$i];
                    $i++;
                }

                // Precision
                if ($i < $len && $format[$i] === '.') {
                    $spec .= '.';
                    $i++;
                    while ($i < $len && ctype_digit($format[$i])) {
                        $spec .= $format[$i];
                        $i++;
                    }
                }

                // Conversion
                if ($i < $len) {
                    $conv = $format[$i];
                    $arg = $args[$argIdx] ?? '';
                    $argIdx++;

                    $result .= match ($conv) {
                        's' => sprintf(str_replace('%', $spec[0] === '%' ? '%' : '%%', $spec).'s', $arg),
                        'd', 'i' => sprintf($spec.'d', (int) $arg),
                        'f' => sprintf($spec.'f', (float) $arg),
                        'x' => sprintf($spec.'x', (int) $arg),
                        'X' => sprintf($spec.'X', (int) $arg),
                        'o' => sprintf($spec.'o', (int) $arg),
                        'c' => $arg !== '' ? $arg[0] : '',
                        default => $spec.$conv,
                    };
                }

                continue;
            }

            $result .= $format[$i];
        }

        return $result;
    }
}
