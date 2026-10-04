<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;
use BashBox\Filesystem\FsStat;
use BashBox\Filesystem\UnixFileMode;
use BashBox\Regex\PosixRegex;
use BashBox\Regex\RegexException;
use BashBox\Regex\SafePcreRegex;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;

/** GNU find (findutils 4.10), visiting directory entries in sorted order where GNU takes the directory's own order. */
final class Find_ extends AbstractCommand
{
    /** -regextype names in the order GNU lists them, and the syntax each one uses here. */
    private const array REGEX_TYPES = [
        'findutils-default' => 'emacs', 'ed' => 'basic', 'emacs' => 'emacs', 'gnu-awk' => 'extended', 'grep' => 'basic',
        'posix-awk' => 'extended', 'awk' => 'extended', 'posix-basic' => 'basic', 'posix-egrep' => 'extended',
        'egrep' => 'extended', 'posix-extended' => 'extended', 'posix-minimal-basic' => 'basic', 'sed' => 'basic',
    ];

    /** Options that only change how the walk is done (or nothing at all here), and always evaluate to true. */
    private const array NO_OPS = ['-noleaf', '-xdev', '-mount', '-ignore_readdir_race', '-noignore_readdir_race', '-nowarn', '-warn'];

    private const array ESCAPES = ['a' => "\x07", 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", '\\' => '\\'];

    /** strftime() conversions for -printf's %T, %A and %C, as DateTimeInterface::format() strings. */
    private const array TIME_FORMATS = [
        'a' => 'D', 'A' => 'l', 'b' => 'M', 'B' => 'F', 'h' => 'M', 'd' => 'd', 'D' => 'm/d/y', 'F' => 'Y-m-d', 'H' => 'H',
        'I' => 'h', 'j' => 'z', 'm' => 'm', 'M' => 'i', 'p' => 'A', 'r' => 'h:i:s A', 'R' => 'H:i', 's' => 'U', 'S' => 's.0000000000',
        'T' => 'H:i:s.0000000000', 'X' => 'H:i:s.0000000000', 'x' => 'm/d/y', 'u' => 'N', 'w' => 'w', 'y' => 'y', 'Y' => 'Y',
        'z' => 'O', 'Z' => 'T', '@' => 'U.0000000000', '+' => 'Y-m-d+H:i:s.0000000000',
    ];

    private CommandContext $commandContext;

    private string $stdout = '';

    private string $stderr = '';

    private bool $failed = false;

    /** @var list<string> */
    private array $tokens = [];

    private int $at = 0;

    private string $lastPredicate = '';

    /** @var list<string> */
    private array $startPoints = [];

    private bool $hasAction = false;

    private bool $depthFirst = false;

    private bool $explicitDepth = false;

    private bool $prunes = false;

    private bool $deletes = false;

    private int $maxDepth = -1;

    private int $minDepth = 0;

    private string $regexSyntax = 'emacs';

    private bool $quit = false;

    private bool $pruned = false;

    private int $now = 0;

    /** @var ?list<string> answers to -ok prompts, read from stdin */
    private ?array $answers = null;

    /** @var array<int, array{command: list<string>, dir: ?string, paths: list<string>}> pending `-exec ... {} +` runs */
    private array $batches = [];

    /** P, H or L: which symbolic links are followed */
    private string $follow = 'P';

    /** Start of today once -daystart is seen */
    private ?int $midnight = null;

    /** @var array<string, string> directories being walked with -L, real path => path as shown, to spot loops */
    private array $ancestors = [];

    /** @var array<string, string> output files of -fprint and friends: resolved path => what to write */
    private array $files = [];

    /** @var array<int, int> -ls column widths, which only ever grow: inode, blocks, links, owner, group, size */
    private array $lsWidths = [9, 6, 3, 8, 8, 8];

    private string $user = 'root';

    public function getName(): string
    {
        return 'find';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $this->commandContext = $commandContext;
        $this->stdout = '';
        $this->stderr = '';
        $this->lastPredicate = '';
        $this->failed = false;
        $this->hasAction = false;
        $this->depthFirst = false;
        $this->explicitDepth = false;
        $this->prunes = false;
        $this->deletes = false;
        $this->quit = false;
        $this->maxDepth = -1;
        $this->minDepth = 0;
        $this->regexSyntax = 'emacs';
        $this->batches = [];
        $this->answers = null;
        $this->now = time();
        $this->follow = 'P';
        $this->midnight = null;
        $this->ancestors = [];
        $this->files = [];
        $this->lsWidths = [9, 6, 3, 8, 8, 8];
        $this->user = $commandContext->env['USER'] ?? 'root';

        $first = 0;

        // -H, -L and -P come before the start points, the last one winning; -- ends them.
        while (in_array($option = $args[$first] ?? '', ['-H', '-L', '-P', '--'], true)) {
            $first++;

            if ($option === '--') {
                break;
            }

            $this->follow = $option[1];
        }

        $count = $first;

        while ($count < count($args) && ! $this->looksLikeExpression($args[$count])) {
            $count++;
        }

        $this->startPoints = array_slice($args, $first, $count - $first) ?: ['.'];
        $this->tokens = array_slice($args, $count);
        $this->at = 0;

        try {
            $expression = $this->tokens === [] ? fn (): bool => true : $this->parseComma();

            if ($this->at < count($this->tokens)) {
                throw new RuntimeException("you have too many ')'");
            }

            if ($this->deletes && $this->prunes && ! $this->explicitDepth) {
                throw new RuntimeException('The -delete action automatically turns on -depth, but -prune does nothing when -depth is in effect.  If you want to carry on anyway, just explicitly use the -depth option.');
            }
        } catch (RuntimeException $runtimeException) {
            return $this->failure($this->stderr.'find: '.$runtimeException->getMessage()."\n");
        }

        if (! $this->hasAction) {
            $print = $this->output("\n");
            $expression = fn (FindEntry $findEntry): bool => $expression($findEntry) && $print($findEntry);
        }

        try {
            foreach ($this->startPoints as $startPoint) {
                if ($this->quit) {
                    break;
                }

                $path = $this->resolvePath($commandContext, $startPoint);

                try {
                    $stat = $this->statAt($path, 0);
                } catch (RuntimeException $runtimeException) {
                    $this->error(sprintf("'%s': %s", $startPoint, $this->describeError($runtimeException)));

                    continue;
                }

                $this->visit(new FindEntry($path, $startPoint, $startPoint, '', 0, $this->typeOf($stat), $stat), $expression);
            }
        } catch (RegexException $regexException) {
            $this->error($regexException->getMessage());
        }

        foreach (array_keys($this->batches) as $id) {
            $this->flush($id);
        }

        // Like a buffered stream, the output lands at the end, unless the file has been removed meanwhile.
        foreach ($this->files as $path => $content) {
            if ($content !== '' && $commandContext->fs->exists($path)) {
                $commandContext->fs->writeFile($path, $content);
            }
        }

        return new ExecResult(stdout: $this->stdout, stderr: $this->stderr, exitCode: $this->failed ? 1 : 0);
    }

    /**
     * @param  Closure(FindEntry): bool  $expression
     */
    private function visit(FindEntry $findEntry, Closure $expression): void
    {
        $this->pruned = false;
        $real = $this->follow === 'L' && $findEntry->type === 'd' ? $this->commandContext->fs->realpath($findEntry->path) : null;

        // Following links can lead back to a directory being walked.
        if ($real !== null && isset($this->ancestors[$real])) {
            $this->error(sprintf("File system loop detected; '%s' is part of the same file system loop as '%s'.", $findEntry->display, $this->ancestors[$real]));

            return;
        }

        if (! $this->depthFirst && $findEntry->depth >= $this->minDepth) {
            $expression($findEntry);
        }

        if ($findEntry->type === 'd' && $findEntry->depth !== $this->maxDepth && ! $this->pruned && ! $this->quit) {
            try {
                $names = $this->commandContext->fs->readdir($findEntry->path);
            } catch (RuntimeException $runtimeException) {
                $this->error(sprintf("'%s': %s", $findEntry->display, $this->describeError($runtimeException)));
                $names = [];
            }

            if ($real !== null) {
                $this->ancestors[$real] = $findEntry->display;
            }

            foreach ($names as $name) {
                if ($this->quit) {
                    break;
                }

                // GNU keeps the start point as given, so "dir/" gives "dir/x" and "dir//" gives "dir//x".
                $path = rtrim($findEntry->path, '/').'/'.$name;
                $display = $findEntry->display.(str_ends_with($findEntry->display, '/') ? '' : '/').$name;

                try {
                    $stat = $this->statAt($path, $findEntry->depth + 1);
                } catch (RuntimeException $runtimeException) {
                    $this->error(sprintf("'%s': %s", $display, $this->describeError($runtimeException)));

                    continue;
                }

                $this->visit(new FindEntry(
                    $path,
                    $display,
                    $findEntry->start,
                    ltrim($findEntry->relative.'/'.$name, '/'),
                    $findEntry->depth + 1,
                    $this->typeOf($stat),
                    $stat,
                ), $expression);
            }

            unset($this->ancestors[(string) $real]);
        }

        if ($this->depthFirst && ! $this->quit && $findEntry->depth >= $this->minDepth) {
            $expression($findEntry);
        }
    }

    private function following(int $depth): bool
    {
        return $this->follow === 'L' || ($this->follow === 'H' && $depth === 0);
    }

    /**
     * GNU's stat for a file at a depth: the link's target when following links, but the link itself when broken.
     *
     * @throws RuntimeException
     */
    private function statAt(string $path, int $depth): FsStat
    {
        if ($this->following($depth)) {
            try {
                return $this->commandContext->fs->stat($path);
            } catch (RuntimeException $runtimeException) {
                if (preg_match('/^(ENOENT|ENOTDIR)/', $runtimeException->getMessage()) !== 1) {
                    throw $runtimeException;
                }
            }
        }

        return $this->commandContext->fs->lstat($path);
    }

    /** The parse-time stat of a file named in the expression, like -newer's, which follows links with -H too. */
    private function referenceStat(string $file): FsStat
    {
        try {
            return $this->statAt($this->resolvePath($this->commandContext, $file), 0);
        } catch (RuntimeException $runtimeException) {
            throw new RuntimeException(sprintf("'%s': %s", $file, $this->describeError($runtimeException)), 0, $runtimeException);
        }
    }

    private function typeOf(FsStat $fsStat): string
    {
        return $fsStat->isDirectory ? 'd' : ($fsStat->isSymbolicLink ? 'l' : 'f');
    }

    private function error(string $message): void
    {
        $this->stderr .= sprintf("find: %s\n", $message);
        $this->failed = true;
    }

    /** Start points run until the first argument that looks like part of an expression. */
    private function looksLikeExpression(string $arg): bool
    {
        return (str_starts_with($arg, '-') && $arg !== '-') || $arg === '!' || $arg === '(';
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->at] ?? null;
    }

