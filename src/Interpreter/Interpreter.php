<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\ArithmeticCommandNode;
use BashBox\Ast\ArithmeticExpressionNode;
use BashBox\Ast\AssignmentNode;
use BashBox\Ast\CaseNode;
use BashBox\Ast\CommandNode;
use BashBox\Ast\CompoundCommandNode;
use BashBox\Ast\ConditionalCommandNode;
use BashBox\Ast\CStyleForNode;
use BashBox\Ast\ForNode;
use BashBox\Ast\FunctionDefNode;
use BashBox\Ast\FunctionPrinter;
use BashBox\Ast\GroupNode;
use BashBox\Ast\IfNode;
use BashBox\Ast\Node;
use BashBox\Ast\Parts\LiteralPart;
use BashBox\Ast\PipelineNode;
use BashBox\Ast\ScriptNode;
use BashBox\Ast\SimpleCommandNode;
use BashBox\Ast\StatementNode;
use BashBox\Ast\SubshellNode;
use BashBox\Ast\UntilNode;
use BashBox\Ast\WhileNode;
use BashBox\Ast\WordNode;
use BashBox\Commands\CommandContext;
use BashBox\Commands\CommandInterface;
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
use BashBox\Filesystem\FsStat;
use BashBox\Interpreter\Expansion\WordExpander;
use BashBox\Network\SecureHttpClient;
use BashBox\Parser\Parser;
use BashBox\Regex\RegexException;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Runs parsed scripts: statements, pipelines, simple and compound commands, functions and subshells.
 *
 * @phpstan-import-type Assignment from Assignments
 */
final class Interpreter
{
    public readonly ArithmeticEvaluator $arithmetic;

    private readonly WordExpander $wordExpander;

    private readonly Assignments $assignments;

    private readonly Redirections $redirections;

    private readonly ConditionalEvaluator $conditionalEvaluator;

    private readonly Builtins $builtins;

    private string $stdout = '';

    private string $stderr = '';

    public private(set) int $loopDepth = 0;

    /** Nested $(...), <(...), eval, source and trap texts being run */
    private int $nesting = 0;

    /** Above 0 while a command's status is being tested (if/while/until conditions, `a && b`, `! a`): no ERR trap or errexit */
    private int $conditionDepth = 0;

    /** @var array<string, true> aliases being expanded, which don't expand again inside themselves */
    private array $expandingAliases = [];

    /** @var array<string, string> the aliases when the current input line began */
    private array $lineAliases = [];

    /** @var list<array{path: string, reader: ?SubshellNode}> open process substitutions; reader is a `>(...)` command still to run */
    private array $processSubstitutions = [];

    public function __construct(
        private readonly InterpreterState $interpreterState,
        private readonly FileSystemInterface $fileSystem,
        private readonly CommandRegistry $commandRegistry,
        private readonly ?SecureHttpClient $secureHttpClient = null,
    ) {
        $this->wordExpander = new WordExpander($interpreterState, $this);
        $this->arithmetic = new ArithmeticEvaluator($interpreterState, $this);
        $this->assignments = new Assignments($interpreterState, $this->wordExpander, $this->arithmetic);
        $this->redirections = new Redirections($interpreterState, $fileSystem, $this->wordExpander, $this->assignments);
        $this->conditionalEvaluator = new ConditionalEvaluator($interpreterState, $this->wordExpander, $this->arithmetic, $this);
        $this->builtins = new Builtins($interpreterState, $fileSystem, $commandRegistry, $this, $this->redirections, $this->assignments, $this->arithmetic);
        // An -i variable's new value that doesn't evaluate ends the shell, as in bash
        $interpreterState->arithmetic = fn (string $expression): int => $this->arithmetic->evaluateOrExit($expression, $this->builtins->assigning);
        $interpreterState->warn = fn (string $message) => $this->writeStderr("bash: warning: {$message}\n");
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
     * Runs top-level statements; a non-fatal shell error abandons the rest of its input line with status 1, and the next line still runs.
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
    public function captureInShell(string $script, StdinStream $stdinStream, int $line = 1, ?array $aliases = null, string $errorPrefix = 'bash: '): ExecResult
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

        // ERR and errexit fire only for an and-or list's untested final pipeline; a compound command's failing command already had its turn
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
        $cpuBefore = TimeFormat::cpuTimes();

        // Thread each command's stdout (plus stderr for `|&`) into the next one's stdin
        $statuses = [];
        $lastIndex = count($pipelineNode->commands) - 1;

        if ($lastIndex >= $this->interpreterState->limits->maxPipelineDepth) {
            throw new ExecutionLimitException(sprintf('Pipeline limit exceeded (%d commands)', $this->interpreterState->limits->maxPipelineDepth));
        }

        // Each stage of a real pipeline is a subshell, the last one too unless `shopt -s lastpipe`
        $stage = fn (int $i, CommandNode $commandNode, StdinStream $stdinStream): ExecResult => $lastIndex > 0 && ($i < $lastIndex || ! $this->interpreterState->shopt['lastpipe'])
            ? $this->inSubshell(fn (): ExecResult => $this->executeCommand($commandNode, $stdinStream), counted: false)
            : $this->executeCommand($commandNode, $stdinStream);

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
            $format = $pipelineNode->timePosix ? "real %2R\nuser %2U\nsys %2S" : $this->interpreterState->getVar('TIMEFORMAT') ?? "\nreal\t%3lR\nuser\t%3lU\nsys\t%3lS";
            $this->writeStderr(TimeFormat::report($format, intdiv(hrtime(true) - $started, 1000), $cpuBefore));
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
     * Runs $run on empty output buffers and returns what it wrote plus its result; unwinding through keeps what was written.
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
     * Runs $run as a new capture level (a pipe stage, `$(...)`) whose fd changes don't outlive it.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    private function inCaptureLevel(Closure $run, bool $stderrToo = false): mixed
    {
        $savedFds = $this->redirections->enterCaptureLevel($stderrToo);

        try {
            return $run();
        } finally {
            $this->writeStdout($this->redirections->leaveCaptureLevel($savedFds));
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
                $this->redirections->tryFs(fn () => $this->fileSystem->rm($path, ['force' => true, 'recursive' => true]));
            }
        }
    }

