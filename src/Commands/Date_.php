<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Date_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'date';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $format = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '+')) {
                $format = substr($arg, 1);

                break;
            }
        }

        if ($format === null) {
            // Default output similar to: Thu Mar  7 12:00:00 UTC 2026
            $output = date('D M j H:i:s T Y')."\n";

            return $this->success($output);
        }

        $output = $this->formatDate($format);

        return $this->success($output."\n");
    }

    private function formatDate(string $format): string
    {
        $replacements = [
            '%Y' => date('Y'),
            '%m' => date('m'),
            '%d' => date('d'),
            '%H' => date('H'),
            '%M' => date('i'),
            '%S' => date('s'),
            '%s' => (string) time(),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $format
        );
    }
}