    private function expectOperand(string $operator): void
    {
        match ($this->peek()) {
            null => throw new RuntimeException(sprintf("expected an expression after '%s'", $operator)),
            ')' => throw new RuntimeException(sprintf("expected an expression between '%s' and ')'", $operator)),
            default => null,
        };
    }

    /**
     * Expression grammar: expression := or (',' or)*, or := and ('-o' and)*, and := unary ('-a'? unary)*.
     *
     * @return Closure(FindEntry): bool
     *
     * @phpstan-impure
     */
    private function parseComma(): Closure
    {
        $left = $this->parseOr();

        while ($this->peek() === ',') {
            $this->at++;
            $this->expectOperand(',');
            $right = $this->parseOr();
            $left = function (FindEntry $findEntry) use ($left, $right): bool {
                $left($findEntry);

                return $right($findEntry);
            };
        }

        return $left;
    }

    /** @return Closure(FindEntry): bool */
    private function parseOr(): Closure
    {
        $left = $this->parseAnd();

        while (in_array($operator = $this->peek(), ['-o', '-or'], true)) {
            $this->at++;
            $this->expectOperand($operator);
            $right = $this->parseAnd();
            $left = fn (FindEntry $findEntry): bool => $left($findEntry) || $right($findEntry);
        }

        return $left;
    }

