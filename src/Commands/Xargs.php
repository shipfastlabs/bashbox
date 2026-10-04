<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Interpreter\StdinStream;
use Generator;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/** GNU findutils xargs; commands run one after another, so -P only gets validated. */
final class Xargs extends AbstractCommand
{
    private const array LONG = [
        'null' => ['0', false],
        'arg-file' => ['a', true],
        'delimiter' => ['d', true],
        'eof' => ['e', null],
        'replace' => ['i', null],
        'max-lines' => ['l', null],
        'max-args' => ['n', true],
        'open-tty' => ['o', false],
        'interactive' => ['p', false],
        'no-run-if-empty' => ['r', false],
        'max-chars' => ['s', true],
        'verbose' => ['t', false],
        'exit' => ['x', false],
        'max-procs' => ['P', true],
    ];

    // What a bare -e, -i or -l means.
    private const array DEFAULTS = ['e' => '--eof=', 'i' => '--replace={}', 'l' => '--max-lines=1'];

    // The command buffer GNU uses on Linux.
    private const int ARG_MAX = 131072;

    private const array ESCAPES = ['a' => "\x07", 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", '\\' => '\\'];

    private string $stdout = '';

    private string $stderr = '';

    private int $status = 0;

    private int $runs = 0;

    /** @var list<string> */
    private array $pending = [];

    /** @var non-empty-list<string> */
    private array $command = ['echo'];

    /** @var array<string, true> */
    private array $flags = [];

    private string|StdinStream $childStdin = '';

    private CommandContext $commandContext;

    public function getName(): string
    {
        return 'xargs';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $this->stdout = '';
        $this->stderr = '';
        $this->status = 0;
        $this->runs = 0;
        $this->pending = [];
        $this->flags = [];
        $this->commandContext = $commandContext;

        try {
            [$options, $command] = Getopt::parse($this->attachDefaults($args), '+0a:E:e:i:I:l:L:n:oprs:txP:d:', self::LONG);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $delimiter = null;
        $eof = null;
        $replace = null;
        $maxLines = 0;
        $maxArgs = 0;
        $size = self::ARG_MAX;
        $file = null;

        try {
            foreach ($options as [$option, $value]) {
                switch ($option) {
                    case '0':
                        $delimiter = "\0";

                        break;

                    case 'd':
                        $delimiter = $this->delimiter($value);

                        break;

                    case 'E':
                    case 'e':
                        $eof = $value === '' ? null : $value;

                        break;

                    case 'I':
                    case 'i':
                        $replace = $value;
                        $this->warnExclusive($maxArgs > 0, '--replace/-I/-i', '--max-args');
                        $this->warnExclusive($maxLines > 0, '--replace/-I/-i', '--max-lines');
                        [$maxArgs, $maxLines] = [0, 0];

                        break;

                    case 'L':
                    case 'l':
                        $maxLines = $this->number($value, $option, 1);
                        $this->warnExclusive($maxArgs > 0, $option === 'L' ? '-L' : '--max-lines/-l', '--max-args');
                        $this->warnExclusive($replace !== null, $option === 'L' ? '-L' : '--max-lines/-l', '--replace');
                        [$maxArgs, $replace] = [0, null];

                        break;

                    case 'n':
                        $maxArgs = $this->number($value, 'n', 1);
                        $this->warnExclusive($maxLines > 0, '--max-args/-n', '--max-lines');
                        $maxLines = 0;

                        // GNU ignores -n1 after -I (sv.gnu.org/patch/?1500).
                        if ($replace !== null && $maxArgs === 1) {
                            $maxArgs = 0;
                        } else {
                            $this->warnExclusive($replace !== null, '--max-args/-n', '--replace');
                            $replace = null;
                        }

                        break;

                    case 's':
                        $size = $this->number($value, 's', 1, false);

                        break;

                    case 'P':
                        $this->number($value, 'P', 0, max: 2147483647);

                        break;

                    case 'a':
                        $file = $value;

                        break;

                    default:
                        $this->flags[$option] = true;
                }
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->failure($this->stderr.$invalidArgumentException->getMessage());
        }

        if ($eof !== null && $delimiter !== null) {
            $this->stderr .= "xargs: warning: the -E option has no effect if -0 or -d is used.\n\n";
        }

        if ($file === null || $file === '-') {
            $input = $commandContext->stdin;
            $this->childStdin = '';
        } else {
            try {
                $input = $commandContext->fs->readFile($this->resolvePath($commandContext, $file));
            } catch (RuntimeException $runtimeException) {
                $error = $this->describeError($runtimeException);

                // GNU never checks for read errors, so a directory reads as empty.
                if ($error !== 'Is a directory') {
                    return $this->failure($this->stderr.sprintf("xargs: Cannot open input file '%s': %s\n", $file, $error));
                }

                $input = '';
            }

            // With -a the commands get xargs's own stdin, otherwise /dev/null.
            $this->childStdin = new StdinStream($commandContext->stdin);
        }

        $this->command = $command ?: ['echo'];
        // -I builds each command afresh, so no initial arguments take up room.
        $initialChars = $replace === null ? array_sum(array_map(fn (string $arg): int => strlen($arg) + 1, $this->command)) : 0;
        $exitIfExceeded = isset($this->flags['x']) || $maxLines > 0 || $replace !== null;
        $tokens = $delimiter === null
            ? $this->words($input, $eof, $replace !== null, $size - $initialChars - 1)
            : $this->items($input, $delimiter, $size - $initialChars - 1);

        try {
            if ($initialChars > $size) {
                throw new RuntimeException('cannot fit single argument within argument list size limit', 1);
            }

            try {
                if ($replace === null) {
                    $this->collect($tokens, $initialChars, $size, $maxArgs, $maxLines, $exitIfExceeded);
                } else {
                    $this->replaceEach($tokens, $replace, $size);
                }
            } catch (UnexpectedValueException $unexpectedValueException) {
                // A read error first runs what was collected, unless -I, -L or -x promised whole lines.
                if ($this->pending !== [] && ! $exitIfExceeded) {
                    $this->flush();
                }

                throw new RuntimeException($unexpectedValueException->getMessage(), 1, $unexpectedValueException);
            }

            if ($replace === null && ($this->pending !== [] || (! isset($this->flags['r']) && $this->runs === 0))) {
                $this->flush();
            }
        } catch (RuntimeException $runtimeException) {
            return new ExecResult(stdout: $this->stdout, stderr: $this->stderr.'xargs: '.$runtimeException->getMessage()."\n", exitCode: $runtimeException->getCode());
        }

        return new ExecResult(stdout: $this->stdout, stderr: $this->stderr, exitCode: $this->status);
    }

    /**
     * Batches arguments like GNU: by -n count, -L lines and the -s size limit.
     *
     * @param  Generator<int, string|bool>  $tokens
     */
    private function collect(Generator $tokens, int $chars, int $size, int $maxArgs, int $maxLines, bool $exitIfExceeded): void
    {
        $lines = 0;
        $initialChars = $chars;

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($chars + strlen($token) + 1 > $size) {
                    if ($exitIfExceeded && ($maxArgs > 0 || $maxLines > 0)) {
                        throw new RuntimeException('argument list too long', 1);
                    }

                    $this->flush();
                    $chars = $initialChars;
                }

                $this->pending[] = $token;
                $chars += strlen($token) + 1;

                if (count($this->pending) === $maxArgs) {
                    $this->flush();
                    $chars = $initialChars;
                }

                continue;
            }

            $lines += $token ? 1 : 0;

            if ($maxLines > 0 && $lines >= $maxLines) {
                $this->flush();
                $chars = $initialChars;
                $lines = 0;
            }
        }
    }

    /**
     * -I: one run per line, with the line put in place of $replace in every argument but the command name.
     *
     * @param  Generator<int, string|bool>  $tokens
     */
    private function replaceEach(Generator $tokens, string $replace, int $size): void
    {
        $line = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $line = $token;

                continue;
            }

            $argv = [$this->command[0]];
            $chars = strlen($this->command[0]) + 1;

            if ($chars > $size) {
                throw new RuntimeException('cannot fit single argument within argument list size limit', 1);
            }

            foreach (array_slice($this->command, 1) as $arg) {
                $inserted = $replace === '' ? $line : str_replace($replace, $line, $arg);

                // GNU finds an empty pattern over and over, so any text around it overflows.
                if (strlen($inserted) > $size - 2 || ($replace === '' && $arg !== '')) {
                    throw new RuntimeException('command too long', 1);
                }

                $argv[] = $inserted;
                $chars += strlen($inserted) + 1;

                if ($chars > $size) {
                    throw new RuntimeException('argument list too long', 1);
                }
            }

            $this->run($argv);
        }
    }

    private function flush(): void
    {
        $argv = [...$this->command, ...$this->pending];
        $this->pending = [];
        $this->run($argv);
    }

    /**
     * Splits input like GNU's read_line into blank-separated words with quotes and backslashes, then true at a line end that counts for -L (false otherwise).
     *
     * @return Generator<int, string|bool>
     *
     * @throws UnexpectedValueException
     */
    private function words(string $input, ?string $eof, bool $wholeLines, int $maxLength): Generator
    {
        $state = 'space';
        $quote = '';
        $word = '';
        $first = true;
        $previous = '';
        $warned = false;

        for ($i = 0, $length = strlen($input); $i < $length; $i++) {
            $char = $input[$i];
            [$before, $previous] = [$previous, $char];

            if ($state === 'space') {
                if (ctype_space($char)) {
                    continue;
                }

                $state = 'normal';
            }

            if ($state === 'normal') {
                if ($char === "\n") {
                    // A blank before the newline continues the line for -L.
                    $counted = $before !== ' ' && $before !== "\t";

                    if ($eof !== null && $this->cString($word) === $eof) {
                        if (! $first) {
                            yield $counted;
                        }

                        return;
                    }

                    yield $this->cString($word);

                    yield $counted;
                    [$state, $word, $first] = ['space', '', true];

                    continue;
                }

                if (! $wholeLines && ($char === ' ' || $char === "\t")) {
                    if ($eof !== null && $this->cString($word) === $eof) {
                        return;
                    }

                    yield $this->cString($word);
                    [$state, $word, $first] = ['space', '', false];

                    continue;
                }

                if ($char === '\\') {
                    $state = 'backslash';

                    continue;
                }

                if ($char === "'" || $char === '"') {
                    [$state, $quote] = ['quote', $char];

                    continue;
                }
            } elseif ($state === 'quote') {
                if ($char === "\n") {
                    throw new UnexpectedValueException($this->unmatched($quote));
                }

                if ($char === $quote) {
                    $state = 'normal';

                    continue;
                }
            } else {
                $state = 'normal';
            }

            if ($char === "\0" && ! $warned) {
                $warned = true;
                $this->stderr .= "xargs: WARNING: a NUL character occurred in the input.  It cannot be passed through in the argument list.  Did you mean to use the --null option?\n";
            }

            if (strlen($word) >= $maxLength) {
                throw new UnexpectedValueException('argument line too long');
            }

            $word .= $char;
        }

        // GNU drops an empty last word, even an unterminated '' or '.
        if ($word === '') {
            return;
        }

        if ($state === 'quote') {
            throw new UnexpectedValueException($this->unmatched($quote));
        }

        if (! $first || $eof === null || $this->cString($word) !== $eof) {
            yield $this->cString($word);

            yield false;
        }
    }

    /**
     * Splits input at a -0/-d delimiter, taking every character literally.
     *
     * @param  non-empty-string  $delimiter
     * @return Generator<int, string|bool>
     *
     * @throws UnexpectedValueException
     */
    private function items(string $input, string $delimiter, int $maxLength): Generator
    {
        $items = explode($delimiter, $input);
        $last = array_pop($items);

        foreach ([...$items, ...($last === '' ? [] : [$last])] as $i => $item) {
            if (strlen($item) > $maxLength) {
                throw new UnexpectedValueException('argument line too long');
            }

            yield $this->cString($item);

            yield $i < count($items);
        }
    }

    /** @param non-empty-list<string> $argv */
    private function run(array $argv): void
    {
        $this->runs++;
        $trace = implode(' ', array_map($this->shellEscape(...), $argv));

        if (isset($this->flags['p'])) {
            // -p asks on /dev/tty, which the sandbox doesn't have.
            $this->stderr .= $trace;

            throw new RuntimeException('failed to open /dev/tty for reading: No such device or address', 1);
        }

        if (isset($this->flags['t'])) {
            $this->stderr .= $trace."\n";
        }

        if (isset($this->flags['o'])) {
            $this->stderr .= "xargs: '/dev/tty': No such device or address\n";
            $this->status = 123;

            return;
        }

        $result = $this->spawn($this->commandContext, $argv, stdin: $this->childStdin);

        if (is_array($result)) {
            throw new RuntimeException($argv[0].': '.$result[0], $result[1]);
        }

        $this->stdout .= $result->stdout;
        $this->checkOutputSize($this->commandContext, strlen($this->stdout));
        $this->stderr .= $result->stderr;

        if ($result->exitCode === 255) {
            throw new RuntimeException($argv[0].': exited with status 255; aborting', 124);
        }

        if ($result->exitCode !== 0) {
            $this->status = 123;
        }
    }

    /** Parses a numeric option like GNU's parse_num; only -s survives a value out of range. */
    private function number(string $value, string $option, int $min, bool $fatal = true, int $max = PHP_INT_MAX): int
    {
        $try = "Try 'xargs --help' for more information.\n";

        if (preg_match('/^\s*[-+]?\d+\z/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf("xargs: invalid number \"%s\" for -%s option\n%s", $value, $option, $try));
        }

        if ((int) $value >= $min && (int) $value <= $max) {
            return (int) $value;
        }

        $message = (int) $value < $min
            ? sprintf("xargs: value %s for -%s option should be >= %d\n", $value, $option, $min)
            : sprintf("xargs: value %s for -%s option should be <= %d\n", $value, $option, $max);

        if ($fatal) {
            throw new InvalidArgumentException($message.$try);
        }

        $this->stderr .= $message;

        return max($min, min($max, (int) $value));
    }

    /**
     * getopt gives -e, -i and -l a value only when it's attached, so a bare one gets its default attached.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    private function attachDefaults(array $args): array
    {
        $attached = [];

        while (($arg = array_shift($args)) !== null) {
            if ($arg === '--' || $arg === '-' || ! str_starts_with($arg, '-')) {
                return [...$attached, $arg, ...$args];
            }

            // The option at the end of $arg that has no value attached, and what comes before it.
            [$head, $bare] = ['', ''];

            if (str_starts_with($arg, '--')) {
                $names = str_contains($arg, '=') ? [] : array_filter(array_keys(self::LONG), fn (string $name): bool => str_starts_with($name, substr($arg, 2)));
                $bare = count($names) === 1 ? self::LONG[current($names)][0] : '';
            } elseif (preg_match('/^-([^-aEILnsPdeil]*)([aEILnsPdeil])$/', $arg, $m) === 1) {
                [$head, $bare] = [$m[1] === '' ? '' : '-'.$m[1], $m[2]];
            }

            if (isset(self::DEFAULTS[$bare])) {
                array_push($attached, ...array_filter([$head, self::DEFAULTS[$bare]]));
            } else {
                $attached[] = $arg;
                array_push($attached, ...($bare !== '' && str_contains('aEILnsPd', $bare) ? array_splice($args, 0, 1) : []));
            }
        }

        return $attached;
    }

    private function warnExclusive(bool $set, string $option, string $excluded): void
    {
        if ($set) {
            $this->stderr .= sprintf("xargs: warning: options %s and %s are mutually exclusive, ignoring previous %s value\n", $excluded, $option, $excluded);
        }
    }

    /** @return non-empty-string */
    private function delimiter(string $spec): string
    {
        if (strlen($spec) === 1) {
            return $spec;
        }

        if (! str_starts_with($spec, '\\')) {
            throw new InvalidArgumentException(sprintf("xargs: Invalid input delimiter specification %s: the delimiter must be either a single character or an escape sequence starting with \\.\n", $spec));
        }

        if (isset(self::ESCAPES[$spec[1]])) {
            return self::ESCAPES[$spec[1]];
        }

        $hex = $spec[1] === 'x';

        if (! $hex && ! ctype_digit($spec[1])) {
            throw new InvalidArgumentException(sprintf("xargs: Invalid escape sequence %s in input delimiter specification.\n", $spec));
        }

        // Parsed with strtoul, which allows leading blanks, a sign and a 0x prefix.
        $digits = substr($spec, $hex ? 2 : 1);
        $value = 0;
        $rest = $digits;

        if (preg_match($hex ? '/^\s*([-+]?)(?:0x(?=[0-9a-f]))?([0-9a-f]+)/i' : '/^\s*([-+]?)([0-7]+)/', $digits, $m) === 1) {
            $value = $hex ? hexdec($m[2]) : octdec($m[2]);
            $value = $m[1] === '-' && $value > 0 ? PHP_INT_MAX : $value;
            $rest = substr($digits, strlen($m[0]));
        }

        if ($value > 255) {
            throw new InvalidArgumentException(sprintf("xargs: Invalid escape sequence %s in input delimiter specification; character values must not exceed %s.\n", $spec, $hex ? 'ff' : '377'));
        }

        if ($rest !== '') {
            throw new InvalidArgumentException(sprintf("xargs: Invalid escape sequence %s in input delimiter specification; trailing characters %s not recognised.\n", $spec, $rest));
        }

        return chr((int) $value);
    }

    private function unmatched(string $quote): string
    {
        return sprintf('unmatched %s quote; by default quotes are special to xargs unless you use the -0 option', $quote === '"' ? 'double' : 'single');
    }

    // An argument goes to exec() as a C string, so a NUL ends it.
    private function cString(string $arg): string
    {
        return explode("\0", $arg, 2)[0];
    }
}
