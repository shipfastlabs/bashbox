<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Printf_ extends AbstractCommand
{
    /** Characters sh_backslash_quote() escapes for %q. */
    private const string SHELL_SPECIAL = " \t\n!\"$&'()*,;<>?[\\]^`{|}";

    /** Escapes ansic_quote() uses inside $'...'. */
    private const array ANSI_C = ["\x07" => 'a', "\x08" => 'b', "\e" => 'E', "\f" => 'f', "\n" => 'n', "\r" => 'r', "\t" => 't', "\v" => 'v', '\\' => '\\', "'" => "'"];

    private string $stderr = '';

    private bool $stopped = false;

    private CommandContext $commandContext;

    public function getName(): string
    {
        return 'printf';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $usage = "printf: usage: printf [-v var] format [arguments]\n";

        if (($args[0] ?? '') === '--') {
            array_shift($args);
        } elseif (str_starts_with($args[0] ?? '', '-') && $args[0] !== '-') {
            return $this->failure(sprintf("printf: %s: invalid option\n%s", substr($args[0], 0, 2), $usage), 2);
        }

        if ($args === []) {
            return $this->failure($usage, 2);
        }

        $format = $args[0];
        $args = array_slice($args, 1);
        $this->stderr = '';
        $this->stopped = false;
        $this->commandContext = $commandContext;
        $output = '';

        // The format is reused until every argument has been consumed.
        do {
            $remaining = count($args);
            $output .= $this->formatOnce($format, $args);
            $this->checkOutputSize($commandContext, strlen($output));
        } while (! $this->stopped && $args !== [] && count($args) < $remaining);

        return $this->stderr === '' ? $this->success($output) : $this->failure($this->stderr, 1, $output);
    }

    /**
     * @param  list<string>  $args  consumed from the front
     *
     * @phpstan-impure
     */
    private function formatOnce(string $format, array &$args): string
    {
        $output = '';
        $parts = preg_split('/(%[-+ 0#\']*(?:\*|\d+)?(?:\.(?:\*|\d*))?[hlLjzt]*(?:\([^)]*\))?.?)/s', $format, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                [$text] = $this->expandEscapes($part, '[0-7]{1,3}', honorStop: false);
            } else {
                $text = $this->convert($part, $args);
            }

            $output .= $text;
            $this->checkOutputSize($this->commandContext, strlen($output));

            if ($this->stopped) {
                break;
            }
        }

        return $output;
    }

    /**
     * @param  list<string>  $args
     */
    private function convert(string $spec, array &$args): string
    {
        if ($spec === '%%') {
            return '%';
        }

        preg_match('/^%([-+ 0#\']*)(\*|\d*)(?:(\.)(\*|\d*))?[hlLjzt]*(?:\(([^)]*)\))?(.?)$/s', $spec, $m);
        [, $flags, $width, $dot, $precision, $timeFormat, $conversion] = $m;

        if ($conversion === '') {
            return $this->fail(sprintf("`%s': missing format character", $spec));
        }

        // `*` takes the width or precision from the arguments; a negative width left-justifies.
        $width = $width === '*' ? $this->integer(array_shift($args)) : (int) $width;
        $flags .= $width < 0 ? '-' : '';
        $width = abs($width);
        $precision = $precision === '*' ? $this->integer(array_shift($args)) : ($dot === '' ? -1 : (int) $precision);
        $this->checkOutputSize($this->commandContext, max($width, $precision));

        // A missing argument counts as empty (or zero), but an explicitly empty one is not a valid number.
        $arg = array_shift($args);
        $text = $arg ?? '';

        return match ($conversion) {
            's' => $this->pad('', $precision < 0 ? $text : substr($text, 0, $precision), $flags, $width, true),
            'c' => $this->pad('', $text[0] ?? "\0", $flags, $width, true),
            // These are padded by bash itself, which never pads with zeros.
            'b' => $this->pad('', $this->truncate($this->expandBackslashes($text), $precision), $flags, $width, false),
            'q' => $this->pad('', $this->truncate($this->quote($text), $precision), $flags, $width, false),
            'Q' => $this->pad('', $this->quote($this->truncate($text, $precision)), $flags, $width, false),
            'd', 'i', 'o', 'u', 'x', 'X' => $this->formatInteger($this->integer($arg), $conversion, $flags, $width, $precision),
            'f', 'F', 'e', 'E', 'g', 'G', 'a', 'A' => $this->formatFloat($this->float($arg), $conversion, $flags, $width, $precision),
            'T' => $timeFormat === '' && ! str_contains($spec, '(')
                ? $this->fail("`T': invalid format character")
                : $this->pad('', $this->truncate($this->strftime($timeFormat, $arg), $precision), $flags, $width, false),
            default => $this->fail(sprintf("`%s': invalid format character", $conversion)),
        };
    }

    private function fail(string $message): string
    {
        $this->stderr .= sprintf("printf: %s\n", $message);
        $this->stopped = true;

        return '';
    }

    private function truncate(string $text, int $precision): string
    {
        return $precision < 0 ? $text : substr($text, 0, $precision);
    }

    private function expandBackslashes(string $text): string
    {
        [$text, $this->stopped] = $this->expandEscapes($text, '0[0-7]{0,3}|[1-7][0-7]{0,2}');

        return $text;
    }

    /** Pad to the field width; $sign holds whatever zero padding goes after (a sign, or a 0x prefix). */
    private function pad(string $sign, string $body, string $flags, int $width, bool $zeroPad): string
    {
        $length = $width - strlen($sign);

        return match (true) {
            str_contains($flags, '-') => str_pad($sign.$body, $width),
            $zeroPad && str_contains($flags, '0') => $sign.str_pad($body, $length, '0', STR_PAD_LEFT),
            default => str_pad($sign.$body, $width, ' ', STR_PAD_LEFT),
        };
    }

    private function formatInteger(int $value, string $conversion, string $flags, int $width, int $precision): string
    {
        $signed = in_array($conversion, ['d', 'i'], true);
        // o, u, x and X reinterpret negative numbers as unsigned 64-bit values, which sprintf() already does.
        $digits = ltrim(sprintf('%'.($signed ? 'd' : strtr($conversion, 'X', 'x')), $value), '-');
        $digits = $conversion === 'X' ? strtoupper($digits) : $digits;

        if ($precision >= 0) {
            $digits = $precision === 0 && $value === 0 ? '' : str_pad($digits, $precision, '0', STR_PAD_LEFT);
        }

        $sign = $signed ? $this->sign($value < 0, $flags) : '';

        if (str_contains($flags, '#')) {
            if ($conversion === 'o' && ! str_starts_with($digits, '0')) {
                $digits = '0'.$digits;
            } elseif ($value !== 0 && ($conversion === 'x' || $conversion === 'X')) {
                $sign = '0'.$conversion;
            }
        }

        return $this->pad($sign, $digits, $flags, $width, $precision < 0);
    }

    private function sign(bool $negative, string $flags): string
    {
        return match (true) {
            $negative => '-',
            str_contains($flags, '+') => '+',
            str_contains($flags, ' ') => ' ',
            default => '',
        };
    }

    private function formatFloat(float $value, string $conversion, string $flags, int $width, int $precision): string
    {
        $upper = ctype_upper($conversion);
        $sign = $this->sign($value < 0 || ($value === 0.0 && fdiv(1, $value) < 0), $flags);
        $value = abs($value);

        if (! is_finite($value)) {
            $body = is_nan($value) ? 'nan' : 'inf';

            return $this->pad($sign, $upper ? strtoupper($body) : $body, $flags, $width, false);
        }

        $alternate = str_contains($flags, '#');
        $body = match (strtolower($conversion)) {
            'f' => $this->fixed($value, $precision < 0 ? 6 : $precision, $alternate),
            'e' => $this->exponential($value, $precision < 0 ? 6 : $precision, $alternate),
            'g' => $this->general($value, $precision < 0 ? 6 : max($precision, 1), $alternate),
            default => $this->hexadecimal($value, $precision, $alternate),
        };

        return $this->pad($sign, $upper ? strtoupper($body) : $body, $flags, $width, true);
    }

    private function fixed(float $value, int $precision, bool $alternate): string
    {
        return sprintf(sprintf('%%.%dF', $precision), $value).($alternate && $precision === 0 ? '.' : '');
    }

    private function exponential(float $value, int $precision, bool $alternate): string
    {
        // PHP writes the exponent without C's minimum of two digits.
        [$mantissa, $exponent] = explode('e', sprintf(sprintf('%%.%de', $precision), $value));

        return $mantissa.($alternate && $precision === 0 ? '.' : '').'e'.$exponent[0].str_pad(substr($exponent, 1), 2, '0', STR_PAD_LEFT);
    }

    /** %g: %e when the exponent is below -4 or not below the precision, %f otherwise, then trailing zeros dropped unless #. */
    private function general(float $value, int $precision, bool $alternate): string
    {
        $exponent = (int) explode('e', sprintf('%.'.($precision - 1).'e', $value))[1];
        $body = $exponent < -4 || $exponent >= $precision
            ? $this->exponential($value, $precision - 1, $alternate)
            : $this->fixed($value, $precision - 1 - $exponent, $alternate);

        return $alternate ? $body : (string) preg_replace(['/(\.\d*?)0+(?=e|$)/', '/\.(?=e|$)/'], ['$1', ''], $body);
    }

    /** %a in the form macOS prints it: a normalised leading 1, and ties rounded down. */
    private function hexadecimal(float $value, int $precision, bool $alternate): string
    {
        if ($value === 0.0) {
            [$mantissa, $exponent] = [0, 0];
        } else {
            $bits = (int) hexdec(bin2hex(pack('E', $value)));
            $exponent = ($bits >> 52) - 1023;
            $mantissa = $bits & 0xFFFFFFFFFFFFF;

            if ($exponent === -1023) {
                // Subnormal: shift the mantissa up until the leading 1 is in place.
                $exponent = -1022;

                while (($mantissa & (1 << 52)) === 0) {
                    $mantissa <<= 1;
                    $exponent--;
                }
            }

            $mantissa |= 1 << 52;
        }

        if ($precision >= 0 && $precision < 13) {
            $shift = 52 - 4 * $precision;
            $remainder = $mantissa & ((1 << $shift) - 1);
            $mantissa = ($mantissa >> $shift) + ($remainder > 1 << ($shift - 1) ? 1 : 0);
            $digits = $precision === 0 ? '' : sprintf(sprintf('%%0%dx', $precision), $mantissa & ((1 << 4 * $precision) - 1));
            $lead = $mantissa >> 4 * $precision;
        } else {
            $digits = sprintf('%013x', $mantissa & 0xFFFFFFFFFFFFF);
            $digits = $precision < 0 ? rtrim($digits, '0') : str_pad($digits, $precision, '0');
            $lead = $mantissa >> 52;
        }

        return '0x'.$lead.($digits !== '' || $alternate ? '.' : '').$digits.'p'.($exponent < 0 ? '-' : '+').abs($exponent);
    }

    /** bash's %q in the C locale: '' for empty, $'...' when there are unprintable bytes, backslashes otherwise. */
    private function quote(string $text): string
    {
        if ($text === '') {
            return "''";
        }

        if (preg_match('/[^\x20-\x7e]/', $text) === 1) {
            return "$'".implode('', array_map(fn (string $char): string => match (true) {
                isset(self::ANSI_C[$char]) => '\\'.self::ANSI_C[$char],
                $char >= ' ' && $char <= '~' => $char,
                default => sprintf('\\%03o', ord($char)),
            }, str_split($text)))."'";
        }

        $quoted = '';

        foreach (str_split($text) as $i => $char) {
            // `#` starts a comment only at the start of a word, `~` expands there and after `:` or `=`.
            $special = str_contains(self::SHELL_SPECIAL, $char)
                || ($char === '#' && $i === 0)
                || ($char === '~' && ($i === 0 || in_array($text[$i - 1], [':', '='], true)));
            $quoted .= ($special ? '\\' : '').$char;
        }

        return $quoted;
    }

    /** %(fmt)T: the argument is seconds since the epoch, with -1, -2 or no argument meaning now. */
    private function strftime(string $format, ?string $arg): string
    {
        $time = $arg === null ? -1 : $this->integer($arg);
        $execResult = new Date_()->execute(['-d', '@'.(in_array($time, [-1, -2], true) ? time() : $time), '+'.$format], $this->commandContext);

        return substr($execResult->stdout, 0, -1);
    }

    private function integer(?string $arg): int
    {
        $text = $arg ?? '';

        if (preg_match('/^[\'"]/', $text) === 1) {
            return ord($text[1] ?? "\0");
        }

        preg_match('/^\s*([-+]?)(?:0x([\da-f]+)|(0[0-7]*)|(\d+))?/i', $text, $m, PREG_UNMATCHED_AS_NULL);
        $digits = ltrim($m[4] ?? '', '0');

        if (strlen($digits) > 19 || (strlen($digits) === 19 && $digits > ($m[1] === '-' ? '9223372036854775808' : '9223372036854775807'))) {
            $this->stderr .= "printf: {$text}: Result too large\n";

            return $m[1] === '-' ? PHP_INT_MIN : PHP_INT_MAX;
        }

        // bash names the base it expected from how the argument starts.
        $kind = match (true) {
            preg_match('/^0x/i', $text) === 1 => 'hex ',
            str_starts_with($text, '0') => 'octal ',
            default => '',
        };

        return (int) $this->number($arg, $m[0] === $text && ($m[2] ?? $m[3] ?? $m[4]) !== null, intval($m[0], 0), $kind);
    }

    private function float(?string $arg): float
    {
        $text = $arg ?? '';

        if (preg_match('/^[\'"]/', $text) === 1) {
            return ord($text[1] ?? "\0");
        }

        [$value, $prefix] = self::strtold($text);

        // Overflow, or underflow of a number that is not zero.
        preg_match('/^\s*[-+]?(?:0x([\da-f.]*)|([\d.]*))/i', $prefix, $digits);

        if (strpbrk($digits[1].($digits[2] ?? ''), '123456789abcdefABCDEF') !== false && (is_infinite($value) || abs($value) < PHP_FLOAT_MIN)) {
            $this->stderr .= "printf: {$text}: Result too large\n";
        }

        return (float) $this->number($arg, $prefix !== '' && $prefix === $text, $value);
    }

    /**
     * C's strtold(): decimal and hexadecimal floats, inf and nan after optional blanks and a sign.
     *
     * @return array{float, string} the value, and the prefix of $text it was read from ('' when there is no number)
     */
    public static function strtold(string $text): array
    {
        if (preg_match('/^\s*([-+]?)(?:0x([\da-f]*)\.?([\da-f]*)(?:p([-+]?\d+))?|((?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?)|(inf(?:inity)?)|(nan))/i', $text, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return [0.0, ''];
        }

        [, $sign, $hexInteger, $hexFraction, $hexExponent, $decimal, $inf] = $m;
        $value = match (true) {
            $inf !== null => INF,
            $hexInteger !== null => hexdec($hexInteger.$hexFraction ?: '0') * 2 ** ((int) $hexExponent - 4 * strlen((string) $hexFraction)),
            $decimal !== null => (float) $decimal,
            default => NAN,
        };

        return [$sign === '-' ? -$value : $value, $m[0]];
    }

    /** Garbage is reported but still formatted by its numeric prefix, like bash does. */
    private function number(?string $arg, bool $valid, int|float $value, string $kind = ''): int|float
    {
        if ($arg !== null && ! $valid) {
            $this->stderr .= "printf: {$arg}: invalid {$kind}number\n";
        }

        return $value;
    }
}