    /** @return Closure(FindEntry): bool */
    private function parseAnd(): Closure
    {
        $left = $this->parseUnary();

        while (! in_array($operator = $this->peek(), [null, '-o', '-or', ',', ')'], true)) {
            if ($operator === '-a' || $operator === '-and') {
                $this->at++;
                $this->expectOperand($operator);
            }

            $right = $this->parseUnary();
            $left = fn (FindEntry $findEntry): bool => $left($findEntry) && $right($findEntry);
        }

        return $left;
    }

    /** @return Closure(FindEntry): bool */
    private function parseUnary(): Closure
    {
        $token = (string) $this->peek();

        if ($token === '!' || $token === '-not') {
            $this->at++;
            $this->expectOperand($token);
            $operand = $this->parseUnary();

            return fn (FindEntry $findEntry): bool => ! $operand($findEntry);
        }

        if ($token === '(') {
            $this->at++;

            if ($this->peek() === ')') {
                throw new RuntimeException('invalid expression; empty parentheses are not allowed.');
            }

            $inner = $this->peek() === null ? null : $this->parseComma();

            if (! $inner instanceof Closure || $this->peek() !== ')') {
                throw new RuntimeException("invalid expression; I was expecting to find a ')' somewhere but did not see one.");
            }

            $this->at++;

            return $inner;
        }

        if (in_array($token, ['-o', '-or', '-a', '-and', ','], true)) {
            throw new RuntimeException(sprintf("invalid expression; you have used a binary operator '%s' with nothing before it.", $token));
        }

        return $this->parsePrimary();
    }

    private function argument(string $predicate): string
    {
        return $this->tokens[$this->at++] ?? throw new RuntimeException(sprintf("missing argument to `%s'", $predicate));
    }

    /** @return Closure(FindEntry): bool */
    private function parsePrimary(): Closure
    {
        $token = $this->tokens[$this->at++];

        if (! str_starts_with($token, '-')) {
            // GNU guesses at an unquoted glob that the shell expanded into existing names.
            $hint = $this->lastPredicate !== '' && $this->commandContext->fs->exists($this->resolvePath($this->commandContext, $token))
                ? "\nfind: possible unquoted pattern after predicate `{$this->lastPredicate}'?"
                : '';

            throw new RuntimeException(sprintf("paths must precede expression: `%s'", $token).$hint);
        }

        $this->lastPredicate = $token;

        return match ($token) {
            '-name', '-iname' => $this->nameTest($this->argument($token), $token === '-iname'),
            '-path', '-wholename', '-ipath', '-iwholename' => $this->pathTest($token, $this->argument($token)),
            '-regex', '-iregex' => $this->regexTest($this->argument($token), $token === '-iregex'),
            '-regextype' => $this->setRegexType($this->argument($token)),
            '-type', '-xtype' => $this->typeTest($token, $this->argument($token)),
            '-size' => $this->sizeTest($this->argument($token)),
            '-empty' => $this->isEmpty(...),
            '-newer', '-anewer', '-cnewer' => $this->newerThan($this->referenceStat($this->argument($token))->mtime),
            '-samefile' => $this->sameFile($this->referenceStat($this->argument($token))->ino),
            '-inum' => $this->numberTest($token, fn (FindEntry $findEntry): int => $findEntry->stat->ino),
            '-links' => $this->numberTest($token, fn (FindEntry $findEntry): int => $findEntry->stat->nlink),
            '-uid', '-gid' => $this->numberTest($token, fn (): int => $this->uid()),
            '-user', '-group' => $this->ownerTest($token, $this->argument($token)),
            '-nouser', '-nogroup' => fn (): bool => false,
            '-lname', '-ilname' => $this->linkNameTest($this->argument($token), $token === '-ilname'),
            '-daystart' => $this->dayStart(),
            '-follow' => $this->followAll(),
            '-mtime', '-atime', '-ctime' => $this->timeTest($token, $this->argument($token), 86400),
            '-mmin', '-amin', '-cmin' => $this->timeTest($token, $this->argument($token), 60),
            '-perm' => $this->permTest($this->argument($token)),
            '-readable', '-writable', '-executable' => $this->accessTest(['-readable' => 0400, '-writable' => 0200, '-executable' => 0100][$token]),
            '-true' => fn (): bool => true,
            '-false' => fn (): bool => false,
            '-maxdepth', '-mindepth' => $this->setDepth($token, $this->argument($token)),
            '-depth' => $this->setDepthFirst(),
            '-print' => $this->output("\n"),
            '-print0' => $this->output("\0"),
            '-fprint' => $this->output("\n", $this->argument($token)),
            '-fprint0' => $this->output("\0", $this->argument($token)),
            '-printf' => $this->printf($this->argument($token)),
            '-fprintf' => $this->printf(...$this->fprintfArguments()),
            '-ls' => $this->ls(),
            '-fls' => $this->ls($this->argument($token)),
            '-prune' => $this->prune(),
            '-quit' => $this->quitAction(),
            '-delete' => $this->deleteAction(),
            '-exec', '-execdir', '-ok', '-okdir' => $this->execAction($token),
            default => match (true) {
                in_array($token, self::NO_OPS, true) => fn (): bool => true,
                preg_match('/^-newer(.)(.)$/', $token, $m) === 1 => $this->newerXYTest($token, $m[1], $m[2]),
                default => throw new RuntimeException(sprintf("unknown predicate `%s'", $token)),
            },
        };
    }

    /** The base name -name matches: trailing slashes do not count, but "/" stays "/". */
    private function nameOf(string $display): string
    {
        $trimmed = rtrim($display, '/');

        return $trimmed === '' ? '/' : basename($trimmed);
    }

    /** @return Closure(FindEntry): bool */
    private function nameTest(string $pattern, bool $caseless): Closure
    {
        return fn (FindEntry $findEntry): bool => fnmatch($pattern, $this->nameOf($findEntry->display), $caseless ? FNM_CASEFOLD : 0);
    }

