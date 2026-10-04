<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

/** The report the `time` keyword writes, from a TIMEFORMAT string and the pipeline's times. */
final class TimeFormat
{
    /**
     * TIMEFORMAT filled in with real time and the CPU time this process spent since $before, or the error for a bad format character.
     *
     * @param  array{int, int}  $before  cpuTimes() from when the pipeline started
     */
    public static function report(string $format, int $real, array $before): string
    {
        [$user, $sys] = self::cpuTimes();
        $user -= $before[0];
        $sys -= $before[1];
        $percent = $real === 0 ? 0 : intdiv(($user + $sys) * 10000, $real);
        $output = '';

        for ($i = 0, $len = strlen($format); $i < $len; $i++) {
            if ($format[$i] !== '%' || $i + 1 === $len) {
                $output .= $format[$i];

                continue;
            }

            $char = $format[++$i];

            if ($char === '%' || $char === 'P') {
                // bash scales %P's fraction as milliseconds but formats it as microseconds, so it always ends in .00
                $output .= $char === '%' ? '%' : self::seconds(intdiv($percent, 100), $percent % 100 * 10, 2, false);

                continue;
            }

            $precision = ctype_digit($char) ? min(6, (int) $char) : 3;
            $char = ctype_digit($char) ? $format[++$i] ?? '' : $char;
            $long = $char === 'l';
            $char = $long ? $format[++$i] ?? '' : $char;
            $micros = match ($char) {
                'R', 'E' => $real,
                'U' => $user,
                'S' => $sys,
                default => null,
            };

            if ($micros === null) {
                return sprintf("bash: TIMEFORMAT: `%s': invalid format character\n", $char === '' ? "\0" : $char);
            }

            $output .= self::seconds(intdiv($micros, 1_000_000), $micros % 1_000_000, $precision, $long);
        }

        // An empty TIMEFORMAT prints nothing at all
        return $format === '' ? '' : $output."\n";
    }

    /**
     * The whole pipeline runs in this process, so its CPU time is this process's getrusage() delta.
     *
     * @return array{int, int} user and system CPU time used so far, in microseconds
     */
    public static function cpuTimes(): array
    {
        $usage = getrusage() ?: [];
        $field = fn (string $key): int => is_int($usage[$key] ?? null) ? $usage[$key] : 0;
        $micros = fn (string $kind): int => $field(sprintf('ru_%s.tv_sec', $kind)) * 1_000_000 + $field(sprintf('ru_%s.tv_usec', $kind));

        return [$micros('utime'), $micros('stime')];
    }

    /** bash's mkfmt(): [MMm]SS[.FFF][s] with the fraction rounded to $precision places, carry quirk included */
    private static function seconds(int $seconds, int $micros, int $precision, bool $long): string
    {
        $text = $long ? intdiv($seconds, 60).'m'.($seconds % 60) : (string) $seconds;

        if ($precision > 0) {
            $unit = 10 ** (6 - $precision);
            $micros = (intdiv($micros, $unit) + ($micros % $unit >= intdiv($unit, 2) ? 1 : 0)) * $unit;
            $text .= '.';

            for ($place = 5; $place >= 6 - $precision; $place--) {
                $text .= chr(48 + intdiv($micros, 10 ** $place));
                $micros %= 10 ** $place;
            }
        }

        return $text.($long ? 's' : '');
    }
}
