<?php

declare(strict_types=1);

namespace BashBox\Parser;

/** Two's-complement 64-bit arithmetic, as bash does it in C: results wrap around instead of becoming floats. */
final class Int64
{
    public static function add(int $a, int $b): int
    {
        // Well inside the range (judged in floating point), PHP's own + can't overflow.
        if (abs((float) $a + $b) < 4.0e18) {
            return $a + $b;
        }

        // Add as unsigned 32-bit halves and keep the low 64 bits.
        $low = ($a & 0xFFFFFFFF) + ($b & 0xFFFFFFFF);
        $high = (($a >> 32) & 0xFFFFFFFF) + (($b >> 32) & 0xFFFFFFFF) + ($low >> 32);

        return (($high & 0xFFFFFFFF) << 32) | ($low & 0xFFFFFFFF);
    }

    public static function sub(int $a, int $b): int
    {
        return self::add($a, self::add(~$b, 1));
    }

    public static function mul(int $a, int $b): int
    {
        if (abs((float) $a * $b) < 4.0e18) {
            return $a * $b;
        }

        // a * b mod 2^64, taking b 16 bits at a time so no partial product overflows.
        $result = 0;

        for ($shift = 0; $shift < 64; $shift += 16) {
            $chunk = ($b >> $shift) & 0xFFFF;
            $partial = self::add((($a >> 32) * $chunk) << 32, ($a & 0xFFFFFFFF) * $chunk);
            $result = self::add($result, $partial << $shift);
        }

        return $result;
    }

    /** $exponent is never negative here: bash rejects that before it gets this far */
    public static function pow(int $base, int $exponent): int
    {
        $result = 1;

        for (; $exponent > 0; $exponent >>= 1) {
            if (($exponent & 1) === 1) {
                $result = self::mul($result, $base);
            }

            $base = self::mul($base, $base);
        }

        return $result;
    }
}