    /** @return Closure(FindEntry): bool */
    private function pathTest(string $predicate, string $pattern): Closure
    {
        $flags = str_starts_with($predicate, '-i') ? FNM_CASEFOLD : 0;

        // A path pattern ending in "/" can only match a start point given that way.
        if (str_ends_with($pattern, '/') && ! array_any($this->startPoints, fn (string $start): bool => fnmatch($pattern, $start, $flags))) {
            $this->stderr .= "find: warning: {$predicate} {$pattern} will not match anything because it ends with /.\n";
        }

        return fn (FindEntry $findEntry): bool => fnmatch($pattern, $findEntry->display, $flags);
    }

    /** @return Closure(FindEntry): bool */
    private function setRegexType(string $type): Closure
    {
        $this->regexSyntax = self::REGEX_TYPES[$type] ?? throw new RuntimeException(sprintf(
            "Unknown regular expression type '%s'; valid types are %s.",
            $type,
            implode(', ', array_map(fn (string $name): string => sprintf("'%s'", $name), array_keys(self::REGEX_TYPES))),
        ));

        return fn (): bool => true;
    }

    /** @return Closure(FindEntry): bool */
    private function regexTest(string $pattern, bool $caseless): Closure
    {
        $basic = $this->regexSyntax === 'emacs' ? $this->emacsToBasic($pattern) : $pattern;
        $regex = '/^(?:'.PosixRegex::toPcre($basic, $this->regexSyntax === 'extended', ignoreLeadingOps: false).')$/s'.($caseless ? 'i' : '');
        $error = PosixRegex::error($regex);

        if ($error !== null) {
            throw new RuntimeException(sprintf("failed to compile regular expression '%s': %s", $pattern, $error));
        }

        return fn (FindEntry $findEntry): bool => SafePcreRegex::match($regex, $findEntry->display);
    }

    /** Emacs syntax, find's default, is a BRE where `+` and `?` are operators and `\{` is not an interval. */
    private function emacsToBasic(string $pattern): string
    {
        // Bracket expressions are the same in both.
        return (string) preg_replace_callback(
            '/\[\^?\]?(?:\[:[a-z]+:\]|[^]])*\]|\\\\([\s\S])|[+?]/',
            fn (array $m): string => match (true) {
                $m[0][0] === '[' => $m[0],
                isset($m[1]) => str_contains('+?{}', $m[1]) ? $m[1] : $m[0],
                default => '\\'.$m[0],
            },
            $pattern,
        );
    }

    /** @return Closure(FindEntry): bool */
    private function typeTest(string $predicate, string $types): Closure
    {
        if ($types === '') {
            throw new RuntimeException(sprintf('Arguments to %s should contain at least one letter', $predicate));
        }

        $wanted = [];

        foreach (str_split($types) as $i => $letter) {
            if ($i % 2 === 1) {
                if ($letter !== ',') {
                    throw new RuntimeException(sprintf("Must separate multiple arguments to %s using: ','", $predicate));
                }

                if ($i === strlen($types) - 1) {
                    throw new RuntimeException(sprintf("Last file type in list argument to %s is missing, i.e., list is ending on: ','", $predicate));
                }

                continue;
            }

            match (true) {
                $letter === 'D' => throw new RuntimeException($predicate.' D is not supported because Solaris doors are not supported on the platform find was compiled on.'),
                ! str_contains('bcdpfls', $letter) => throw new RuntimeException(sprintf('Unknown argument to %s: %s', $predicate, $letter)),
                isset($wanted[$letter]) => throw new RuntimeException(sprintf("Duplicate file type '%s' in the argument list to %s.", $letter, $predicate)),
                default => $wanted[$letter] = true,
            };
        }

        if ($predicate === '-type') {
            return fn (FindEntry $findEntry): bool => isset($wanted[$findEntry->type]);
        }

        // -xtype looks at the link itself when links are followed, and at its target (a broken one stays a link) when not.
        return fn (FindEntry $findEntry): bool => isset($wanted[$this->following($findEntry->depth)
            ? $this->typeOf($this->commandContext->fs->lstat($findEntry->path))
            : strtr($this->targetType($findEntry), 'NL', 'll')]);
    }

    /** The type of what a symbolic link points to: N when it is broken, L when it is part of a loop. */
    private function targetType(FindEntry $findEntry): string
    {
        if ($findEntry->type !== 'l') {
            return $findEntry->type;
        }

        try {
            return $this->typeOf($this->commandContext->fs->stat($findEntry->path));
        } catch (RuntimeException $runtimeException) {
            return str_starts_with($runtimeException->getMessage(), 'ELOOP') ? 'L' : 'N';
        }
    }

    /** @return Closure(FindEntry): bool */
    private function sizeTest(string $spec): Closure
    {
        if ($spec === '') {
            throw new RuntimeException('invalid null argument to -size');
        }

        $suffix = $spec[-1];
        $unit = ['b' => 512, 'c' => 1, 'w' => 2, 'k' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][$suffix] ?? (ctype_digit($suffix) ? 512 : throw new RuntimeException(sprintf("invalid -size type `%s'", $suffix)));
        $number = ctype_digit($suffix) ? $spec : substr($spec, 0, -1);

        if (preg_match('/^([-+]?)(\d+)$/', $number, $m) !== 1) {
            throw new RuntimeException(sprintf("Invalid argument `%s' to -size", $spec));
        }

        // Sizes are rounded up to whole units.
        return fn (FindEntry $findEntry): bool => $this->compare((int) ceil($findEntry->stat->size / $unit), $m[1], (int) $m[2]);
    }

    private function compare(int $value, string $sign, int $wanted): bool
    {
        return match ($sign) {
            '+' => $value > $wanted,
            '-' => $value < $wanted,
            default => $value === $wanted,
        };
    }

    private function isEmpty(FindEntry $findEntry): bool
    {
        try {
            return $findEntry->type === 'd' ? $this->commandContext->fs->readdir($findEntry->path) === [] : $findEntry->type === 'f' && $findEntry->stat->size === 0;
        } catch (RuntimeException $runtimeException) {
            $this->error(sprintf("'%s': %s", $findEntry->display, $this->describeError($runtimeException)));

            return false;
        }
    }

    /** @return Closure(FindEntry): bool */
    private function newerThan(int $time): Closure
    {
        return fn (FindEntry $findEntry): bool => $findEntry->stat->mtime > $time;
    }

