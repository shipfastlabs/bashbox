<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecResult;
use BashBox\Regex\PosixRegex;
use BashBox\Regex\RegexException;
use BashBox\Regex\SafePcreRegex;
use RuntimeException;
use UnexpectedValueException;

/**
 * GNU sed 4.9 in the C locale, ported from compile.c and execute.c; r R w W use the virtual filesystem and e the sandboxed shell.
 *
 * @phpstan-type Regex array{pcre: string, nsub: int, noSub: bool, re: string, flags: string}
 * @phpstan-type Address array{type: string, n: int, step?: int, re?: Regex|null}
 * @phpstan-type Part array{string, int, int}
 * @phpstan-type Subst array{re: Regex|null, parts: list<Part>, maxId: int, global: bool, print: int, numb: int, eval: bool, out: string|null}
 * @phpstan-type Command array{cmd: string, a1: Address|null, a2: Address|null, bang: bool, jump?: int, text?: string|null, int?: int, file?: string, append?: bool, s?: Subst, map?: array<string, string>, label?: string}
 */
final class Sed_ extends AbstractCommand
{
    private const int INACTIVE = 0;

    private const int ACTIVE = 1;

    private const int CLOSED = 2;

    private const int TEXT_BUFFER = 0;

    private const int TEXT_REPLACEMENT = 1;

    private const int TEXT_REGEX = 2;

    /** Case conversion flags of a replacement part, as in GNU's enum replacement_types. */
    private const int UPPER = 1;

    private const int LOWER = 2;

    private const int UPPER_FIRST = 4;

    private const int LOWER_FIRST = 8;

    private const int FIRST = self::UPPER_FIRST | self::LOWER_FIRST;

    /** Long option => [short option, 0 no argument | 1 required | 2 optional] */
    private const array LONG_OPTIONS = [
        'binary' => ['b', 0], 'regexp-extended' => ['E', 0], 'expression' => ['e', 1], 'file' => ['f', 1],
        'in-place' => ['i', 2], 'line-length' => ['l', 1], 'null-data' => ['z', 0], 'zero-terminated' => ['z', 0],
        'quiet' => ['n', 0], 'silent' => ['n', 0], 'sandbox' => ['S', 0], 'separate' => ['s', 0],
        'unbuffered' => ['u', 0], 'version' => ['v', 0], 'help' => ['h', 0], 'follow-symlinks' => ['F', 0],
        'debug' => ['D', 0], 'posix' => ['p', 0],
    ];

    private const string USAGE = <<<'TXT'
        Usage: sed [OPTION]... {script-only-if-no-other-script} [input-file]...

