<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\Arithmetic\ArithArrayElementNode;
use BashBox\Ast\Arithmetic\ArithAssignmentNode;
use BashBox\Ast\Arithmetic\ArithBinaryNode;
use BashBox\Ast\Arithmetic\ArithExpr;
use BashBox\Ast\Arithmetic\ArithGroupNode;
use BashBox\Ast\Arithmetic\ArithNumberNode;
use BashBox\Ast\Arithmetic\ArithTernaryNode;
use BashBox\Ast\Arithmetic\ArithUnaryNode;
use BashBox\Ast\Arithmetic\ArithVariableNode;
use BashBox\Ast\ArithmeticCommandNode;
use BashBox\Ast\CaseNode;
use BashBox\Ast\CommandNode;
use BashBox\Ast\CompoundCommandNode;
use BashBox\Ast\Conditional\CondAndNode;
use BashBox\Ast\Conditional\CondBinaryNode;
use BashBox\Ast\Conditional\CondGroupNode;
use BashBox\Ast\Conditional\ConditionalExpressionNode;
use BashBox\Ast\Conditional\CondNotNode;
use BashBox\Ast\Conditional\CondOrNode;
use BashBox\Ast\Conditional\CondUnaryNode;
use BashBox\Ast\Conditional\CondWordNode;
use BashBox\Ast\ConditionalCommandNode;
use BashBox\Ast\CStyleForNode;
use BashBox\Ast\ForNode;
use BashBox\Ast\FunctionDefNode;
use BashBox\Ast\FunctionPrinter;
use BashBox\Ast\GroupNode;
use BashBox\Ast\HereDocNode;
use BashBox\Ast\IfNode;
use BashBox\Ast\Node;
use BashBox\Ast\PipelineNode;
use BashBox\Ast\RedirectionNode;
use BashBox\Ast\ScriptNode;
use BashBox\Ast\SimpleCommandNode;
use BashBox\Ast\StatementNode;
use BashBox\Ast\SubshellNode;
use BashBox\Ast\UntilNode;
use BashBox\Ast\WhileNode;
use BashBox\Ast\WordNode;
use BashBox\Commands\CommandContext;
use BashBox\Commands\CommandRegistry;
use BashBox\Exceptions\ArithmeticException;
use BashBox\Exceptions\AssignmentException;
use BashBox\Exceptions\BreakException;
use BashBox\Exceptions\ContinueException;
use BashBox\Exceptions\ErrexitException;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Exceptions\ExitException;
use BashBox\Exceptions\ExpansionException;
use BashBox\Exceptions\ParseException;
use BashBox\Exceptions\ReturnException;
use BashBox\Exceptions\UnboundVariableException;
use BashBox\ExecResult;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Interpreter\Expansion\Glob;
use BashBox\Interpreter\Expansion\WordExpander;
use BashBox\Network\SecureHttpClient;
use BashBox\Parser\Int64;
use BashBox\Parser\Parser;
use BashBox\Regex\PosixRegex;
use BashBox\Regex\RegexException;
use BashBox\Regex\SafePcreRegex;
use Closure;
use RuntimeException;
use Throwable;

final class Interpreter
{
    private readonly WordExpander $wordExpander;

    private string $stdout = '';

    private string $stderr = '';

    private int $loopDepth = 0;

    /** Nested $(...), <(...), eval, source and trap texts being run */
    private int $nesting = 0;

    /** Above 0 while a command's status is being tested (if/while/until conditions, `a && b`, `! a`): no ERR trap or errexit */
    private int $conditionDepth = 0;

    /** @var array<string, true> aliases being expanded, which don't expand again inside themselves */
    private array $expandingAliases = [];

    /** @var array<string, string> the aliases when the current input line began */
    private array $lineAliases = [];

    private int $sourceDepth = 0;

    /**
     * The `name=(...)` operands of the declaration builtin being run, by name
     *
     * @var array<string, array{type: 'scalar'|'array'|'element', name: string, value?: string, append: bool, elements?: list<array{int|string|null, string}>, subscript?: int|string}>
     */
    private array $arrayOperands = [];

    /** `declare: ` while a builtin that names itself in assignment errors is assigning, else '' */
    private string $assigning = '';

    /** getopts' position inside a grouped option argument such as -abc */
    private int $getoptsCharIndex = 1;

    /**
     * The open fds (0 travels as the StdinStream argument instead). Output goes to '@1:<level>' (the
     * stdout of that capture level: the shell, a pipe, a `$(...)`), '@2' (the shell's stderr), '@null'
     * or a file path; a StdinStream is a file opened for reading (or read-write). Every write is
     * routed through this table when it happens, so a command's own redirections are in force for
     * everything it runs and `exec 3>file` lasts until closed.
     *
     * @var array<int, string|StdinStream>
     */
    private array $fds = [1 => '@1:0', 2 => '@2'];

    /** @var list<array{path: string, reader: ?SubshellNode}> open process substitutions; reader is a `>(...)` command still to run */
    private array $processSubstitutions = [];

    /** Capture level of the innermost pipe or `$(...)`; output for an outer level waits in $passthrough */
    private int $captureLevel = 0;

    /** @var array<int, string> output written to an outer level's stdout from inside a capture, keyed by level */
    private array $passthrough = [];

    public function __construct(
        private readonly InterpreterState $interpreterState,
        private readonly FileSystemInterface $fileSystem,
        private readonly CommandRegistry $commandRegistry,
        private readonly ?SecureHttpClient $secureHttpClient = null,
    ) {
        $this->wordExpander = new WordExpander($this->interpreterState, $this);
        $this->interpreterState->arithmetic = $this->integerValue(...);
        $this->interpreterState->warn = fn (string $message) => $this->writeStderr("bash: warning: {$message}\n");
    }

    /** An -i variable's new value; an expression that doesn't evaluate ends the shell, as in bash. */
    private function integerValue(string $expression): int
    {
        return $this->fatalArithmetic($expression, $this->assigning);
    }

    /** Evaluates an -i value or an array subscript, already expanded: one that doesn't evaluate ends the shell, as in bash. */
    public function fatalArithmetic(string $expression, string $builtin = ''): int
    {
        try {
            return $this->evaluateArithmeticText($expression);
        } catch (ArithmeticException $arithmeticException) {
            $this->writeStderr("bash: {$builtin}{$arithmeticException->getMessage()}\n");

            throw new ExitException(1);
        }
    }

    /** Runs a script as the whole shell, like `bash -c`: one that doesn't parse runs nothing and exits with status 2. */
    public function executeScript(string $script, string|StdinStream $stdin = '', string $errorPrefix = 'bash: '): ExecResult
    {
        $stdinStream = is_string($stdin) ? new StdinStream($stdin) : $stdin;

        try {
            $exitCode = $this->executeTopLevel($this->parse($script)->statements, $stdinStream);
        } catch (ParseException $parseException) {
            $this->writeStderr($errorPrefix.$parseException->getMessage()."\n");
            $exitCode = 2;
        } catch (ExitException|ErrexitException $e) {
            $exitCode = $e->exitCode;
        } catch (ExpansionException|UnboundVariableException $e) {
            // `${x?}` and nounset errors end a non-interactive shell with status 127
            $this->writeStderr($e->getMessage()."\n");
            $exitCode = 127;
        }

        $exitCode = $this->runExitTrap($exitCode, $stdinStream);

        return new ExecResult(stdout: $this->stdout, stderr: $this->stderr, exitCode: $exitCode);
    }

    /** $line numbers the first line, for source text that sits further down a script (eval, $(...)) */
    private function parse(string $script, int $line = 1, bool $substitution = false): ScriptNode
    {
        return new Parser($this->interpreterState->limits)->parse($script, $line, $substitution);
    }

    /**
     * Runs a script's top-level statements. A non-fatal expansion or arithmetic error abandons the
     * rest of the input line the failing statement ends on, however deeply nested, with status 1;
     * the next line still runs.
     *
     * @param  list<StatementNode>  $statements
     */
    private function executeTopLevel(array $statements, StdinStream $stdinStream): int
    {
        $exitCode = 0;
        $abandonedLine = null;
        $previousEnd = null;

        foreach ($statements as $statement) {
            if ($statement->line === $abandonedLine) {
                continue;
            }

            // bash expands aliases as it reads each line, so a line sees the aliases from before it began
            if ($statement->line !== $previousEnd) {
                $this->lineAliases = $this->interpreterState->aliases;
            }

            $previousEnd = $statement->endLine;

            try {
                $exitCode = $this->executeStatement($statement, $stdinStream);
            } catch (ArithmeticException|ExpansionException|AssignmentException $e) {
                // fatal ones (`${x?}`) keep unwinding to executeScript
                if ($e instanceof ExpansionException && $e->fatal) {
                    throw $e;
                }

                $this->writeStderr('bash: '.preg_replace('/^bash: /', '', $e->getMessage())."\n");
                $exitCode = 1;
                $this->interpreterState->lastExitCode = 1;
                $abandonedLine = $statement->endLine;
            }
        }

        return $exitCode;
    }

    private function runExitTrap(int $exitCode, StdinStream $stdinStream): int
    {
        $trap = $this->interpreterState->traps['EXIT'] ?? '';
        unset($this->interpreterState->traps['EXIT']);

        if ($trap === '') {
            return $exitCode;
        }

        $this->interpreterState->lastExitCode = $exitCode;

        try {
            $this->runInShell($trap, $stdinStream, errorPrefix: 'bash: exit trap: ');
        } catch (ExitException|ErrexitException $e) {
            // `exit N` inside the trap replaces the status
            return $e->exitCode;
        }

        return $exitCode;
    }

    /**
     * Runs source text (eval, source, a trap) in the current shell, where exit/return keep unwinding; a syntax error is status 2.
     *
     * @param  array<string, string>|null  $aliases  an alias's text keeps the aliases of the line it was used on
     */
    private function runInShell(string $script, StdinStream $stdinStream, int $line = 1, ?array $aliases = null, string $errorPrefix = 'bash: '): int
    {
        $currentLine = $this->interpreterState->currentLine;
        $lineAliases = $this->lineAliases;
        $this->lineAliases = $aliases ?? $this->interpreterState->aliases;

        try {
            return $this->nested(fn (): int => $this->executeStatementList($this->parse($script, $line)->statements, $stdinStream));
        } catch (ParseException $parseException) {
            $this->writeStderr($errorPrefix.$parseException->getMessage()."\n");

            return 2;
        } finally {
            $this->interpreterState->currentLine = $currentLine;
            $this->lineAliases = $lineAliases;
        }
    }

    /**
     * runInShell() for a builtin, whose output must come back as a result so its redirections apply
     *
     * @param  array<string, string>|null  $aliases
     */
    private function captureInShell(string $script, StdinStream $stdinStream, int $line = 1, ?array $aliases = null, string $errorPrefix = 'bash: '): ExecResult
    {
        $savedStdout = $this->stdout;
        $savedStderr = $this->stderr;
        $this->stdout = '';
        $this->stderr = '';

        try {
            $exitCode = $this->runInShell($script, $stdinStream, $line, $aliases, $errorPrefix);

            return new ExecResult($this->stdout, $this->stderr, $exitCode);
        } catch (Throwable $throwable) {
            // exit/return unwinding: keep what was already written
            $savedStdout .= $this->stdout;
            $savedStderr .= $this->stderr;

            throw $throwable;
        } finally {
            $this->stdout = $savedStdout;
            $this->stderr = $savedStderr;
        }
    }

    public function executeStatement(StatementNode $statementNode, StdinStream $stdinStream): int
    {
        // set -n: the rest of the script is read but not run
        if ($this->interpreterState->shellOpts['noexec']) {
            return $this->interpreterState->lastExitCode;
        }

        $final = count($statementNode->operators);
        $last = 0;
        // The ERR trap fires only if it was already set when the command began
        $trapped = ($this->interpreterState->traps['ERR'] ?? '') !== '';
        $exitCode = $this->runListPipeline($statementNode, 0, $stdinStream);

        foreach ($statementNode->operators as $i => $op) {
            // A skipped pipeline keeps the status, so `false && a || b` still reaches b
            if (($op === '&&') === ($exitCode === 0)) {
                $last = $i + 1;
                $trapped = ($this->interpreterState->traps['ERR'] ?? '') !== '';
                $exitCode = $this->runListPipeline($statementNode, $last, $stdinStream);
            }
        }

        $pipeline = $statementNode->pipelines[$last];
        $command = $pipeline->commands[0];

        // ERR and errexit fire only for the final pipeline of an and-or list, not when it's negated or tested
        // (if/while/until conditions, or anything running for one); a compound command fails only through a
        // command inside it, which has already had its turn
        if ($exitCode === 0 || $last !== $final || $pipeline->negated || $this->conditionDepth > 0
            || (count($pipeline->commands) === 1 && ! $this->hasOwnStatus($command))) {
            return $exitCode;
        }

        $errTrap = $this->interpreterState->traps['ERR'] ?? '';

        if ($trapped && $errTrap !== '') {
            unset($this->interpreterState->traps['ERR']);

            try {
                $this->runInShell($errTrap, $stdinStream, $this->interpreterState->currentLine, errorPrefix: 'bash: error trap: ');
            } finally {
                $this->interpreterState->traps['ERR'] = $errTrap;
            }

            $this->interpreterState->lastExitCode = $exitCode;
        }

        if ($this->interpreterState->shellOpts['errexit'] ?? false) {
            throw new ErrexitException($exitCode);
        }

        return $exitCode;
    }

    /** Runs pipeline $index of an and-or list; one followed by && or ||, or negated, runs as a condition. */
    private function runListPipeline(StatementNode $statementNode, int $index, StdinStream $stdinStream): int
    {
        $tested = $index < count($statementNode->operators) || $statementNode->pipelines[$index]->negated;
        $this->conditionDepth += (int) $tested;

        try {
            return $this->interpreterState->lastExitCode = $this->executePipeline($statementNode->pipelines[$index], $stdinStream);
        } finally {
            $this->conditionDepth -= (int) $tested;
        }
    }

    public function executePipeline(PipelineNode $pipelineNode, StdinStream $stdinStream): int
    {
        $started = hrtime(true);
        $cpuBefore = $this->cpuTimes();

        // Thread each command's stdout (plus stderr for `|&`) into the next one's stdin
        $statuses = [];
        $lastIndex = count($pipelineNode->commands) - 1;

        if ($lastIndex >= $this->interpreterState->limits->maxPipelineDepth) {
            throw new ExecutionLimitException(sprintf('Pipeline limit exceeded (%d commands)', $this->interpreterState->limits->maxPipelineDepth));
        }

        // Each stage of a real pipeline is a subshell, the last one too unless `shopt -s lastpipe`
        $stage = fn (int $i, CommandNode $command, StdinStream $stdinStream): ExecResult => $lastIndex > 0 && ($i < $lastIndex || ! $this->interpreterState->shopt['lastpipe'])
            ? $this->inSubshell(fn (): ExecResult => $this->executeCommand($command, $stdinStream), counted: false)
            : $this->executeCommand($command, $stdinStream);

        foreach ($pipelineNode->commands as $i => $command) {
            if ($i === $lastIndex) {
                $result = $stage($i, $command, $stdinStream);
                $statuses[] = $result->exitCode;
                $this->appendStderr($result->stderr);
                $this->writeStdout($result->stdout);

                break;
            }

            // An earlier stage writes into the pipe, a capture level of its own
            $result = $this->inCaptureLevel(
                fn (): ExecResult => $this->capture(fn (): ExecResult => $stage($i, $command, $stdinStream)),
                $pipelineNode->pipeStderr[$i] ?? false,
            );
            $statuses[] = $result->exitCode;
            $this->appendStderr($result->stderr);
            $stdinStream = new StdinStream($result->stdout);
        }

        // A lone compound command leaves PIPESTATUS as the last pipeline inside it set it
        if ($lastIndex > 0 || $this->hasOwnStatus($pipelineNode->commands[0])) {
            unset($this->interpreterState->env['PIPESTATUS']);
            $this->interpreterState->arrays['PIPESTATUS'] = array_map(strval(...), $statuses);
        }

        // pipefail: the rightmost failing command decides
        $failures = array_filter($statuses);
        $exitCode = ($this->interpreterState->shellOpts['pipefail'] ?? false) && $failures !== [] ? end($failures) : end($statuses);

        if ($pipelineNode->timed) {
            $this->reportTime($pipelineNode->timePosix, intdiv(hrtime(true) - $started, 1000), $cpuBefore);
        }

        return $pipelineNode->negated ? (int) ($exitCode === 0) : (int) $exitCode;
    }

    /** A simple command, subshell, ((...)) or [[...]]: unlike other compound commands and definitions, its status is its own */
    private function hasOwnStatus(CommandNode $commandNode): bool
    {
        return $commandNode instanceof SimpleCommandNode || $commandNode instanceof SubshellNode
            || $commandNode instanceof ArithmeticCommandNode || $commandNode instanceof ConditionalCommandNode;
    }

    /**
     * `time`'s report on the shell's stderr: TIMEFORMAT (bash's default when unset, the POSIX format for
     * `time -p`) filled in with real time and the user/system CPU time this process spent on the pipeline.
     *
     * @param  array{int, int}  $before  cpuTimes() from when the pipeline started
     */
    private function reportTime(bool $posix, int $real, array $before): void
    {
        [$user, $sys] = $this->cpuTimes();
        $user -= $before[0];
        $sys -= $before[1];
        $format = $posix ? "real %2R\nuser %2U\nsys %2S" : $this->interpreterState->getVar('TIMEFORMAT') ?? "\nreal\t%3lR\nuser\t%3lU\nsys\t%3lS";
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
                $output .= $char === '%' ? '%' : $this->formatSeconds(intdiv($percent, 100), $percent % 100 * 10, 2, false);

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
                $this->writeStderr(sprintf("bash: TIMEFORMAT: `%s': invalid format character\n", $char === '' ? "\0" : $char));

                return;
            }