    /**
     * -newerXY: X and Y are a, c or m (all the modification time here), and Y may be t for a date like `date -d` takes.
     *
     * @return Closure(FindEntry): bool
     */
    private function newerXYTest(string $predicate, string $x, string $y): Closure
    {
        if ($x === 'B' || $y === 'B') {
            throw new RuntimeException("This system does not provide a way to find the birth time of a file.\nfind: invalid predicate `{$predicate}'");
        }

        if (! str_contains('acm', $x) || ! str_contains('acmt', $y)) {
            throw new RuntimeException(sprintf("invalid predicate `%s'", $predicate));
        }

        $reference = $this->tokens[$this->at++] ?? throw new RuntimeException(sprintf("The '%s' test needs an argument", $predicate));

        if ($y !== 't') {
            return $this->newerThan($this->referenceStat($reference)->mtime);
        }

        try {
            return $this->newerThan(new DateTimeImmutable($reference)->getTimestamp());
        } catch (Exception) {
            throw new RuntimeException(sprintf("I cannot figure out how to interpret '%s' as a date or time", $reference));
        }
    }

    /** @return Closure(FindEntry): bool */
    private function sameFile(int $inode): Closure
    {
        return fn (FindEntry $findEntry): bool => $findEntry->stat->ino === $inode;
    }

    /**
     * -inum, -links, -uid and -gid: N, +N or -N.
     *
     * @param  Closure(FindEntry): int  $value
     * @return Closure(FindEntry): bool
     */
    private function numberTest(string $predicate, Closure $value): Closure
    {
        $spec = $this->argument($predicate);

        if (preg_match('/^([-+]?)\s*\+?(\d+)$/', $spec, $m) !== 1) {
            throw new RuntimeException(sprintf("non-numeric argument to %s: '%s'", $predicate, $spec));
        }

        return fn (FindEntry $findEntry): bool => $this->compare($value($findEntry), $m[1], (int) $m[2]);
    }

    /** The sandbox user's (and group's) id. */
    private function uid(): int
    {
        return $this->user === 'root' ? 0 : 1000;
    }

    /**
     * -user and -group take a name the system knows (the sandbox user, or root) or a number.
     *
     * @return Closure(FindEntry): bool
     */
    private function ownerTest(string $predicate, string $name): Closure
    {
        $id = [$this->user => $this->uid(), 'root' => 0][$name] ?? null;

        if ($id === null && preg_match('/^\s*\+?(\d+)$/', $name, $m) === 1 && (int) $m[1] <= 0xFFFFFFFF) {
            $id = (int) $m[1];
        }

        if ($id === null) {
            throw new RuntimeException(sprintf("invalid %s name or %sID argument to %s: '%s'", substr($predicate, 1), strtoupper($predicate[1]), $predicate, $name));
        }

        return fn (): bool => $id === $this->uid();
    }

    /**
     * What a symbolic link points to; with -L only a broken link is still a link.
     *
     * @return Closure(FindEntry): bool
     */
    private function linkNameTest(string $pattern, bool $caseless): Closure
    {
        return fn (FindEntry $findEntry): bool => $findEntry->type === 'l'
            && fnmatch($pattern, $this->commandContext->fs->readlink($findEntry->path), $caseless ? FNM_CASEFOLD : 0);
    }

    /**
     * -mtime N matches ages from N up to N+1 days (+N beyond, -N below); -mmin N from N-1 up to N minutes (+N beyond N, -N below N).
     *
     * @return Closure(FindEntry): bool
     */
    private function timeTest(string $predicate, string $spec, int $unit): Closure
    {
        if (preg_match('/^([-+]?)(\s*[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?)$/i', $spec, $m) !== 1) {
            throw new RuntimeException(sprintf("invalid argument `%s' to `%s'", $spec, $predicate));
        }

        // GNU's origin: a day ago (or the start of today with -daystart) for days, less a second for -N; a day after that for minutes.
        $dayStart = $this->midnight ?? $this->now - 86400;
        $origin = $unit === 60 ? $dayStart + 86400 : $dayStart + ($m[1] === '-' ? 86399 : 0);
        $reference = $origin - (float) $m[2] * $unit;

        return fn (FindEntry $findEntry): bool => match ($m[1]) {
            '+' => $findEntry->stat->mtime < $reference,
            '-' => $findEntry->stat->mtime > $reference,
            default => $findEntry->stat->mtime > $reference && $findEntry->stat->mtime <= $reference + $unit,
        };
    }

    /** @return Closure(FindEntry): bool */
    private function permTest(string $spec): Closure
    {
        $kind = in_array($spec[0] ?? '', ['-', '/'], true) ? $spec[0] : '';
        $mode = substr($spec, strlen($kind));
        // +NNN was an old GNU extension, now rejected.
        $file = UnixFileMode::adjust($mode, 0);
        $directory = UnixFileMode::adjust($mode, 0, true);

        if ($file === null || $directory === null || preg_match('/^\+[0-7]/', $spec) === 1) {
            throw new RuntimeException(sprintf("invalid mode '%s'", $spec));
        }

        $modes = [$file, $directory];

        if ($kind === '/' && $modes === [0, 0]) {
            $this->stderr .= "find: warning: you have specified a mode pattern {$spec} (which is equivalent to /000). The meaning of -perm /000 has now been changed to be consistent with -perm -000; that is, while it used to match no files, it now matches all files.\n";
            $kind = '-';
        }

        return function (FindEntry $findEntry) use ($kind, $modes): bool {
            $wanted = $modes[$findEntry->type === 'd' ? 1 : 0];
            $mode = $findEntry->stat->mode & 07777;

            return match ($kind) {
                '-' => ($mode & $wanted) === $wanted,
                '/' => ($mode & $wanted) !== 0,
                default => $mode === $wanted,
            };
        };
    }

    /** @return Closure(FindEntry): bool */
    private function accessTest(int $bit): Closure
    {
        // Permission checks follow symbolic links, and use the owner's bits since the sandbox user owns everything.
        return function (FindEntry $findEntry) use ($bit): bool {
            try {
                return ($this->commandContext->fs->stat($findEntry->path)->mode & $bit) !== 0;
            } catch (RuntimeException) {
                return false;
            }
        };
    }