          -n, --quiet, --silent
                         suppress automatic printing of pattern space
              --debug
                         annotate program execution
          -e script, --expression=script
                         add the script to the commands to be executed
          -f script-file, --file=script-file
                         add the contents of script-file to the commands to be executed
          --follow-symlinks
                         follow symlinks when processing in place
          -i[SUFFIX], --in-place[=SUFFIX]
                         edit files in place (makes backup if SUFFIX supplied)
          -l N, --line-length=N
                         specify the desired line-wrap length for the `l' command
          --posix
                         disable all GNU extensions.
          -E, -r, --regexp-extended
                         use extended regular expressions in the script
                         (for portability use POSIX -E).
          -s, --separate
                         consider files as separate rather than as a single,
                         continuous long stream.
              --sandbox
                         operate in sandbox mode (disable e/r/w commands).
          -u, --unbuffered
                         load minimal amounts of data from the input files and flush
                         the output buffers more often
          -z, --null-data
                         separate lines by NUL characters
              --help     display this help and exit
              --version  output version information and exit

        If no -e, --expression, -f, or --file option is given, then the first
        non-option argument is taken as the sed script to interpret.  All
        remaining arguments are names of input files; if no input files are
        specified, then the standard input is read.

        GNU sed home page: <https://www.gnu.org/software/sed/>.
        General help using GNU software: <https://www.gnu.org/gethelp/>.

        TXT;

    private const string VERSION = <<<'TXT'
        sed (GNU sed) 4.9
        Copyright (C) 2022 Free Software Foundation, Inc.
        License GPLv3+: GNU GPL version 3 or later <https://gnu.org/licenses/gpl.html>.
        This is free software: you are free to change and redistribute it.
        There is NO WARRANTY, to the extent permitted by law.

        Written by Jay Fenlason, Tom Lord, Ken Pizzini,
        Paolo Bonzini, Jim Meyering, and Assaf Gordon.

        This sed program was built without SELinux support.

        GNU sed home page: <https://www.gnu.org/software/sed/>.
        General help using GNU software: <https://www.gnu.org/gethelp/>.
        E-mail bug reports to: <bug-sed@gnu.org>.

        TXT;

    private CommandContext $commandContext;

    private bool $quiet = false;

    private bool $extended = false;

    private bool $separate = false;

    private bool $sandbox = false;

    private bool $posix = false;

    private bool $debug = false;

    private bool $unbuffered = false;

    /** Indentation of the --debug trace, which like GNU's follows the `{` and `}` executed */
    private int $blockLevel = 0;

    private ?string $inPlace = null;

    private string $delim = "\n";

    private int $lineLength = 70;

    private string $prog = '';

    private int $pos = 0;

    private int $line = 0;

    private ?string $scriptFile = null;

    private int $expressions = 0;

    private bool $compiled = false;

    /** @var list<Command> */
    private array $program = [];

    /** @var list<array{int, int, ?string, int}> open `{`: command index, expression number, script file, line */
    private array $blocks = [];

    /** @var array<string, int> */
    private array $labels = [];

    /** @var list<array{int, string}> */
    private array $branches = [];

    private ?string $pendingText = null;

    private int $pendingCommand = 0;

    private string $space = '';

    private bool $chomped = true;

    private string $hold = '';

    private bool $holdChomped = true;

    /** Whether GNU's scratch buffer, which `e` swaps with the pattern space, holds a chomped line */
    private bool $accumChomped = true;

    private bool $replaced = false;

    /** @var Regex|null */
    private ?array $lastRegex = null;

    /** @var array<int, int> */
    private array $range = [];

    /** @var array<int, int> where a `addr1,+N` or `addr1,~N` range ends */
    private array $rangeEnd = [];

    /** @var list<array{?string, ?string}> queued text, or the name of a file to copy */
    private array $appendQueue = [];

    private int $jumps = 0;

    /** @var list<string> */
    private array $files = [];

    private int $nextFile = 0;

    private ?string $data = null;

    private int $offset = 0;

    private string $fileName = '-';

    private int $lineNumber = 0;

    private bool $resetAtNextFile = true;

    private int $badCount = 0;

    private bool $stdinUsed = false;

    /** @var array<string, array{string, int}> files read by R: contents and position */
    private array $rFiles = [];

    /** @var array<string, string> */
    private array $sinks = ['stdout' => '', 'stderr' => ''];

    /** @var array<string, array{string, bool}> output name => [sink, whether its last line lacks a newline] */
    private array $outputs = ['' => ['stdout', false]];

    public function getName(): string
    {
        return 'sed';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        // A fresh instance per run: `e` may run sed again.
        return (new self)->run($args, $commandContext);
    }

    /** @param list<string> $args */
    private function run(array $args, CommandContext $commandContext): ExecResult
    {
        $this->commandContext = $commandContext;
        $columns = (int) ($commandContext->env['COLS'] ?? 0);
        $this->lineLength = $columns > 1 ? $columns - 1 : 70;

        try {
            $operands = $this->parseOptions($args);

            if (! $this->compiled) {
                $this->compile(array_shift($operands) ?? throw new UnexpectedValueException(self::USAGE, 1));
            }

            $this->finishProgram();

            if ($this->debug) {
                $this->sinks['stdout'] .= "SED PROGRAM:\n";
                $this->blockLevel = 1;

                foreach ($this->program as $command) {
                    $this->debugCommand($command);
                }

                $this->blockLevel = 0;
            }

            $status = $this->processFiles($operands);
        } catch (UnexpectedValueException $unexpectedValueException) {
            $status = $unexpectedValueException->getCode();
            $this->sinks[$status === 0 ? 'stdout' : 'stderr'] .= $unexpectedValueException->getMessage();
        }

        foreach (array_diff_key($this->sinks, ['stdout' => 0, 'stderr' => 0, "\0" => 0, "\1" => 0, "\2" => 0]) as $path => $content) {
            $this->commandContext->fs->writeFile($path, $content);
        }

        // --posix's w /dev/stdout and /dev/stderr are files of their own, flushed on close: before stdout's buffer, after stderr's.
        return new ExecResult(
            stdout: ($this->sinks["\1"] ?? '').$this->sinks['stdout'],
            stderr: $this->sinks['stderr'].($this->sinks["\2"] ?? ''),
            exitCode: $status,
        );
    }

    /**
     * getopt_long with GNU argument permutation; scripts are compiled as their options are met.
     *
     * @param  list<string>  $args
     * @return list<string> the operands
     */
    private function parseOptions(array $args): array
    {
        $operands = [];
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if ($arg === '--') {
                return [...$operands, ...array_slice($args, $i + 1)];
            }

            if ($arg === '-' || ! str_starts_with($arg, '-')) {
                $operands[] = $arg;
            } elseif (str_starts_with($arg, '--')) {
                $name = explode('=', substr($arg, 2), 2)[0];
                $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 3) : null;
                $matches = array_filter(self::LONG_OPTIONS, fn (string $long): bool => str_starts_with($long, $name), ARRAY_FILTER_USE_KEY);
                $matches = isset($matches[$name]) ? [$name => $matches[$name]] : $matches;

                if ($matches === []) {
                    throw $this->usage(sprintf("sed: unrecognized option '%s'\n", $arg));
                }

                if (count(array_unique(array_column($matches, 0))) > 1) {
                    $names = implode(' ', array_map(fn (string $long): string => sprintf("'--%s'", $long), array_keys($matches)));

                    throw $this->usage(sprintf("sed: option '%s' is ambiguous; possibilities: %s\n", $arg, $names));
                }

                $long = (string) array_key_first($matches);
                [$short, $hasArgument] = $matches[$long];

                if ($hasArgument === 0 && $value !== null) {
                    throw $this->usage(sprintf("sed: option '--%s' doesn't allow an argument\n", $long));
                }

                if ($hasArgument === 1 && $value === null) {
                    $value = $args[++$i] ?? throw $this->usage(sprintf("sed: option '--%s' requires an argument\n", $long));
                }

                $this->option($short, $value);
            } else {
                for ($j = 1, $len = strlen($arg); $j < $len; $j++) {
                    $short = $arg[$j];
                    $rest = $j + 1 < $len ? substr($arg, $j + 1) : null;

                    if (str_contains('efl', $short)) {
                        $this->option($short, $rest ?? $args[++$i] ?? throw $this->usage(sprintf("sed: option requires an argument -- '%s'\n", $short)));

                        break;
                    }

                    if ($short === 'i') {
                        $this->option($short, $rest);

                        break;
                    }

                    if (! str_contains('bsnrzuE', $short)) {
                        throw $this->usage(sprintf("sed: invalid option -- '%s'\n", $short));
                    }

                    $this->option($short, null);
                }
            }
        }

        return $operands;
    }

    private function option(string $short, ?string $value): void
    {
        match ($short) {
            'n' => $this->quiet = true,
            'E', 'r' => $this->extended = true,
            's' => $this->separate = true,
            'z' => $this->delim = "\0",
            'S' => $this->sandbox = true,
            'p' => $this->posix = true,
            'D' => $this->debug = true,
            'u' => $this->unbuffered = true,
            'l' => $this->lineLength = (int) $value,
            'e' => $this->compile((string) $value),
            'f' => $this->compile($this->readScriptFile((string) $value), (string) $value),
            'i' => [$this->separate, $this->inPlace] = [true, $value === null ? '*' : (str_contains($value, '*') ? $value : '*'.$value)],
            // Exit code 0 sends the text to stdout.
            'v' => throw new UnexpectedValueException(self::VERSION, 0),
            'h' => throw new UnexpectedValueException(self::USAGE."E-mail bug reports to: <bug-sed@gnu.org>.\n", 0),
            default => null, // -b and --follow-symlinks change nothing here
        };
    }

    private function usage(string $message): UnexpectedValueException
    {
        return new UnexpectedValueException($message.self::USAGE, 1);
    }

    private function readScriptFile(string $name): string
    {
        if ($name === '-' || $name === '/dev/stdin') {
            return $this->readStdin();
        }

        try {
            return $this->commandContext->fs->readFile($this->resolvePath($this->commandContext, $name));
        } catch (RuntimeException $runtimeException) {
            // fopen() opens a directory, which then reads as empty.
            return str_starts_with($runtimeException->getMessage(), 'EISDIR') ? ''
                : throw $this->panic(sprintf("couldn't open file %s: %s", $name, $this->describeError($runtimeException)));
        }
    }

    private function readStdin(): string
    {
        $data = $this->stdinUsed ? '' : $this->commandContext->stdin;
        $this->stdinUsed = true;

        return $data;
    }

    private function compile(string $script, ?string $file = null): void
    {
        $first = ! $this->compiled;
        $this->compiled = true;
        $this->prog = $script;
        $this->pos = 0;
        $this->scriptFile = $file;
        $this->line = $file === null ? 0 : 1;
        $this->expressions += $file === null ? 1 : 0;

        if ($this->pendingText !== null) {
            $this->program[$this->pendingCommand]['text'] = $this->readText("\n");
        }

        while (true) {
            do {
                $ch = $this->inchar();
            } while ($ch !== null && str_contains("; \t\n\v\f\r", $ch));

            if ($ch === null) {
                if ($this->posix && $this->pendingText !== null) {
                    throw $this->badProg('incomplete command');
                }

                return;
            }

            $command = ['cmd' => '', 'a1' => null, 'a2' => null, 'bang' => false];
            $address = $this->compileAddress($ch);

            if ($address !== null) {
                if ($address['type'] === 'step' || $address['type'] === 'stepmod') {
                    throw $this->badProg('invalid usage of +N or ~N as first address');
                }

                $command['a1'] = $address;
                $ch = $this->inNonblank();

                if ($ch === ',') {
                    $command['a2'] = $this->compileAddress($this->inNonblank()) ?? throw $this->badProg("unexpected `,'");
                    $ch = $this->inNonblank();
                }

