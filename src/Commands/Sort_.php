<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use InvalidArgumentException;
use RuntimeException;

/** GNU sort in the C locale: bytewise collation, blanks are space, tab and newline. */
final class Sort_ extends AbstractCommand
{
    /** Long options in GNU's table order, which ambiguity messages list them in. */
    private const array LONG = [
        'ignore-leading-blanks' => ['b', false],
        'check' => ['check', null],
        'compress-program' => ['compress-program', true],
        'dictionary-order' => ['d', false],
        'ignore-case' => ['f', false],
        'general-numeric-sort' => ['g', false],
        'ignore-nonprinting' => ['i', false],
        'key' => ['k', true],
        'merge' => ['m', false],
        'month-sort' => ['M', false],
        'numeric-sort' => ['n', false],
        'human-numeric-sort' => ['h', false],
        'version-sort' => ['V', false],
        'random-sort' => ['R', false],
        'sort' => ['sort', true],
        'output' => ['o', true],
        'reverse' => ['r', false],
        'stable' => ['s', false],
        'buffer-size' => ['S', true],
        'field-separator' => ['t', true],
        'temporary-directory' => ['T', true],
        'unique' => ['u', false],
        'zero-terminated' => ['z', false],
        'parallel' => ['parallel', true],
    ];

    private const array SORT_WORDS = ['general-numeric' => 'g', 'human-numeric' => 'h', 'month' => 'M', 'numeric' => 'n', 'random' => 'R', 'version' => 'V'];

    private const array CHECK_WORDS = ['quiet' => 'C', 'silent' => 'C', 'diagnose-first' => 'c'];

    /** Key modifiers, in the order GNU lists incompatible ones. */
    private const string MODIFIERS = 'bdfghiMnRrV';

    private const array MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

    private const string BLANKS = " \t\n";

    /** @var list<array{startField: int, startChar: int, endField: ?int, endChar: int, modifiers: string, endBlanks: bool}> */
    private array $keys = [];

    private string $tab = '';

    private string $global = '';

    private bool $unique = false;

    private bool $stable = false;

    private string $salt = '';