    /** @return Closure(FindEntry): bool */
    private function setDepth(string $option, string $value): Closure
    {
        if (! ctype_digit($value)) {
            throw new RuntimeException(sprintf("Expected a positive decimal integer argument to %s, but got '%s'", $option, $value));
        }

        if ($option === '-maxdepth') {
            $this->maxDepth = (int) $value;
        } else {
            $this->minDepth = (int) $value;
        }

        return fn (): bool => true;
    }

    /** @return Closure(FindEntry): bool */
    private function dayStart(): Closure
    {
        // GNU takes the time of day off now, so a repeated -daystart changes nothing.
        [$hours, $minutes, $seconds] = array_map(intval(...), explode(':', $this->time($this->now, 'G:i:s')));
        $this->midnight ??= $this->now - $hours * 3600 - $minutes * 60 - $seconds;

        return fn (): bool => true;
    }

    /** @return Closure(FindEntry): bool */
    private function followAll(): Closure
    {
        $this->follow = 'L';

        return fn (): bool => true;
    }

    /** @return Closure(FindEntry): bool */
    private function setDepthFirst(): Closure
    {
        $this->depthFirst = true;
        $this->explicitDepth = true;

        return fn (): bool => true;
    }

    /**
     * Where an action writes: stdout, or a file opened (and truncated) now, which actions naming the same file share.
     *
     * @return Closure(string): void
     */
    private function sink(?string $file): Closure
    {
        $this->hasAction = true;

        if ($file === null || $file === '/dev/stdout') {
            return function (string $text): void {
                $this->stdout .= $text;
                $this->checkOutputSize($this->commandContext, strlen($this->stdout));
            };
        }

        if ($file === '/dev/stderr') {
            return function (string $text): void {
                $this->stderr .= $text;
            };
        }

        $path = $this->resolvePath($this->commandContext, $file);

        if (! isset($this->files[$path])) {
            try {
                $this->writeOutputFile($this->commandContext, $file, '');
            } catch (RuntimeException $runtimeException) {
                throw new RuntimeException(sprintf("'%s': %s", $file, $this->describeError($runtimeException)), 0, $runtimeException);
            }

            $this->files[$path] = '';
        }

        return function (string $text) use ($path): void {
            $this->files[$path] .= $text;
        };
    }

    /** @return Closure(FindEntry): bool */
    private function output(string $terminator, ?string $file = null): Closure
    {
        $write = $this->sink($file);

        return function (FindEntry $findEntry) use ($write, $terminator): bool {
            $write($findEntry->display.$terminator);

            return true;
        };
    }

    /**
     * -ls and -fls: like `ls -dils`, with columns that widen as wider values come along.
     *
     * @return Closure(FindEntry): bool
     */
    private function ls(?string $file = null): Closure
    {
        $write = $this->sink($file);

        return function (FindEntry $findEntry) use ($write): bool {
            $stat = $findEntry->stat;
            $column = function (int $i, int|string $value, int $pad = STR_PAD_LEFT): string {
                $this->lsWidths[$i] = max($this->lsWidths[$i], strlen((string) $value));

                return str_pad((string) $value, $this->lsWidths[$i], ' ', $pad);
            };
            // The year replaces the time for files over six months old or in the future.
            $recent = $stat->mtime >= $this->now - 15552000 && $stat->mtime <= $this->now + 3600;

            $write(implode(' ', [
                $column(0, $stat->ino),
                $column(1, $this->blocks($stat)),
                ($findEntry->type === 'f' ? '-' : $findEntry->type).UnixFileMode::symbolic($stat->mode),
                $column(2, $stat->nlink),
                $column(3, $this->user, STR_PAD_RIGHT),
                $column(4, $this->user, STR_PAD_RIGHT),
                $column(5, $stat->size),
                $this->time($stat->mtime, 'M ').sprintf('%2d', $this->time($stat->mtime, 'j')).$this->time($stat->mtime, $recent ? ' H:i' : '  Y'),
                $this->lsQuote($findEntry->display).($findEntry->type === 'l' ? ' -> '.$this->lsQuote($this->commandContext->fs->readlink($findEntry->path)) : ''),
            ])."\n");

            return true;
        };
    }

    /** -ls escapes spaces, quotes, backslashes and unprintable bytes. */
    private function lsQuote(string $name): string
    {
        return (string) preg_replace_callback('/[^\x21-\x7e]|[\\\\"]/', fn (array $m): string => match ($m[0]) {
            ' ', '"', '\\' => '\\'.$m[0],
            "\n" => '\\n',
            "\x08" => '\\b',
            "\r" => '\\r',
            "\t" => '\\t',
            "\f" => '\\f',
            default => sprintf('\\%03o', ord($m[0])),
        }, $name);
    }

    /** Allocated 1K blocks, like ls -l's total and du: a symlink's target is stored in its inode. */
    private function blocks(FsStat $fsStat): int
    {
        return $fsStat->isSymbolicLink ? 0 : (int) ceil($fsStat->size / 1024);
    }

    /** @return Closure(FindEntry): bool */
    private function prune(): Closure
    {
        $this->prunes = true;

        return function (): bool {
            $this->pruned = true;

            return true;
        };
    }

    /** @return Closure(FindEntry): bool */
    private function quitAction(): Closure
    {
        $this->hasAction = true;

        return function (): bool {
            $this->quit = true;

            return true;
        };
    }

    /** @return Closure(FindEntry): bool */
    private function deleteAction(): Closure
    {
        $this->hasAction = true;
        $this->deletes = true;
        $this->depthFirst = true;

        return function (FindEntry $findEntry): bool {
            // GNU never removes the "." it was started from.
            if ($this->nameOf($findEntry->display) === '.') {
                return true;
            }

            if ($findEntry->type === 'd' && ! $this->isEmpty($findEntry)) {
                $this->error(sprintf("cannot delete '%s': Directory not empty", $findEntry->display));

                return false;
            }

            try {
                $this->commandContext->fs->rm($findEntry->path);
            } catch (RuntimeException $runtimeException) {
                $this->error(sprintf("cannot delete '%s': %s", $findEntry->display, $this->describeError($runtimeException)));

                return false;
            }

            return true;
        };
    }