    /** `<(cmd)` and `>(cmd)` expand to a file /dev/fd/N (63 down, as bash numbers them): `<(cmd)` runs now into it, `>(cmd)` reads it once the command is done. */
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

        $error = $this->redirections->tryFs(function () use ($path, $content): void {
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

        $opened = $this->redirections->open($commandNode->redirections, $stdinStream);

        if ($opened instanceof ExecResult) {
            return $opened;
        }

        // The body writes straight through the compound's fds; what reaches the buffers is captured so it can flow into a pipe
        $savedFds = $this->redirections->fds;
        $this->redirections->fds = $opened['fds'];

        try {
            return $this->capture(fn (): ExecResult => $this->redirections->route($this->dispatchCommand($commandNode, $opened['stdin'] ?? $stdinStream)));
        } finally {
            $this->redirections->restore($savedFds, $opened['fds']);
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
            $prefixAssignments[] = $this->assignments->resolve($assignment);
        }

        if (($this->interpreterState->shellOpts['xtrace'] ?? false) && ($simpleCommandNode->assignments !== [] || $simpleCommandNode->name instanceof WordNode)) {
            $this->writeStderr('+ '.$this->formatTraceCommand($simpleCommandNode, $prefixAssignments)."\n");
        }

        // Command name and args both go through field splitting, so `$CMD arg` with CMD="ls -l" runs ls.
        $words = [];

        if ($simpleCommandNode->name instanceof WordNode) {
            $words = $this->expandWordList($simpleCommandNode->name);
        }

        foreach ($simpleCommandNode->args as $arg) {
            array_push($words, ...$this->expandWordList($arg));
        }

        $opened = $this->redirections->open($simpleCommandNode->redirections, $stdinStream);

        if ($opened instanceof ExecResult) {
            return $opened;
        }

        $fds = $opened['fds'];
        $redirectedStdin = $opened['stdin'] ?? $stdinStream;

        // `exec` without a command applies its redirections to the shell itself
        if ($words === ['exec'] && ! isset($this->interpreterState->disabledBuiltins['exec'])) {
            $this->redirections->fds = $fds;

            if ($opened['stdin'] instanceof StdinStream) {
                $stdinStream->redirect($opened['stdin']);
            }

            return new ExecResult(exitCode: 0);
        }

        if ($words === []) {
            foreach ($prefixAssignments as $prefixAssignment) {
                $this->assignments->apply($prefixAssignment);
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
            $arrayOperands[$arrayArg->name] = $this->assignments->resolve($arrayArg);
        }

        // The command (and anything it runs) writes through its own fds; its output arrives already routed
        $savedFds = $this->redirections->fds;
        $this->redirections->fds = $fds;
        $this->builtins->arrayOperands = $arrayOperands;

        try {
            return $this->builtins->run($commandName, $words, $redirectedStdin)
                ?? (isset($this->interpreterState->functions[$commandName])
                    ? $this->callFunction($commandName, $words, $redirectedStdin)
                    : $this->runCommand($commandName, $words, $redirectedStdin));
        } finally {
            $this->builtins->arrayOperands = [];
            $this->restoreVars($savedVars);
            $this->redirections->restore($savedFds, $fds);
        }
    }

    /** set -k: assignments among the arguments, not just before the command name, go into the command's environment */
    private function keywordAssignments(SimpleCommandNode $simpleCommandNode): SimpleCommandNode
    {
        $args = [];
        $assignments = $simpleCommandNode->assignments;

        foreach ($simpleCommandNode->args as $arg) {
            $first = $arg->parts[0] ?? null;

            if ($first instanceof LiteralPart && preg_match('/^([a-zA-Z_]\w*)(\+?)=(.*)$/s', $first->value, $m) === 1) {
                $assignments[] = new AssignmentNode($m[1], new WordNode([new LiteralPart($m[3]), ...array_slice($arg->parts, 1)]), $m[2] === '+');
            } else {
                $args[] = $arg;
            }
        }

        return new SimpleCommandNode($simpleCommandNode->name, $args, $assignments, $simpleCommandNode->redirections, $simpleCommandNode->line, $simpleCommandNode->arrayArgs);
    }

    /** With `shopt -s expand_aliases`, a command named by an alias runs as the alias text plus the rest of the command. */
    private function expandAlias(SimpleCommandNode $simpleCommandNode, StdinStream $stdinStream): ?ExecResult
    {
        $name = $simpleCommandNode->name instanceof WordNode ? WordExpander::literalText($simpleCommandNode->name) : '';
        $alias = $this->lineAliases[$name] ?? null;

        if ($alias === null || ! $this->interpreterState->shopt['expand_aliases'] || isset($this->expandingAliases[$name])) {
            return null;
        }

        $this->expandingAliases[$name] = true;

        try {
            $expanded = new SimpleCommandNode(
                new WordNode([new LiteralPart($alias)]),
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
    public function runCommand(string $name, array $args, StdinStream $stdinStream): ExecResult
    {
        if (str_contains($name, '/')) {
            return $this->redirections->route($this->runScriptFile($name, $args, $stdinStream));
        }

        $cmd = $this->commandRegistry->get($name);

        if (! $cmd instanceof CommandInterface) {
            return $this->redirections->route(new ExecResult(stderr: "bash: {$name}: command not found\n", exitCode: 127));
        }

        return $this->redirections->route($cmd->execute($args, $this->commandContext($stdinStream)), in_array($name, Builtins::REGISTRY_BUILTINS, true) ? 'bash: '.$name : $name);
    }

    public function commandContext(StdinStream $stdinStream): CommandContext
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
     * A command named by path runs the file as a script in a child shell that sees only exported variables.
     *
     * @param  list<string>  $args
     */
    private function runScriptFile(string $name, array $args, StdinStream $stdinStream): ExecResult
    {
        $stat = $this->statPath($name);
        [$error, $status] = match (true) {
            ! $stat instanceof FsStat => ['No such file or directory', 127],
            $stat->isDirectory => ['Is a directory', 126],
            ($stat->mode & 0o111) === 0 => ['Permission denied', 126],
            default => [null, 0],
        };
        $script = '';

        try {
            $script = $error === null ? $this->fileSystem->readFile($this->resolveFsPath($name)) : '';
        } catch (RuntimeException $runtimeException) {
            [$error, $status] = [Redirections::strerror($runtimeException), 126];
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
        if ($cStyleForNode->init instanceof ArithmeticExpressionNode) {
            $this->arithmetic->evaluateExpression($cStyleForNode->init);
        }

        $exitCode = 0;
        $iterations = 0;

        while (! $cStyleForNode->condition instanceof ArithmeticExpressionNode || $this->arithmetic->evaluateExpression($cStyleForNode->condition) !== 0) {
            if (! $this->runLoopBody($cStyleForNode->body, $stdinStream, $exitCode, $iterations)) {
                break;
            }

            if ($cStyleForNode->update instanceof ArithmeticExpressionNode) {
                $this->arithmetic->evaluateExpression($cStyleForNode->update);
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
            if (! $fallThrough && ! array_any($item->patterns, fn (WordNode $wordNode): bool => $this->conditionalEvaluator->matchPattern($word, $wordNode))) {
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
     * Runs $run in a subshell, where state changes, exit and shell errors stay; a pipeline stage isn't $counted in BASH_SUBSHELL.
     *
     * @param  Closure(): ExecResult  $run
     */
    private function inSubshell(Closure $run, bool $counted = true): ExecResult
    {
        $snapshot = clone $this->interpreterState;
        $savedFds = $this->redirections->fds;
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
            $this->redirections->fds = $savedFds;
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
            $result = $this->arithmetic->evaluateExpression($arithmeticCommandNode->expression);
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
            return new ExecResult(exitCode: $this->conditionalEvaluator->evaluate($conditionalCommandNode->expression) ? 0 : 1);
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
        // RETURN is inherited only under `set -T` and ERR only under `set -E`; otherwise only one the body sets fires, and stays set
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

    /** @param list<StatementNode> $statements */
    private function executeStatementList(array $statements, StdinStream $stdinStream): int
    {
        $exitCode = 0;

        foreach ($statements as $statement) {
            $exitCode = $this->executeStatement($statement, $stdinStream);
        }

        return $exitCode;
    }

    /** @param list<StatementNode> $statements */
    private function executeStatementListResult(array $statements, StdinStream $stdinStream): ExecResult
    {
        // Output goes straight to the shell's buffers; executeCommand() captures it for the compound's redirections
        return new ExecResult(exitCode: $this->executeStatementList($statements, $stdinStream));
    }

    public function expandWord(WordNode $wordNode): string
    {
        return $this->wordExpander->expand($wordNode);
    }

    /** @return list<string> */
    public function expandWordList(WordNode $wordNode): array
    {
        return $this->wordExpander->expandToList($wordNode);
    }

    /** Runs $script and returns its output; as a $subshell (`$(...)`, xargs) its state changes, `exit` and shell errors stay inside it. */
    public function execSubcommand(string $script, bool $subshell = true, bool $substitution = false, ?StdinStream $stdinStream = null): ExecResult
    {
        return $this->nested(fn (): ExecResult => $this->inCaptureLevel(fn (): ExecResult => $this->runSubcommand($script, $subshell, $substitution, $stdinStream)));
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
            return $this->runText($script, stdinStream: $stdin);
        }

        $saved = [$this->interpreterState->env, $this->interpreterState->exported];
        $this->interpreterState->env = $env;
        $this->interpreterState->exported = array_fill_keys(array_keys($env), true);

        try {
            return $this->runText($script, stdinStream: $stdin);
        } finally {
            [$this->interpreterState->env, $this->interpreterState->exported] = $saved;
        }
    }

    /** execSubcommand() for text that isn't part of the script (a trap, a command's `sh -c`): a syntax error is just status 2 */
    private function runText(string $script, bool $subshell = true, string $errorPrefix = 'bash: ', ?StdinStream $stdinStream = null): ExecResult
    {
        try {
            return $this->execSubcommand($script, $subshell, stdinStream: $stdinStream);
        } catch (ParseException $parseException) {
            return new ExecResult(stderr: $errorPrefix.$parseException->getMessage()."\n", exitCode: 2);
        }
    }

    private function runSubcommand(string $script, bool $subshell, bool $substitution, ?StdinStream $stdinStream = null): ExecResult
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
                $exitCode = $this->executeStatementList($scriptNode->statements, $stdinStream ?? new StdinStream);
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
        $this->interpreterState->limitOutput(strlen($this->stdout) + strlen($this->stderr));
    }

    public function appendStderr(string $data): void
    {
        $this->stderr .= $data;
        $this->interpreterState->limitOutput(strlen($this->stdout) + strlen($this->stderr));
    }

    /** The shell's own diagnostics: they go to whatever fd 2 currently is */
    public function writeStderr(string $data): void
    {
        $execResult = $this->redirections->route(new ExecResult(stderr: $data));
        $this->writeStdout($execResult->stdout);
        $this->appendStderr($execResult->stderr);
    }

    /** @return list<string> */
    public function listDirectory(string $path): array
    {
        return $this->fileSystem->readdir($path);
    }

    public function isDirectory(string $path, bool $followLinks = true): bool
    {
        return $this->statPath($path, $followLinks)->isDirectory ?? false;
    }

    /** @param list<Assignment> $assignments */
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

    private function resolveFsPath(string $path): string
    {
        return $this->fileSystem->resolvePath($this->interpreterState->cwd, $path);
    }

    public function statPath(string $path, bool $followLinks = true): ?FsStat
    {
        try {
            return $followLinks ? $this->fileSystem->stat($this->resolveFsPath($path)) : $this->fileSystem->lstat($this->resolveFsPath($path));
        } catch (RuntimeException) {
            return null;
        }
    }

    public function realPath(string $path): ?string
    {
        try {
            return $this->fileSystem->realpath($this->resolveFsPath($path));
        } catch (RuntimeException) {
            return null;
        }
    }
}
