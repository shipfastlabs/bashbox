<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use InvalidArgumentException;

/** `od` with integer, character and named-character types (no floats or -S); the files are read as one stream. */
final class Od extends AbstractCommand
{
    private const array LONG = [
        'address-radix' => ['A', true],
        'format' => ['t', true],
        'output-duplicates' => ['v', false],
        'read-bytes' => ['N', true],
        'skip-bytes' => ['j', true],
        'width' => ['w', null],
    ];

    /** The traditional options and the type each stands for. */
    private const array SHORTHANDS = [
        'a' => 'a', 'b' => 'o1', 'B' => 'o2', 'c' => 'c', 'd' => 'u2', 'D' => 'u4', 'h' => 'x2', 'H' => 'x4', 'i' => 'dI',
        'I' => 'dL', 'l' => 'dL', 'L' => 'dL', 'o' => 'o2', 'O' => 'o4', 's' => 'd2', 'x' => 'x2', 'X' => 'x4',
    ];

    /** Column width of each integer type by size: the digits of its widest value. */
    private const array WIDTHS = [
        'd' => [1 => 4, 2 => 6, 4 => 11, 8 => 20],
        'o' => [1 => 3, 2 => 6, 4 => 11, 8 => 22],
        'u' => [1 => 3, 2 => 5, 4 => 10, 8 => 20],
        'x' => [1 => 2, 2 => 4, 4 => 8, 8 => 16],
    ];

    private const array NAMES = [
        'nul', 'soh', 'stx', 'etx', 'eot', 'enq', 'ack', 'bel', 'bs', 'ht', 'nl', 'vt', 'ff', 'cr', 'so', 'si',
        'dle', 'dc1', 'dc2', 'dc3', 'dc4', 'nak', 'syn', 'etb', 'can', 'em', 'sub', 'esc', 'fs', 'gs', 'rs', 'us', 'sp',
    ];