    /**
     * -exec/-execdir/-ok/-okdir COMMAND ;, and -exec/-execdir COMMAND {} + which runs once with every name.
     *
     * @return Closure(FindEntry): bool
     */
    private function execAction(string $predicate): Closure
    {
        $this->hasAction = true;
        $command = [];
        $batched = false;
        $inDirectory = str_ends_with($predicate, 'dir');
        $confirm = str_starts_with($predicate, '-ok');

        while (($token = $this->tokens[$this->at++] ?? null) !== ';') {
            if ($token === null) {
                throw new RuntimeException(sprintf("missing argument to `%s'", $predicate));
            }

            if ($token === '+' && ! $confirm && str_contains((string) end($command), '{}')) {
                $batched = true;

                break;
            }

            $command[] = $token;
        }

        if ($command === []) {
            throw new RuntimeException(sprintf("invalid argument `;' to `%s'", $predicate));
        }

        if (! $batched) {
            return function (FindEntry $findEntry) use ($command, $inDirectory, $confirm): bool {
                [$dir, $name] = $this->execTarget($findEntry, $inDirectory);

                if ($confirm && ! $this->confirm($command[0], $findEntry->display)) {
                    return false;
                }

                return $this->run(array_map(fn (string $arg): string => str_replace('{}', $name, $arg), $command), $dir) === 0;
            };
        }

        $suffix = $inDirectory ? 'dir' : '';
        $braces = array_filter($command, fn (string $arg): bool => str_contains($arg, '{}'));

        if (count($braces) > 1) {
            throw new RuntimeException(sprintf('Only one instance of {} is supported with -exec%s ... +', $suffix));
        }

        if (end($command) !== '{}') {
            throw new RuntimeException(sprintf("In '-exec%s ... {} +' the '{}' must appear by itself, but you specified '", $suffix).end($command)."'");
        }

        $id = count($this->batches);
        $this->batches[$id] = ['command' => array_slice($command, 0, -1), 'dir' => null, 'paths' => []];

        return function (FindEntry $findEntry) use ($id, $inDirectory): bool {
            [$dir, $name] = $this->execTarget($findEntry, $inDirectory);

            // -execdir runs separately for each directory.
            if ($this->batches[$id]['paths'] !== [] && $this->batches[$id]['dir'] !== $dir) {
                $this->flush($id);
            }

            $this->batches[$id] = ['command' => $this->batches[$id]['command'], 'dir' => $dir, 'paths' => [...$this->batches[$id]['paths'], $name]];

            return true;
        };
    }

    /**
     * Where a command runs and what {} becomes: the path as shown, or for -execdir "./name" run from the containing directory.
     *
     * @return array{?string, string}
     */
    private function execTarget(FindEntry $findEntry, bool $inDirectory): array
    {
        if (! $inDirectory) {
            return [null, $findEntry->display];
        }

        if (trim($findEntry->display, '/') === '') {
            return ['/', '/'];
        }

        $trimmed = rtrim($findEntry->display, '/');

        return [
            $this->resolvePath($this->commandContext, dirname($trimmed)),
            './'.basename($trimmed).($trimmed === $findEntry->display ? '' : '/'),
        ];
    }

    private function flush(int $id): void
    {
        if ($this->batches[$id]['paths'] !== []) {
            // Any failing run makes find fail, though the action itself is always true.
            if ($this->run([...$this->batches[$id]['command'], ...$this->batches[$id]['paths']], $this->batches[$id]['dir']) !== 0) {
                $this->failed = true;
            }

            $this->batches[$id]['paths'] = [];
        }
    }

    /** -ok asks on stderr and reads the answer from stdin; only an answer starting with y means yes. */
    private function confirm(string $command, string $path): bool
    {
        $this->answers ??= explode("\n", $this->commandContext->stdin);

        $this->stderr .= sprintf('< %s ... %s > ? ', $command, $path);

        return preg_match('/^[yY]/', (string) array_shift($this->answers)) === 1;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, ?string $dir): int
    {
        $script = implode(' ', array_map(fn (string $arg): string => "'".str_replace("'", "'\\''", $arg)."'", $command));
        $execResult = ($this->commandContext->exec)(($dir === null ? '' : "cd '".str_replace("'", "'\\''", $dir)."' && ").$script);
        $this->stdout .= $execResult->stdout;
        $this->checkOutputSize($this->commandContext, strlen($this->stdout));
        // find reports a command it cannot run itself.
        $this->stderr .= $execResult->exitCode === 127 ? "find: '{$command[0]}': No such file or directory\n" : $execResult->stderr;

        return $execResult->exitCode;
    }

    /**
     * Parsed up front, like GNU, so format warnings come before any output.
     *
     * @return Closure(FindEntry): bool
     */
    private function printf(string $format, ?string $file = null): Closure
    {
        $write = $this->sink($file);
        $segments = [];
        $text = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            if ($char === '\\') {
                $next = $format[$i + 1] ?? null;

                if ($next === 'c') {
                    // \c ends the output for this file.
                    $length = $i;

                    break;
                }

                if ($next === null) {
                    $this->stderr .= "find: warning: escape `\\' followed by nothing at all\n";
                    $text .= '\\';
                } elseif (preg_match('/\G[0-7]{1,3}/', $format, $m, 0, $i + 1) === 1) {
                    $text .= chr((int) octdec($m[0]) & 0xFF);
                    $i += strlen($m[0]);
                } elseif (isset(self::ESCAPES[$next])) {
                    $text .= self::ESCAPES[$next];
                    $i++;
                } else {
                    $this->stderr .= "find: warning: unrecognized escape `\\{$next}'\n";
                    $text .= '\\'.$next;
                    $i++;
                }

                continue;
            }

            if ($char !== '%') {
                $text .= $char;

                continue;
            }

            if ($i === $length - 1) {
                throw new RuntimeException('error: % at end of format string');
            }

            if ($format[$i + 1] === '%') {
                $text .= '%';
                $i++;

                continue;
            }

            preg_match('/\G%([-+ #]*\d*(?:\.\d*)?)/', $format, $m, 0, $i);
            $end = $i + strlen($m[0]);
            $directive = $format[$end] ?? '';
            $timed = $directive !== '' && str_contains('ABCT', $directive);

            if ($directive === '' || str_contains('{[(', $directive)) {
                throw new RuntimeException(sprintf("error: the format directive `%%%s' is reserved for future use", $directive === '' ? "\0" : $directive));
            }

            if (str_contains('abcdDfFgGhHiklmMnpPsStuUyYZ', $directive) || ($timed && isset($format[$end + 1]))) {
                $segments[] = $text;
                $segments[] = [$m[1], $timed ? $directive.$format[++$end] : $directive];
                $text = '';
            } else {
                $this->stderr .= $timed
                    ? "find: warning: format directive `%{$directive}' should be followed by another character\n"
                    : "find: warning: unrecognized format directive `%{$directive}'\n";
                $text .= substr($format, $i, $end - $i + 1);
            }

            $i = $end;
        }