    public function getName(): string
    {
        return 'sort';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        try {
            [$options, $files] = Getopt::parse($args, 'bcCdfghik:mMno:rRsS:t:T:uVz', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->failure("sort: {$invalidArgumentException->getMessage()}\nTry 'sort --help' for more information.\n", 2);
        }

        $this->keys = [];
        $this->tab = '';
        $this->global = '';
        $this->unique = false;
        $this->stable = false;
        $this->salt = random_bytes(16);
        $check = '';
        $merge = false;
        $output = null;
        $eol = "\n";

        try {
            foreach ($options as [$option, $value]) {
                match ($option) {
                    'k' => $this->keys[] = $this->parseKey($value),
                    't' => $this->setTab($value),
                    'c', 'C' => $check = $this->setCheck($check, $option),
                    'check' => $check = $this->setCheck($check, $value === '' ? 'c' : $this->argmatch($value, 'check', self::CHECK_WORDS)),
                    'sort' => $this->global .= $this->argmatch($value, 'sort', self::SORT_WORDS),
                    'm' => $merge = true,
                    'o' => $output = $value,
                    's' => $this->stable = true,
                    'u' => $this->unique = true,
                    'z' => $eol = "\0",
                    'S', 'T', 'parallel', 'compress-program' => null,
                    default => $this->global .= $option,
                };
            }

            $this->inheritGlobalOptions();

            if ($check !== '' && $output !== null) {
                throw new RuntimeException(sprintf("options '-%so' are incompatible", $check));
            }
        } catch (RuntimeException $runtimeException) {
            return $this->failure(sprintf("sort: %s\n", $runtimeException->getMessage()), $runtimeException->getCode() ?: 2);
        }

        if ($check !== '') {
            return $this->check($commandContext, $files, $check, $eol);
        }

        $inputs = [];

        foreach ($files ?: ['-'] as $file) {
            try {
                $inputs[] = $this->lines($commandContext, $file, $eol);
            } catch (RuntimeException $runtimeException) {
                return $this->failure($this->readError($file, $runtimeException, 'cannot read'), 2);
            }
        }

        $lines = $merge ? $this->merge($inputs) : $this->sorted(array_merge(...$inputs));

        if ($this->unique) {
            $lines = array_values(array_filter(
                $lines,
                fn (string $line, int $i): bool => $i === 0 || $this->compare($lines[$i - 1], $line) !== 0,
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        $text = $lines === [] ? '' : implode($eol, $lines).$eol;

        if ($output === null) {
            return $this->success($text);
        }

        try {
            $this->writeOutputFile($commandContext, $output, $text);
        } catch (RuntimeException $runtimeException) {
            return $this->failure(sprintf("sort: open failed: %s: %s\n", $output, $this->describeError($runtimeException)), 2);
        }

        return $this->success();
    }

    /**
     * POS1[,POS2] where POS is F[.C][OPTS].
     *
     * @return array{startField: int, startChar: int, endField: ?int, endChar: int, modifiers: string, endBlanks: bool}
     */
    private function parseKey(string $spec): array
    {
        $rest = $spec;
        $count = function (string $what) use (&$rest): int {
            if (preg_match('/^\d+/', $rest, $m) !== 1) {
                throw new RuntimeException(sprintf("%s: invalid count at start of '%s'", $what, $rest));
            }

            $rest = substr($rest, strlen($m[0]));

            return (int) min($m[0], PHP_INT_MAX);
        };
        $invalid = fn (string $what): RuntimeException => new RuntimeException(sprintf("%s: invalid field specification '%s'", $what, $spec));
        $modifiers = function () use (&$rest): string {
            $taken = substr($rest, 0, strspn($rest, self::MODIFIERS));
            $rest = substr($rest, strlen($taken));

            return $taken;
        };

        $startField = $count('invalid number at field start') ?: throw $invalid('field number is zero');
        $startChar = 1;

        if (str_starts_with($rest, '.')) {
            $rest = substr($rest, 1);
            $startChar = $count("invalid number after '.'") ?: throw $invalid('character offset is zero');
        }

        $startModifiers = $modifiers();
        $endField = null;
        $endChar = 0;
        $endModifiers = '';

        if (str_starts_with($rest, ',')) {
            $rest = substr($rest, 1);
            $endField = $count("invalid number after ','") ?: throw $invalid('field number is zero');

            if (str_starts_with($rest, '.')) {
                $rest = substr($rest, 1);
                $endChar = $count("invalid number after '.'");
            }

            $endModifiers = $modifiers();
        }

        if ($rest !== '') {
            throw $invalid('stray character in field spec');
        }

        // A `b` on the start position skips blanks before it, one on the end position before that one.
        return [
            'startField' => $startField - 1,
            'startChar' => $startChar - 1,
            'endField' => $endField === null ? null : $endField - 1,
            'endChar' => $endChar,
            'modifiers' => str_replace('b', '', $startModifiers.$endModifiers).(str_contains($startModifiers, 'b') ? 'b' : ''),
            'endBlanks' => str_contains($endModifiers, 'b'),
        ];
    }

    private function setTab(string $tab): void
    {
        $tab = match (true) {
            $tab === '' => throw new RuntimeException('empty tab'),
            $tab === '\0' => "\0",
            strlen($tab) > 1 => throw new RuntimeException(sprintf("multi-character tab '%s'", $tab)),
            default => $tab,
        };

        if ($this->tab !== '' && $this->tab !== $tab) {
            throw new RuntimeException('incompatible tabs');
        }

        $this->tab = $tab;
    }

    private function setCheck(string $check, string $mode): string
    {
        if ($check !== '' && $check !== $mode) {
            throw new RuntimeException("options '-cC' are incompatible");
        }

        return $mode;
    }

    /**
     * An option argument from a fixed list, which may be abbreviated.
     *
     * @param  array<string, string>  $words
     */
    private function argmatch(string $value, string $option, array $words): string
    {
        $matches = array_filter($words, fn (string $word): bool => str_starts_with($word, $value), ARRAY_FILTER_USE_KEY);

        if (count(array_unique($matches)) === 1 || isset($words[$value])) {
            return $words[$value] ?? (string) reset($matches);
        }

        // Synonyms are listed together.
        $valid = [];

        foreach ($words as $word => $result) {
            $valid[$result] = isset($valid[$result]) ? $valid[$result].sprintf(", '%s'", $word) : sprintf("  - '%s'", $word);
        }

        throw new RuntimeException(sprintf("%s argument '%s' for '--%s'\nValid arguments are:\n%s\nTry 'sort --help' for more information.", $matches === [] ? 'invalid' : 'ambiguous', $value, $option, implode("\n", $valid)), 1);
    }

    /** Keys without modifiers of their own take the global ones; with no keys at all, the global options make a whole-line key. */
    private function inheritGlobalOptions(): void
    {
        foreach ($this->keys as &$key) {
            if ($key['modifiers'] === '' && ! $key['endBlanks']) {
                $key['modifiers'] = $this->global;
                $key['endBlanks'] = str_contains($this->global, 'b');
            }
        }

        unset($key);

        if ($this->keys === [] && str_replace('r', '', $this->global) !== '') {
            $this->keys[] = ['startField' => 0, 'startChar' => 0, 'endField' => null, 'endChar' => 0, 'modifiers' => $this->global, 'endBlanks' => str_contains($this->global, 'b')];
        }

        foreach ($this->keys as $key) {
            $types = array_sum(array_map(fn (string $type): int => (int) str_contains($key['modifiers'], $type), ['n', 'g', 'h', 'M']))
                + (int) (strpbrk($key['modifiers'], 'VRdi') !== false);

            if ($types > 1) {
                // -d wins over -i, so only one of them is named.
                $letters = array_filter(str_split(self::MODIFIERS), fn (string $m): bool => ! in_array($m, ['b', 'r'], true) && str_contains($key['modifiers'], $m)
                    && ! ($m === 'i' && str_contains($key['modifiers'], 'd')));

                throw new RuntimeException(sprintf("options '-%s' are incompatible", implode('', $letters)));
            }
        }
    }

    /**
     * @param  non-empty-string  $eol
     * @return list<string>
     */
    private function lines(CommandContext $commandContext, string $file, string $eol): array
    {
        $content = $this->readOperand($commandContext, $file);

        return $content === '' ? [] : explode($eol, str_ends_with($content, $eol) ? substr($content, 0, -1) : $content);
    }

    private function readError(string $file, RuntimeException $runtimeException, string $missing): string
    {
        $error = $this->describeError($runtimeException);

        return sprintf("sort: %s: %s: %s\n", $error === 'Is a directory' ? 'read failed' : $missing, $file, $error);
    }

    /**
     * @param  list<string>  $files
     * @param  non-empty-string  $eol
     */
    private function check(CommandContext $commandContext, array $files, string $mode, string $eol): ExecResult
    {
        if (count($files) > 1) {
            return $this->failure(sprintf("sort: extra operand '%s' not allowed with -%s\n", $files[1], $mode), 2);
        }

        $file = $files[0] ?? '-';

        try {
            $lines = $this->lines($commandContext, $file, $eol);
        } catch (RuntimeException $runtimeException) {
            return $this->failure($this->readError($file, $runtimeException, 'open failed'), 2);
        }

        foreach ($lines as $i => $line) {
            // With -u, equal neighbours are out of order too.
            if ($i > 0 && $this->compare($lines[$i - 1], $line) >= ($this->unique ? 0 : 1)) {
                return $this->failure($mode === 'c' ? sprintf("sort: %s:%d: disorder: %s\n", $file, $i + 1, $line) : '');
            }
        }

        return $this->success();
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function sorted(array $lines): array
    {
        // usort() is stable, so lines that compare equal keep their input order.
        usort($lines, $this->compare(...));

        return $lines;
    }

    /**
     * Merge already sorted inputs, taking from the earliest input on ties.
     *
     * @param  list<list<string>>  $inputs
     * @return list<string>
     */
    private function merge(array $inputs): array
    {
        $merged = [];
        $inputs = array_filter($inputs);

        while ($inputs !== []) {
            $next = array_key_first($inputs);

            foreach ($inputs as $i => $input) {
                if ($this->compare($input[0], $inputs[$next][0]) < 0) {
                    $next = $i;
                }
            }

            $merged[] = array_shift($inputs[$next]);
            $inputs = array_filter($inputs);
        }

        return $merged;
    }

    private function compare(string $a, string $b): int
    {
        foreach ($this->keys as $key) {
            $diff = $this->compareKey($this->extract($a, $key), $this->extract($b, $key), $key['modifiers']);

            if ($diff !== 0) {
                return str_contains($key['modifiers'], 'r') ? -$diff : $diff;
            }
        }

        // Equal keys fall back to the whole line, unless -s or -u ask to keep them as equal.
        if ($this->keys !== [] && ($this->unique || $this->stable)) {
            return 0;
        }

        $diff = strcmp($a, $b) <=> 0;

        return str_contains($this->global, 'r') ? -$diff : $diff;
    }

    /**
     * The key's text, already reduced by -d/-i and folded by -f.
     *
     * @param  array{startField: int, startChar: int, endField: ?int, endChar: int, modifiers: string, endBlanks: bool}  $key
     */
    private function extract(string $line, array $key): string
    {
        $begin = $this->skipFields($line, $key['startField'], false);

        if (str_contains($key['modifiers'], 'b')) {
            $begin += strspn($line, self::BLANKS, $begin);
        }

        $begin = min(strlen($line), $begin + $key['startChar']);
        $end = strlen($line);

        if ($key['endField'] !== null) {
            // A character position of zero means the end of the field.
            $end = $this->skipFields($line, $key['endField'] + ($key['endChar'] === 0 ? 1 : 0), $key['endChar'] === 0);

            if ($key['endChar'] !== 0) {
                $end += $key['endBlanks'] ? strspn($line, self::BLANKS, $end) : 0;
                $end = min(strlen($line), $end + $key['endChar']);
            }
        }

        $text = substr($line, $begin, max(0, $end - $begin));

        if (str_contains($key['modifiers'], 'd')) {
            $text = (string) preg_replace('/[^a-zA-Z0-9 \t\n]/', '', $text);
        } elseif (str_contains($key['modifiers'], 'i')) {
            $text = (string) preg_replace('/[^\x20-\x7e]/', '', $text);
        }

        return str_contains($key['modifiers'], 'f') ? strtoupper($text) : $text;
    }

    /** Offset just past $count fields: with -t past each separator (not the last when ending a key on a whole field), else past each blank run and the non-blanks after it. */
    private function skipFields(string $line, int $count, bool $stopAtTab): int
    {
        $pos = 0;
        $length = strlen($line);

        for ($n = $count; $pos < $length && $n > 0; $n--) {
            if ($this->tab !== '') {
                $pos += strcspn($line, $this->tab, $pos);
                $pos += $pos < $length && ! ($stopAtTab && $n === 1) ? 1 : 0;
            } else {
                $pos += strspn($line, self::BLANKS, $pos);
                $pos += strcspn($line, self::BLANKS, $pos);
            }
        }

        return $pos;
    }

    private function compareKey(string $a, string $b, string $modifiers): int
    {
        return match (true) {
            str_contains($modifiers, 'n') => $this->compareNumbers($a, $b),
            str_contains($modifiers, 'g') => $this->compareGeneral($a, $b),
            str_contains($modifiers, 'h') => ($this->unitOrder($a) <=> $this->unitOrder($b)) ?: $this->compareNumbers($a, $b),
            str_contains($modifiers, 'M') => $this->month($a) <=> $this->month($b),
            str_contains($modifiers, 'V') => $this->compareVersions($a, $b),
            str_contains($modifiers, 'R') => strcmp(md5($this->salt.$a), md5($this->salt.$b)) <=> 0 ?: strcmp($a, $b) <=> 0,
            default => strcmp($a, $b) <=> 0,
        };
    }

    /** -n: an optional minus, digits and a fraction, compared exactly; anything else counts as zero. */
    private function compareNumbers(string $a, string $b): int
    {
        [$signA, $intA, $fracA] = $this->parseNumber($a);
        [$signB, $intB, $fracB] = $this->parseNumber($b);

        if ($signA !== $signB) {
            return $signA <=> $signB;
        }

        $magnitude = (strlen($intA) <=> strlen($intB)) ?: (strcmp($intA, $intB) <=> 0) ?: (strcmp($fracA, $fracB) <=> 0);

        return $signA * $magnitude;
    }

    /**
     * @return array{int, string, string} the sign (-1, 0 or 1), and the integer and fraction digits without insignificant zeros
     */
    private function parseNumber(string $text): array
    {
        preg_match('/^[ \t\n]*(-?)(\d*)(?:\.(\d*))?/', $text, $m);
        $integer = ltrim($m[2], '0');
        $fraction = rtrim($m[3] ?? '', '0');

        if ($integer === '' && $fraction === '') {
            return [0, '', ''];
        }

        return [$m[1] === '-' ? -1 : 1, $integer, $fraction];
    }

    /** -h: the SI suffix decides first, for numbers that are not zero. */
    private function unitOrder(string $text): int
    {
        preg_match('/^[ \t\n]*(-?)([\d.]*)(.?)/', $text, $m);
        $order = (int) strpos(' KMGTPEZYRQ', $m[3] === 'k' ? 'K' : $m[3]);

        return $order === 0 || strpbrk($m[2], '123456789') === false ? 0 : ($m[1] === '-' ? -$order : $order);
    }

    /** -g: like strtold(); non-numbers first, then NaN, then numbers. */
    private function compareGeneral(string $a, string $b): int
    {
        $x = $this->float($a);
        $y = $this->float($b);

        return match (true) {
            $x === null || $y === null => ($y === null) <=> ($x === null),
            is_nan($x) || is_nan($y) => is_nan($y) <=> is_nan($x),
            default => $x <=> $y,
        };
    }

    private function float(string $text): ?float
    {
        [$value, $prefix] = Printf_::strtold($text);

        return $prefix === '' ? null : $value;
    }

    private function month(string $text): int
    {
        return self::MONTHS[strtoupper(substr(ltrim($text, self::BLANKS), 0, 3))] ?? 0;
    }

    /** -V: gnulib's filevercmp(), which sets file suffixes aside on a first pass. */
    private function compareVersions(string $a, string $b): int
    {
        // Empty names first, then ".", "..", and other hidden names.
        if ($a === '' || $b === '') {
            return ($a !== '') <=> ($b !== '');
        }

        if ($a[0] === '.' || $b[0] === '.') {
            if (($a[0] === '.') !== ($b[0] === '.')) {
                return $a[0] === '.' ? -1 : 1;
            }

            foreach (['.', '..'] as $special) {
                if ($a === $special || $b === $special) {
                    return ($a !== $special) <=> ($b !== $special);
                }
            }
        }

        $suffix = '/(?<=.)(?:\.[A-Za-z~][A-Za-z0-9~]*)*$/s';
        $prefixA = (string) preg_replace($suffix, '', $a, 1);
        $prefixB = (string) preg_replace($suffix, '', $b, 1);
        $diff = $this->verrevcmp($prefixA, $prefixB);

        return $diff !== 0 || ($prefixA === $a && $prefixB === $b) ? $diff : $this->verrevcmp($a, $b);
    }

    /** Debian's version comparison: non-digit runs by character order (~ before the end, letters before others), digit runs by value. */
    private function verrevcmp(string $a, string $b): int
    {
        $order = fn (string $s, int $i): int => match (true) {
            $i >= strlen($s) => -1,
            ctype_digit($s[$i]) => 0,
            ctype_alpha($s[$i]) => ord($s[$i]),
            $s[$i] === '~' => -2,
            default => ord($s[$i]) + 256,
        };
        $i = 0;
        $j = 0;

        while ($i < strlen($a) || $j < strlen($b)) {
            while (($i < strlen($a) && ! ctype_digit($a[$i])) || ($j < strlen($b) && ! ctype_digit($b[$j]))) {
                $diff = $order($a, $i++) <=> $order($b, $j++);

                if ($diff !== 0) {
                    return $diff;
                }
            }

            preg_match('/\G0*(\d*)/', $a, $x, 0, $i);
            preg_match('/\G0*(\d*)/', $b, $y, 0, $j);
            $i += strlen($x[0]);
            $j += strlen($y[0]);
            $diff = (strlen($x[1]) <=> strlen($y[1])) ?: strcmp($x[1], $y[1]) <=> 0;

            if ($diff !== 0) {
                return $diff;
            }
        }

        return 0;
    }
}