                if ($address['type'] === 'num' && $address['n'] === 0
                    && ($this->posix || ($command['a2'] === null ? $ch !== 'r' : $command['a2']['type'] !== 'regex'))) {
                    throw $this->badProg('invalid usage of line address 0');
                }
            }

            if ($ch === '!') {
                $command['bang'] = true;
                $ch = $this->inNonblank();

                if ($ch === '!') {
                    throw $this->badProg("multiple `!'s");
                }
            }

            // --posix rejects the GNU commands, and a second address where POSIX allows one.
            if ($this->posix && $ch !== null && str_contains('eFvzLQTRW', $ch)) {
                throw $this->badProg(sprintf("unknown command: `%s'", $ch));
            }

            if ($this->posix && $ch !== null && str_contains('ail=r', $ch) && $command['a2'] !== null) {
                throw $this->badProg('command only uses one address');
            }

            $command['cmd'] = (string) $ch;
            $index = count($this->program);

            switch ($ch) {
                case '#':
                    if ($command['a1'] !== null) {
                        throw $this->badProg("comments don't accept any addresses");
                    }

                    // #n on the first line of the first script acts like -n.
                    $ch = $this->inchar();
                    $this->quiet = $this->quiet || ($ch === 'n' && $first && $this->line < 2 && $this->pos === 2);

                    while ($ch !== null && $ch !== "\n") {
                        $ch = $this->inchar();
                    }

                    continue 2;

                case 'v':
                    if (version_compare($this->readLabel() ?: '4.0', '4.9', '>')) {
                        throw $this->badProg('expected newer version of sed');
                    }

                    continue 2;

                case '{':
                    $this->blocks[] = [$index, $this->expressions, $this->scriptFile, $this->line];
                    $command['bang'] = ! $command['bang'];

                    break;

                case '}':
                    $block = array_pop($this->blocks) ?? throw $this->badProg("unexpected `}'");

                    if ($command['a1'] !== null) {
                        throw $this->badProg("`}' doesn't want any addresses");
                    }

                    $this->readEndOfCmd();
                    $this->program[$block[0]]['jump'] = $index;

                    break;

                case 'e':
                    if ($this->sandbox) {
                        throw $this->badProg('e/r/w commands disabled in sandbox mode');
                    }

                    $ch = $this->inNonblank();

                    if ($ch === null || $ch === "\n") {
                        $command['text'] = null;

                        break;
                    }

                    $this->savchar($ch);
                    $command['text'] = $this->readTextCommand($index);

                    break;

                case 'a':
                case 'i':
                case 'c':
                    $command['text'] = $this->readTextCommand($index);

                    break;

                case ':':
                    if ($command['a1'] !== null) {
                        throw $this->badProg(": doesn't want any addresses");
                    }

                    $command['label'] = $this->readLabel();
                    $this->labels[$command['label'] !== '' ? $command['label'] : throw $this->badProg('":" lacks a label')] = $index;

                    break;

                case 'T':
                case 'b':
                case 't':
                    $this->branches[] = [$index, $this->readLabel()];

                    break;

                case 'Q':
                case 'q':
                    if ($command['a2'] !== null) {
                        throw $this->badProg('command only uses one address');
                    }

                    // no break
                case 'L':
                case 'l':
                    $ch = $this->inNonblank();
                    $command['int'] = $ch !== null && ctype_digit($ch) && ! $this->posix ? $this->inInteger($ch) : -1;

                    if ($command['int'] === -1) {
                        $this->savchar($ch);
                    }

                    $this->readEndOfCmd();

                    break;

                case '=':
                case 'd':
                case 'D':
                case 'F':
                case 'g':
                case 'G':
                case 'h':
                case 'H':
                case 'n':
                case 'N':
                case 'p':
                case 'P':
                case 'z':
                case 'x':
                    $this->readEndOfCmd();

                    break;

                case 'r':
                    $command['file'] = $this->readFilename();
                    // 0r inserts the file before the first line.
                    $command['append'] = $command['a1'] !== ['type' => 'num', 'n' => 0] || $command['a2'] !== null;
                    $command['a1'] = $command['append'] ? $command['a1'] : ['type' => 'num', 'n' => 1];

                    break;

                case 'R':
                    $command['file'] = $this->readFilename();
                    $this->rFiles[$command['file']] ??= [$this->readOptionalFile($command['file']) ?? '', 0];

                    break;

                case 'W':
                case 'w':
                    $command['file'] = $this->openWriteFile();

                    break;

                case 's':
                    $command['s'] = $this->compileSubstitution();

                    break;

                case 'y':
                    $command['map'] = $this->compileTransliteration();

                    break;

                case null:
                    throw $this->badProg('missing command');

                default:
                    throw $this->badProg(sprintf("unknown command: `%s'", $ch));
            }

            $this->program[] = $command;
        }
    }

    /** @return string|null the text, or null while it is still pending */
    private function readTextCommand(int $index): ?string
    {
        $ch = $this->inNonblank();

        // --posix has no one-liner form.
        if ($ch === null || ($this->posix && $ch !== '\\')) {
            throw $this->badProg("expected \\ after `a', `c' or `i'");
        }

        if ($ch === '\\') {
            $ch = $this->inchar();
        } else {
            // GNU's one-liner form: `a text`.
            $this->savchar($ch);
            $ch = "\n";
        }

        $this->pendingText = '';
        $this->pendingCommand = $index;

        return $this->readText($ch);
    }

    /** Reads the text of a, i, c or e up to an unescaped newline; text cut short by the end of a script stays pending for the next -e. */
    private function readText(?string $leadin): ?string
    {
        if ($leadin === null) {
            return null;
        }

        $text = $this->pendingText.($leadin !== "\n" ? $leadin : '');

        for ($ch = $this->inchar(); $ch !== null && $ch !== "\n"; $ch = $this->inchar()) {
            if ($ch === '\\') {
                $ch = $this->inchar();
                $text .= $ch !== null ? '\\' : '';
            }

            if ($ch === null) {
                $this->pendingText = $text."\n";

                return null;
            }

            $text .= $ch;
        }

        $this->pendingText = null;

        return $this->normalize($text."\n", self::TEXT_BUFFER);
    }

    /** @return Address|null */
    private function compileAddress(?string $ch): ?array
    {
        if ($ch === '/' || $ch === '\\') {
            $slash = $ch === '\\' ? $this->inchar() : '/';
            $pattern = $this->matchSlash($slash, true) ?? throw $this->badProg('unterminated address regex');
            $flags = '';

            while (true) {
                $ch = $this->inNonblank();

                if ($this->posix || ($ch !== 'I' && $ch !== 'M')) {
                    $this->savchar($ch);

                    return ['type' => 'regex', 'n' => 0, 're' => $this->compileRegex($pattern, $flags, 0)];
                }

                $flags .= $ch;
            }
        }

        if ($ch !== null && ctype_digit($ch)) {
            $n = $this->inInteger($ch);
            $ch = $this->inNonblank();

            if ($ch !== '~' || $this->posix) {
                $this->savchar($ch);

                return ['type' => 'num', 'n' => $n];
            }

            $step = $this->inInteger($this->inNonblank());

            return $step > 0 ? ['type' => 'mod', 'n' => $n, 'step' => $step] : ['type' => 'num', 'n' => $n];
        }

        if (($ch === '+' || $ch === '~') && ! $this->posix) {
            $step = $this->inInteger($this->inNonblank());

            // +0 and ~0 end the range on the line that starts it.
            return ['type' => $step === 0 ? 'null' : ($ch === '+' ? 'step' : 'stepmod'), 'n' => $step];
        }

        return $ch === '$' ? ['type' => 'last', 'n' => 0] : null;
    }

    /** @return Subst */
    private function compileSubstitution(): array
    {
        $slash = $this->inchar();
        $pattern = $this->matchSlash($slash, true);
        $replacement = $pattern === null ? null : $this->matchSlash($slash, false);

        if ($pattern === null || $replacement === null) {
            throw $this->badProg("unterminated `s' command");
        }

        [$parts, $maxId] = $this->parseReplacement($replacement);
        $sub = ['parts' => $parts, 'maxId' => $maxId, 'global' => false, 'print' => 0, 'numb' => 0, 'eval' => false, 'out' => null];
        $flags = '';

        while (true) {
            $ch = $this->inNonblank();

            if ($this->posix && $ch !== null && str_contains('iImMe', $ch)) {
                throw $this->badProg("unknown option to `s'");
            }

            switch ($ch) {
                case 'i':
                case 'I':
                    $flags .= 'I';

                    break;

                case 'm':
                case 'M':
                    $flags .= 'M';

                    break;

                case 'e':
                    $sub['eval'] = true;

                    break;

                case 'p':
                    if ($sub['print'] !== 0) {
                        throw $this->badProg("multiple `p' options to `s' command");
                    }

                    // 1 prints before the e flag runs, 2 after.
                    $sub['print'] = $sub['eval'] ? 2 : 1;

                    break;

                case 'g':
                    if ($sub['global']) {
                        throw $this->badProg("multiple `g' options to `s' command");
                    }

                    $sub['global'] = true;

                    break;

                case 'w':
                    $sub['out'] = $this->openWriteFile();

                    break 2;

                case '}':
                case '#':
                    $this->savchar($ch);

                    break 2;

                case null:
                case "\n":
                case ';':
                    break 2;

                default:
                    if ($ch === "\r" && $this->inchar() === "\n") {
                        break 2;
                    }

                    if (! ctype_digit($ch)) {
                        throw $this->badProg("unknown option to `s'");
                    }

                    if ($sub['numb'] !== 0) {
                        throw $this->badProg("multiple number options to `s' command");
                    }

                    $sub['numb'] = $this->inInteger($ch) ?: throw $this->badProg("number option to `s' command may not be zero");
            }
        }

        $sub['re'] = $this->compileRegex($pattern, $flags, $maxId + 1);

        if ($sub['eval'] && $this->sandbox) {
            throw $this->badProg('e/r/w commands disabled in sandbox mode');
        }

        return $sub;
    }

    /** @return array<string, string> */
    private function compileTransliteration(): array
    {
        $slash = $this->inchar();
        $from = $this->matchSlash($slash, false) ?? throw $this->badProg("unterminated `y' command");
        $from = $this->normalize($from, self::TEXT_BUFFER);

        $to = $this->matchSlash($slash, false) ?? throw $this->badProg("unterminated `y' command");
        $to = $this->normalize($to, self::TEXT_BUFFER);

        if (strlen($from) !== strlen($to)) {
            throw $this->badProg("strings for `y' command are different lengths");
        }

        $this->readEndOfCmd();

        return $from === '' ? [] : array_combine(str_split($from), str_split($to));
    }

    /**
     * Splits the replacement into [literal prefix, group number or -1, case conversion] parts like GNU's setup_replacement(), with the highest group referenced.
     *
     * @return array{list<Part>, int}
     */
    private function parseReplacement(string $text): array
    {
        $text = $this->normalize($text, self::TEXT_REPLACEMENT);
        $parts = [];
        $type = 0;
        $saved = 0;
        $base = 0;
        $maxId = 0;
        $len = strlen($text);

        for ($p = 0; $p < $len; $p++) {
            if ($text[$p] === '&') {
                $parts[] = [substr($text, $base, $p - $base), 0, $type];
                $type = $saved;
                $base = $p + 1;
            } elseif ($text[$p] === '\\') {
                $part = [substr($text, $base, $p - $base), -1, $type];
                $type = $saved;
                $ch = $text[++$p] ?? '\\';

                if (ctype_digit($ch)) {
                    $part[1] = (int) $ch;
                    $maxId = max($maxId, $part[1]);
                } elseif (! $this->posix && in_array($ch, ['L', 'U', 'E'], true)) {
                    $type = ['L' => self::LOWER, 'U' => self::UPPER, 'E' => 0][$ch];
                    $saved = ['L' => self::LOWER, 'U' => self::UPPER, 'E' => 0][$ch];
                } elseif (! $this->posix && ($ch === 'l' || $ch === 'u')) {
                    $saved = $type;
                    $type |= $ch === 'l' ? self::LOWER_FIRST : self::UPPER_FIRST;
                } else {
                    $part[0] .= $ch;
                }

                $parts[] = $part;
                $base = $p + 1;
            }
        }

        if ($base < $len) {
            $parts[] = [substr($text, $base), -1, $type];
        }

        return [$parts, $maxId];
    }

    /** Reads up to the unescaped delimiter, null at a bare newline or the end; `\delim` becomes the delimiter (`\&` stays escaped in a replacement). */
    /** @phpstan-impure */
    private function matchSlash(?string $slash, bool $regex): ?string
    {
        $buffer = '';

        while (($ch = $this->inchar()) !== null && $ch !== "\n") {
            if ($ch === $slash) {
                return $buffer;
            }

            if ($ch === '\\') {
                $ch = $this->inchar();

                if ($ch === null) {
                    break;
                }

                if ($ch !== "\n" && ($ch !== $slash || (! $regex && $ch === '&'))) {
                    $buffer .= '\\';
                }
            } elseif ($ch === '[' && $regex) {
                // The delimiter does not end a bracket expression.
                $buffer .= $ch;
                $ch = $this->snarfCharClass($buffer);

                if ($ch !== ']') {
                    break;
                }
            }

            $buffer .= $ch;
        }

        $this->savchar($ch === "\n" ? $ch : null);

        return null;
    }

    /** Copies a bracket expression up to (not including) its closing `]`, which it returns; null or "\n" if there is none. */
    private function snarfCharClass(string &$buffer): ?string
    {
        // 0 outside [: [. [=, 1 after its '[', 2 inside it, 3 after its closing ':', '.' or '='.
        $state = 0;
        $delim = '';
        $ch = $this->inchar();

        foreach (['^', ']'] as $leading) {
            if ($ch === $leading) {
                $buffer .= $ch;
                $ch = $this->inchar();
            }
        }

        for (; ; $buffer .= $ch, $ch = $this->inchar()) {
            if ($ch === null || $ch === "\n") {
                return $ch;
            }

            if (in_array($ch, ['.', ':', '='], true)) {
                if ($state === 1) {
                    [$delim, $state] = [$ch, 2];

                    continue;
                }

                if ($state === 2 && $ch === $delim) {
                    $state = 3;

                    continue;
                }
            } elseif ($ch === '[') {
                $state = $state === 0 ? 1 : $state;

                continue;
            } elseif ($ch === ']') {
                if ($state < 2) {
                    return $ch;
                }

                $state = $state === 3 ? 0 : $state;
            }

            $state &= ~1;
        }
    }

    /** @return Regex|null null for the empty regex, which reuses the last one used */
    private function compileRegex(string $pattern, string $flags, int $neededSub): ?array
    {
        if ($pattern === '') {
            return $flags === '' ? null : throw $this->badProg('cannot specify modifiers on empty regexp');
        }

        $newline = str_contains($flags, 'M');
        $re = $this->normalize($pattern, self::TEXT_REGEX);
        $body = PosixRegex::toPcre($this->posix ? $this->posixRegex($re) : $re, $this->extended, ignoreLeadingOps: false, multiline: $newline ? $this->delim : null);
        // Without M, `.` matches a newline and `$` is only the end of the pattern space.
        $pcre = '/'.$body.'/'.($newline ? '' : 'sD').(str_contains($flags, 'I') ? 'i' : '');
        $error = PosixRegex::error($pcre);

        if ($error !== null) {
            throw $this->badProg($error);
        }

        // Count the groups by matching the empty string with the pattern made optional.
        preg_match('/(?:'.$body.')?/', '', $groups, PREG_UNMATCHED_AS_NULL);
        $nsub = count($groups) - 1;

        // --posix leaves a missing group empty.
        if ($neededSub > 0 && $nsub < $neededSub - 1 && ! $this->posix) {
            throw $this->badProg(sprintf("invalid reference \\%d on `s' command's RHS", $neededSub - 1));
        }

        $flags = (str_contains($flags, 'I') ? 'I' : '').($newline ? 'M' : '');

        return ['pcre' => $pcre, 'nsub' => $nsub, 'noSub' => $neededSub === 0, 're' => $re, 'flags' => $flags];
    }

    /** glibc's POSIXLY_BASIC: \w \W \s \S \b \B \< \> \` \' and a BRE's \+ \? \| are literal, and so is an unmatched ) or \). */
    private function posixRegex(string $re): string
    {
        $out = '';
        $depth = 0;
        $len = strlen($re);

        for ($i = 0; $i < $len; $i++) {
            $escaped = $re[$i] === '\\' && $i + 1 < $len;
            $ch = $escaped ? $re[++$i] : $re[$i];
            $token = ($escaped ? '\\' : '').$ch;
            $group = $escaped !== $this->extended;

            if (! $escaped && $ch === '[') {
                // A bracket expression is copied as it is.
                preg_match('/\[\^?\]?(?:\[([:.=]).*?\1\]|[^]])*]?/As', $re, $m, 0, $i);
                $out .= $m[0];
                $i += strlen($m[0]) - 1;
            } elseif ($escaped && str_contains("wWsSbB<>`'".($this->extended ? '' : '+?|'), $ch)) {
                $out .= $ch;
            } elseif ($group && $ch === ')' && $depth === 0) {
                $out .= $this->extended ? '\\)' : ')';
            } else {
                $depth += $group ? ['(' => 1, ')' => -1][$ch] ?? 0 : 0;
                $out .= $token;
            }
        }

        return $out;
    }

    /** Resolves GNU sed's escapes \a \f \n \r \t \v \dNNN \oNNN \xHH \cX, which --posix leaves alone inside a regex's bracket expressions. */
    private function normalize(string $buffer, int $type): string
    {
        $out = '';
        $len = strlen($buffer);
        $bracket = 0;

        for ($p = 0; $p < $len; $p++) {
            if ($buffer[$p] !== '\\' || $p + 1 >= $len || $bracket !== 0) {
                if ($type === self::TEXT_REGEX && $this->posix) {
                    $bracket = match ($buffer[$p]) {
                        '[' => $bracket ?: -1,
                        ':', '.', '=' => $bracket === -1 && $buffer[$p - 1] === '[' ? $buffer[$p] : $bracket,
                        ']' => $bracket === -1 ? 0 : ($bracket !== 0 && $buffer[$p - 2] !== $bracket && $buffer[$p - 1] === $bracket ? -1 : $bracket),
                        default => $bracket,
                    };
                }

                $out .= $buffer[$p];

                continue;
            }

            $ch = $buffer[++$p];
            $simple = ['a' => "\x07", 'f' => "\f", "\n" => "\n", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v"][$ch] ?? null;

            if ($simple !== null) {
                $out .= $simple;
            } elseif (in_array($ch, ['d', 'o', 'x'], true)) {
                $digits = ['d' => '[0-9]{1,3}', 'o' => '[0-7]{1,3}', 'x' => '[0-9a-fA-F]{1,2}'][$ch];

                if (preg_match('/'.$digits.'/A', $buffer, $m, 0, $p + 1) === 1) {
                    $p += strlen($m[0]);
                    $ch = chr((int) ($ch === 'd' ? $m[0] : ($ch === 'o' ? octdec($m[0]) : hexdec($m[0]))) & 0xFF);
                }

                // A produced & or backslash stays literal in a replacement.
                $out .= ($type === self::TEXT_REPLACEMENT && ($ch === '&' || $ch === '\\') ? '\\' : '').$ch;
            } elseif ($ch === 'c') {
                if (++$p >= $len) {
                    $out .= $type !== self::TEXT_BUFFER ? '\\' : '';

                    continue;
                }

                $out .= chr(ord(strtoupper($buffer[$p])) ^ 0x40);

                if ($buffer[$p] === '\\' && ($buffer[++$p] ?? '') !== '\\') {
                    throw $this->badProg('recursive escaping after \\c not allowed');
                }
            } else {
                $out .= ($type !== self::TEXT_BUFFER ? '\\' : '').$ch;
            }
        }

        return $out;
    }

    private function readEndOfCmd(): void
    {
        $ch = $this->inNonblank();

        if ($ch === '}' || $ch === '#') {
            $this->savchar($ch);
        } elseif (! in_array($ch, [null, "\n", ';'], true)) {
            throw $this->badProg('extra characters after command');
        }
    }

    private function readLabel(): string
    {
        $label = '';

        for ($ch = $this->inNonblank(); $ch !== null && ! str_contains("\n \t;}#", $ch); $ch = $this->inchar()) {
            $label .= $ch;
        }

        $this->savchar($ch);

        return $label;
    }

    /** The rest of the line, blanks and semicolons included. */
    private function readFilename(): string
    {
        if ($this->sandbox) {
            throw $this->badProg('e/r/w commands disabled in sandbox mode');
        }

        $name = '';

        for ($ch = $this->inNonblank(); $ch !== null && $ch !== "\n"; $ch = $this->inchar()) {
            $name .= $ch;
        }

        return $name !== '' ? $name : throw $this->badProg('missing filename in r/R/w/W commands');
    }

    /** Like GNU, creates (truncates) the file of a w command while compiling; returns the output's key. */
    private function openWriteFile(): string
    {
        $name = $this->readFilename();

        if (isset($this->outputs[$name])) {
            return $name;
        }

        if ($name === '/dev/stdout' || $name === '/dev/stderr') {
            // --posix has no special files: the device is a stream of its own.
            $sink = $this->posix ? ($name === '/dev/stdout' ? "\1" : "\2") : substr($name, 5);
            $this->sinks[$sink] ??= '';
            $this->outputs[$name] = [$sink, false];

            return $name;
        }

        $path = $this->resolvePath($this->commandContext, $name);

        try {
            // The filesystem would create missing parent directories; fopen() does not.
            if (! $this->commandContext->fs->exists(dirname($path))) {
                throw new RuntimeException('ENOENT: no such file or directory');
            }

            $this->commandContext->fs->writeFile($path, '');
        } catch (RuntimeException $runtimeException) {
            throw $this->panic(sprintf("couldn't open file %s: %s", $name, $this->describeError($runtimeException)));
        }

        $this->sinks[$path] = '';
        $this->outputs[$name] = [$path, false];

        return $name;
    }

    /** @phpstan-impure */
    private function inchar(): ?string
    {
        if ($this->pos >= strlen($this->prog)) {
            return null;
        }

        $ch = $this->prog[$this->pos++];
        $this->line += $ch === "\n" ? 1 : 0;

        return $ch;
    }

    private function savchar(?string $ch): void
    {
        if ($ch !== null) {
            $this->line -= $ch === "\n" ? 1 : 0;
            $this->pos--;
        }
    }

    /** @phpstan-impure */
    private function inNonblank(): ?string
    {
        do {
            $ch = $this->inchar();
        } while ($ch === ' ' || $ch === "\t");

        return $ch;
    }

    private function inInteger(?string $ch): int
    {
        $n = 0;

        for (; $ch !== null && ctype_digit($ch); $ch = $this->inchar()) {
            $n = $n * 10 + (int) $ch;
        }

        $this->savchar($ch);

        return $n;
    }

    private function badProg(string $why): UnexpectedValueException
    {
        $where = $this->scriptFile !== null
            ? sprintf('file %s line %d', $this->scriptFile, $this->line)
            : sprintf('-e expression #%d, char %d', $this->expressions, $this->pos);

        return new UnexpectedValueException(sprintf("sed: %s: %s\n", $where, $why), 1);
    }

    private function panic(string $message): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf("sed: %s\n", $message), 4);
    }

    /** check_final_program(): unclosed blocks, an unfinished a/i/c text, and branch targets. */
    private function finishProgram(): void
    {
        // Errors found from here on report no character position.
        $this->prog = '';
        $this->pos = 0;

        if ($this->blocks !== []) {
            [, $this->expressions, $this->scriptFile, $this->line] = $this->blocks[count($this->blocks) - 1];

            throw $this->badProg("unmatched `{'");
        }

        if ($this->pendingText !== null) {
            $this->program[$this->pendingCommand]['text'] = $this->pendingText !== '' ? $this->pendingText : null;
        }

        foreach ($this->branches as [$index, $label]) {
            $this->program[$index]['jump'] = $label === '' ? count($this->program)
                : $this->labels[$label] ?? throw $this->panic(sprintf("can't find label for jump to `%s'", $label));
        }
    }

    /** @param list<string> $operands */
    private function processFiles(array $operands): int
    {
        if ($operands === [] && $this->inPlace !== null) {
            throw $this->panic('no input files');
        }

        $this->files = $operands ?: ['-'];
        $status = 0;

        while ($this->readPatternSpace(false)) {
            $this->trace(sprintf("INPUT:   '%s' line %d\n", $this->fileName === '-' && $this->inPlace === null ? 'STDIN' : $this->fileName, $this->lineNumber));
            $this->traceSpaces();
            $status = $this->executeProgram();

            if ($status !== -1) {
                break;
            }

            $status = 0;
        }

        $this->closedown();

        return $this->badCount > 0 ? 2 : $status;
    }

    /** @return int -1 to go on with the next cycle, else the exit status */
    private function executeProgram(): int
    {
        $count = count($this->program);
        $limits = $this->commandContext->limits;

        for ($pc = 0; $pc < $count; $pc++) {
            if (strlen($this->space) + strlen($this->hold) > $limits->maxStringLength
                || max(strlen($this->sinks[$this->outputs[''][0]]), strlen($this->sinks['stdout'])) > $limits->maxOutputSize) {
                throw new ExecutionLimitException('sed: buffer size limit exceeded');
            }

            $command = $this->program[$pc];

            if ($this->debug) {
                $this->sinks['stdout'] .= 'COMMAND: ';
                $this->debugCommand($command);
            }

            if ($this->matchAddress($pc) === $command['bang']) {
                continue;
            }

            switch ($command['cmd']) {
                case '{':
                case 'b':
                    $pc = $this->jump($command, $command['cmd'] === 'b');

                    break;

                case 't':
                case 'T':
                    if ($this->replaced === ($command['cmd'] === 't')) {
                        $pc = $this->jump($command, true);
                    }

                    $this->replaced = false;

                    break;

                case 'a':
                    $this->appendQueue[] = [$command['text'] ?? null, null];

                    break;

                case 'c':
                    // A range is replaced once, at its end.
                    if (($this->range[$pc] ?? self::INACTIVE) !== self::ACTIVE) {
                        $this->output($this->textLine($command), true);
                    }

                    $this->trace("END-OF-CYCLE:\n");

                    return -1;

                case 'd':
                    $this->trace("END-OF-CYCLE:\n");

                    return -1;

                case 'D':
                    $newline = strpos($this->space, $this->delim);

                    if ($newline === false) {
                        return -1;
                    }

                    // Restart the cycle on the rest, without reading a line.
                    $this->space = substr($this->space, $newline + 1);
                    $pc = $this->jump($command, true);
                    $this->traceSpaces();

                    break;

                case 'e':
                    $this->evaluate($command['text'] ?? null);

                    break;

                case 'g':
                    [$this->space, $this->chomped] = [$this->hold, $this->holdChomped];
                    $this->traceSpaces(false, true);

                    break;

                case 'G':
                    $this->space .= $this->delim.$this->hold;
                    $this->chomped = $this->holdChomped;
                    $this->traceSpaces();

                    break;

                case 'h':
                    [$this->hold, $this->holdChomped] = [$this->space, $this->chomped];
                    $this->traceSpaces(false, true);

                    break;

                case 'H':
                    $this->hold .= $this->delim.$this->space;
                    $this->holdChomped = $this->chomped;
                    $this->traceSpaces(false, true);

                    break;

                case 'i':
                    $this->output($this->textLine($command), true);

                    break;

                case 'l':
                    $length = $command['int'] ?? -1;
                    $this->listLine($length === -1 ? $this->lineLength : $length);

                    break;

                case 'L':
                    throw $this->panic('INTERNAL ERROR: Bad cmd L');

                case 'n':
                    if (! $this->quiet) {
                        $this->output($this->space, $this->chomped);
                    }

                    if ($this->testEof() || ! $this->readPatternSpace(false)) {
                        $this->trace("END-OF-CYCLE:\n");

                        return -1;
                    }

                    $this->traceSpaces();

                    break;

                case 'N':
                    $this->space .= $this->delim;

                    if ($this->testEof() || ! $this->readPatternSpace(true)) {
                        $this->trace("END-OF-CYCLE:\n");
                        // GNU prints the pattern space when there is no next line; POSIX does not.
                        $this->space = substr($this->space, 0, -1);

                        if (! $this->quiet && ! $this->posix) {
                            $this->output($this->space, $this->chomped);
                        }

                        return -1;
                    }

                    $this->traceSpaces();

                    break;

                case 'p':
                    $this->output($this->space, $this->chomped);

                    break;

                case 'P':
                case 'W':
                    $newline = strpos($this->space, $this->delim);
                    $this->output(
                        $newline === false ? $this->space : substr($this->space, 0, $newline),
                        $newline !== false || $this->chomped,
                        $command['cmd'] === 'W' ? $command['file'] ?? '' : '',
                    );

                    break;

                case 'q':
                    if (! $this->quiet) {
                        $this->output($this->space, $this->chomped);
                    }

                    // Ends a line left without its newline even when nothing is queued.
                    $this->write('');
                    $this->dumpAppendQueue();

                    // no break
                case 'Q':
                    return max(0, $command['int'] ?? 0) & 0xFF;

                case 'r':
                    if ($command['append'] ?? true) {
                        $this->appendQueue[] = [null, $command['file'] ?? ''];
                    } else {
                        $this->write($this->readOptionalFile($command['file'] ?? '') ?? '', false);
                    }

                    break;

                case 'R':
                    $this->readFileLine($command['file'] ?? '');

                    break;

                case 's':
                    $this->substitute($command['s'] ?? throw new RuntimeException('s without substitution'));
                    $this->traceSpaces();

                    break;

                case 'w':
                    $this->output($this->space, $this->chomped, $command['file'] ?? '');

                    break;

                case 'x':
                    [$this->space, $this->chomped, $this->hold, $this->holdChomped] = [$this->hold, $this->holdChomped, $this->space, $this->chomped];
                    $this->traceSpaces(true, true);

                    break;

                case 'y':
                    $this->space = strtr($this->space, $command['map'] ?? []);
                    $this->traceSpaces();

                    break;

                case 'z':
                    $this->space = '';
                    $this->traceSpaces();

                    break;

                case '=':
                    $this->write($this->lineNumber.$this->delim);

                    break;

                case 'F':
                    $this->write($this->fileName.$this->delim);

                    break;

                default: // } and :
            }
        }

        $this->trace("END-OF-CYCLE:\n");

        if (! $this->quiet) {
            $this->output($this->space, $this->chomped);
        }

        return -1;
    }

    /**
     * Returns the command index before the jump target (the start for D), counting backward jumps against the sed iteration limit.
     *
     * @param  Command  $command
     */
    private function jump(array $command, bool $counted): int
    {
        if ($counted && ++$this->jumps > $this->commandContext->limits->maxSedIterations) {
            throw new ExecutionLimitException('sed: iteration limit exceeded');
        }

        return ($command['jump'] ?? 0) - 1;
    }

    /** @param Command $command */
    private function textLine(array $command): ?string
    {
        $text = $command['text'] ?? null;

        return $text === null ? null : substr($text, 0, -1);
    }

    private function matchAddress(int $pc): bool
    {
        $command = $this->program[$pc];
        $a1 = $command['a1'];
        $a2 = $command['a2'];

        if ($a1 === null) {
            return true;
        }

        if ($a2 === null) {
            return $this->matchOne($a1, $pc);
        }

        $state = $this->range[$pc] ?? self::INACTIVE;

        if ($state !== self::ACTIVE) {
            // A line number starts the range at or after that line, once.
            if ($a1['type'] === 'num' ? $state === self::CLOSED || $this->lineNumber < $a1['n'] : ! $this->matchOne($a1, $pc)) {
                return false;
            }

            $this->range[$pc] = self::ACTIVE;

            switch ($a2['type']) {
                case 'regex':
                    // The end regex is tried from the next line on.
                    return true;

                case 'num':
                    // An end line at or before the start makes a one-line range.
                    if ($this->lineNumber >= $a2['n']) {
                        $this->range[$pc] = self::CLOSED;
                    }

                    return $this->lineNumber <= $a2['n'] || $this->matchOne($a1, $pc);

                case 'step':
                    $this->rangeEnd[$pc] = $this->lineNumber + $a2['n'];

                    return true;

                case 'stepmod':
                    $this->rangeEnd[$pc] = $this->lineNumber + $a2['n'] - $this->lineNumber % $a2['n'];

                    return true;
            }
        }

        if ($a2['type'] === 'num') {
            // Lines skipped by n or N can jump past the end.
            if ($this->lineNumber >= $a2['n']) {
                $this->range[$pc] = self::CLOSED;
            }

            return $this->lineNumber <= $a2['n'];
        }

        if ($this->matchOne($a2, $pc)) {
            $this->range[$pc] = self::CLOSED;
        }

        return true;
    }

    /** @param Address $address */
    private function matchOne(array $address, int $pc): bool
    {
        $n = $address['n'];

        return match ($address['type']) {
            'regex' => $this->match($address['re'] ?? null, $this->space, 0, 0) !== null,
            'mod' => $this->lineNumber >= $n && ($this->lineNumber - $n) % ($address['step'] ?? 1) === 0,
            'step', 'stepmod' => ($this->rangeEnd[$pc] ?? 0) <= $this->lineNumber,
            'last' => $this->testEof(),
            'num' => $this->lineNumber === $n,
            default => true,
        };
    }

    /**
     * @param  Regex|null  $regex  null reuses the last regex used
     * @return array<array{?string, int}>|null
     */
    private function match(?array $regex, string $subject, int $offset, int $maxId): ?array
    {
        if ($regex === null) {
            $regex = $this->lastRegex ?? throw $this->badProg('no previous regular expression');

            // An address regex is compiled without groups; GNU checks the references when s reuses it.
            if ($regex['noSub'] && $regex['nsub'] < $maxId && ! $this->posix) {
                throw $this->badProg(sprintf("invalid reference \\%d on `s' command's RHS", $maxId));
            }
        }

        $this->lastRegex = $regex;

        try {
            $matched = SafePcreRegex::match($regex['pcre'], $subject, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset);
        } catch (RegexException $regexException) {
            throw $this->panic($regexException->getMessage());
        }

        /** @var array<array{?string, int}> $m */
        return $matched ? $m : null;
    }

    /** @param Subst $sub */
    private function substitute(array $sub): void
    {
        $space = $this->space;
        $len = strlen($space);
        $m = $this->match($sub['re'], $space, 0, $sub['maxId']);

        if ($m === null) {
            return;
        }

        if ($this->debug) {
            $this->sinks['stdout'] .= "MATCHED REGEX REGISTERS\n";

            // Up to the first group that did not take part.
            for ($i = 0; ($m[$i][0] ?? null) !== null; $i++) {
                $this->sinks['stdout'] .= sprintf("  regex[%d] = %d-%d '%s'\n", $i, $m[$i][1], $m[$i][1] + strlen((string) $m[$i][0]), $m[$i][0]);
            }
        }

        $start = 0;
        $lastEnd = 0;
        $count = 0;
        $out = '';
        $numb = max(1, $sub['numb']);
        // GNU edits in place, without its scratch buffer, when deleting a match at either end.
        $inPlace = $sub['parts'] === [] && $numb === 1 && (($m[0][1] === 0 && ! $sub['global']) || $m[0][1] + strlen((string) $m[0][0]) === $len);

        do {
            [$matchText, $offset] = $m[0];
            $matched = strlen((string) $matchText);
            $out .= substr($space, $start, $offset - $start);
            $start = $offset;

            // An empty match right after a match is skipped, so s/a*/x/g on baaac gives xbxcx.
            if (($matched > 0 || $count === 0 || $offset > $lastEnd) && ++$count >= $numb) {
                $this->replaced = true;
                $out .= $this->expand($sub['parts'], $m);
                $again = $sub['global'];
            } else {
                if ($matched === 0) {
                    if ($start >= $len) {
                        break;
                    }

                    $matched = 1;
                }

                $out .= substr($space, $offset, $matched);
                $again = true;
            }

            $start = $offset + $matched;
            $lastEnd = $offset + strlen((string) $matchText);
        } while ($again && $start <= $len && ($m = $this->match($sub['re'], $space, $start, $sub['maxId'])) !== null);

        $this->space = $out.substr($space, $start);
        $this->accumChomped = $inPlace ? $this->accumChomped : $this->chomped;

        if ($count < $numb) {
            return;
        }

        if ($sub['print'] === 1) {
            $this->output($this->space, $this->chomped);
        }

        if ($sub['eval']) {
            $this->evaluate(null);
        }

        if ($sub['print'] === 2) {
            $this->output($this->space, $this->chomped);
        }

        if ($sub['out'] !== null) {
            $this->output($this->space, $this->chomped, $sub['out']);
        }
    }

    /**
     * Builds the replacement, applying \U \L \E and the one-character \u \l (carried over an empty group, as in s/\(\)\([a-z]\)/\u\1\2/).
     *
     * @param  list<Part>  $parts
     * @param  array<array{?string, int}>  $m
     */
    private function expand(array $parts, array $m): string
    {
        $out = '';
        $carry = 0;

        foreach ($parts as [$prefix, $group, $type]) {
            $current = ($type & self::FIRST) !== 0 ? $type : $type | $carry;
            $carry = 0;

            if ($prefix !== '') {
                $out .= $this->convertCase($prefix, $current);
                $current &= ~self::FIRST;
            }

            $text = $group >= 0 ? (string) ($m[$group][0] ?? '') : null;

            if ((string) $text !== '') {
                $out .= $this->convertCase((string) $text, $current);
            } elseif ($text === '' && ($type & self::FIRST) !== 0) {
                $carry = $current & self::FIRST;
            }
        }

        return $out;
    }

    private function convertCase(string $text, int $type): string
    {
        if (($type & self::FIRST) !== 0) {
            $first = ($type & self::UPPER_FIRST) !== 0 ? strtoupper($text[0]) : strtolower($text[0]);

            return $first.$this->convertCase(substr($text, 1), $type & ~self::FIRST);
        }

        return match ($type) {
            self::UPPER => strtoupper($text),
            self::LOWER => strtolower($text),
            default => $text,
        };
    }

    /** `e command` writes the command's output; `e` and s///e replace the pattern space with what it prints. */
    private function evaluate(?string $command): void
    {
        if ($command === null || $command === '') {
            $result = ($this->commandContext->exec)($this->space);
            $stdout = $result->stdout;
            $this->space = str_ends_with((string) $stdout, $this->delim) ? substr((string) $stdout, 0, -1) : $stdout;
            [$this->chomped, $this->accumChomped] = [$this->accumChomped, $this->chomped];
        } else {
            $result = ($this->commandContext->exec)(substr($command, 0, -1));
            $this->write($result->stdout);
        }

        $this->sinks['stderr'] .= $result->stderr;
    }

    /** The `l` command: escapes, wrapped with a backslash before $lineLength columns (0 or less: never). */
    private function listLine(int $lineLength): void
    {
        $out = '';
        $width = 0;

        foreach (str_split($this->space) as $ch) {
            $code = ord($ch);
            $escaped = match (true) {
                $ch === '\\' => '\\\\',
                $code >= 0x20 && $code < 0x7F => $ch,
                default => '\\'.(["\x07" => 'a', "\x08" => 'b', "\f" => 'f', "\n" => 'n', "\r" => 'r', "\t" => 't', "\v" => 'v'][$ch] ?? sprintf('%03o', $code)),
            };

            if ($lineLength > 0 && $width + strlen($escaped) >= $lineLength) {
                $out .= '\\'.$this->delim;
                $width = 0;
            }

            $out .= $escaped;
            $width += strlen($escaped);
        }

        $this->write($out.'$'.$this->delim);
    }

    private function readPatternSpace(bool $append): bool
    {
        $this->dumpAppendQueue();
        $this->replaced = false;
        $this->space = $append ? $this->space : '';
        $this->chomped = true;

        while (! $this->readInputLine()) {
            $this->closedown();

            if ($this->nextFile >= count($this->files)) {
                return false;
            }

            if ($this->resetAtNextFile) {
                // With -s and -i each file starts afresh.
                $this->lineNumber = 0;
                $this->hold = '';
                $this->range = array_map(fn (array $command): int => $command['a1'] === ['type' => 'num', 'n' => 0] ? self::ACTIVE : self::INACTIVE, $this->program);
                $this->rFiles = array_map(fn (array $file): array => [$file[0], 0], $this->rFiles);
                $this->resetAtNextFile = $this->separate;
            }

            $this->openNextFile();
        }

        $this->lineNumber++;
        $this->jumps = 0;

        return true;
    }

    private function readInputLine(): bool
    {
        if ($this->data === null || $this->offset >= strlen($this->data)) {
            return false;
        }

        $end = strpos($this->data, $this->delim, $this->offset);
        $this->chomped = $end !== false;
        $end = $end === false ? strlen($this->data) : $end;
        $this->space .= substr($this->data, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return true;
    }

    private function openNextFile(): void
    {
        $name = $this->files[$this->nextFile++];
        $this->fileName = $name;
        $this->data = null;
        $this->offset = 0;

        if ($name === '-' && $this->inPlace === null) {
            $this->data = $this->readStdin();

            return;
        }

        try {
            $this->data = $this->commandContext->fs->readFile($this->resolvePath($this->commandContext, $name));
        } catch (RuntimeException $runtimeException) {
            if (str_starts_with($runtimeException->getMessage(), 'EISDIR')) {
                throw $this->panic($this->inPlace !== null ? sprintf("couldn't edit %s: not a regular file", $name) : sprintf('read error on %s: Is a directory', $name));
            }

            $this->sinks['stderr'] .= sprintf("sed: can't read %s: %s\n", $name, $this->describeError($runtimeException));
            $this->badCount++;

            return;
        }

        if ($this->inPlace !== null) {
            $this->sinks["\0"] = '';
            $this->outputs[''] = ["\0", false];
        }
    }

    /** Finishes the current file; with -i that writes it back, keeping a backup when a suffix was given. */
    private function closedown(): void
    {
        if ($this->data !== null && $this->inPlace !== null) {
            $backup = str_replace('*', $this->fileName, $this->inPlace);

            try {
                if ($this->inPlace !== '*') {
                    $this->commandContext->fs->writeFile($this->resolvePath($this->commandContext, $backup), $this->data);
                }

                $this->commandContext->fs->writeFile($this->resolvePath($this->commandContext, $this->fileName), $this->sinks["\0"]);
            } catch (RuntimeException $runtimeException) {
                throw $this->panic(sprintf("couldn't open temporary file %s: %s", $this->fileName, $this->describeError($runtimeException)));
            }
        }

        $this->data = null;
    }

    /** Whether the current line is the last: of its file with -s, else of the last file that has data. */
    private function testEof(): bool
    {
        if ($this->data !== null && $this->offset < strlen($this->data)) {
            return false;
        }

        if ($this->separate) {
            return true;
        }

        while (true) {
            $this->closedown();

            if ($this->nextFile >= count($this->files)) {
                return true;
            }

            $this->openNextFile();

            if ((string) $this->data !== '') {
                return false;
            }
        }
    }

    /** R: queue the next line of the file, delimiter included, if there is one. */
    private function readFileLine(string $name): void
    {
        [$content, $offset] = $this->rFiles[$name];

        if ($offset < strlen($content)) {
            $end = strpos($content, $this->delim, $offset);
            $end = $end === false ? strlen($content) : $end + 1;
            $this->appendQueue[] = [substr($content, $offset, $end - $offset), null];
            $this->rFiles[$name][1] = $end;
        }
    }

    /** A file for r or R, or null if it cannot be read ("treated as if it were an empty file", says POSIX). */
    private function readOptionalFile(string $name): ?string
    {
        if ($name === '/dev/stdin') {
            return $this->readStdin();
        }

        try {
            return $this->commandContext->fs->readFile($this->resolvePath($this->commandContext, $name));
        } catch (RuntimeException $runtimeException) {
            return str_starts_with($runtimeException->getMessage(), 'EISDIR') ? throw $this->panic(sprintf('read error on %s: Is a directory', $name)) : null;
        }
    }

    private function dumpAppendQueue(): void
    {
        if ($this->appendQueue === []) {
            return;
        }

        $queue = $this->appendQueue;
        $this->appendQueue = [];
        $this->write(implode('', array_map(fn (array $entry): string => $entry[0] ?? ($entry[1] === null ? '' : $this->readOptionalFile($entry[1]) ?? ''), $queue)));
    }

    /** output_line(): writes a line, first ending a previous line that lacked its newline. Null writes nothing. */
    private function output(?string $text, bool $newline, string $output = ''): void
    {
        if ($text === null) {
            return;
        }

        [$sink, $missing] = $this->outputs[$output];
        $this->sinks[$sink] .= ($missing ? $this->delim : '').$text.($newline ? $this->delim : '');
        $this->outputs[$output][1] = ! $newline;
        $this->flush($sink);
    }

    /** Writes raw text to the main output, by default first ending a line that lacked its newline. */
    private function write(string $text, bool $endLine = true): void
    {
        [$sink, $missing] = $this->outputs[''];

        if ($missing && $endLine) {
            $this->sinks[$sink] .= $this->delim;
            $this->outputs[''][1] = false;
        }

        $this->sinks[$sink] .= $text;

        // print_file() for 0r leaves its output in stdio's buffer.
        if ($endLine) {
            $this->flush($sink);
        }
    }

    /** With -u output reaches the stream at once, which only matters for the streams of --posix's w /dev/stdout and /dev/stderr. */
    private function flush(string $sink): void
    {
        if (! $this->unbuffered) {
            return;
        }

        if ($sink === 'stdout' && isset($this->sinks["\1"])) {
            [$this->sinks["\1"], $this->sinks['stdout']] = [$this->sinks["\1"].$this->sinks['stdout'], ''];
        } elseif ($sink === "\2") {
            [$this->sinks['stderr'], $this->sinks["\2"]] = [$this->sinks['stderr'].$this->sinks["\2"], ''];
        }
    }

    private function trace(string $text): void
    {
        if ($this->debug) {
            $this->sinks['stdout'] .= $text;
        }
    }

    private function traceSpaces(bool $pattern = true, bool $hold = false): void
    {
        if ($this->debug) {
            $this->sinks['stdout'] .= ($pattern ? 'PATTERN: '.$this->debugText($this->space)."\n" : '')
                .($hold ? 'HOLD:    '.$this->debugText($this->hold)."\n" : '');
        }
    }

    /** debug_print_char(): C escapes, other unprintable bytes as \oNNN of the (signed) char's value. */
    private function debugText(string $text, bool $slash = false): string
    {
        return (string) preg_replace_callback($slash ? '/[^\x20-\x2e\x30-\x5b\x5d-\x7e]/' : '/[^\x20-\x5b\x5d-\x7e]/', fn (array $m): string => '\\'.([
            '/' => '/', '\\' => '\\', "\x07" => 'a', "\f" => 'f', "\r" => 'r', "\t" => 't', "\v" => 'v', "\n" => 'n',
        ][$m[0]] ?? sprintf('o%03o', ord($m[0]) < 0x80 ? ord($m[0]) : 0xFFFFFF00 | ord($m[0]))), $text);
    }

    /**
     * debug_print_command(): one command of the program, indented by the blocks it is in.
     *
     * @param  Command  $command
     */
    private function debugCommand(array $command): void
    {
        $this->blockLevel -= $command['cmd'] === '}' ? 1 : 0;
        $jump = $command['jump'] ?? 0;
        $int = $command['int'] ?? -1;
        $this->sinks['stdout'] .= str_repeat('  ', $this->blockLevel)
            .$this->debugAddress($command['a1']).($command['a2'] !== null ? ',' : '').$this->debugAddress($command['a2'])
            // A block is compiled with the address negated.
            .($command['bang'] !== ($command['cmd'] === '{') ? '!' : '').($command['a1'] !== null ? ' ' : '').$command['cmd']
            .match ($command['cmd']) {
                ':' => $command['label'] ?? '',
                'a', 'c', 'i' => '\\'.($command['text'] ?? ''),
                'b', 't', 'T' => isset($this->program[$jump]['label']) ? ' '.$this->program[$jump]['label'] : '',
                'e' => ' '.($command['text'] ?? ''),
                'l', 'L', 'q', 'Q' => $int !== -1 ? ' '.$int : '',
                'r', 'R' => ' '.($command['file'] ?? ''),
                'w', 'W' => $command['file'] ?? '',
                's' => $this->debugSubst($command['s'] ?? throw new RuntimeException('s without substitution')),
                'y' => $this->debugMap($command['map'] ?? []),
                default => '',
            }."\n";
        $this->blockLevel += $command['cmd'] === '{' ? 1 : 0;
    }

    /** @param Address|null $address */
    private function debugAddress(?array $address): string
    {
        return match ($address['type'] ?? '') {
            '' => '',
            'null' => '[ADDR-NULL]',
            'regex' => $this->debugRegex($address['re'] ?? null).($address['re']['flags'] ?? ''),
            'num' => (string) $address['n'],
            'mod' => $address['n'].'~'.($address['step'] ?? 0),
            'step' => '+'.$address['n'],
            'stepmod' => '~'.$address['n'],
            default => '$',
        };
    }

    /** @param Regex|null $regex */
    private function debugRegex(?array $regex): string
    {
        return $regex === null ? '//' : '/'.$this->debugText($regex['re'], true).'/';
    }

    /**
     * The replacement prints its case conversions where they change, as GNU keeps them.
     *
     * @param  Subst  $sub
     */
    private function debugSubst(array $sub): string
    {
        $out = $this->debugRegex($sub['re']);
        $last = 0;

        foreach ($sub['parts'] as [$prefix, $group, $type]) {
            if ($type !== $last) {
                $out .= '\\'.match (true) {
                    $type === 0 => 'E',
                    $type === self::UPPER => 'U',
                    $type === self::LOWER => 'L',
                    ($type & self::UPPER_FIRST) !== 0 => 'u',
                    default => 'l',
                };
                $last = $type;
            }

            $out .= $prefix.match (true) {
                $group === 0 => '&',
                $group > 0 => '\\'.$group,
                default => '',
            };
        }

        return $out.'/'.strtolower($sub['re']['flags'] ?? '').($sub['global'] ? 'g' : '').($sub['eval'] ? 'e' : '')
            .($sub['print'] !== 0 ? 'p' : '').($sub['numb'] ?: '').($sub['out'] !== null ? 'w'.$sub['out'] : '');
    }

    /**
     * The bytes y changes, in byte order.
     *
     * @param  array<string, string>  $map
     */
    private function debugMap(array $map): string
    {
        $from = '';
        $to = '';

        foreach (array_map(chr(...), range(0, 255)) as $byte) {
            if (($map[$byte] ?? $byte) !== $byte) {
                $from .= $byte;
                $to .= $map[$byte];
            }
        }

        return '/'.$from.'/'.$to.'/';
    }
}