        $segments[] = $text;

        return function (FindEntry $findEntry) use ($segments, $write): bool {
            foreach ($segments as $segment) {
                $write(is_string($segment) ? $segment : $this->directive($findEntry, $segment[0], $segment[1]));
            }

            return true;
        };
    }

    /**
     * -fprintf FILE FORMAT, where a missing format makes the file an invalid argument.
     *
     * @return array{string, string}
     */
    private function fprintfArguments(): array
    {
        $file = $this->argument('-fprintf');

        return [$this->tokens[$this->at++] ?? throw new RuntimeException(sprintf("invalid argument `%s' to `-fprintf'", $file)), $file];
    }

    private function directive(FindEntry $findEntry, string $flags, string $directive): string
    {
        preg_match('/^([-+ #]*)(\d*)(?:\.(\d*))?$/', $flags, $f, PREG_UNMATCHED_AS_NULL);
        $mode = $findEntry->stat->mode & 07777;

        // %d and %m are numbers that honour the + and # flags; everything else is a string.
        $value = match ($directive[0]) {
            'd' => (str_contains((string) $f[1], '+') ? '+' : '').$findEntry->depth,
            'm' => (str_contains((string) $f[1], '#') && $mode !== 0 ? '0' : '').decoct($mode),
            'p' => $findEntry->display,
            'f' => $this->baseName($findEntry->display),
            'h' => $this->leadingDirectories($findEntry->display),
            'P' => $findEntry->relative,
            'H' => $findEntry->start,
            's' => (string) $findEntry->stat->size,
            'M' => ($findEntry->type === 'f' ? '-' : $findEntry->type).UnixFileMode::symbolic($mode),
            'y' => $findEntry->type,
            'Y' => $this->targetType($findEntry),
            'i' => (string) $findEntry->stat->ino,
            'n' => (string) $findEntry->stat->nlink,
            'k' => (string) $this->blocks($findEntry->stat),
            'b' => (string) ($this->blocks($findEntry->stat) * 2),
            'u', 'g' => $this->user,
            'U', 'G' => (string) $this->uid(),
            // No devices or mount table: GNU prints "unknown" for a filesystem missing from the mount table.
            'D' => '0',
            'F' => 'unknown',
            'S' => $this->sparseness($findEntry->stat, $f[3]),
            'Z' => $this->noSecurityContext($findEntry),
            'l' => $findEntry->type === 'l' ? $this->commandContext->fs->readlink($findEntry->path) : '',
            'a', 'c', 't' => $this->time($findEntry->stat->mtime, 'D M ').sprintf('%2d', $this->time($findEntry->stat->mtime, 'j')).$this->time($findEntry->stat->mtime, ' H:i:s.0000000000 Y'),
            'A', 'C', 'T' => $this->strftime($findEntry->stat->mtime, $directive[1]),
            default => '',
        };

        $value = $f[3] === null || $directive === 'S' ? $value : substr($value, 0, (int) $f[3]);

        $width = (int) $f[2];
        $this->checkOutputSize($this->commandContext, $width);

        return match (true) {
            str_contains((string) $f[1], '-') => str_pad($value, $width),
            str_starts_with((string) $f[2], '0') => str_pad($value, $width, '0', STR_PAD_LEFT),
            default => str_pad($value, $width, ' ', STR_PAD_LEFT),
        };
    }

    /** Allocated bytes over the size, as C's %g (to an optional precision); an empty file counts as 1. */
    private function sparseness(FsStat $fsStat, ?string $precision): string
    {
        $ratio = $fsStat->size === 0 ? 1 : $this->blocks($fsStat) * 1024 / $fsStat->size;
        $value = sprintf('%.'.max(1, (int) ($precision ?? 6)).'g', $ratio);

        // C drops trailing zeros and writes at least two exponent digits.
        return (string) preg_replace(['/\.?0+e/', '/e([+-])(\d)$/'], ['e', 'e${1}0$2'], $value);
    }

    private function noSecurityContext(FindEntry $findEntry): string
    {
        $this->error(sprintf("getfilecon failed: '%s': Operation not supported", $findEntry->display));

        return '';
    }

    /** GNU's base_name(): the last component, keeping one trailing slash; "/" for the root. */
    private function baseName(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : basename($trimmed).($trimmed === $path ? '' : '/');
    }

    private function leadingDirectories(string $path): string
    {
        $trimmed = rtrim($path, '/');

        if ($trimmed === '') {
            return '';
        }

        $slash = strrpos($trimmed, '/');

        return $slash === false ? '.' : substr($trimmed, 0, $slash);
    }

    private function time(int $timestamp, string $format): string
    {
        return new DateTimeImmutable('@'.$timestamp)->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
    }

    /** One strftime() conversion; GNU prints an unknown one as the letter itself. */
    private function strftime(int $timestamp, string $conversion): string
    {
        return match ($conversion) {
            'e' => sprintf('%2d', $this->time($timestamp, 'j')),
            'k' => sprintf('%2d', $this->time($timestamp, 'G')),
            'l' => sprintf('%2d', $this->time($timestamp, 'g')),
            'j' => sprintf('%03d', (int) $this->time($timestamp, 'z') + 1),
            default => isset(self::TIME_FORMATS[$conversion]) ? $this->time($timestamp, self::TIME_FORMATS[$conversion]) : $conversion,
        };
    }
}