            $output .= $this->formatSeconds(intdiv($micros, 1_000_000), $micros % 1_000_000, $precision, $long);
        }

        // An empty TIMEFORMAT prints nothing at all
        $this->writeStderr($format === '' ? '' : $output."\n");
    }

    /**
     * The whole pipeline runs in this process, so its CPU time is this process's getrusage() delta.
     *
     * @return array{int, int} user and system CPU time used so far, in microseconds
     */
    private function cpuTimes(): array
    {
        $usage = getrusage() ?: [];
        $field = fn (string $key): int => is_int($usage[$key] ?? null) ? $usage[$key] : 0;
        $micros = fn (string $kind): int => $field(sprintf('ru_%s.tv_sec', $kind)) * 1_000_000 + $field(sprintf('ru_%s.tv_usec', $kind));

        return [$micros('utime'), $micros('stime')];
    }

    /** bash's mkfmt(): [MMm]SS[.FFF][s] with the fraction rounded to $precision places, carry quirk included */
    private function formatSeconds(int $seconds, int $micros, int $precision, bool $long): string
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

    /**
     * Runs $run with empty output buffers and returns what was written plus the result's own output.
     * On break/return/exit unwinding through, what was written so far is kept.
     *
     * @param  Closure(): ExecResult  $run
     */
    private function capture(Closure $run): ExecResult
    {
        $savedStdout = $this->stdout;
        $savedStderr = $this->stderr;
        $this->stdout = '';
        $this->stderr = '';

        try {
            $result = $run();
        } catch (Throwable $throwable) {
            $this->stdout = $savedStdout.$this->stdout;
            $this->stderr = $savedStderr.$this->stderr;

            throw $throwable;
        }

        $execResult = new ExecResult($this->stdout.$result->stdout, $this->stderr.$result->stderr, $result->exitCode);
        $this->stdout = $savedStdout;
        $this->stderr = $savedStderr;

        return $execResult;
    }

    /**
     * Runs $run as a new capture level (a pipe stage, `$(...)`): fd 1 (and fd 2 for `|&`) is the capture,
     * and fd changes don't outlive it. Output sent to an outer level's stdout through a duplicated fd
     * (`exec 3>&1; x=$(echo hi >&3)`) is delivered once that level is current again.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    private function inCaptureLevel(Closure $run, bool $stderrToo = false): mixed
    {
        $savedFds = $this->fds;
        $this->fds[1] = '@1:'.++$this->captureLevel;

        if ($stderrToo) {
            $this->fds[2] = $this->fds[1];
        }

        try {
            return $run();
        } finally {
            $this->fds = $savedFds;
            $this->captureLevel--;
            $this->writeStdout($this->passthrough[$this->captureLevel] ?? '');
            unset($this->passthrough[$this->captureLevel]);
        }
    }

    /**
     * After a command, puts back the fds its redirections changed; other changes (`exec 3>f` inside it) stay.
     *
     * @param  array<int, string|StdinStream>  $saved
     * @param  array<int, string|StdinStream>  $installed
     */
    private function restoreFds(array $saved, array $installed): void
    {
        foreach (array_keys($saved + $installed) as $fd) {
            if (($saved[$fd] ?? null) === ($installed[$fd] ?? null)) {
                continue;
            }

            if (isset($saved[$fd])) {
                $this->fds[$fd] = $saved[$fd];
            } else {
                unset($this->fds[$fd]);
            }
        }
    }

    /** Process substitutions the command opens are closed when it finishes, after any `>(...)` reader has run. */
    public function executeCommand(CommandNode $commandNode, StdinStream $stdinStream): ExecResult
    {
        $mark = count($this->processSubstitutions);

        try {
            $result = $this->runCommandNode($commandNode, $stdinStream);

            foreach (array_slice($this->processSubstitutions, $mark) as ['path' => $path, 'reader' => $reader]) {
                if ($reader !== null) {
                    // A command may have removed or replaced the file; its reader then gets nothing
                    $read = $this->runProcessSubstitution($reader, ($this->statPath($path)->isFile ?? false) ? $this->fileSystem->readFile($path) : '');
                    $result = new ExecResult($result->stdout.$read->stdout, $result->stderr.$read->stderr, $result->exitCode);
                }
            }

            return $result;
        } finally {
            foreach (array_splice($this->processSubstitutions, $mark) as ['path' => $path]) {
                $this->tryFs(fn () => $this->fileSystem->rm($path, ['force' => true, 'recursive' => true]));
            }
        }
    }

    /**
     * `<(cmd)` and `>(cmd)` expand to a file at /dev/fd/N (63 down, numbered as bash does) that any command can open.
     * `<(cmd)` runs now and the file holds its output; `>(cmd)` runs once the command that expanded it is done,
     * reading what was written to the file. Both run in a subshell; the file goes when the command finishes.
     */
    public function processSubstitution(string $direction, string $script): string
    {
        $subshellNode = new SubshellNode($this->parse($script, $this->interpreterState->currentLine, substitution: true)->statements);
        $path = '/dev/fd/'.(63 - count($this->processSubstitutions));
        $this->processSubstitutions[] = ['path' => $path, 'reader' => $direction === '>' ? $subshellNode : null];
        $content = '';

        if ($direction === '<') {
            $result = $this->runProcessSubstitution($subshellNode, '');
            $this->writeStderr($result->stderr);
            $content = $result->stdout;
        }

        $error = $this->tryFs(function () use ($path, $content): void {
            $this->fileSystem->mkdir('/dev/fd', ['recursive' => true]);
            $this->fileSystem->writeFile($path, $content);
        });

        return $error === null ? $path : throw new ExpansionException('bash: cannot make pipe for process substitution: '.$error);
    }

    private function runProcessSubstitution(SubshellNode $subshellNode, string $stdin): ExecResult
    {
        return $this->nested(fn (): ExecResult => $this->inCaptureLevel(fn (): ExecResult => $this->capture(fn (): ExecResult => $this->executeSubshell($subshellNode, new StdinStream($stdin)))));
    }

    /**
     * Runs nested shell text ($(...), <(...), eval, source, a trap) within maxSubstitutionDepth.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    private function nested(Closure $run): mixed
    {
        if ($this->nesting >= $this->interpreterState->limits->maxSubstitutionDepth) {
            throw new ExecutionLimitException(sprintf('Substitution depth limit exceeded (%d)', $this->interpreterState->limits->maxSubstitutionDepth));
        }

        $this->nesting++;

        try {
            return $run();
        } finally {
            $this->nesting--;
        }
    }

    private function runCommandNode(CommandNode $commandNode, StdinStream $stdinStream): ExecResult
    {
        $this->interpreterState->incrementCommandCount();
        $this->interpreterState->currentLine = $commandNode->line;

        if (! $commandNode instanceof CompoundCommandNode) {
            return $this->dispatchCommand($commandNode, $stdinStream);
        }

        $opened = $this->openRedirections($commandNode->redirections, $stdinStream);

        if ($opened instanceof ExecResult) {
            return $opened;
        }

        // The body runs with the compound's fds, so its commands write straight to the targets; what reaches
        // the buffers is for the caller's stdout/stderr, captured so it can flow into a pipe after the compound.
        $savedFds = $this->fds;
        $this->fds = $opened['fds'];

        try {
            return $this->capture(fn (): ExecResult => $this->routeOutput($this->dispatchCommand($commandNode, $opened['stdin'] ?? $stdinStream), $this->fds));
        } finally {
            $this->restoreFds($savedFds, $opened['fds']);
        }
    }

    private function dispatchCommand(Node $node, StdinStream $stdinStream): ExecResult
    {
        // The parser builds only these node types; anything else fails loudly with UnhandledMatchError
        return match (true) { // @phpstan-ignore match.unhandled
            $node instanceof SimpleCommandNode => $this->executeSimpleCommand($node, $stdinStream),
            $node instanceof IfNode => $this->executeIf($node, $stdinStream),
            $node instanceof ForNode => $this->inLoop(fn (): ExecResult => $this->executeFor($node, $stdinStream)),
            $node instanceof CStyleForNode => $this->inLoop(fn (): ExecResult => $this->executeCStyleFor($node, $stdinStream)),
            $node instanceof WhileNode => $this->inLoop(fn (): ExecResult => $this->executeWhile($node, $stdinStream)),
            $node instanceof UntilNode => $this->inLoop(fn (): ExecResult => $this->executeUntil($node, $stdinStream)),
            $node instanceof CaseNode => $this->executeCase($node, $stdinStream),
            $node instanceof SubshellNode => $this->executeSubshell($node, $stdinStream),
            $node instanceof GroupNode => $this->executeGroup($node, $stdinStream),
            $node instanceof ArithmeticCommandNode => $this->executeArithmeticCommand($node),
            $node instanceof ConditionalCommandNode => $this->executeConditionalCommand($node),
            $node instanceof FunctionDefNode => $this->executeFunctionDef($node),
        };
    }

    /**
     * Tracks loop nesting so break/continue outside a loop can be rejected like bash does.
     *
     * @param  Closure(): ExecResult  $loop
     */
    private function inLoop(Closure $loop): ExecResult
    {
        $this->loopDepth++;

        try {
            return $loop();
        } finally {
            $this->loopDepth--;
        }
    }

    // =========================================================================
    // SIMPLE COMMAND
    // =========================================================================

    private function executeSimpleCommand(SimpleCommandNode $simpleCommandNode, StdinStream $stdinStream): ExecResult
    {
        if (($aliased = $this->expandAlias($simpleCommandNode, $stdinStream)) instanceof ExecResult) {
            return $aliased;
        }

        if ($this->interpreterState->shellOpts['keyword']) {
            $simpleCommandNode = $this->keywordAssignments($simpleCommandNode);
        }

        $prefixAssignments = [];
        $this->interpreterState->substitutionStatus = null;

        foreach ($simpleCommandNode->assignments as $assignment) {
            $prefixAssignments[] = $this->resolveAssignment($assignment);
        }

        if (($this->interpreterState->shellOpts['xtrace'] ?? false) && ($simpleCommandNode->assignments !== [] || $simpleCommandNode->name instanceof \BashBox\Ast\WordNode)) {
            $this->writeStderr('+ '.$this->formatTraceCommand($simpleCommandNode, $prefixAssignments)."\n");
        }

        // Command name and args both go through field splitting, so `$CMD arg` with CMD="ls -l" runs ls.
        $words = [];

        if ($simpleCommandNode->name instanceof \BashBox\Ast\WordNode) {
            $words = $this->expandWordList($simpleCommandNode->name);
        }

        foreach ($simpleCommandNode->args as $arg) {
            array_push($words, ...$this->expandWordList($arg));
        }

        $opened = $this->openRedirections($simpleCommandNode->redirections, $stdinStream);

        if ($opened instanceof ExecResult) {
            return $opened;
        }

        $fds = $opened['fds'];
        $redirectedStdin = $opened['stdin'] ?? $stdinStream;

        // `exec` without a command applies its redirections to the shell itself
        if ($words === ['exec'] && ! isset($this->interpreterState->disabledBuiltins['exec'])) {
            $this->fds = $fds;

            if ($opened['stdin'] instanceof StdinStream) {
                $stdinStream->redirect($opened['stdin']);
            }

            return new ExecResult(exitCode: 0);
        }

        // No command name - just assignments
        if ($words === []) {
            foreach ($prefixAssignments as $prefixAssignment) {
                $this->applyAssignment($prefixAssignment);
            }

            return new ExecResult(exitCode: $this->interpreterState->substitutionStatus ?? 0);
        }

        $commandName = array_shift($words);

        // Prefix assignments (`IFS=: read`, `x=1 f`) hold, exported, only for this one command
        $savedVars = [];

        foreach ($prefixAssignments as $prefixAssignment) {
            $name = $prefixAssignment['name'];

            // like bash: report it on the shell's stderr (not the command's) and run the command anyway
            if ($this->interpreterState->isReadonly($name)) {
                $this->writeStderr("bash: {$name}: readonly variable\n");

                continue;
            }

            $savedVars[$name] ??= [$this->interpreterState->getVar($name), isset($this->interpreterState->exported[$name])];
            // bash's temporary variable for the command has none of the -i, -l and -u attributes
            $this->interpreterState->setVar($name, $prefixAssignment['value'] ?? '', plain: true);
            $this->interpreterState->exported[$name] = true;
        }

        // `declare -A m=(...)`: the builtin sees the name m, and assigns the array once it has the attributes
        $arrayOperands = [];

        foreach (in_array($commandName, ['declare', 'typeset', 'local', 'readonly', 'export'], true) ? $simpleCommandNode->arrayArgs : [] as $arrayArg) {
            $arrayOperands[$arrayArg->name] = $this->resolveAssignment($arrayArg);
        }

        // The command (and anything it runs) writes through its own fds; its output arrives already routed
        $savedFds = $this->fds;
        $this->fds = $fds;
        $this->arrayOperands = $arrayOperands;

        try {
            return $this->tryBuiltin($commandName, $words, $redirectedStdin)
                ?? (isset($this->interpreterState->functions[$commandName])
                    ? $this->callFunction($commandName, $words, $redirectedStdin)
                    : $this->runCommand($commandName, $words, $redirectedStdin));
        } finally {
            $this->arrayOperands = [];
            $this->restoreVars($savedVars);
            $this->restoreFds($savedFds, $fds);
        }
    }

    /** set -k: assignments among the arguments, not just before the command name, go into the command's environment */
    private function keywordAssignments(SimpleCommandNode $simpleCommandNode): SimpleCommandNode
    {
        $args = [];
        $assignments = $simpleCommandNode->assignments;

        foreach ($simpleCommandNode->args as $arg) {
            $first = $arg->parts[0] ?? null;

            if ($first instanceof \BashBox\Ast\Parts\LiteralPart && preg_match('/^([a-zA-Z_]\w*)(\+?)=(.*)$/s', $first->value, $m) === 1) {
                $assignments[] = new \BashBox\Ast\AssignmentNode($m[1], new WordNode([new \BashBox\Ast\Parts\LiteralPart($m[3]), ...array_slice($arg->parts, 1)]), $m[2] === '+');
            } else {
                $args[] = $arg;
            }
        }

        return new SimpleCommandNode($simpleCommandNode->name, $args, $assignments, $simpleCommandNode->redirections, $simpleCommandNode->line, $simpleCommandNode->arrayArgs);
    }

    /**
     * With `shopt -s expand_aliases`, a command named by an unquoted alias runs as the alias text followed by
     * the rest of the command; an alias isn't expanded again inside its own expansion.
     */
    private function expandAlias(SimpleCommandNode $simpleCommandNode, StdinStream $stdinStream): ?ExecResult
    {
        $name = $simpleCommandNode->name instanceof WordNode ? $this->rawWordValue($simpleCommandNode->name) : '';
        $alias = $this->lineAliases[$name] ?? null;

        if ($alias === null || ! $this->interpreterState->shopt['expand_aliases'] || isset($this->expandingAliases[$name])) {
            return null;
        }

        $this->expandingAliases[$name] = true;

        try {
            $expanded = new SimpleCommandNode(
                new WordNode([new \BashBox\Ast\Parts\LiteralPart($alias)]),
                $simpleCommandNode->args,
                $simpleCommandNode->assignments,
                $simpleCommandNode->redirections,
                $simpleCommandNode->line,
                $simpleCommandNode->arrayArgs,
            );

            return $this->captureInShell(FunctionPrinter::simpleCommandText($expanded), $stdinStream, $simpleCommandNode->line, $this->lineAliases);
        } finally {
            unset($this->expandingAliases[$name]);
        }
    }

    /** @param list<string> $args */
    private function callFunction(string $name, array $args, StdinStream $stdinStream): ExecResult
    {
        // break/continue in a function body can't reach the caller's loops
        $loopDepth = $this->loopDepth;
        $this->loopDepth = 0;

        try {
            return $this->executeFunction($name, $args, $stdinStream);
        } finally {
            $this->loopDepth = $loopDepth;
        }
    }

    /** @param array<string, array{?string, bool}> $savedVars value (null = was unset) and export attribute from before a prefix assignment */
    private function restoreVars(array $savedVars): void
    {
        foreach ($savedVars as $name => [$value, $exported]) {
            if ($value === null) {
                unset($this->interpreterState->env[$name]);
            } else {
                $this->interpreterState->setVar($name, $value, plain: true);
            }

            if (! $exported) {
                unset($this->interpreterState->exported[$name]);
            }
        }
    }

    /**
     * Runs a registered (external-style) command, skipping builtins and functions.
     *
     * @param  list<string>  $args
     */
    private function runCommand(string $name, array $args, StdinStream $stdinStream): ExecResult
    {
        if (str_contains($name, '/')) {
            return $this->routeOutput($this->runScriptFile($name, $args, $stdinStream), $this->fds);
        }

        $cmd = $this->commandRegistry->get($name);

        if (! $cmd instanceof \BashBox\Commands\CommandInterface) {
            return $this->routeOutput(new ExecResult(stderr: "bash: {$name}: command not found\n", exitCode: 127), $this->fds);
        }

        return $this->routeOutput($cmd->execute($args, $this->commandContext($stdinStream)), $this->fds, in_array($name, self::REGISTRY_BUILTINS, true) ? 'bash: '.$name : $name);
    }

    private function commandContext(StdinStream $stdinStream): CommandContext
    {
        return new CommandContext(
            fs: $this->fileSystem,
            cwd: $this->interpreterState->cwd,
            env: $this->interpreterState->getExportedEnv(),
            stdin: $stdinStream,
            limits: $this->interpreterState->limits,
            exec: $this->runForCommand(...),
            fetch: $this->secureHttpClient,
            registry: $this->commandRegistry,
            umask: (int) octdec($this->interpreterState->umask),
        );
    }

    /**
     * `printf -v name format [arguments]`: the printf command's output, newlines and all, assigned to the variable
     * (or element); without -v it's left to the printf command.
     *
     * @param  list<string>  $args
     */
    private function builtinPrintf(array $args, StdinStream $stdinStream): ?ExecResult
    {
        if (preg_match('/^-v(.*)$/s', $args[0] ?? '', $m) !== 1) {
            return null;
        }

        $usage = "printf: usage: printf [-v var] format [arguments]\n";
        $name = $m[1] === '' ? $args[1] ?? null : $m[1];
        $args = array_slice($args, $m[1] === '' ? 2 : 1);

        if ($name === null) {
            return new ExecResult(stderr: "bash: printf: -v: option requires an argument\n".$usage, exitCode: 2);
        }

        if (preg_match('/^[a-zA-Z_]\w*(\[.+\])?$/s', $name) !== 1) {
            return new ExecResult(stderr: "bash: printf: `{$name}': not a valid identifier\n", exitCode: 2);
        }

        if (array_values(array_diff($args, ['--'])) === []) {
            return new ExecResult(stderr: $usage, exitCode: 2);
        }

        $result = $this->commandRegistry->get('printf')?->execute($args, $this->commandContext($stdinStream)) ?? new ExecResult;
        $this->readAssign($name, $result->stdout);

        return new ExecResult(stderr: $result->stderr, exitCode: $result->exitCode);
    }

    /**
     * A command named by path runs the file as a script in a child shell that sees only exported variables.
     *
     * @param  list<string>  $args
     */
    private function runScriptFile(string $name, array $args, StdinStream $stdinStream): ExecResult
    {
        $stat = $this->statPath($name);
        [$error, $status] = match (true) {
            ! $stat instanceof \BashBox\Filesystem\FsStat => ['No such file or directory', 127],
            $stat->isDirectory => ['Is a directory', 126],
            ($stat->mode & 0o111) === 0 => ['Permission denied', 126],
            default => [null, 0],
        };
        $script = '';

        try {
            $script = $error === null ? $this->fileSystem->readFile($this->resolveFsPath($name)) : '';
        } catch (RuntimeException $runtimeException) {
            [$error, $status] = [$this->strerror($runtimeException), 126];
        }

        // Only a shell script can run here: no other interpreter exists in the sandbox
        $interpreter = preg_match('/^#!\s*(\S+)(?:\s+(\S+))?/', $script, $m) === 1 ? $m[1] : null;

        if ($interpreter !== null && ! in_array(basename($interpreter === '/usr/bin/env' ? $m[2] ?? '' : $interpreter), ['bash', 'sh'], true)) {
            [$error, $status] = [$interpreter.': bad interpreter: No such file or directory', 126];
        }

        if ($error !== null) {
            return new ExecResult(stderr: sprintf("bash: %s: %s\n", $name, $error), exitCode: $status);
        }

        $interpreterState = new InterpreterState($this->interpreterState->getExportedEnv(), $this->interpreterState->cwd, $this->interpreterState->limits);
        $interpreterState->positionalParams = $args;
        $interpreterState->scriptName = $name;
        $interpreterState->commandCount = $this->interpreterState->commandCount;

        $child = new self($interpreterState, $this->fileSystem, $this->commandRegistry, $this->secureHttpClient);

        try {
            return $this->nested(function () use ($child, $script, $stdinStream, $name): ExecResult {
                // The child counts toward the same nesting and command limits, so a script running itself stops
                $child->nesting = $this->nesting;

                return $child->executeScript($script, $stdinStream, $name.': ');
            });
        } finally {
            $this->interpreterState->commandCount = $interpreterState->commandCount;
        }
    }

    // =========================================================================
    // BUILTINS
    // =========================================================================

    /** Commands BashBox implements in the registry that real bash runs as builtins */
    private const array REGISTRY_BUILTINS = ['echo', 'printf', 'test', 'true', 'false', 'pwd'];

    /** @param list<string> $args */
    private function tryBuiltin(string $name, array $args, StdinStream $stdinStream): ?ExecResult
    {
        if (isset($this->interpreterState->disabledBuiltins[$name])) {
            return null;
        }

        $assigning = $this->assigning;
        $this->assigning = in_array($name, ['declare', 'typeset', 'local', 'export', 'readonly', 'read', 'printf'], true) ? $name.': ' : '';

        try {
            $result = $this->dispatchBuiltin($name, $args, $stdinStream);
        } catch (AssignmentException $assignmentException) {
            // a builtin assigning to a readonly variable (read, let, mapfile, getopts, ...) just fails, but a failed
            // `declare a=(...)` abandons its line like a plain assignment
            $result = $this->arrayOperands === [] ? new ExecResult(stderr: $assignmentException->getMessage()."\n", exitCode: 1) : throw $assignmentException;
        } finally {
            $this->assigning = $assigning;
        }

        // Builtins that run other commands return output those already routed through the fd table
        return ! $result instanceof ExecResult || in_array($name, ['source', '.', 'eval', 'command', 'builtin', 'exec'], true)
            ? $result
            : $this->routeOutput($result, $this->fds, 'bash: '.$name);
    }

    /** @param list<string> $args */
    private function dispatchBuiltin(string $name, array $args, StdinStream $stdinStream): ?ExecResult
    {
        return match ($name) {
            'exit' => $this->builtinExit($args),
            'export' => $this->builtinExport($args),
            'unset' => $this->builtinUnset($args),
            'local' => $this->builtinLocal($args),
            'set' => $this->builtinSet($args),
            'shopt' => $this->builtinShopt($args),
            'cd' => $this->builtinCd($args),
            'source', '.' => $this->builtinSource($args, $stdinStream),
            'eval' => $this->captureInShell(implode(' ', $args), $stdinStream, $this->interpreterState->currentLine, errorPrefix: 'bash: eval: '),
            'declare', 'typeset' => $this->builtinDeclare($args),
            'printf' => $this->builtinPrintf($args, $stdinStream),
            'read' => $this->builtinRead($args, $stdinStream),
            'break' => $this->builtinBreak($args),
            'continue' => $this->builtinContinue($args),
            'return' => $this->builtinReturn($args),
            'shift' => $this->builtinShift($args),
            'let' => $this->builtinLet($args),
            'getopts' => $this->builtinGetopts($args),
            'mapfile', 'readarray' => $this->builtinMapfile($args, $stdinStream),
            ':' => new ExecResult(exitCode: 0),
            'type' => $this->builtinType($args),
            'command' => $this->builtinCommand($args, $stdinStream),
            'alias' => $this->builtinAlias($args),
            'unalias' => $this->builtinUnalias($args),
            // No external programs are ever looked up on PATH, so the hash table stays empty
            'hash' => new ExecResult(stdout: $args === [] ? "hash: hash table empty\n" : ''),
            'readonly' => $this->builtinReadonly($args),
            'trap' => $this->builtinTrap($args),
            'builtin' => $this->builtinBuiltin($args, $stdinStream),
            'exec' => $this->builtinExec($args, $stdinStream),
            'pushd' => $this->builtinPushd($args),
            'popd' => $this->builtinPopd(),
            'dirs' => $this->builtinDirs($args),
            'caller' => $this->builtinCaller($args),
            'help' => $this->builtinHelp($args),
            'enable' => $this->builtinEnable($args),
            // There's no job control or programmable completion: these report an empty state
            'wait', 'jobs', 'complete' => new ExecResult(exitCode: 0),
            'compgen' => new ExecResult(exitCode: 1), // no completions are ever generated
            'disown' => new ExecResult(stderr: 'bash: disown: '.($args[0] ?? 'current').": no such job\n", exitCode: 1),
            'compopt' => new ExecResult(stderr: "bash: compopt: not currently executing completion function\n", exitCode: 1),
            'fg' => new ExecResult(stderr: "bash: fg: no job control\n", exitCode: 1),
            'bg' => new ExecResult(stderr: "bash: bg: no job control\n", exitCode: 1),
            'kill' => $this->builtinKill($args),
            'suspend' => new ExecResult(stderr: "bash: suspend: cannot suspend: no job control\n", exitCode: 1),
            'logout' => new ExecResult(stderr: "bash: logout: not login shell: use `exit'\n", exitCode: 1),
            'times' => new ExecResult(stdout: "0m0.000s 0m0.000s\n0m0.000s 0m0.000s\n", exitCode: 0),
            'ulimit' => $this->builtinUlimit($args),
            'umask' => $this->builtinUmask($args),
            default => null,
        };
    }

    /** @param array<int, string> $args */
    private function builtinExit(array $args): ExecResult
    {
        if (count($args) > 1) {
            $this->writeStderr("bash: exit: too many arguments\n");

            throw new ExitException(1);
        }

        throw new ExitException($this->parseStatusArg('exit', $args));
    }

    /**
     * The optional status operand of exit/return, truncated to 0-255 like a process status.
     *
     * @param  array<int, string>  $args
     */
    private function parseStatusArg(string $builtin, array $args): int
    {
        if ($args === []) {
            return $this->interpreterState->lastExitCode;
        }

        if (preg_match('/^\s*[-+]?\d+\s*$/', $args[0]) !== 1) {
            $this->writeStderr("bash: {$builtin}: {$args[0]}: numeric argument required\n");

            return 2;
        }

        return (int) $args[0] & 255;
    }

    /** @param array<int, string> $args */
    private function builtinExport(array $args): ExecResult
    {
        [$options, , $names] = $this->declarationOptions($args);
        $valid = strspn($options, 'fnp');

        if ($valid < strlen($options)) {
            return new ExecResult(stderr: "bash: export: -{$options[$valid]}: invalid option\nexport: usage: export [-fn] [name[=value] ...] or export -p [-f]\n", exitCode: 2);
        }

        // Functions are never passed to commands, so `export -f` only checks the names
        if (str_contains($options, 'f')) {
            $missing = array_diff($names, array_keys($this->interpreterState->functions));

            return new ExecResult(stderr: implode('', array_map(fn (string $name): string => "bash: export: {$name}: not a function\n", $missing)), exitCode: $missing === [] ? 0 : 1);
        }

        if ($names === []) {
            $exported = array_keys($this->interpreterState->exported);
            sort($exported);

            return $this->printDeclarations($exported);
        }

        $status = 0;

        foreach ($names as $arg) {
            [$name, $value] = $this->splitAssignment($arg);

            if (! $this->validIdentifier('export', $arg, $name)) {
                $status = 1;

                continue;
            }

            if ($value !== null && $this->interpreterState->isReadonly($name)) {
                return new ExecResult(stderr: "bash: {$name}: readonly variable\n", exitCode: 1);
            }

            $this->assignOperand($name, $value);

            if (str_contains($options, 'n')) {
                unset($this->interpreterState->exported[$name]);
            } else {
                $this->interpreterState->exported[$name] = true;
            }
        }

        return new ExecResult(exitCode: $status);
    }

    /** @param array<int, string> $args */
    private function builtinUnset(array $args): ExecResult
    {
        $options = '';

        while (preg_match('/^-(.+)$/s', $args[0] ?? '', $m) === 1) {
            array_shift($args);

            if ($m[1] === '-') {
                break;
            }

            $options .= $m[1];
        }

        $valid = strspn($options, 'fvn');

        if ($valid < strlen($options)) {
            return new ExecResult(stderr: "bash: unset: -{$options[$valid]}: invalid option\nunset: usage: unset [-f] [-v] [-n] [name ...]\n", exitCode: 2);
        }

        $reference = str_contains($options, 'n');
        $stderr = '';

        foreach ($args as $arg) {
            if (str_contains($options, 'f')) {
                unset($this->interpreterState->functions[$arg]);
            } elseif (preg_match('/^([a-zA-Z_]\w*)(\[.+\])?$/s', $arg, $m) !== 1) {
                // bash passes over other names that can't be variables
                $stderr .= str_starts_with($arg, '-') ? "bash: unset: `{$arg}': not a valid identifier\n" : '';
            } elseif ($this->interpreterState->isReadonly(explode('[', $reference ? $m[1] : $this->interpreterState->resolve($m[1]) ?? '')[0])) {
                return new ExecResult(stderr: $stderr."bash: unset: {$m[1]}: cannot unset: readonly variable\n", exitCode: 1);
            } else {
                $this->interpreterState->unsetVar($arg, $reference);
            }
        }

        return new ExecResult(stderr: $stderr, exitCode: $stderr === '' ? 0 : 1);
    }

    /** @param array<int, string> $args */
    private function builtinLocal(array $args): ExecResult
    {
        if ($this->interpreterState->localScopes === []) {
            return new ExecResult(stderr: "bash: local: can only be used in a function\n", exitCode: 1);
        }

        // local takes declare's options; with no names it lists the function's locals
        return $this->builtinDeclare($args, 'local');
    }

    /** @param array<int, string> $args */
    private function builtinSet(array $args): ExecResult
    {
        if ($args === []) {
            $env = $this->interpreterState->env;
            ksort($env);
            $output = '';

            foreach ($env as $name => $value) {
                // bash quotes only values that need it
                $output .= $name.'='.(preg_match('/^[\w\/:,+@%=.~#-]*$/', $value) === 1 ? $value : "'".str_replace("'", "'\\''", $value)."'")."\n";
            }

            return new ExecResult(stdout: $output);
        }

        // Letters bash accepts but that change nothing here map to null
        $letters = ['a' => 'allexport', 'e' => 'errexit', 'u' => 'nounset', 'x' => 'xtrace', 'v' => 'verbose', 'f' => 'noglob', 'C' => 'noclobber', 'E' => 'errtrace', 'T' => 'functrace', 'k' => 'keyword', 'n' => 'noexec']
            + array_fill_keys(str_split('bhmptBHP'), null);
        $changes = [];
        $listing = null;
        $counter = count($args);

        // Every option is checked before any takes effect: one bad option changes nothing
        for ($i = 0; $i < $counter; $i++) {
            $arg = $args[$i];

            if ($arg === '--' || ! in_array($arg[0] ?? '', ['-', '+'], true)) {
                $positional = array_slice($args, $arg === '--' ? $i + 1 : $i);

                break;
            }

            $enable = $arg[0] === '-';

            foreach (str_split(substr($arg, 1)) as $flag) {
                // `o` takes the next argument as an option name, wherever it is in a cluster (`set -euo pipefail`)
                if ($flag === 'o' && ! isset($args[$i + 1])) {
                    $listing = $enable;
                } elseif ($flag === 'o' && array_key_exists($args[++$i], self::SET_OPTIONS)) {
                    $changes[$args[$i]] = $enable;
                } elseif ($flag === 'o') {
                    return new ExecResult(stderr: "bash: set: {$args[$i]}: invalid option name\n", exitCode: 2);
                } elseif (! array_key_exists($flag, $letters)) {
                    return new ExecResult(stderr: "bash: set: -{$flag}: invalid option\nset: usage: set [-abefhkmnptuvxBCEHPT] [-o option-name] [--] [-] [arg ...]\n", exitCode: 2);
                } elseif ($letters[$flag] !== null) {
                    $changes[$letters[$flag]] = $enable;
                }
            }
        }

        $this->interpreterState->shellOpts = $changes + $this->interpreterState->shellOpts;
        $this->interpreterState->positionalParams = $positional ?? $this->interpreterState->positionalParams;

        // Without a name, `set -o` lists the options and `set +o` prints them as commands, like shopt -o/-po
        return $listing === null ? new ExecResult(exitCode: 0) : $this->builtinShopt([$listing ? '-o' : '-po']);
    }

    /** `set -o` options in bash's order, with its defaults for a non-interactive shell */
    private const array SET_OPTIONS = [
        'allexport' => false, 'braceexpand' => true, 'emacs' => false, 'errexit' => false, 'errtrace' => false,
        'functrace' => false, 'hashall' => true, 'histexpand' => false, 'history' => false, 'ignoreeof' => false,
        'interactive-comments' => true, 'keyword' => false, 'monitor' => false, 'noclobber' => false, 'noexec' => false,
        'noglob' => false, 'nolog' => false, 'notify' => false, 'nounset' => false, 'onecmd' => false, 'physical' => false,
        'pipefail' => false, 'posix' => false, 'privileged' => false, 'verbose' => false, 'vi' => false, 'xtrace' => false,
    ];

    /** @param array<int, string> $args */
    private function builtinShopt(array $args): ExecResult
    {
        $flags = '';

        while (str_starts_with($args[0] ?? '', '-')) {
            $arg = array_shift($args);

            if ($arg === '--') {
                break;
            }

            $flags .= substr($arg, 1);
        }

        $valid = strspn($flags, 'pqsuo');

        if ($valid !== strlen($flags)) {
            return new ExecResult(stderr: "bash: shopt: -{$flags[$valid]}: invalid option\nshopt: usage: shopt [-pqsu] [-o] [optname ...]\n", exitCode: 2);
        }

        [$set, $unset, $setOptions] = [str_contains($flags, 's'), str_contains($flags, 'u'), str_contains($flags, 'o')];

        if ($set && $unset) {
            return new ExecResult(stderr: "bash: shopt: cannot set and unset shell options simultaneously\n", exitCode: 1);
        }

        $options = $setOptions
            ? array_merge(self::SET_OPTIONS, array_intersect_key($this->interpreterState->shellOpts, self::SET_OPTIONS))
            : $this->interpreterState->shopt;
        $format = fn (string $name, bool $on, int $width): string => str_contains($flags, 'p')
            ? ($setOptions ? 'set '.($on ? '-' : '+').'o ' : 'shopt -'.($on ? 's' : 'u').' ').$name."\n"
            : sprintf("%-{$width}s\t%s\n", $name, $on ? 'on' : 'off');
        $stdout = '';

        if ($args === []) {
            // Every option, or with -s/-u the ones that are on/off
            foreach ($options as $name => $on) {
                if (! $set && ! $unset || $on === $set) {
                    $stdout .= $format($name, $on, $setOptions ? 15 : 20);
                }
            }

            return new ExecResult(str_contains($flags, 'q') ? '' : $stdout);
        }

        $stderr = '';
        $status = 0;

        foreach ($args as $arg) {
            if (! array_key_exists($arg, $options)) {
                $stderr .= 'bash: shopt: '.$arg.($setOptions ? ': invalid option name' : ': invalid shell option name')."\n";
                // bash quirk: a bad name for -so/-uo still succeeds
                $status = $setOptions && ($set || $unset) ? $status : 1;
            } elseif ($setOptions && ($set || $unset)) {
                $this->interpreterState->shellOpts[$arg] = $set;
            } elseif ($set || $unset) {
                $this->interpreterState->shopt[$arg] = $set;
            } else {
                // Querying names fails when any of them is off
                $stdout .= $format($arg, $options[$arg], 20);
                $status = $options[$arg] ? $status : 1;
            }
        }

        return new ExecResult(str_contains($flags, 'q') ? '' : $stdout, $stderr, $status);
    }

    /** @param array<int, string> $args */
    private function builtinCd(array $args, string $builtin = 'cd'): ExecResult
    {
        $arg = $args[0] ?? null;
        $dir = match ($arg) {
            null => $this->interpreterState->getVar('HOME'),
            '-' => $this->interpreterState->getVar('OLDPWD'),
            default => $arg,
        };

        if ($dir === null) {
            return new ExecResult(stderr: 'bash: cd: '.($arg === null ? 'HOME' : 'OLDPWD')." not set\n", exitCode: 1);
        }

        $target = $this->fileSystem->resolvePath($this->interpreterState->cwd, $dir);

        try {
            $stat = $this->fileSystem->stat($target);
        } catch (RuntimeException $runtimeException) {
            return new ExecResult(stderr: sprintf("bash: %s: %s: %s\n", $builtin, $dir, $this->strerror($runtimeException)), exitCode: 1);
        }

        if (! $stat->isDirectory) {
            return new ExecResult(stderr: "bash: {$builtin}: {$dir}: Not a directory\n", exitCode: 1);
        }

        $this->interpreterState->setVar('OLDPWD', $this->interpreterState->cwd);
        $this->interpreterState->cwd = $target;
        $this->interpreterState->setVar('PWD', $target);
        // bash exports both from startup
        $this->interpreterState->exported += ['PWD' => true, 'OLDPWD' => true];

        // `cd -` reports where it landed
        return new ExecResult(stdout: $arg === '-' ? $target."\n" : '');
    }

    /** @param array<int, string> $args */
    private function builtinSource(array $args, StdinStream $stdinStream): ExecResult
    {
        if ($args === []) {
            return $this->routeOutput(new ExecResult(stderr: "bash: source: filename argument required\nsource: usage: source [-p path] filename [arguments]\n", exitCode: 2), $this->fds);
        }

        try {
            $content = $this->fileSystem->readFile($this->fileSystem->resolvePath($this->interpreterState->cwd, $args[0]));
        } catch (RuntimeException) {
            $error = $this->isDirectory($args[0]) ? "bash: source: {$args[0]}: is a directory\n" : "bash: {$args[0]}: No such file or directory\n";

            return $this->routeOutput(new ExecResult(stderr: $error, exitCode: 1), $this->fds);
        }

        // Extra operands become $1.. for the duration of the file
        $savedParams = count($args) > 1 ? $this->interpreterState->positionalParams : null;

        if ($savedParams !== null) {
            $this->interpreterState->positionalParams = array_slice($args, 1);
        }

        $this->sourceDepth++;
        $this->interpreterState->pushFrame('source', $args[0]);

        try {
            return $this->captureInShell($content, $stdinStream, errorPrefix: $args[0].': ');
        } catch (ReturnException $returnException) {
            return new ExecResult(exitCode: $returnException->exitCode);
        } finally {
            $this->sourceDepth--;
            $this->interpreterState->popFrame();

            if ($savedParams !== null) {
                $this->interpreterState->positionalParams = $savedParams;
            }
        }
    }

    /** @param array<int, string> $args */
    private function builtinDeclare(array $args, string $builtin = 'declare'): ExecResult
    {
        [$flags, $off, $vars] = $this->declarationOptions($args);
        $state = $this->interpreterState;

        if (strpbrk($flags, 'fF') !== false) {
            return $this->declareFunctions($vars, str_contains($flags, 'F'));
        }

        if ($vars === [] && $builtin === 'local') {
            $locals = array_keys($state->localScopes[count($state->localScopes) - 1]);
            sort($locals);

            return $this->printDeclarations($locals);
        }

        if ($vars === []) {
            return $this->listDeclarations($flags);
        }

        if (str_contains($flags, 'p')) {
            return $this->printDeclarations($vars);
        }

        $global = str_contains($flags, 'g');
        // Inside a function declare makes locals unless -g
        $scoped = $state->localScopes !== [] && ! $global;
        $status = 0;

        foreach ($vars as $var) {
            [$name, $value] = $this->splitAssignment($var);

            if (! $this->validIdentifier($builtin, $var, $name)) {
                $status = 1;

                continue;
            }

            // Without -n or +n, declare acts on the variable a nameref points to
            $target = str_contains($flags.$off, 'n') ? $name : $state->resolve($name) ?? $name;
            $base = explode('[', $target)[0];

            // Making a readonly variable local is refused even without a value
            if (($value !== null || $scoped) && $state->isReadonly($base)) {
                return new ExecResult(stderr: "bash: {$builtin}: {$base}: readonly variable\n", exitCode: 1);
            }

            if ($scoped && $target === $name) {
                $state->declareLocal($base);
            }

            if (! $this->declareType($builtin, $base, $flags, $off) || ! $this->declareReference($builtin, $base, $flags, $off, $value, $scoped)) {
                $status = 1;

                continue;
            }

            if (isset($this->arrayOperands[$name])) {
                $this->applyAssignment(['name' => $target] + $this->arrayOperands[$name]);
            } elseif ($value !== null && ! str_contains($flags, 'n') && $global) {
                $state->setGlobal($target, $value);
            } elseif ($value !== null && ! str_contains($flags, 'n')) {
                $this->readAssign($target, $value);
            }

            $this->setAttributes($base, $flags, $off);
        }

        return new ExecResult(exitCode: $status);
    }

    /** declare's -a, -A, -i, -l and -u, and +i, +l and +u; false after reporting why they can't apply */
    private function declareType(string $builtin, string $name, string $flags, string $off): bool
    {
        $state = $this->interpreterState;
        $assoc = str_contains($flags, 'A');

        if (($assoc && isset($state->arrays[$name]) && ! $state->hasAttribute($name, 'A')) || (! $assoc && str_contains($flags, 'a') && $state->hasAttribute($name, 'A'))) {
            $error = sprintf('%s: cannot convert %s', $name, $assoc ? 'indexed to associative array' : 'associative to indexed array');

            // With a `name=(...)` operand it's an assignment error
            if (isset($this->arrayOperands[$name])) {
                throw new AssignmentException('bash: '.$error);
            }

            $this->writeStderr("bash: {$builtin}: {$error}\n");

            return false;
        }

        // A scalar becomes element 0
        if (strpbrk($flags, 'aA') !== false && isset($state->env[$name])) {
            $state->arrays[$name] ??= [$state->env[$name]];
            unset($state->env[$name]);
        }

        if (strpbrk($flags, 'aA') !== false) {
            $state->setAttribute($name, $assoc ? 'A' : 'a');
        }

        foreach (str_split('ilu') as $letter) {
            if (str_contains($off, $letter)) {
                $state->setAttribute($name, $letter, false);
            }
        }

        if (str_contains($flags, 'i')) {
            $state->setAttribute($name, 'i');
        }

        // -l and -u each turn the other off, so together they turn both off
        if (strpbrk($flags, 'lu') !== false) {
            $state->setAttribute($name, 'l', ! str_contains($flags, 'u'));
            $state->setAttribute($name, 'u', ! str_contains($flags, 'l'));
        }

        return true;
    }

    /** declare -n (whose value is the name referred to) and +n; false after reporting why they can't apply */
    private function declareReference(string $builtin, string $name, string $flags, string $off, ?string $value, bool $scoped): bool
    {
        $state = $this->interpreterState;

        if (str_contains($off, 'n')) {
            $state->setAttribute($name, 'n', false);
        }

        if (! str_contains($flags, 'n')) {
            return true;
        }

        $reference = $value ?? $state->env[$name] ?? null;

        // Without a value it's the variable's own value that must be a name
        if ($reference !== null && preg_match('/^[a-zA-Z_]\w*(\[.+\])?$/s', $reference) !== 1) {
            $this->writeStderr("bash: {$builtin}: `{$reference}': invalid variable name for name reference\n");

            return false;
        }

        // In a function the name may mean the global of that name, so bash only warns
        if ($reference === $name && ! $scoped) {
            $this->writeStderr("bash: {$builtin}: {$name}: nameref variable self references not allowed\n");

            return false;
        }

        if ($reference === $name) {
            $this->writeStderr("bash: {$builtin}: warning: {$name}: circular name reference\nbash: warning: {$name}: circular name reference\n");
        }

        $state->setAttribute($name, 'n');

        if ($value !== null) {
            $state->setReference($name, $value);
        }

        return true;
    }

    /** declare's -r and -x/+x on a variable */
    private function setAttributes(string $name, string $flags, string $off): void
    {
        if (str_contains($flags, 'r')) {
            $this->interpreterState->markReadonly($name);
        }

        if (str_contains($flags, 'x')) {
            $this->interpreterState->exported[$name] = true;
        } elseif (str_contains($off, 'x')) {
            unset($this->interpreterState->exported[$name]);
        }
    }

    /** declare without names: the variables with every attribute named (`declare -ai`), `declare -p` all of them, plain `declare` like set */
    private function listDeclarations(string $flags): ExecResult
    {
        $state = $this->interpreterState;
        $letters = str_split((string) preg_replace('/[^aAilnrux]/', '', $flags));

        if ($letters === [] && ! str_contains($flags, 'p')) {
            return $this->builtinSet([]);
        }

        $names = array_keys($state->env + $state->arrays + $state->attributes + $state->exported + $state->readonlyVars);
        sort($names);

        return $this->printDeclarations(array_values(array_filter($names, fn (string $name): bool => array_all($letters, fn (string $letter): bool => str_contains($this->attributeLetters($name), $letter)))));
    }

    /** A variable's attributes in the order `declare -p` prints them */
    private function attributeLetters(string $name): string
    {
        $state = $this->interpreterState;
        $letters = $state->isArray($name) ? ($state->hasAttribute($name, 'A') ? 'A' : 'a') : '';
        $letters .= ($state->hasAttribute($name, 'i') ? 'i' : '').($state->hasAttribute($name, 'n') ? 'n' : '');
        $letters .= ($state->isReadonly($name) ? 'r' : '').(isset($state->exported[$name]) ? 'x' : '');

        return $letters.($state->hasAttribute($name, 'l') ? 'l' : '').($state->hasAttribute($name, 'u') ? 'u' : '');
    }

    /**
     * The leading option words of declare/export/readonly: letters set with -, letters cleared with +, and the operands.
     *
     * @param  array<int, string>  $args
     * @return array{string, string, list<string>}
     */
    private function declarationOptions(array $args): array
    {
        $args = array_values($args);
        $on = '';
        $off = '';

        while (preg_match('/^([-+])(.+)$/s', $args[0] ?? '', $m) === 1) {
            array_shift($args);

            if ($m[0] === '--') {
                break;
            }

            $m[1] === '-' ? $on .= $m[2] : $off .= $m[2];
        }

        return [$on, $off, $args];
    }

    /** A declaration operand's name must be an identifier, or one with a subscript; anything else is reported. */
    private function validIdentifier(string $builtin, string $operand, string $name): bool
    {
        if (preg_match('/^[a-zA-Z_]\w*(\[.+\])?$/s', $name) === 1) {
            return true;
        }

        $this->writeStderr("bash: {$builtin}: `{$operand}': not a valid identifier\n");

        return false;
    }

    /** @param list<string> $names */
    private function printDeclarations(array $names): ExecResult
    {
        $state = $this->interpreterState;
        $stdout = '';
        $stderr = '';
        $quote = $this->declareQuote(...);

        foreach ($names as $name) {
            $array = $state->arrays[$name] ?? null;
            $flags = $this->attributeLetters($name);
            $declaration = 'declare -'.($flags === '' ? '-' : $flags).' '.$name;
            // A nameref shows the name it holds, not the value it leads to
            $value = $state->hasAttribute($name, 'n') ? $state->env[$name] ?? null : $state->getVar($name);

            if ($array !== null) {
                // A key with shell metacharacters is quoted too
                $key = fn (int|string $key): string => preg_match('/[\s\x00-\x1f\x7f\'"\\\\|&;()<>!{}*?\[\]^$`]|^[~#]/', (string) $key) === 1 ? $quote((string) $key) : (string) $key;
                $items = array_map(fn (int|string $index, string $value): string => sprintf('[%s]=%s', $key($index), $quote($value)), array_keys($array), $array);
                // bash ends a non-empty associative array's list with a space
                $stdout .= $declaration.'=('.implode(' ', $items).($state->hasAttribute($name, 'A') && $array !== [] ? ' ' : '').")\n";
            } elseif ($value !== null) {
                $stdout .= $declaration.'='.$quote($value)."\n";
            } elseif ($flags !== '' || $state->isLocal($name)) {
                $stdout .= $declaration."\n";
            } else {
                $stderr .= "bash: declare: {$name}: not found\n";
            }
        }

        return new ExecResult($stdout, $stderr, $stderr === '' ? 0 : 1);
    }

    /** A value as declare -p quotes it: in $'...' when it holds control characters or bytes that aren't UTF-8, else in "..." */
    private function declareQuote(string $value): string
    {
        $utf8 = mb_check_encoding($value, 'UTF-8');

        if (preg_match($utf8 ? '/[\x00-\x1f\x7f]/' : '/[\x00-\x1f\x7f-\xff]/', $value) !== 1) {
            return '"'.addcslashes($value, '"\\$`').'"';
        }

        $escapes = ["\x07" => '\a', "\x08" => '\b', "\x1b" => '\E', "\f" => '\f', "\n" => '\n', "\r" => '\r', "\t" => '\t', "\v" => '\v', '\\' => '\\\\', "'" => "\\'"];

        return "$'".(string) preg_replace_callback(
            $utf8 ? '/[\x00-\x1f\x7f\\\\\']/' : '/[\x00-\x1f\x7f-\xff\\\\\']/',
            fn (array $m): string => $escapes[$m[0]] ?? sprintf('\\%03o', ord($m[0])),
            $value,
        )."'";
    }

    /** @param array<int, string> $args */
    private function builtinRead(array $args, StdinStream $stdinStream): ExecResult
    {
        $usage = "read: usage: read [-Eers] [-a array] [-d delim] [-i text] [-n nchars] [-N nchars] [-p prompt] [-t timeout] [-u fd] [name ...]\n";
        $flags = '';
        $values = [];

        // Options come first; letters may be grouped (-ra arr), and one taking a value uses the rest of the word or the next word
        while ($args !== [] && strlen($args[0]) > 1 && $args[0][0] === '-') {
            $arg = array_shift($args);

            for ($j = 1; $j < strlen($arg) && $arg !== '--'; $j++) {
                $letter = $arg[$j];

                if (str_contains('Eers', $letter)) {
                    $flags .= $letter;

                    continue;
                }

                if (! str_contains('adinNptu', $letter)) {
                    return new ExecResult(stderr: "bash: read: -{$letter}: invalid option\n".$usage, exitCode: 2);
                }

                if ($j + 1 === strlen($arg) && $args === []) {
                    return new ExecResult(stderr: "bash: read: -{$letter}: option requires an argument\n".$usage, exitCode: 2);
                }

                $values[$letter] = $j + 1 < strlen($arg) ? substr($arg, $j + 1) : (string) array_shift($args);

                break;
            }

            if ($arg === '--') {
                break;
            }
        }

        $names = $args;
        $timeout = $values['t'] ?? '';
        $count = $values['N'] ?? $values['n'] ?? null;
        $fd = $values['u'] ?? '0';

        if (isset($values['t']) && preg_match('/^(\d+\.?\d*|\.\d+)$/', $timeout) !== 1) {
            return new ExecResult(stderr: "bash: read: {$timeout}: invalid timeout specification\n", exitCode: 1);
        }

        if ($count !== null && ! ctype_digit($count)) {
            return new ExecResult(stderr: "bash: read: {$count}: invalid number\n", exitCode: 1);
        }

        if (! ctype_digit($fd)) {
            return new ExecResult(stderr: "bash: read: {$fd}: invalid file descriptor specification\n", exitCode: 1);
        }

        $stream = (int) $fd === 0 ? $stdinStream : $this->fds[(int) $fd] ?? null;

        if ($stream === null) {
            return new ExecResult(stderr: sprintf("bash: read: %d: invalid file descriptor: Bad file descriptor\n", $fd), exitCode: 1);
        }

        if (! $stream instanceof StdinStream || ! $stream->isReadable()) {
            return new ExecResult(stderr: sprintf("bash: read: %d: read error: Bad file descriptor\n", $fd), exitCode: 1);
        }

        // Input is never a terminal: -p's prompt isn't shown, -e/-i/-s/-E change nothing, and as all
        // input is already there a timeout never expires; `-t 0` just reports input is available
        if (isset($values['t']) && (float) $timeout === 0.0) {
            return new ExecResult(exitCode: 0);
        }

        $raw = str_contains($flags, 'r');
        $delimiter = $values['d'] ?? "\n";

        if ($count !== null) {
            $line = $stream->readChars((int) $count, isset($values['N']) ? null : $delimiter, $raw, $terminated);
        } else {
            $line = $stream->readLine($delimiter, $terminated);

            // A trailing unescaped backslash continues the record onto the next one
            while (! $raw && $terminated && $line !== null && (strlen($line) - strlen(rtrim($line, '\\'))) % 2 === 1) {
                $line = substr($line, 0, -1).$stream->readLine($delimiter, $terminated);
            }
        }

        // Backslash-newline inside a record (e.g. with -d ,) is dropped entirely
        $line = $raw ? ($line ?? '') : str_replace("\\\n", '', $line ?? '');

        if (isset($values['N'])) {
            // -N takes the characters as they are: no field splitting, no trimming, all into the first name
            $value = $raw ? $line : $this->unescape($line);

            if (isset($values['a'])) {
                return $this->readIntoArray($values['a'], [$value], $terminated);
            }

            foreach ($names === [] ? ['REPLY'] : $names as $index => $name) {
                if (! $this->readAssign($name, $index === 0 ? $value : '')) {
                    return new ExecResult(exitCode: 1);
                }
            }

            return new ExecResult(exitCode: $terminated ? 0 : 1);
        }

        $ifs = $this->interpreterState->getVar('IFS') ?? " \t\n";
        $fields = $this->readFields($line, $ifs, $raw);

        if (isset($values['a'])) {
            return $this->readIntoArray($values['a'], array_column($fields, 0), $terminated);
        }

        if ($names === []) {
            $this->interpreterState->setVar('REPLY', $raw ? $line : $this->unescape($line));
        }

        foreach ($names as $index => $name) {
            $value = $fields[$index][0] ?? '';

            // The last name takes the rest of the line, original separators included
            if ($index === count($names) - 1 && isset($fields[$index])) {
                // Only IFS whitespace is trimmed: `IFS= read x` keeps trailing blanks
                $rest = rtrim(substr($line, $fields[$index][1]), $this->ifsWhitespace($ifs));
                $value = $raw ? $rest : $this->unescape($rest);
            }

            // Names are checked as they're assigned: the ones before a bad name keep their fields
            if (! $this->readAssign($name, $value)) {
                return new ExecResult(exitCode: 1);
            }
        }

        // An unterminated last record still assigns, but read reports failure
        return new ExecResult(exitCode: $terminated ? 0 : 1);
    }

    /** A read target is a name or an array element (`a[1]`); anything else is reported. */
    private function readAssign(string $name, string $value): bool
    {
        if (preg_match('/^([a-zA-Z_]\w*)(?:\[(.+)\])?$/s', $name, $m) !== 1) {
            $this->writeStderr("bash: read: `{$name}': not a valid identifier\n");

            return false;
        }

        $this->applyAssignment(isset($m[2])
            ? ['type' => 'element', 'name' => $m[1], 'subscript' => $this->expandSubscript($m[2]), 'append' => false, 'value' => $value]
            : ['type' => 'scalar', 'name' => $name, 'append' => false, 'value' => $value]);

        return true;
    }

    /** @param list<string> $items */
    private function readIntoArray(string $name, array $items, bool $terminated): ExecResult
    {
        if (preg_match('/^[a-zA-Z_]\w*$/', $name) !== 1) {
            return new ExecResult(stderr: "bash: read: `{$name}': not a valid identifier\n", exitCode: 1);
        }

        $this->interpreterState->setArray($name, $items);

        return new ExecResult(exitCode: $terminated ? 0 : 1);
    }

    private function unescape(string $text): string
    {
        return preg_replace('/\\\\(.)/s', '$1', $text) ?? $text;
    }

    /**
     * Splits a record the way read does: leading IFS whitespace is skipped, runs of IFS whitespace
     * separate, each other IFS char separates exactly once, and a backslash escapes (unless raw).
     *
     * @return list<array{string, int}> each field with the offset where it starts
     */
    private function readFields(string $line, string $ifs, bool $raw): array
    {
        $whitespace = $this->ifsWhitespace($ifs);
        $len = strlen($line);
        $fields = [];
        $i = strspn($line, $whitespace);

        while ($i < $len) {
            $start = $i;
            $field = '';

            while ($i < $len && ! str_contains($ifs, $line[$i])) {
                if (! $raw && $line[$i] === '\\' && $i + 1 < $len) {
                    $i++;
                }

                $field .= $line[$i++];
            }

            $fields[] = [$field, $start];
            $i += strspn($line, $whitespace, $i);

            if ($i < $len && str_contains($ifs, $line[$i])) {
                $i++;
                $i += strspn($line, $whitespace, $i);
            }
        }

        return $fields;
    }

    private function ifsWhitespace(string $ifs): string
    {
        return implode('', array_intersect([' ', "\t", "\n"], str_split($ifs)));
    }

    /** @param array<int, string> $args */
    private function builtinBreak(array $args): ExecResult
    {
        if ($this->loopDepth === 0) {
            return new ExecResult(stderr: "bash: break: only meaningful in a `for', `while', or `until' loop\n");
        }

        throw new BreakException(max(1, (int) ($args[0] ?? 1)));
    }

    /** @param array<int, string> $args */
    private function builtinContinue(array $args): ExecResult
    {
        if ($this->loopDepth === 0) {
            return new ExecResult(stderr: "bash: continue: only meaningful in a `for', `while', or `until' loop\n");
        }

        throw new ContinueException(max(1, (int) ($args[0] ?? 1)));
    }

    /** @param array<int, string> $args */
    private function builtinReturn(array $args): ExecResult
    {
        if ($this->interpreterState->callDepth === 0 && $this->sourceDepth === 0) {
            return new ExecResult(stderr: "bash: return: can only `return' from a function or sourced script\n", exitCode: 2);
        }

        throw new ReturnException($this->parseStatusArg('return', $args));
    }

    /** @param array<int, string> $args */
    private function builtinShift(array $args): ExecResult
    {
        $n = (int) ($args[0] ?? 1);

        if ($n > count($this->interpreterState->positionalParams)) {
            return new ExecResult(exitCode: 1);
        }

        $this->interpreterState->positionalParams = array_slice($this->interpreterState->positionalParams, $n);

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinLet(array $args): ExecResult
    {
        if ($args === []) {
            return new ExecResult(stderr: "bash: let: expression expected\n", exitCode: 1);
        }

        $lastResult = 0;

        try {
            foreach ($args as $arg) {
                $lastResult = $this->evaluateArithmeticString($arg);
            }
        } catch (ArithmeticException $arithmeticException) {
            // Like ((...)), a failing let is just a failed command
            return new ExecResult(stderr: sprintf("bash: let: %s\n", $arithmeticException->getMessage()), exitCode: 1);
        }

        return new ExecResult(exitCode: $lastResult === 0 ? 1 : 0);
    }

    /** @param array<int, string> $args */
    private function builtinGetopts(array $args): ExecResult
    {
        if (count($args) < 2) {
            return new ExecResult(stderr: "getopts: usage: getopts optstring name [arg ...]\n", exitCode: 2);
        }

        [$optstring, $name] = $args;
        $params = count($args) > 2 ? array_slice($args, 2) : $this->interpreterState->positionalParams;
        $optind = max(1, (int) ($this->interpreterState->getVar('OPTIND') ?? 1));
        $arg = $params[$optind - 1] ?? '';
        $this->interpreterState->unsetVar('OPTARG');

        // A script that resets OPTIND starts over at the first letter of the word
        if ($this->getoptsCharIndex >= strlen($arg)) {
            $this->getoptsCharIndex = 1;
        }

        if ($arg === '--' || strlen($arg) < 2 || $arg[0] !== '-') {
            $this->interpreterState->setVar($name, '?');
            $this->interpreterState->setVar('OPTIND', (string) ($optind + (int) ($arg === '--')));

            return new ExecResult(exitCode: 1);
        }

        $letter = $arg[$this->getoptsCharIndex++];
        $rest = substr($arg, $this->getoptsCharIndex);

        if ($rest === '') {
            $optind++;
            $this->getoptsCharIndex = 1;
        }

        $silent = str_starts_with($optstring, ':');
        $pos = $letter === ':' ? false : strpos($optstring, $letter);
        $result = $letter;
        $error = null;

        if ($pos === false) {
            $result = '?';
            $error = 'illegal option';
        } elseif (($optstring[$pos + 1] ?? '') === ':') {
            if ($rest !== '') {
                // -bvalue: the rest of the word is the argument
                $this->interpreterState->setVar('OPTARG', $rest);
                $optind++;
                $this->getoptsCharIndex = 1;
            } elseif (isset($params[$optind - 1])) {
                $this->interpreterState->setVar('OPTARG', $params[$optind - 1]);
                $optind++;
            } else {
                $result = $silent ? ':' : '?';
                $error = 'option requires an argument';
            }
        }

        // A leading ':' in optstring silences errors and reports the letter in OPTARG instead
        if ($error !== null && $silent) {
            $this->interpreterState->setVar('OPTARG', $letter);
        }

        $this->interpreterState->setVar($name, $result);
        $this->interpreterState->setVar('OPTIND', (string) $optind);

        return new ExecResult(stderr: $error !== null && ! $silent ? sprintf("bash: %s -- %s\n", $error, $letter) : '');
    }

    /** @param array<int, string> $args */
    private function builtinMapfile(array $args, StdinStream $stdinStream): ExecResult
    {
        $names = [];
        $strip = false;
        $delimiter = "\n";
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            if ($args[$i] === '-t') {
                $strip = true;
            } elseif ($args[$i] === '-d') {
                $delimiter = $args[++$i] ?? "\n";
            } elseif (! str_starts_with($args[$i], '-')) {
                $names[] = $args[$i];
            }
        }

        // Each element keeps its delimiter unless -t; `-d ''` splits on NUL
        $lines = [];

        while (($line = $stdinStream->readLine($delimiter, $terminated)) !== null) {
            $lines[] = $strip || ! $terminated ? $line : $line.($delimiter === '' ? "\0" : $delimiter);
        }

        $this->interpreterState->setArray($names[0] ?? 'MAPFILE', $lines);

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinType(array $args): ExecResult
    {
        $terse = in_array('-t', $args, true);
        $stdout = '';
        $stderr = '';
        $status = 0;

        foreach (array_filter($args, fn (string $arg): bool => ! str_starts_with($arg, '-')) as $name) {
            $kind = $this->commandKind($name);

            if ($kind === null) {
                $stderr .= $terse ? '' : "bash: type: {$name}: not found\n";
                $status = 1;

                continue;
            }

            $stdout .= $terse ? $kind."\n" : match ($kind) {
                'function' => $name." is a function\n".$this->functionSource($name),
                'builtin' => $name." is a shell builtin\n",
                'file' => sprintf("%s is /usr/bin/%s\n", $name, $name),
            };
        }

        return new ExecResult($stdout, $stderr, $status);
    }

    /** A function's definition as bash prints it */
    private function functionSource(string $name): string
    {
        return FunctionPrinter::print($name, $this->interpreterState->functions[$name]['body'])."\n";
    }

    /**
     * declare -f prints definitions and -F just names (as `declare -f name` when listing them all);
     * with names, a missing one fails quietly.
     *
     * @param  list<string>  $names
     */
    private function declareFunctions(array $names, bool $namesOnly): ExecResult
    {
        $functions = $this->interpreterState->functions;
        ksort($functions);
        $stdout = '';

        foreach ($names === [] ? array_keys($functions) : $names as $name) {
            if (isset($functions[$name])) {
                $stdout .= match (true) {
                    ! $namesOnly => $this->functionSource($name),
                    $names === [] => sprintf("declare -f %s\n", $name),
                    default => $name."\n",
                };
            }
        }

        return new ExecResult($stdout, exitCode: array_diff($names, array_keys($functions)) !== [] ? 1 : 0);
    }

    /**
     * How bash would resolve a command name: function, then builtin, then a file on PATH.
     *
     * @return 'function'|'builtin'|'file'|null
     */
    private function commandKind(string $name): ?string
    {
        return match (true) {
            isset($this->interpreterState->functions[$name]) => 'function',
            $this->isBuiltin($name) || in_array($name, self::REGISTRY_BUILTINS, true) => 'builtin',
            $this->commandRegistry->has($name) => 'file',
            default => null,
        };
    }

    /** @param array<int, string> $args */
    private function builtinCommand(array $args, StdinStream $stdinStream): ExecResult
    {
        if ($args === []) {
            return new ExecResult(exitCode: 0);
        }

        if ($args[0] === '-v') {
            $stdout = '';

            foreach (array_slice($args, 1) as $name) {
                $kind = $this->commandKind($name);

                if ($kind !== null) {
                    $stdout .= ($kind === 'file' ? '/usr/bin/'.$name : $name)."\n";
                }
            }

            // Unlike most builtins, `command -v` doesn't report a failed write
            return $this->routeOutput(new ExecResult(stdout: $stdout, exitCode: $stdout === '' ? 1 : 0), $this->fds);
        }

        // Runs a builtin or command, skipping shell functions
        return $this->tryBuiltin($args[0], array_slice($args, 1), $stdinStream) ?? $this->runCommand($args[0], array_slice($args, 1), $stdinStream);
    }

    /** @param array<int, string> $args */
    private function builtinAlias(array $args): ExecResult
    {
        $stdout = '';
        $stderr = '';

        foreach ($args === [] ? array_keys($this->interpreterState->aliases) : $args as $arg) {
            [$name, $value] = $this->splitAssignment($arg);

            if ($value !== null) {
                $this->interpreterState->aliases[$name] = $value;
            } elseif (isset($this->interpreterState->aliases[$name])) {
                $stdout .= sprintf('alias %s=', $name).$this->singleQuote($this->interpreterState->aliases[$name])."\n";
            } else {
                $stderr .= "bash: alias: {$name}: not found\n";
            }
        }

        return new ExecResult($stdout, $stderr, $stderr === '' ? 0 : 1);
    }

    /**
     * `name=value` operand of declare/alias/readonly; the value is null when there's no `=`.
     *
     * @return array{string, ?string}
     */
    private function splitAssignment(string $word): array
    {
        $parts = explode('=', $word, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    private function singleQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /** @param array<int, string> $args */
    private function builtinUnalias(array $args): ExecResult
    {
        $stderr = '';

        foreach ($args as $arg) {
            if ($arg === '-a') {
                $this->interpreterState->aliases = [];
            } elseif (isset($this->interpreterState->aliases[$arg])) {
                unset($this->interpreterState->aliases[$arg]);
            } else {
                $stderr .= "bash: unalias: {$arg}: not found\n";
            }
        }

        return new ExecResult(stderr: $stderr, exitCode: $stderr === '' ? 0 : 1);
    }

    /** @param array<int, string> $args */
    private function builtinReadonly(array $args): ExecResult
    {
        [$flags, $off, $names] = $this->declarationOptions($args);
        $valid = strspn($flags.$off, 'aAfp');

        if ($valid < strlen($flags.$off)) {
            return new ExecResult(stderr: sprintf("bash: readonly: -%s: invalid option\nreadonly: usage: readonly [-aAf] [name[=value] ...] or readonly -p\n", ($flags.$off)[$valid]), exitCode: 2);
        }

        if ($names === []) {
            return $this->printDeclarations(array_keys($this->interpreterState->readonlyVars));
        }

        $status = 0;

        foreach ($names as $arg) {
            [$name, $value] = $this->splitAssignment($arg);

            if (! $this->validIdentifier('readonly', $arg, $name)) {
                $status = 1;

                continue;
            }

            // readonly's -A matters only for the array it assigns
            if (str_contains($flags, 'A') && isset($this->arrayOperands[$name])) {
                $this->interpreterState->setAttribute($name, 'A');
            }

            $this->assignOperand($name, $value);
            $this->setAttributes($name, 'r', '');
        }

        return new ExecResult(exitCode: $status);
    }

    /** export's and readonly's `name=value` or `name=(...)` operand */
    private function assignOperand(string $name, ?string $value): void
    {
        if (isset($this->arrayOperands[$name])) {
            $this->applyAssignment($this->arrayOperands[$name]);
        } elseif ($value !== null) {
            $this->interpreterState->setVar($name, $value);
        }
    }

    /** @param array<int, string> $args */
    private function builtinTrap(array $args): ExecResult
    {
        if ($args === []) {
            // bash lists EXIT first, then real signals, then DEBUG, ERR and RETURN
            $rank = ['EXIT' => 0, 'DEBUG' => 2, 'ERR' => 3, 'RETURN' => 4];
            $traps = $this->interpreterState->traps;
            uksort($traps, fn (string $a, string $b): int => ($rank[$a] ?? 1) <=> ($rank[$b] ?? 1));
            $output = '';

            foreach ($traps as $signal => $command) {
                $name = in_array($signal, ['EXIT', 'ERR', 'DEBUG', 'RETURN'], true) ? $signal : 'SIG'.$signal;
                $output .= 'trap -- '.$this->singleQuote($command).sprintf(" %s\n", $name);
            }

            return new ExecResult(stdout: $output);
        }

        // A lone signal (`trap EXIT`) resets it, the same as `trap - EXIT`
        $command = count($args) === 1 ? '-' : array_shift($args);

        foreach ($args as $arg) {
            $arg = strtoupper((string) preg_replace('/^SIG/i', '', $arg));
            $arg = $arg === '0' ? 'EXIT' : $arg;

            if ($command === '-') {
                unset($this->interpreterState->traps[$arg]);
            } else {
                $this->interpreterState->traps[$arg] = $command;
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param list<string> $args */
    private function builtinBuiltin(array $args, StdinStream $stdinStream): ExecResult
    {
        if ($args === []) {
            return new ExecResult(exitCode: 0);
        }

        $name = array_shift($args);

        return $this->tryBuiltin($name, $args, $stdinStream)
            ?? (in_array($name, self::REGISTRY_BUILTINS, true) && ! isset($this->interpreterState->disabledBuiltins[$name])
                ? $this->runCommand($name, $args, $stdinStream)
                : $this->routeOutput(new ExecResult(stderr: "bash: builtin: {$name}: not a shell builtin\n", exitCode: 1), $this->fds));
    }

    /**
     * exec replaces the shell with a command (never a function or builtin), so the script ends with its status.
     * The command runs with exec's redirections; `exec` with only redirections is handled by executeSimpleCommand().
     *
     * @param  list<string>  $args
     */
    private function builtinExec(array $args, StdinStream $stdinStream): ExecResult
    {
        if ($args === []) {
            return new ExecResult(exitCode: 0);
        }

        $name = array_shift($args);
        $result = $this->commandRegistry->has($name)
            ? $this->runCommand($name, $args, $stdinStream)
            : $this->routeOutput(new ExecResult(stderr: "bash: exec: {$name}: not found\n", exitCode: 127), $this->fds);

        $this->writeStdout($result->stdout);
        $this->appendStderr($result->stderr);

        throw new ExitException($result->exitCode);
    }

    /** @param array<int, string> $args */
    private function builtinPushd(array $args): ExecResult
    {
        $stack = &$this->interpreterState->directoryStack;

        if ($args === []) {
            // No operand swaps the top two directories
            if ($stack === []) {
                return new ExecResult(stderr: "bash: pushd: no other directory\n", exitCode: 1);
            }

            $result = $this->builtinCd([array_pop($stack)], 'pushd');
        } else {
            $result = $this->builtinCd([$args[0]], 'pushd');
        }

        if ($result->exitCode === 0) {
            $stack[] = (string) $this->interpreterState->getVar('OLDPWD');
        }

        return $result->exitCode === 0 ? $this->formatDirStack() : $result;
    }

    private function builtinPopd(): ExecResult
    {
        if ($this->interpreterState->directoryStack === []) {
            return new ExecResult(stderr: "bash: popd: directory stack empty\n", exitCode: 1);
        }

        $this->builtinCd([array_pop($this->interpreterState->directoryStack)], 'popd');

        return $this->formatDirStack();
    }

    /** The stack as pushd/popd print it: current directory first, $HOME shown as ~ */
    private function formatDirStack(): ExecResult
    {
        $home = $this->interpreterState->getVar('HOME') ?? '';
        $dirs = [$this->interpreterState->cwd, ...array_reverse($this->interpreterState->directoryStack)];
        $dirs = array_map(fn (string $dir): string => $home !== '' && ($dir === $home || str_starts_with($dir, $home.'/')) ? '~'.substr($dir, strlen($home)) : $dir, $dirs);

        return new ExecResult(stdout: implode(' ', $dirs)."\n");
    }

    /** @param array<int, string> $args */
    private function builtinDirs(array $args): ExecResult
    {
        if (in_array('-c', $args, true)) {
            $this->interpreterState->directoryStack = [];

            return new ExecResult(exitCode: 0);
        }

        $perLine = in_array('-p', $args, true) || in_array('-v', $args, true);

        $dirs = [$this->interpreterState->cwd, ...array_reverse($this->interpreterState->directoryStack)];

        if ($perLine) {
            $output = '';

            foreach ($dirs as $i => $d) {
                $output .= (in_array('-v', $args, true) ? sprintf(' %s  ', $i) : '').$d."\n";
            }

            return new ExecResult(stdout: $output, exitCode: 0);
        }

        return new ExecResult(stdout: implode(' ', $dirs)."\n", exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinCaller(array $args): ExecResult
    {
        $arg = $args[0] ?? null;

        if ($arg !== null && preg_match('/^\+?\d+$/', $arg) !== 1) {
            $problem = str_starts_with($arg, '-') ? 'invalid option' : 'invalid number';

            return new ExecResult(stderr: "bash: caller: {$arg}: {$problem}\ncaller: usage: caller [expr]\n", exitCode: 2);
        }

        // Frame N's call line, with the name and file of whatever made that call (frame N+1); like bash -c,
        // the main script isn't a frame of its own, so a call made from it has no caller name
        $frames = array_reverse($this->interpreterState->callStack);
        $n = (int) $arg;
        $line = $frames[$n]['line'] ?? null;

        if ($line === null || ($arg !== null && ! isset($frames[$n + 1]))) {
            return new ExecResult(exitCode: 1);
        }

        return new ExecResult(stdout: $arg === null
            ? $line.' '.($frames[1]['file'] ?? 'NULL')."\n"
            : sprintf("%d %s %s\n", $line, $frames[$n + 1]['function'], $frames[$n + 1]['file']));
    }

    /** @param array<int, string> $args */
    private function builtinHelp(array $args): ExecResult
    {
        $flags = '';

        while (str_starts_with($args[0] ?? '', '-') && strlen($args[0]) > 1 && ($arg = array_shift($args)) !== '--') {
            foreach (str_split(substr($arg, 1)) as $letter) {
                if (! str_contains('dms', $letter)) {
                    return new ExecResult(stderr: "bash: help: -{$letter}: invalid option\nhelp: usage: help [-dms] [pattern ...]\n", exitCode: 2);
                }

                $flags .= $letter;
            }
        }

        // What `help name` prints in bash 5.3 for each builtin BashBox has: "name: synopsis", then the indented long text
        preg_match_all('/^(\S+?): (.*)\n((?: .*\n)*)/m', (string) file_get_contents(__DIR__.'/builtin-help.txt'), $entries, PREG_SET_ORDER);
        $topics = array_column(array_map(fn (array $entry): array => [$entry[1], $entry[2], $entry[3]], $entries), null, 0);
        $version = sprintf('GNU bash, version %s (x86_64-pc-linux-gnu)', $this->interpreterState->getSpecialVar('BASH_VERSION'));

        if ($args === []) {
            return new ExecResult(stdout: $version."\n".$this->helpListing(array_column($topics, 1, 0)));
        }

        $output = preg_match('/[*?]|\[.*]/', $args[0]) === 1 ? sprintf("Shell commands matching keyword%s `%s'\n\n", count($args) > 1 ? 's' : '', implode(', ', $args)) : '';
        $found = false;

        foreach ($args as $pattern) {
            // Exact or glob matches; failing those, names the pattern is a prefix of
            $matches = array_filter($topics, fn (array $topic): bool => $topic[0] === $pattern || fnmatch($pattern, $topic[0]))
                ?: array_filter($topics, fn (array $topic): bool => str_starts_with((string) $topic[0], $pattern));

            foreach ($matches as [$name, $synopsis, $long]) {
                $summary = substr($long, 4, (int) strpos($long, "\n") - 4);
                $output .= match (true) {
                    str_contains($flags, 'd') => sprintf("%s - %s\n", $name, $summary),
                    str_contains($flags, 'm') => "NAME\n    {$name} - {$summary}\n\nSYNOPSIS\n    {$synopsis}\n\nDESCRIPTION\n{$long}\nSEE ALSO\n    bash(1)\n\n"
                        ."IMPLEMENTATION\n    {$version}\n    Copyright (C) 2025 Free Software Foundation, Inc.\n    License GPLv3+: GNU GPL version 3 or later <http://gnu.org/licenses/gpl.html>\n\n",
                    str_contains($flags, 's') => sprintf("%s: %s\n", $name, $synopsis),
                    default => sprintf("%s: %s\n%s", $name, $synopsis, $long),
                };
                $found = true;
            }
        }

        if (! $found) {
            return new ExecResult(stderr: "bash: help: no help topics match `{$pattern}'.  Try `help help' or `man -k {$pattern}' or `info {$pattern}'.\n", exitCode: 1);
        }

        return new ExecResult(stdout: $output, exitCode: 0);
    }

    /**
     * Plain `help`: the synopses in two columns of half the terminal width, cut short with `>`,
     * and `*` marking a disabled builtin.
     *
     * @param  array<string, string>  $synopses
     */
    private function helpListing(array $synopses): string
    {
        $width = intdiv((int) ($this->interpreterState->getVar('COLUMNS') ?? 80), 2);
        $width = $width <= 3 ? 40 : min($width, 128);

        $names = array_keys($synopses);
        $height = intdiv(count($names) + 1, 2);
        $cell = fn (string $name, int $max): string => (isset($this->interpreterState->disabledBuiltins[$name]) ? '*' : ' ')
            .(strlen($synopses[$name]) >= $max ? substr($synopses[$name], 0, $max).'>' : $synopses[$name]);
        $listing = "These shell commands are defined internally.  Type `help' to see this list.\n"
            ."Type `help name' to find out more about the function `name'.\n"
            ."Use `info bash' to find out more about the shell in general.\n"
            ."Use `man -k' or `info' to find out more about commands not in this list.\n\n"
            ."A star (*) next to a name means that the command is disabled.\n\n";

        for ($row = 0; $row < $height; $row++) {
            $right = $names[$row + $height] ?? null;
            $listing .= $right === null ? $cell($names[$row], $width - 3)."\n" : str_pad($cell($names[$row], $width - 3), $width).$cell($right, $width - 4)."\n";
        }

        return $listing;
    }

    /** @param array<int, string> $args */
    private function builtinEnable(array $args): ExecResult
    {
        $disable = in_array('-n', $args, true);

        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                continue;
            }

            if ($disable) {
                $this->interpreterState->disabledBuiltins[$arg] = true;
            } else {
                unset($this->interpreterState->disabledBuiltins[$arg]);
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinKill(array $args): ExecResult
    {
        if (in_array($args[0] ?? '', ['-l', '-L'], true)) {
            return $this->listSignals(array_slice($args, 1));
        }

        // The sandbox has no other processes, so every target is missing
        $stderr = '';

        foreach ($args as $arg) {
            if (! str_starts_with($arg, '-')) {
                $stderr .= "bash: kill: ({$arg}) - No such process\n";
            }
        }

        return new ExecResult(stderr: $stderr, exitCode: 1);
    }

    /**
     * `kill -l`/`-L`: bash's signal table on Linux (glibc: 32 and 33 are reserved, real-time signals
     * from 34 named relative to RTMIN/RTMAX), or each operand translated between number and name.
     *
     * @param  array<int, string>  $args
     */
    private function listSignals(array $args): ExecResult
    {
        $signals = array_combine(range(1, 31), explode(' ', 'HUP INT QUIT ILL TRAP ABRT BUS FPE KILL USR1 SEGV USR2 PIPE ALRM TERM STKFLT CHLD CONT STOP TSTP TTIN TTOU URG XCPU XFSZ VTALRM PROF WINCH IO PWR SYS'));

        foreach (range(34, 64) as $number) {
            $signals[$number] = match (true) {
                $number === 34 => 'RTMIN',
                $number < 50 => 'RTMIN+'.($number - 34),
                $number < 64 => 'RTMAX-'.(64 - $number),
                default => 'RTMAX',
            };
        }

        // Options after -l (a signal like `-9`, or `--`) are skipped, as bash's option loop consumes them
        while (str_starts_with($args[0] ?? '', '-') && strlen($args[0]) > 1 && array_shift($args) !== '--') {
        }

        if ($args === []) {
            $table = '';

            foreach (array_keys($signals) as $column => $number) {
                $table .= sprintf('%2d) SIG%s', $number, $signals[$number]).($column % 5 === 4 ? "\n" : "\t");
            }

            return new ExecResult(stdout: $table."\n");
        }

        $stdout = '';
        $stderr = '';
        $numbers = array_flip($signals) + ['EXIT' => 0, 'DEBUG' => 65, 'ERR' => 66, 'RETURN' => 67];

        foreach ($args as $arg) {
            if (preg_match('/^\s*[-+]?\d+\s*$/', $arg) === 1) {
                // An exit status above 128 names the signal that caused it
                $number = (int) $arg > 128 ? (int) $arg - 128 : (int) $arg;
                $name = $number === 0 ? 'EXIT' : $signals[$number] ?? null;
            } else {
                // Any case, and a SIG prefix is optional for real signals only (not EXIT, DEBUG, ERR, RETURN)
                $upper = strtoupper($arg);
                $unprefixed = str_starts_with($upper, 'SIG') ? substr($upper, 3) : '';
                $number = $numbers[$upper] ?? (in_array($unprefixed, $signals, true) ? $numbers[$unprefixed] : null);
                $name = $number === null ? null : (string) $number;
            }

            if ($name === null) {
                $stderr .= "bash: kill: {$arg}: invalid signal specification\n";
            } else {
                $stdout .= $name."\n";
            }
        }

        return new ExecResult($stdout, $stderr, $stderr === '' ? 0 : 1);
    }

    /** @param array<int, string> $args */
    private function builtinUlimit(array $args): ExecResult
    {
        // Queries report no limit; setting one is accepted and ignored
        foreach ($args as $arg) {
            if (! str_starts_with($arg, '-')) {
                return new ExecResult(exitCode: 0);
            }
        }

        return new ExecResult(stdout: "unlimited\n", exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinUmask(array $args): ExecResult
    {
        $symbolic = false;
        $reusable = false;

        while ($args !== [] && strlen($args[0]) > 1 && $args[0][0] === '-' && ($arg = array_shift($args)) !== '--') {
            foreach (str_split(substr($arg, 1)) as $letter) {
                if ($letter !== 'S' && $letter !== 'p') {
                    return new ExecResult(stderr: "bash: umask: -{$letter}: invalid option\numask: usage: umask [-p] [-S] [mode]\n", exitCode: 2);
                }

                $symbolic = $symbolic || $letter === 'S';
                $reusable = $reusable || $letter === 'p';
            }
        }

        $mask = (int) octdec($this->interpreterState->umask);
        $show = fn (int $mask): string => $symbolic
            ? vsprintf("u=%s,g=%s,o=%s\n", array_map(fn (int $shift): string => implode('', array_filter(['r', 'w', 'x'], fn (int $bit): bool => ($mask >> $shift & 4 >> $bit) === 0, ARRAY_FILTER_USE_KEY)), [6, 3, 0]))
            : sprintf("%04o\n", $mask);

        if ($args === []) {
            return new ExecResult(stdout: ($reusable ? 'umask '.($symbolic ? '-S ' : '') : '').$show($mask));
        }

        $mode = $args[0];

        if (ctype_digit(substr($mode, 0, 1))) {
            if (preg_match('/^[0-7]+$/', $mode) !== 1 || octdec($mode) > 07777) {
                return new ExecResult(stderr: "bash: umask: {$mode}: octal number out of range\n", exitCode: 1);
            }

            $mask = (int) octdec($mode);
        } else {
            // Symbolic modes work on the permissions the mask leaves, like chmod
            $bits = $this->applySymbolicMode($mode, ~$mask & 0777);

            if (is_string($bits)) {
                return new ExecResult(stderr: sprintf("bash: umask: %s\n", $bits), exitCode: 1);
            }

            $mask = ~$bits & 0777;
        }

        $this->interpreterState->umask = sprintf('%04o', $mask);

        // With a mode, -S still shows the new mask but -p doesn't
        return new ExecResult(stdout: $symbolic ? $show($mask) : '');
    }

    /**
     * bash's parse_symbolic_mode(): comma-separated [ugoa]*([-+=]([rwxXst]*|[ugo]))+ clauses applied to $initial.
     *
     * @return int|string the resulting permission bits, or the error message
     */
    private function applySymbolicMode(string $mode, int $initial): int|string
    {
        $bits = $initial;
        $char = fn (int $at): string => $mode[$at] ?? "\0";

        for ($i = 0; ; $i++) {
            $who = 0;

            for (; str_contains('agou', $char($i)); $i++) {
                $who |= ['a' => 0777, 'u' => 0700, 'g' => 070, 'o' => 07][$char($i)];
            }

            $who = $who === 0 ? 0777 : $who;

            do {
                $op = $char($i++);

                if (! str_contains('+-=', $op)) {
                    return sprintf("`%s': invalid symbolic mode operator", $op);
                }

                $perm = 0;

                for (; str_contains('rwxXstugo', $char($i)); $i++) {
                    $perm = match ($char($i)) {
                        // u, g and o copy that class's permissions; s and t mean nothing in a mask
                        'u', 'g', 'o' => ($initial >> ['u' => 6, 'g' => 3, 'o' => 0][$char($i)] & 7) * 0111,
                        'r' => $perm | 0444,
                        'w' => $perm | 0222,
                        'x' => $perm | 0111,
                        'X' => ($initial & 0111) === 0 ? $perm : $perm | 0111,
                        default => $perm,
                    };
                }

                $perm &= $who;
                $bits = match ($op) {
                    '+' => $bits | $perm,
                    '-' => $bits & ~$perm,
                    default => $bits & ~$who | $perm,
                };
            } while (str_contains('+-=', $char($i)));

            if ($i >= strlen($mode)) {
                return $bits;
            }

            if ($char($i) !== ',') {
                return sprintf("`%s': invalid symbolic mode character", $char($i));
            }
        }
    }

    private function isBuiltin(string $name): bool
    {
        return in_array($name, [
            'exit', 'export', 'unset', 'local', 'set', 'shopt', 'cd', 'source', '.',
            'eval', 'declare', 'typeset', 'read', 'break', 'continue', 'return',
            'shift', 'let', 'getopts', 'mapfile', 'readarray', ':', 'type', 'command',
            'alias', 'unalias', 'hash', 'readonly', 'trap', 'builtin', 'exec',
            'pushd', 'popd', 'dirs', 'caller', 'help', 'enable',
            'wait', 'disown', 'complete', 'compopt', 'jobs', 'fg', 'bg',
            'kill', 'suspend', 'logout', 'times', 'ulimit', 'umask', 'compgen',
        ], true);
    }

    // =========================================================================
    // COMPOUND COMMANDS
    // =========================================================================

    private function executeIf(IfNode $ifNode, StdinStream $stdinStream): ExecResult
    {
        foreach ($ifNode->clauses as $clause) {
            $condResult = $this->executeCondition($clause->condition, $stdinStream);

            if ($condResult === 0) {
                return $this->executeStatementListResult($clause->body, $stdinStream);
            }
        }

        if ($ifNode->elseBody !== null) {
            return $this->executeStatementListResult($ifNode->elseBody, $stdinStream);
        }

        return new ExecResult(exitCode: 0);
    }

    private function executeFor(ForNode $forNode, StdinStream $stdinStream): ExecResult
    {
        if ($forNode->words !== null) {
            $words = [];

            foreach ($forNode->words as $w) {
                $expanded = $this->expandWordList($w);
                array_push($words, ...$expanded);
            }
        } else {
            $words = $this->interpreterState->positionalParams;
        }

        $exitCode = 0;
        $iterations = 0;

        foreach ($words as $word) {
            try {
                // A nameref loop variable is pointed at each word in turn
                $this->interpreterState->hasAttribute($forNode->variable, 'n')
                    ? $this->interpreterState->setReference($forNode->variable, $word)
                    : $this->interpreterState->setVar($forNode->variable, $word);
            } catch (AssignmentException $assignmentException) {
                return new ExecResult(stderr: $assignmentException->getMessage()."\n", exitCode: 1);
            }

            if (! $this->runLoopBody($forNode->body, $stdinStream, $exitCode, $iterations)) {
                break;
            }
        }

        return new ExecResult(exitCode: $exitCode);
    }

    private function executeCStyleFor(CStyleForNode $cStyleForNode, StdinStream $stdinStream): ExecResult
    {
        if ($cStyleForNode->init instanceof \BashBox\Ast\ArithmeticExpressionNode) {
            $this->evaluateArithmeticExpression($cStyleForNode->init);
        }

        $exitCode = 0;
        $iterations = 0;

        while (! $cStyleForNode->condition instanceof \BashBox\Ast\ArithmeticExpressionNode || $this->evaluateArithmeticExpression($cStyleForNode->condition) !== 0) {
            if (! $this->runLoopBody($cStyleForNode->body, $stdinStream, $exitCode, $iterations)) {
                break;
            }

            if ($cStyleForNode->update instanceof \BashBox\Ast\ArithmeticExpressionNode) {
                $this->evaluateArithmeticExpression($cStyleForNode->update);
            }
        }

        return new ExecResult(exitCode: $exitCode);
    }

    private function executeWhile(WhileNode $whileNode, StdinStream $stdinStream): ExecResult
    {
        return $this->runConditionLoop($whileNode->condition, $whileNode->body, $stdinStream, until: false);
    }

    private function executeUntil(UntilNode $untilNode, StdinStream $stdinStream): ExecResult
    {
        return $this->runConditionLoop($untilNode->condition, $untilNode->body, $stdinStream, until: true);
    }

    /**
     * @param  list<StatementNode>  $condition
     * @param  list<StatementNode>  $body
     */
    private function runConditionLoop(array $condition, array $body, StdinStream $stdinStream, bool $until): ExecResult
    {
        $exitCode = 0;
        $iterations = 0;

        while (($this->executeCondition($condition, $stdinStream) === 0) !== $until) {
            if (! $this->runLoopBody($body, $stdinStream, $exitCode, $iterations)) {
                break;
            }
        }

        return new ExecResult(exitCode: $exitCode);
    }

    /**
     * Runs one iteration of a loop body, returning false when the loop must stop because of `break`.
     *
     * @param  list<StatementNode>  $body
     */
    private function runLoopBody(array $body, StdinStream $stdinStream, int &$exitCode, int &$iterations): bool
    {
        if (++$iterations > $this->interpreterState->limits->maxLoopIterations) {
            throw new ExecutionLimitException('Loop iteration limit exceeded');
        }

        try {
            $exitCode = $this->executeStatementList($body, $stdinStream);
        } catch (BreakException $e) {
            if ($e->levels > 1) {
                throw new BreakException($e->levels - 1);
            }

            return false;
        } catch (ContinueException $e) {
            if ($e->levels > 1) {
                throw new ContinueException($e->levels - 1);
            }
        }

        return true;
    }

    private function executeCase(CaseNode $caseNode, StdinStream $stdinStream): ExecResult
    {
        $word = $this->expandWord($caseNode->word);
        $exitCode = 0;
        $fallThrough = false;

        foreach ($caseNode->items as $item) {
            if (! $fallThrough && ! array_any($item->patterns, fn (WordNode $wordNode): bool => $this->matchPattern($word, $wordNode))) {
                continue;
            }

            $exitCode = $this->executeStatementList($item->body, $stdinStream);

            if ($item->terminator === ';;') {
                break;
            }

            // `;&` runs the next body unconditionally, `;;&` goes back to testing patterns
            $fallThrough = $item->terminator === ';&';
        }

        return new ExecResult(exitCode: $exitCode);
    }

    private function executeSubshell(SubshellNode $subshellNode, StdinStream $stdinStream): ExecResult
    {
        return $this->inSubshell(fn (): ExecResult => $this->executeStatementListResult($subshellNode->body, $stdinStream));
    }

    /**
     * Runs $run in a subshell: its state changes, exit and shell errors stay inside it.
     * A pipeline stage is one too, but bash doesn't count it in BASH_SUBSHELL.
     *
     * @param  Closure(): ExecResult  $run
     */
    private function inSubshell(Closure $run, bool $counted = true): ExecResult
    {
        $snapshot = clone $this->interpreterState;
        $savedFds = $this->fds;
        $this->enterSubshell();
        $this->interpreterState->subshellDepth -= (int) ! $counted;

        try {
            return $run();
        } catch (ExitException|ErrexitException|ReturnException $e) {
            // These end the subshell, not the shell
            return new ExecResult(exitCode: $e->exitCode);
        } catch (ExpansionException|ArithmeticException|UnboundVariableException|AssignmentException $e) {
            // So do shell errors, fatal or not, with status 1
            $this->writeStderr('bash: '.preg_replace('/^bash: /', '', $e->getMessage())."\n");

            return new ExecResult(exitCode: 1);
        } finally {
            $this->interpreterState->restore($snapshot);
            $this->fds = $savedFds;
        }
    }

    /** A subshell keeps the ERR trap only under `set -E`; the caller restores the state afterwards. */
    private function enterSubshell(): void
    {
        $this->interpreterState->subshellDepth++;

        if (! $this->interpreterState->shellOpts['errtrace']) {
            unset($this->interpreterState->traps['ERR']);
        }
    }

    private function executeGroup(GroupNode $groupNode, StdinStream $stdinStream): ExecResult
    {
        return $this->executeStatementListResult($groupNode->body, $stdinStream);
    }

    private function executeArithmeticCommand(ArithmeticCommandNode $arithmeticCommandNode): ExecResult
    {
        try {
            $result = $this->evaluateArithmeticExpression($arithmeticCommandNode->expression);
        } catch (AssignmentException $assignmentException) {
            return new ExecResult(stderr: $assignmentException->getMessage()."\n", exitCode: 1);
        } catch (ArithmeticException $arithmeticException) {
            // Unlike $((...)), a failing ((...)) is just a failed command
            return new ExecResult(stderr: sprintf("bash: ((: %s\n", $arithmeticException->getMessage()), exitCode: 1);
        }

        return new ExecResult(exitCode: $result !== 0 ? 0 : 1);
    }

    private function executeConditionalCommand(ConditionalCommandNode $conditionalCommandNode): ExecResult
    {
        try {
            return new ExecResult(exitCode: $this->evaluateConditional($conditionalCommandNode->expression) ? 0 : 1);
        } catch (RegexException $regexException) {
            return new ExecResult(stderr: 'bash: [[: '.$regexException->getMessage()."\n", exitCode: 2);
        } catch (ArithmeticException $arithmeticException) {
            return new ExecResult(stderr: 'bash: [[: '.$arithmeticException->getMessage()."\n", exitCode: 1);
        }
    }

    private function executeFunctionDef(FunctionDefNode $functionDefNode): ExecResult
    {
        $this->interpreterState->functions[$functionDefNode->name] = [
            'body' => $functionDefNode->body,
            'sourceFile' => $this->interpreterState->currentSource(),
        ];

        return new ExecResult(exitCode: 0);
    }

    /** @param list<string> $args */
    private function executeFunction(string $name, array $args, StdinStream $stdinStream): ExecResult
    {
        if ($this->interpreterState->callDepth >= $this->interpreterState->limits->maxCallDepth) {
            throw new ExecutionLimitException('Call depth limit exceeded');
        }

        $func = $this->interpreterState->functions[$name];
        $savedParams = $this->interpreterState->positionalParams;
        $this->interpreterState->positionalParams = $args;
        $this->interpreterState->pushLocalScope();
        $this->interpreterState->callDepth++;
        $this->interpreterState->pushFrame($name, $func['sourceFile']);
        // Functions inherit the RETURN trap only under `set -T` and the ERR trap only under `set -E`; otherwise
        // only one set by this body fires, and it then stays set
        $outerTraps = [];

        foreach (['RETURN' => 'functrace', 'ERR' => 'errtrace'] as $trap => $option) {
            if (isset($this->interpreterState->traps[$trap]) && ! $this->interpreterState->shellOpts[$option]) {
                $outerTraps[$trap] = $this->interpreterState->traps[$trap];
                unset($this->interpreterState->traps[$trap]);
            }
        }

        try {
            try {
                $result = $this->executeCommand($func['body'], $stdinStream);
            } catch (ReturnException $returnException) {
                $result = new ExecResult(exitCode: $returnException->exitCode);
            }

            $returnTrap = $this->interpreterState->traps['RETURN'] ?? '';

            if ($returnTrap !== '') {
                // Queue the body's output first so it still precedes the trap's if the trap exits the shell
                $savedStdout = $this->stdout;
                $savedStderr = $this->stderr;
                $this->stdout .= $result->stdout;
                $this->stderr .= $result->stderr;
                $trapResult = $this->runText($returnTrap, subshell: false, errorPrefix: 'bash: return trap: ');
                $this->stdout = $savedStdout;
                $this->stderr = $savedStderr;
                $result = new ExecResult($result->stdout.$trapResult->stdout, $result->stderr.$trapResult->stderr, $result->exitCode);
            }
        } finally {
            $this->interpreterState->callDepth--;
            $this->interpreterState->popLocalScope();
            $this->interpreterState->positionalParams = $savedParams;
            $this->interpreterState->popFrame();

            $this->interpreterState->traps += $outerTraps;
        }

        return $result;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * An if/while/until condition: its failures are tested, so they fire no ERR trap and don't exit under -e.
     *
     * @param  list<StatementNode>  $statements
     */
    private function executeCondition(array $statements, StdinStream $stdinStream): int
    {
        $this->conditionDepth++;

        try {
            return $this->executeStatementList($statements, $stdinStream);
        } finally {
            $this->conditionDepth--;
        }
    }

    /**
     * @param  list<StatementNode>  $statements
     */
    private function executeStatementList(array $statements, StdinStream $stdinStream): int
    {
        $exitCode = 0;

        foreach ($statements as $statement) {
            $exitCode = $this->executeStatement($statement, $stdinStream);
        }

        return $exitCode;
    }

    /**
     * @param  list<StatementNode>  $statements
     */
    private function executeStatementListResult(array $statements, StdinStream $stdinStream): ExecResult
    {
        // Output goes straight to the shell's buffers; executeCommand() captures it for the compound's redirections
        return new ExecResult(exitCode: $this->executeStatementList($statements, $stdinStream));
    }

    public function expandWord(WordNode $wordNode): string
    {
        return $this->wordExpander->expand($wordNode);
    }

    /**
     * @return list<string>
     */
    public function expandWordList(WordNode $wordNode): array
    {
        return $this->wordExpander->expandToList($wordNode);
    }

    /**
     * Runs $script and returns its output instead of writing it.
     * As a $subshell (`$(...)`, xargs) it works on a copy of the state, and `exit` or an expansion error ends only $script.
     * Otherwise (a trap) it runs in this shell, and `exit` ends the shell.
     */
    public function execSubcommand(string $script, bool $subshell = true, bool $substitution = false, ?StdinStream $stdin = null): ExecResult
    {
        return $this->nested(fn (): ExecResult => $this->inCaptureLevel(fn (): ExecResult => $this->runSubcommand($script, $subshell, $substitution, $stdin)));
    }

    /**
     * The `exec` callback commands get, like `sh -c`; $env replaces the child's environment.
     *
     * @param  array<string, string>|null  $env
     */
    private function runForCommand(string $script, ?array $env = null, string|StdinStream|null $stdin = null): ExecResult
    {
        $stdin = is_string($stdin) ? new StdinStream($stdin) : $stdin;

        if ($env === null) {
            return $this->runText($script, stdin: $stdin);
        }

        $saved = [$this->interpreterState->env, $this->interpreterState->exported];
        $this->interpreterState->env = $env;
        $this->interpreterState->exported = array_fill_keys(array_keys($env), true);

        try {
            return $this->runText($script, stdin: $stdin);
        } finally {
            [$this->interpreterState->env, $this->interpreterState->exported] = $saved;
        }
    }

    /** execSubcommand() for text that isn't part of the script (a trap, a command's `sh -c`): a syntax error is just status 2 */
    private function runText(string $script, bool $subshell = true, string $errorPrefix = 'bash: ', ?StdinStream $stdin = null): ExecResult
    {
        try {
            return $this->execSubcommand($script, $subshell, stdin: $stdin);
        } catch (ParseException $parseException) {
            return new ExecResult(stderr: $errorPrefix.$parseException->getMessage()."\n", exitCode: 2);
        }
    }

    private function runSubcommand(string $script, bool $subshell, bool $substitution, ?StdinStream $stdin = null): ExecResult
    {
        // $(...) counts lines on from the line it's on
        $scriptNode = $this->parse($script, $this->interpreterState->currentLine, $substitution);
        $snapshot = clone $this->interpreterState;
        $savedStdout = $this->stdout;
        $savedStderr = $this->stderr;
        $this->stdout = '';
        $this->stderr = '';

        if ($subshell) {
            $this->enterSubshell();
            // $(...) runs without -e unless inherit_errexit is on
            $this->interpreterState->shellOpts['errexit'] = $this->interpreterState->shellOpts['errexit'] && $this->interpreterState->shopt['inherit_errexit'];
        }

        try {
            try {
                $exitCode = $this->executeStatementList($scriptNode->statements, $stdin ?? new StdinStream);
            } catch (ExitException|ErrexitException $e) {
                $exitCode = $subshell ? $e->exitCode : throw $e;
            } catch (ExpansionException|ArithmeticException|UnboundVariableException|AssignmentException $e) {
                $exitCode = $subshell ? 1 : throw $e;
                $this->writeStderr('bash: '.preg_replace('/^bash: /', '', $e->getMessage())."\n");
            }
        } catch (Throwable $throwable) {
            // Unwinding past us (exit, return, break): keep what was written so far
            $this->stdout = $savedStdout.$this->stdout;
            $this->stderr = $savedStderr.$this->stderr;

            throw $throwable;
        } finally {
            if ($subshell) {
                $this->interpreterState->restore($snapshot);
            }
        }

        $execResult = new ExecResult($this->stdout, $this->stderr, $exitCode);
        $this->stdout = $savedStdout;
        $this->stderr = $savedStderr;

        return $execResult;
    }

    public function writeStdout(string $data): void
    {
        $this->stdout .= $data;
        $this->limitOutput(strlen($this->stdout) + strlen($this->stderr));
    }

    private function appendStderr(string $data): void
    {
        $this->stderr .= $data;
        $this->limitOutput(strlen($this->stdout) + strlen($this->stderr));
    }

    private function limitOutput(int $size): void
    {
        if ($size > $this->interpreterState->limits->maxOutputSize) {
            throw new ExecutionLimitException(sprintf('Output size limit exceeded (%d bytes)', $this->interpreterState->limits->maxOutputSize));
        }
    }

    /** The shell's own diagnostics: they go to whatever fd 2 currently is */
    public function writeStderr(string $data): void
    {
        $execResult = $this->routeOutput(new ExecResult(stderr: $data), $this->fds);
        $this->writeStdout($execResult->stdout);
        $this->appendStderr($execResult->stderr);
    }

    /**
     * Opens redirections left to right on top of the current fd table, as bash does, so `>f 2>&1` and
     * `2>&1 >f` differ. Output files are created or truncated here, so routeOutput() only ever appends.
     * A failure is reported through the fds opened so far (`2>/dev/null >/bad/f` stays quiet).
     * `{name}>file` opens the lowest free fd from 10 and puts its number in the variable; `{name}>&-` closes
     * the fd the variable names. Either way the change outlives the command, as in bash.
     *
     * @param  list<RedirectionNode>  $redirections
     * @return array{stdin: ?StdinStream, fds: array<int, string|StdinStream>}|ExecResult ExecResult when a target can't be opened
     */
    private function openRedirections(array $redirections, StdinStream $stdinStream): array|ExecResult
    {
        $stdin = null;
        $fds = $this->fds;

        foreach ($redirections as $redirection) {
            $op = $redirection->operator;
            $fd = $this->limitFd($redirection->fd ?? 1);
            $named = $redirection->fdVariable;

            if ($redirection->target instanceof HereDocNode) {
                $content = $redirection->target->quoted
                    ? $this->rawWordValue($redirection->target->content)
                    : $this->wordExpander->expandHeredoc($redirection->target->content);

                if ($redirection->target->stripTabs) {
                    $content = preg_replace('/^\t+/m', '', $content) ?? $content;
                }

                if (strlen($content) > $this->interpreterState->limits->maxHereDocSize) {
                    throw new ExecutionLimitException(sprintf('Here-document size limit exceeded (%d bytes)', $this->interpreterState->limits->maxHereDocSize));
                }

                $opened = new StdinStream($content);
            } else {
                $target = $this->expandWord($redirection->target);
                $duplicate = ($op === '>&' || $op === '<&') && ($target === '-' || ctype_digit($target));
                $opened = match (true) {
                    $op === '<<<' => new StdinStream($target."\n"),
                    $op === '<', $op === '<>' => $this->openInputFile($target, $op === '<>'),
                    $duplicate => $this->duplicateFd($target, $fds, $stdin ?? $stdinStream, $this->badFdLabel($redirection, $this->rawWordValue($redirection->target))),
                    // `>&file` is the csh spelling of `&>file`
                    $op === '<&' || ($op === '>&' && ($fd !== 1 || $named !== null)) => new ExecResult(stderr: 'bash: '.($named ?? $target).": ambiguous redirect\n", exitCode: 1),
                    default => $this->openOutputFile($target, str_ends_with($op, '>>'), $op === '>|', $fds),
                };

                if (($op === '>&' && ! $duplicate) || $op === '&>' || $op === '&>>') {
                    $fd = -1; // both 1 and 2
                }
            }

            if ($named !== null && ! $opened instanceof ExecResult) {
                $namedFd = $opened === null ? $this->namedFdToClose($named) : $this->assignNamedFd($named, $fds);

                if ($namedFd instanceof ExecResult) {
                    return $this->routeOutput($namedFd, $fds);
                }

                // The shell keeps it, so restoring the fds after the command doesn't undo it
                $fd = $namedFd;

                if ($opened === null) {
                    unset($this->fds[$fd]);
                } else {
                    $this->fds[$fd] = $opened;
                }
            }

            if ($opened instanceof ExecResult) {
                return $this->routeOutput($opened, $fds);
            }

            if ($fd === 0) {
                // fd 0 travels as the StdinStream argument; a closed or write-only one can't be read
                $stdin = $opened instanceof StdinStream ? $opened : new StdinStream(readable: false);
            } elseif ($opened === null) {
                unset($fds[$fd]);
            } elseif ($fd === -1) {
                $fds[1] = $fds[2] = $opened;
            } else {
                $fds[$fd] = $opened;
            }
        }

        return ['stdin' => $stdin, 'fds' => $fds];
    }

    private function limitFd(int $fd): int
    {
        if ($fd >= $this->interpreterState->limits->maxFileDescriptors) {
            throw new ExecutionLimitException(sprintf('File descriptor limit exceeded (%d)', $this->interpreterState->limits->maxFileDescriptors));
        }

        return $fd;
    }

    /** The fd `{name}>&-` closes: the number in the variable */
    private function namedFdToClose(string $name): int|ExecResult
    {
        $value = $this->expandWord(new WordNode([new \BashBox\Ast\Parts\LiteralPart('${'.$name.'}')]));

        return ctype_digit($value) ? (int) $value : new ExecResult(stderr: "bash: {$name}: ambiguous redirect\n", exitCode: 1);
    }

    /**
     * `{name}>file`: the lowest fd from 10 that isn't open, stored in the variable (or array element)
     *
     * @param  array<int, string|StdinStream>  $fds
     */
    private function assignNamedFd(string $name, array $fds): int|ExecResult
    {
        $variable = explode('[', $name)[0];

        if ($this->interpreterState->isReadonly($variable)) {
            return new ExecResult(stderr: "bash: {$variable}: readonly variable\nbash: {$name}: cannot assign fd to variable\n", exitCode: 1);
        }

        $fd = 10;

        while (isset($fds[$fd])) {
            $this->limitFd(++$fd);
        }

        $this->applyAssignment($this->resolveAssignment(new \BashBox\Ast\AssignmentNode($name, new WordNode([new \BashBox\Ast\Parts\LiteralPart((string) $fd)]))));

        return $fd;
    }

    /** `<file` and `<>file` (which creates a missing file): a stream on the file that reads its current content */
    private function openInputFile(string $target, bool $readWrite): StdinStream|ExecResult
    {
        if ($target === '/dev/null') {
            return new StdinStream;
        }

        $path = $this->resolveFsPath($target);

        try {
            if ($readWrite && ! $this->fileSystem->exists($path)) {
                $this->fileSystem->writeFile($path, '');
            }

            // `<dir` fails here, where bash opens it and fails the first read
            return new StdinStream($this->fileSystem->readFile($path), $this->fileSystem, $path, $readWrite);
        } catch (RuntimeException $runtimeException) {
            return $this->openError($target, $runtimeException);
        }
    }

    private function openError(string $target, RuntimeException $runtimeException): ExecResult
    {
        return new ExecResult(stderr: sprintf("bash: %s: %s\n", $target, $this->strerror($runtimeException)), exitCode: 1);
    }

    /** What strerror() says for a filesystem failure such as "ENOSPC: no space left on device, write '/f'" */
    private function strerror(RuntimeException $runtimeException): string
    {
        $message = $runtimeException->getMessage();

        // bash can't put a NUL in a path, so a path holding one stays an exception
        if (str_contains($message, 'null byte')) {
            throw $runtimeException;
        }

        return match (strstr($message, ':', true)) {
            'EACCES' => 'Permission denied',
            'EISDIR' => 'Is a directory',
            'EPERM' => 'Operation not permitted',
            default => ucfirst((string) preg_replace('/^\w+: ([^,]*).*$/s', '$1', $message)),
        };
    }

    /**
     * `N>&M` / `N<&M`: fd N becomes a copy of fd M, sharing its offset; `-` closes N (null).
     *
     * @param  array<int, string|StdinStream>  $fds
     */
    private function duplicateFd(string $target, array $fds, StdinStream $stdinStream, string $label): string|StdinStream|ExecResult|null
    {
        return match (true) {
            $target === '-' => null,
            $target === '0' => $stdinStream->dup(),
            default => $fds[(int) $target] ?? new ExecResult(stderr: "bash: {$label}: Bad file descriptor\n", exitCode: 1),
        };
    }

    /**
     * What bash names when `>&M` finds M closed: a literal number, else the word as written (`$fd`) on the
     * operator's own fd, the fd otherwise, and for `{name}>&M` the variable.
     */
    private function badFdLabel(RedirectionNode $redirectionNode, string $raw): string
    {
        $default = ctype_digit($raw) || $redirectionNode->fd === ($redirectionNode->operator === '>&' ? 1 : 0);

        return $redirectionNode->fdVariable ?? ($default ? $raw : (string) $redirectionNode->fd);
    }

    /** @param array<int, string|StdinStream> $fds */
    private function openOutputFile(string $target, bool $append, bool $clobber, array $fds): string|StdinStream|ExecResult
    {
        $special = ['/dev/null' => '@null', '/dev/stdout' => $fds[1] ?? '@null', '/dev/stderr' => $fds[2] ?? '@null'];

        if (isset($special[$target])) {
            return $special[$target];
        }

        $path = $this->resolveFsPath($target);

        if (! $append && ! $clobber && $this->interpreterState->shellOpts['noclobber'] && $this->fileSystem->exists($path)) {
            return new ExecResult(stderr: "bash: {$target}: cannot overwrite existing file\n", exitCode: 1);
        }

        try {
            // The filesystem would create missing parents; a redirection must not
            if (! $this->fileSystem->stat(dirname($path))->isDirectory) {
                return new ExecResult(stderr: "bash: {$target}: Not a directory\n", exitCode: 1);
            }

            $append ? $this->fileSystem->appendFile($path, '') : $this->fileSystem->writeFile($path, '');
        } catch (RuntimeException $runtimeException) {
            return $this->openError($target, $runtimeException);
        }

        return $path;
    }

    /**
     * Sends a command's stdout and stderr to their fds' targets; what is for the current capture level's
     * stdout or the shell's stderr comes back in the result. Writing to a closed or read-only fd fails
     * like bash: $writer ('bash: echo', 'cat') reports a write error on fd 2 and the status becomes 1.
     * Limitation: pipelines are buffered, not streamed, so a file shared by fd 1 and 2 gets all stdout then all stderr, not interleaved.
     *
     * @param  array<int, string|StdinStream>  $fds
     */
    private function routeOutput(ExecResult $execResult, array $fds, ?string $writer = null): ExecResult
    {
        $stdout = '';
        $stderr = '';
        $errors = $execResult->stderr;
        $exitCode = $execResult->exitCode;

        $error = $this->writeFd($fds[1] ?? null, $execResult->stdout, $stdout, $stderr);

        if ($error !== null && $writer !== null) {
            $errors .= sprintf("%s: write error: %s\n", $writer, $error);
            $exitCode = 1;
        }

        $this->writeFd($fds[2] ?? null, $errors, $stdout, $stderr);

        return new ExecResult($stdout, $stderr, $exitCode);
    }

    /** @return ?string why the write failed: $target isn't open for writing, or the filesystem refused */
    private function writeFd(string|StdinStream|null $target, string $data, string &$stdout, string &$stderr): ?string
    {
        if ($data === '' || $target === '@null') {
            return null;
        }

        if ($target instanceof StdinStream && $target->writable) {
            return $this->tryFs(fn () => $target->write($data));
        }

        if (! is_string($target)) {
            return 'Bad file descriptor';
        }

        if ($target === '@2') {
            $stderr .= $data;
        } elseif (str_starts_with($target, '@1:')) {
            $level = (int) substr($target, 3);

            if ($level === $this->captureLevel) {
                $stdout .= $data;
            } else {
                $this->passthrough[$level] = ($this->passthrough[$level] ?? '').$data;
            }
        } else {
            $this->limitOutput(strlen($data));

            return $this->tryFs(fn () => $this->fileSystem->appendFile($target, $data));
        }

        return null;
    }

    /** @return ?string strerror's text when the filesystem refuses */
    private function tryFs(Closure $write): ?string
    {
        try {
            $write();
        } catch (RuntimeException $runtimeException) {
            return $this->strerror($runtimeException);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function listDirectory(string $path): array
    {
        return $this->fileSystem->readdir($path);
    }

    public function isDirectory(string $path, bool $followLinks = true): bool
    {
        return $this->statPath($path, $followLinks)->isDirectory ?? false;
    }

    /**
     * @param  array<int, array{
     *     type: 'scalar'|'array'|'element',
     *     name: string,
     *     value?: string,
     *     append: bool,
     *     elements?: list<array{int|string|null, string}>,
     *     subscript?: int|string,
     * } >  $assignments
     */
    private function formatTraceCommand(SimpleCommandNode $simpleCommandNode, array $assignments): string
    {
        // bash traces each assignment and then the command on lines of their own; the caller adds the first `+ `
        $lines = [];

        foreach ($assignments as $assignment) {
            $op = $assignment['append'] ? '+=' : '=';
            $lines[] = match ($assignment['type']) {
                'array' => sprintf('%s%s(', $assignment['name'], $op).implode(' ', array_map(
                    fn (array $pair): string => ($pair[0] === null ? '' : sprintf('[%s]=', $pair[0])).$pair[1],
                    $assignment['elements'] ?? [],
                )).')',
                'element' => $assignment['name'].'['.($assignment['subscript'] ?? '').(']'.$op).($assignment['value'] ?? ''),
                default => $assignment['name'].$op.($assignment['value'] ?? ''),
            };
        }

        if ($simpleCommandNode->name instanceof WordNode) {
            $words = [$this->expandWord($simpleCommandNode->name)];

            foreach ($simpleCommandNode->args as $arg) {
                array_push($words, ...$this->expandWordList($arg));
            }

            $lines[] = implode(' ', $words);
        }

        return implode("\n+ ", $lines);
    }

    /**
     * @return array{
     *     type: 'scalar'|'array'|'element',
     *     name: string,
     *     value?: string,
     *     append: bool,
     *     elements?: list<array{int|string|null, string}>,
     *     subscript?: int|string,
     * }
     */
    private function resolveAssignment(\BashBox\Ast\AssignmentNode $assignmentNode): array
    {
        if ($assignmentNode->array !== null) {
            $elements = [];

            foreach ($assignmentNode->array as $element) {
                // `[key]=value` is read from the raw text, before quote removal, so a quoted value stays one word
                if (preg_match('/^\[([^\]]+)\]=(.*)$/s', $this->rawWordValue($element), $m) === 1) {
                    $key = $this->expandWord(new WordNode([new \BashBox\Ast\Parts\LiteralPart($m[1])]));
                    $elements[] = [$this->normalizeArrayKey($key), $this->expandWord(new WordNode([new \BashBox\Ast\Parts\LiteralPart($m[2])]))];

                    continue;
                }

                foreach ($this->expandWordList($element) as $value) {
                    $elements[] = [null, $value];
                }
            }

            return [
                'type' => 'array',
                'name' => $assignmentNode->name,
                'append' => $assignmentNode->append,
                'elements' => $elements,
            ];
        }

        if (preg_match('/^([a-zA-Z_]\w*)\[(.+)\]$/', $assignmentNode->name, $matches) === 1) {
            return [
                'type' => 'element',
                'name' => $matches[1],
                'subscript' => $this->expandSubscript($matches[2]),
                'append' => $assignmentNode->append,
                'value' => $assignmentNode->value instanceof \BashBox\Ast\WordNode ? $this->expandWord($assignmentNode->value) : '',
            ];
        }

        return [
            'type' => 'scalar',
            'name' => $assignmentNode->name,
            'append' => $assignmentNode->append,
            'value' => $assignmentNode->value instanceof \BashBox\Ast\WordNode ? $this->expandWord($assignmentNode->value) : '',
        ];
    }

    /**
     * @param  array{
     *     type: 'scalar'|'array'|'element',
     *     name: string,
     *     value?: string,
     *     append: bool,
     *     elements?: list<array{int|string|null, string}>,
     *     subscript?: int|string,
     * }  $assignment
     */
    private function applyAssignment(array $assignment): void
    {
        $state = $this->interpreterState;
        $name = $assignment['name'];

        if ($assignment['type'] === 'element') {
            $state->setElement($name, $this->arrayIndex($name, $assignment['subscript'] ?? 0), $assignment['value'] ?? '', $assignment['append']);

            return;
        }

        if ($assignment['type'] === 'scalar') {
            $state->setVar($name, $assignment['value'] ?? '', $assignment['append']);

            return;
        }

        $array = $assignment['append'] ? $state->getArray($name) : [];
        $nextIndex = $this->nextArrayIndex($array);
        $elements = [];

        // An unkeyed element goes one past the last numeric index, so (a [5]=b c) puts c at 6
        foreach ($assignment['elements'] ?? [] as [$key, $value]) {
            $key = $key === null ? $nextIndex : $this->arrayIndex($name, $key);
            $elements[$key] = $value;

            if (is_int($key)) {
                $nextIndex = $key + 1;
            }
        }

        if (! $assignment['append']) {
            $state->setArray($name, $elements);
        }

        foreach ($assignment['append'] ? $elements : [] as $key => $value) {
            $state->setElement($name, $key, $value);
        }
    }

    /**
     * @param  array<int|string, string>  $array
     */
    private function nextArrayIndex(array $array): int
    {
        $numericKeys = array_filter(array_keys($array), is_int(...));

        if ($numericKeys === []) {
            return 0;
        }

        return max($numericKeys) + 1;
    }

    private function normalizeArrayKey(string $subscript): int|string
    {
        return preg_match('/^-?\d+$/', $subscript) === 1 ? (int) $subscript : $subscript;
    }

    /** An assignment's `[subscript]`, expanded; whether it's arithmetic waits until the array is known to be indexed */
    private function expandSubscript(string $subscript): int|string
    {
        return $this->normalizeArrayKey($this->expandWord(new WordNode([new \BashBox\Ast\Parts\LiteralPart($subscript)])));
    }

    /** The element an assignment's expanded subscript names: an associative array's key, else an arithmetic index counting back from the end when negative. */
    private function arrayIndex(string $name, int|string $key): int|string
    {
        $state = $this->interpreterState;

        if ($state->hasAttribute($state->resolve($name, quiet: true) ?? $name, 'A')) {
            return $key;
        }

        $index = is_int($key) ? $key : $this->fatalArithmetic($key);
        $index += $index < 0 ? $this->nextArrayIndex($state->getArray($name)) : 0;

        return $index >= 0 ? $index : throw new AssignmentException(sprintf('bash: %s[%s]: bad array subscript', $name, $key));
    }

    private function rawWordValue(WordNode $wordNode): string
    {
        // The parser stores heredoc bodies as literal text
        return implode('', array_map(
            fn (\BashBox\Ast\WordPart $wordPart): string => $wordPart instanceof \BashBox\Ast\Parts\LiteralPart ? $wordPart->value : '',
            $wordNode->parts,
        ));
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    public function evaluateArithmeticExpression(\BashBox\Ast\ArithmeticExpressionNode $arithmeticExpressionNode): int
    {
        // The parser always keeps the source text; it's re-evaluated so `$x` expands textually, as in bash
        return $this->evaluateArithmeticString($arithmeticExpressionNode->originalText);
    }

    public function evaluateArithmeticString(string $expr): int
    {
        // Like bash: parameter expansion, command substitution and quote removal first, then evaluation
        return $this->evaluateArithmeticText($this->expandWord(new WordNode([new \BashBox\Ast\Parts\LiteralPart($expr)])));
    }

    /** Evaluates text that is already expanded, such as a variable's value: a `$` or quote in it is an error, never run. */
    public function evaluateArithmeticText(string $expr): int
    {
        return $this->evaluateArithExpr(new \BashBox\Parser\ArithmeticParser($expr, $this->interpreterState->limits)->parse());
    }

    /**
     * An element's key: associative arrays use the subscript as it is, indexed ones its arithmetic value,
     * counting back from the end when negative.
     */
    private function arithElementKey(ArithArrayElementNode $arithArrayElementNode): int|string
    {
        $array = $this->interpreterState->getArray($arithArrayElementNode->name);

        if (array_filter(array_keys($array), is_string(...)) !== []) {
            return $arithArrayElementNode->subscript;
        }

        $index = $this->evaluateArithmeticString($arithArrayElementNode->subscript);

        return $index < 0 && $array !== [] ? $index + (int) max(array_keys($array)) + 1 : $index;
    }

    private function arithRead(ArithVariableNode|ArithArrayElementNode $node): string
    {
        $name = $node->name;

        // bash reports `a[]` twice and reads it as 0
        if ($node instanceof ArithArrayElementNode && $node->subscript === '') {
            $this->writeStderr(str_repeat("bash: {$name}[]: bad array subscript\n", 2));

            return '0';
        }

        if ($node instanceof ArithArrayElementNode) {
            $key = $this->arithElementKey($node);

            return $this->interpreterState->getArray($name)[$key] ?? '0';
        }

        return $this->interpreterState->getVar($name) ?? $this->interpreterState->getSpecialVar($name) ?? '0';
    }

    private function arithWrite(ArithVariableNode|ArithArrayElementNode $node, int $value): int
    {
        if ($node instanceof ArithArrayElementNode) {
            $this->interpreterState->setElement($node->name, $this->arithElementKey($node), (string) $value);
        } else {
            $this->interpreterState->setVar($node->name, (string) $value);
        }

        return $value;
    }

    public function evaluateArithExpr(ArithExpr $arithExpr): int
    {
        if ($arithExpr instanceof ArithNumberNode) {
            return $arithExpr->value;
        }

        if ($arithExpr instanceof ArithVariableNode || $arithExpr instanceof ArithArrayElementNode) {
            $val = $this->arithRead($arithExpr);

            // A value that isn't a plain decimal is itself an expression, as in bash: `010` is octal, `08` an error
            if ($val !== '' && preg_match('/^-?(?:0|[1-9]\d{0,17})$/', $val) !== 1) {
                return $this->evaluateArithmeticText($val);
            }

            return (int) $val;
        }

        if ($arithExpr instanceof ArithBinaryNode) {
            $left = $this->evaluateArithExpr($arithExpr->left);

            // Short-circuit like bash: the right side's side effects only happen when it's needed
            if ($arithExpr->operator === '&&') {
                return (int) ($left !== 0 && $this->evaluateArithExpr($arithExpr->right) !== 0);
            }

            if ($arithExpr->operator === '||') {
                return (int) ($left !== 0 || $this->evaluateArithExpr($arithExpr->right) !== 0);
            }

            return $this->arithOperate($arithExpr->operator, $left, $this->evaluateArithExpr($arithExpr->right), $arithExpr->error);
        }

        if ($arithExpr instanceof ArithUnaryNode) {
            if (($arithExpr->operator === '++' || $arithExpr->operator === '--') && ($arithExpr->operand instanceof ArithVariableNode || $arithExpr->operand instanceof ArithArrayElementNode)) {
                $old = $this->evaluateArithExpr($arithExpr->operand);
                $new = $this->arithWrite($arithExpr->operand, Int64::add($old, $arithExpr->operator === '++' ? 1 : -1));

                return $arithExpr->prefix ? $new : $old;
            }

            $operand = $this->evaluateArithExpr($arithExpr->operand);

            return match ($arithExpr->operator) {
                '-' => Int64::sub(0, $operand),
                '+' => $operand,
                '!' => $operand === 0 ? 1 : 0,
                '~' => ~$operand,
                default => $operand,
            };
        }

        if ($arithExpr instanceof ArithTernaryNode) {
            $cond = $this->evaluateArithExpr($arithExpr->condition);

            return $cond !== 0
                ? $this->evaluateArithExpr($arithExpr->consequent)
                : $this->evaluateArithExpr($arithExpr->alternate);
        }

        if ($arithExpr instanceof ArithAssignmentNode) {
            $value = $this->evaluateArithExpr($arithExpr->value);
            $current = $arithExpr->operator === '=' ? 0 : $this->evaluateArithExpr($arithExpr->target);

            return $this->arithWrite($arithExpr->target, $arithExpr->operator === '=' ? $value : $this->arithOperate(substr($arithExpr->operator, 0, -1), $current, $value, $arithExpr->error));
        }

        // ArithmeticParser builds no other node types
        assert($arithExpr instanceof ArithGroupNode);

        return $this->evaluateArithExpr($arithExpr->expression);
    }

    /**
     * A binary operator on 64-bit integers that wrap around like bash's; shift counts are taken mod 64.
     * $error is the message for division by 0 or a negative exponent.
     */
    private function arithOperate(string $operator, int $left, int $right, string $error): int
    {
        if (($right === 0 && ($operator === '/' || $operator === '%')) || ($right < 0 && $operator === '**')) {
            throw new ArithmeticException($error);
        }

        return match ($operator) {
            '+' => Int64::add($left, $right),
            '-' => Int64::sub($left, $right),
            '*' => Int64::mul($left, $right),
            // PHP_INT_MIN / -1 doesn't fit: it wraps back to PHP_INT_MIN
            '/' => $right === -1 ? Int64::sub(0, $left) : intdiv($left, $right),
            '%' => $right === -1 ? 0 : $left % $right,
            '**' => Int64::pow($left, $right),
            '<<' => $left << ($right & 63),
            '>>' => $left >> ($right & 63),
            '<' => (int) ($left < $right),
            '<=' => (int) ($left <= $right),
            '>' => (int) ($left > $right),
            '>=' => (int) ($left >= $right),
            '==' => (int) ($left === $right),
            '!=' => (int) ($left !== $right),
            '&' => $left & $right,
            '|' => $left | $right,
            '^' => $left ^ $right,
            default => $right, // ','
        };
    }

    // =========================================================================
    // CONDITIONALS
    // =========================================================================

    public function evaluateConditional(ConditionalExpressionNode $conditionalExpressionNode): bool
    {
        if ($conditionalExpressionNode instanceof CondBinaryNode) {
            $left = $this->expandWord($conditionalExpressionNode->left);
            $operator = $conditionalExpressionNode->operator;

            if (in_array($operator, ['=', '==', '!='], true)) {
                return $this->matchPattern($left, $conditionalExpressionNode->right, extglob: true) === ($operator !== '!=');
            }

            if ($operator === '=~') {
                // Quoted parts of the regex match literally
                return $this->matchRegex($left, $this->wordExpander->expandPattern($conditionalExpressionNode->right, regex: true));
            }

            $right = $this->expandWord($conditionalExpressionNode->right);
            // [[ ]] evaluates integer operands as arithmetic: `010` is 8, `1+1` is 2
            $int = $this->evaluateArithmeticText(...);

            return match ($operator) {
                '<' => strcmp($left, $right) < 0,
                '>' => strcmp($left, $right) > 0,
                '-eq' => $int($left) === $int($right),
                '-ne' => $int($left) !== $int($right),
                '-lt' => $int($left) < $int($right),
                '-le' => $int($left) <= $int($right),
                '-gt' => $int($left) > $int($right),
                '-ge' => $int($left) >= $int($right),
                // A missing file counts as older than any existing one
                '-nt' => ($this->statPath($left)->mtime ?? PHP_INT_MIN) > ($this->statPath($right)->mtime ?? PHP_INT_MIN),
                '-ot' => ($this->statPath($left)->mtime ?? PHP_INT_MIN) < ($this->statPath($right)->mtime ?? PHP_INT_MIN),
                default => $this->realPath($left) !== null && $this->realPath($left) === $this->realPath($right), // -ef
            };
        }

        if ($conditionalExpressionNode instanceof CondUnaryNode) {
            $operand = $this->expandWord($conditionalExpressionNode->operand);

            return match ($conditionalExpressionNode->operator) {
                '-z' => $operand === '',
                '-n' => $operand !== '',
                '-v' => $this->interpreterState->getVar($operand) !== null,
                '-a', '-e', '-r', '-w' => $this->statPath($operand) instanceof \BashBox\Filesystem\FsStat,
                '-f' => $this->statPath($operand)->isFile ?? false,
                '-d' => $this->statPath($operand)->isDirectory ?? false,
                '-s' => ($this->statPath($operand)->size ?? 0) > 0,
                '-x' => (($this->statPath($operand)->mode ?? 0) & 0o111) !== 0,
                '-L', '-h' => $this->statPath($operand, followLinks: false)->isSymbolicLink ?? false,
                // the sandbox has no devices, pipes, sockets, ttys or special mode bits
                default => false,
            };
        }

        if ($conditionalExpressionNode instanceof CondNotNode) {
            return ! $this->evaluateConditional($conditionalExpressionNode->operand);
        }

        if ($conditionalExpressionNode instanceof CondAndNode) {
            return $this->evaluateConditional($conditionalExpressionNode->left) && $this->evaluateConditional($conditionalExpressionNode->right);
        }

        if ($conditionalExpressionNode instanceof CondOrNode) {
            if ($this->evaluateConditional($conditionalExpressionNode->left)) {
                return true;
            }

            return $this->evaluateConditional($conditionalExpressionNode->right);
        }

        if ($conditionalExpressionNode instanceof CondGroupNode) {
            return $this->evaluateConditional($conditionalExpressionNode->expression);
        }

        // A lone word: true when non-empty
        assert($conditionalExpressionNode instanceof CondWordNode);

        return $this->expandWord($conditionalExpressionNode->word) !== '';
    }

    private function resolveFsPath(string $path): string
    {
        return $this->fileSystem->resolvePath($this->interpreterState->cwd, $path);
    }

    private function statPath(string $path, bool $followLinks = true): ?\BashBox\Filesystem\FsStat
    {
        try {
            return $followLinks ? $this->fileSystem->stat($this->resolveFsPath($path)) : $this->fileSystem->lstat($this->resolveFsPath($path));
        } catch (RuntimeException) {
            return null;
        }
    }

    private function realPath(string $path): ?string
    {
        try {
            return $this->fileSystem->realpath($this->resolveFsPath($path));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * `[[ str =~ regex ]]`, filling BASH_REMATCH like bash.
     *
     * @throws RegexException for a regex that doesn't compile or a match that runs out of backtracking
     */
    private function matchRegex(string $subject, string $regex): bool
    {
        $pcre = "\x01{$regex}\x01".(Glob::flags($regex.$subject) === 'su' ? 'u' : '').($this->interpreterState->shopt['nocasematch'] ? 'i' : '');
        $error = PosixRegex::error($pcre);

        if ($error !== null) {
            throw new RegexException(sprintf("invalid regular expression `%s': %s", $regex, $error));
        }

        if (! SafePcreRegex::match($pcre, $subject, $matches)) {
            return false;
        }

        $this->interpreterState->arrays['BASH_REMATCH'] = array_filter($matches, is_string(...));

        return true;
    }

    /**
     * Glob match as in `case` and `[[ == ]]`, honouring nocasematch. Quoted parts of the pattern word match
     * literally. `[[ ]]` always understands extglob patterns; `case` only with `shopt -s extglob`.
     */
    private function matchPattern(string $str, WordNode $wordNode, bool $extglob = false): bool
    {
        return Glob::matches(
            $this->wordExpander->expandPattern($wordNode),
            $str,
            $extglob || $this->interpreterState->shopt['extglob'],
            $this->interpreterState->shopt['nocasematch'],
        );
    }
}
