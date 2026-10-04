<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class Date_ extends AbstractCommand
{
    /** strftime() conversions as DateTimeInterface::format() strings. */
    private const array FORMATS = [
        'a' => 'D', 'A' => 'l', 'b' => 'M', 'B' => 'F', 'h' => 'M', 'd' => 'd', 'm' => 'm', 'y' => 'y', 'Y' => 'Y',
        'H' => 'H', 'I' => 'h', 'M' => 'i', 'S' => 's', 'p' => 'A', 'u' => 'N', 'w' => 'w', 's' => 'U',
        'z' => 'O', 'Z' => 'T', 'F' => 'Y-m-d', 'T' => 'H:i:s', 'D' => 'm/d/y', 'R' => 'H:i', 'n' => "\n", 't' => "\t", '%' => '%',
    ];

    public function getName(): string
    {
        return 'date';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $parsed = $this->getopt($args, 'd:u', ['date' => ['d', true], 'utc' => ['u', false], 'universal' => ['u', false]]);

        if ($parsed instanceof ExecResult) {
            return $parsed;
        }

        [$flags, $operands] = $parsed;
        $when = $flags['d'] ?? '';
        $format = '%a %b %e %H:%M:%S %Z %Y';

        foreach ($operands as $operand) {
            if (str_starts_with($operand, '+')) {
                $format = substr($operand, 1);
            }
        }

        try {
            $date = new DateTimeImmutable($when === '' ? 'now' : $when);
        } catch (Exception) {
            return $this->failure("date: invalid date '{$when}'\n");
        }

        // "@epoch" dates carry a UTC offset, every other one is in PHP's default zone unless -u is given.
        $date = $date->setTimezone(new DateTimeZone(isset($flags['u']) ? 'UTC' : date_default_timezone_get()));

        $output = preg_replace_callback('/%(.)/', fn (array $m): string => match ($m[1]) {
            'e' => sprintf('%2d', $date->format('j')),
            'j' => sprintf('%03d', (int) $date->format('z') + 1),
            default => isset(self::FORMATS[$m[1]]) ? $date->format(self::FORMATS[$m[1]]) : $m[0],
        }, $format);

        return $this->success($output."\n");
    }
}