    private const array ESCAPES = ["\0" => '\\0', "\x07" => '\\a', "\x08" => '\\b', "\f" => '\\f', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', "\v" => '\\v'];

    public function getName(): string
    {
        return 'od';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        // -w takes its argument only when attached; alone it means 32.
        $args = array_map(fn (string $arg): string => preg_match('/^-[aBbcDdhHiIlLoOsvxX]*w$/', $arg) === 1 ? $arg.'32' : $arg, $args);

        try {
            [$options, $files] = Getopt::parse($args, 'A:aBbcDdhHiIlLoOsxXj:N:t:vw:', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $radix = 'o';
        $all = false;
        $sizes = ['j' => 0, 'N' => null, 'w' => null];
        $specs = [];

        foreach ($options as [$option, $value]) {
            $error = null;

            if ($option === 'A') {
                // GNU reads the first byte even of an empty argument: its terminating NUL.
                $radix = $value[0] ?? "\0";
                $error = str_contains('doxn', $radix) ? null : sprintf("invalid output address radix '%s'; it must be one character from [doxn]", $radix);
            } elseif ($option === 'v') {
                $all = true;
            } elseif ($option === 't' || isset(self::SHORTHANDS[$option])) {
                $error = $this->parseTypes(self::SHORTHANDS[$option] ?? $value, $specs);
            } else {
                $sizes[$option] = $option === 'w' && $value === '' ? 32 : $this->bytes($value);
                $error = $sizes[$option] === null || ($option === 'w' && $sizes[$option] === 0) ? sprintf("invalid -%s argument '%s'", $option, $value) : null;
            }

            if ($error !== null) {
                return $this->failure("od: {$error}\n");
            }
        }

        if ($specs === []) {
            $this->parseTypes('o2', $specs);
        }

        [$contents, $stderr] = $this->readFiles($commandContext, $files, "od: %s: %s\n");

        if ($contents === []) {
            return $this->failure($stderr);
        }

        $data = implode('', $contents);
        $status = $stderr === '' ? 0 : 1;
        ['N' => $limit, 'w' => $width] = $sizes;
        $skip = $sizes['j'] ?? 0;

        if ($skip > strlen($data)) {
            return $this->failure($stderr."od: cannot skip past end of combined input\n");
        }

        $data = substr($data, $skip, $limit);
        $lcm = array_reduce($specs, fn (int $lcm, array $spec): int => intdiv($lcm * $spec['size'], $this->gcd($lcm, $spec['size'])), 1);

        if ($width !== null && $width % $lcm !== 0) {
            $stderr .= sprintf("od: warning: invalid width %d; using %d instead\n", $width, $lcm);
            $width = $lcm;
        }

        $output = $this->dump($commandContext, $data, $specs, $width ?? 16, $radix, $skip, $all);

        return new ExecResult($output, $stderr, $status);
    }

    /**
     * @param  list<array{type: string, size: int, width: int, trailer: bool}>  $specs
     */
    private function dump(CommandContext $commandContext, string $data, array $specs, int $bytesPerBlock, string $radix, int $start, bool $all): string
    {
        $lineWidth = max(0, ...array_map(fn (array $spec): int => ($spec['width'] + 1) * intdiv($bytesPerBlock, $spec['size']), $specs));
        $indent = str_repeat(' ', strlen($this->address($radix, 0)));
        $output = '';
        $previous = null;
        $repeated = false;

        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += $bytesPerBlock) {
            $block = substr($data, $offset, $bytesPerBlock);

            // A run of full blocks equal to the one before shows as a single `*`.
            if (! $all && $block === $previous && strlen($block) === $bytesPerBlock) {
                $output .= $repeated ? '' : "*\n";
                $repeated = true;

                continue;
            }

            $previous = $block;
            $repeated = false;

            foreach ($specs as $i => $spec) {
                $output .= ($i === 0 ? $this->address($radix, $start + $offset) : $indent).$this->line($block, $bytesPerBlock, $spec, $lineWidth)."\n";
            }

            $this->checkOutputSize($commandContext, strlen($output));
        }

        return $radix === 'n' ? $output : $output.$this->address($radix, $start + strlen($data))."\n";
    }

    /**
     * One spec's line for a block; GNU spreads the padding that lines up the specs' columns evenly between the fields.
     *
     * @param  array{type: string, size: int, width: int, trailer: bool}  $spec
     */
    private function line(string $block, int $bytesPerBlock, array $spec, int $lineWidth): string
    {
        ['type' => $type, 'size' => $size, 'width' => $width] = $spec;
        $fields = intdiv($bytesPerBlock, $size);
        $blank = intdiv($bytesPerBlock - strlen($block), $size);
        $pad = $lineWidth - $width * $fields;
        $padded = str_pad($block, $bytesPerBlock, "\0");
        $remaining = $pad;
        $line = '';

        for ($i = $fields; $i > $blank; $i--) {
            $nextPad = intdiv($pad * ($i - 1), $fields);
            $line .= str_pad($this->format($type, substr($padded, ($fields - $i) * $size, $size), $width), $remaining - $nextPad + $width, ' ', STR_PAD_LEFT);
            $remaining = $nextPad;
        }

        if ($spec['trailer']) {
            $line .= str_repeat(' ', $blank * ($width + 1)).'  >'.preg_replace('/[^\x20-\x7e]/', '.', $block).'<';
        }

        return $line;
    }

    private function format(string $type, string $bytes, int $width): string
    {
        if ($type === 'a') {
            $byte = ord($bytes);

            return match (true) {
                ($byte & 0x7F) === 0x7F => 'del',
                ($byte & 0x7F) <= 0x20 => self::NAMES[$byte & 0x7F],
                default => chr($byte & 0x7F),
            };
        }

        if ($type === 'c') {
            $byte = ord($bytes);

            return self::ESCAPES[$bytes] ?? ($byte >= 0x20 && $byte < 0x7F ? $bytes : sprintf('%03o', $byte));
        }

        $size = strlen($bytes);
        // 8-byte values wrap to PHP's signed integers, which %u, %o and %x print as unsigned.
        $value = array_reduce(array_reverse(str_split($bytes)), fn (int $value, string $byte): int => $value << 8 | ord($byte), 0);

        return match ($type) {
            'd' => (string) ($size < 8 && $value >= 1 << ($size * 8 - 1) ? $value - (1 << ($size * 8)) : $value),
            'u' => sprintf('%u', $value),
            default => sprintf('%0'.$width.$type, $value),
        };
    }

    /**
     * Appends the specs of a -t type string, returning GNU's complaint about it if any.
     *
     * @param  list<array{type: string, size: int, width: int, trailer: bool}>  $specs
     */
    private function parseTypes(string $types, array &$specs): ?string
    {
        $at = 0;

        while ($at < strlen($types)) {
            if (preg_match('/\G([acdoux])([CSIL]|\d*)(z?)/', $types, $m, 0, $at) !== 1) {
                return sprintf("invalid character '%s' in type string '%s'", $types[$at], $types);
            }

            [$all, $type, $size, $trailer] = $m;
            $at += strlen($all);
            $size = ['' => 4, 'C' => 1, 'S' => 2, 'I' => 4, 'L' => 8][$size] ?? (int) $size;

            if (str_contains('ac', $type)) {
                $specs[] = ['type' => $type, 'size' => 1, 'width' => 3, 'trailer' => $trailer !== ''];

                continue;
            }

            if (! isset(self::WIDTHS[$type][$size])) {
                return sprintf("invalid type string '%s';\nthis system doesn't provide a %d-byte integral type", $types, $size);
            }

            $specs[] = ['type' => $type, 'size' => $size, 'width' => self::WIDTHS[$type][$size], 'trailer' => $trailer !== ''];
        }

        return null;
    }

    /** A byte count like GNU's: decimal, 0x hex or 0 octal, with an optional b, k, m or G multiplier. */
    private function bytes(string $value): ?int
    {
        if (preg_match('/^(0[xX][\da-fA-F]+|0[0-7]*|[1-9]\d*)([bkKmMG]?)$/', $value, $m) !== 1) {
            return null;
        }

        return intval($m[1], 0) * ['' => 1, 'b' => 512, 'k' => 1024, 'K' => 1024, 'm' => 1024 ** 2, 'M' => 1024 ** 2, 'G' => 1024 ** 3][$m[2]];
    }

    private function address(string $radix, int $offset): string
    {
        return match ($radix) {
            'd' => sprintf('%07d', $offset),
            'x' => sprintf('%06x', $offset),
            'n' => '',
            default => sprintf('%07o', $offset),
        };
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }
}
