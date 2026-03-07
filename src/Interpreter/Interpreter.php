<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

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
use BashBox\Exceptions\BreakException;
use BashBox\Exceptions\ContinueException;
use BashBox\Exceptions\ErrexitException;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Exceptions\ExitException;
use BashBox\Exceptions\ReturnException;
use BashBox\Exceptions\UnboundVariableException;
use BashBox\ExecResult;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Interpreter\Expansion\WordExpander;
use BashBox\Network\SecureHttpClient;
use RuntimeException;

final class Interpreter
{
    private readonly WordExpander $wordExpander;

    private string $stdout = '';

    private string $stderr = '';

    public function __construct(
        private readonly InterpreterState $interpreterState,
        private readonly FileSystemInterface $fileSystem,
        private readonly CommandRegistry $commandRegistry,
        private readonly ?SecureHttpClient $secureHttpClient = null,
    ) {
        $this->wordExpander = new WordExpander($this->interpreterState, $this);
    }

    public function getState(): InterpreterState
    {
        return $this->interpreterState;
    }

    public function executeScript(ScriptNode $scriptNode, string $stdin = ''): ExecResult
    {
        $exitCode = 0;

        try {
            foreach ($scriptNode->statements as $statement) {
                $exitCode = $this->executeStatement($statement, $stdin);
            }
        } catch (ExitException|ErrexitException $e) {
            $exitCode = $e->exitCode;
        } finally {
            $trapStdout = '';
            $trapStderr = '';

            if (isset($this->interpreterState->traps['EXIT']) && $this->interpreterState->traps['EXIT'] !== '') {
                $trapCmd = $this->interpreterState->traps['EXIT'];
                unset($this->interpreterState->traps['EXIT']);

                try {
                    $trapResult = $this->execSubcommand($trapCmd);
                    $trapStdout = $trapResult->stdout;
                    $trapStderr = $trapResult->stderr;
                } catch (ExitException|ErrexitException) {
                    // Ignore exit inside EXIT trap
                }
            }

            // Capture stdout/stderr accumulated before the trap wiped them
            // execSubcommand resets $this->stdout, so we must restore + append
            $stdout = $this->stdout.$trapStdout;
            $stderr = $this->stderr.$trapStderr;
            $this->stdout = '';
            $this->stderr = '';
        }

        return new ExecResult(stdout: $stdout, stderr: $stderr, exitCode: $exitCode);
    }

    public function executeStatement(StatementNode $statementNode, string $stdin = ''): int
    {
        if ($statementNode->deferredError !== null) {
            $this->writeStderr('bash: '.$statementNode->deferredError['message']."\n");

            return 2;
        }

        $exitCode = 0;
        $counter = count($statementNode->pipelines);

        for ($i = 0; $i < $counter; $i++) {
            $pipeline = $statementNode->pipelines[$i];
            $exitCode = $this->executePipeline($pipeline, $stdin);
            $this->interpreterState->lastExitCode = $exitCode;

            if ($i < count($statementNode->operators)) {
                $op = $statementNode->operators[$i];

                if ($op === '&&' && $exitCode !== 0) {
                    break;
                }

                if ($op === '||' && $exitCode === 0) {
                    break;
                }
            }
        }

        // Run ERR trap on non-zero exit
        if ($exitCode !== 0 && isset($this->interpreterState->traps['ERR']) && $this->interpreterState->traps['ERR'] !== '') {
            $errTrap = $this->interpreterState->traps['ERR'];
            unset($this->interpreterState->traps['ERR']);

            try {
                $trapResult = $this->execSubcommand($errTrap);
                $this->writeStdout($trapResult->stdout);

                if ($trapResult->stderr !== '') {
                    $this->writeStderr($trapResult->stderr);
                }
            } catch (ExitException|ErrexitException) {
                // Ignore
            }

            $this->interpreterState->traps['ERR'] = $errTrap;
        }

        // Check errexit
        // Don't trigger errexit for conditions in if/while/until or negated pipelines
        if (($this->interpreterState->shellOpts['errexit'] ?? false) && $exitCode !== 0 && $statementNode->operators === []) {
            throw new ErrexitException($exitCode);
        }

        return $exitCode;
    }

    public function executePipeline(PipelineNode $pipelineNode, string $stdin = ''): int
    {
        $commands = $pipelineNode->commands;

        if ($commands === []) {
            return 0;
        }

        // Execute pipeline: thread stdout of each command into stdin of next
        $currentStdin = $stdin;
        $lastExitCode = 0;
        $counter = count($commands);

        for ($i = 0; $i < $counter; $i++) {
            $command = $commands[$i];
            $result = $this->executeCommand($command, $currentStdin);
            $lastExitCode = $result->exitCode;

            if ($result->stderr !== '') {
                $this->writeStderr($result->stderr);
            }

            if ($i < count($commands) - 1) {
                $currentStdin = $result->stdout;
            } else {
                $this->writeStdout($result->stdout);
            }
        }

        if ($pipelineNode->negated) {
            return $lastExitCode === 0 ? 1 : 0;
        }

        return $lastExitCode;
    }

    public function executeCommand(Node $node, string $stdin = ''): ExecResult
    {
        $this->interpreterState->incrementCommandCount();

        if ($node instanceof SimpleCommandNode) {
            return $this->executeSimpleCommand($node, $stdin);
        }

        if ($node instanceof IfNode) {
            return $this->executeIf($node, $stdin);
        }

        if ($node instanceof ForNode) {
            return $this->executeFor($node, $stdin);
        }

        if ($node instanceof CStyleForNode) {
            return $this->executeCStyleFor($node, $stdin);
        }

        if ($node instanceof WhileNode) {
            return $this->executeWhile($node, $stdin);
        }

        if ($node instanceof UntilNode) {
            return $this->executeUntil($node, $stdin);
        }

        if ($node instanceof CaseNode) {
            return $this->executeCase($node, $stdin);
        }

        if ($node instanceof SubshellNode) {
            return $this->executeSubshell($node, $stdin);
        }

        if ($node instanceof GroupNode) {
            return $this->executeGroup($node, $stdin);
        }

        if ($node instanceof ArithmeticCommandNode) {
            return $this->executeArithmeticCommand($node);
        }

        if ($node instanceof ConditionalCommandNode) {
            return $this->executeConditionalCommand($node);
        }

        if ($node instanceof FunctionDefNode) {
            return $this->executeFunctionDef($node);
        }

        return new ExecResult(exitCode: 1);
    }

    // =========================================================================
    // SIMPLE COMMAND
    // =========================================================================

    private function executeSimpleCommand(SimpleCommandNode $simpleCommandNode, string $stdin = ''): ExecResult
    {
        try {
            $prefixAssignments = [];

            foreach ($simpleCommandNode->assignments as $assignment) {
                $prefixAssignments[] = $this->resolveAssignment($assignment);
            }

            if (($this->interpreterState->shellOpts['xtrace'] ?? false) && ($simpleCommandNode->assignments !== [] || $simpleCommandNode->name instanceof \BashBox\Ast\WordNode)) {
                $this->writeStderr('+ '.$this->formatTraceCommand($simpleCommandNode, $prefixAssignments)."\n");
            }

            // No command name - just assignments
            if (! $simpleCommandNode->name instanceof \BashBox\Ast\WordNode) {
                foreach ($prefixAssignments as $prefixAssignment) {
                    $result = $this->applyAssignment($prefixAssignment);

                    if ($result instanceof ExecResult) {
                        return $result;
                    }
                }

                return new ExecResult(exitCode: 0);
            }

            $commandName = $this->expandWord($simpleCommandNode->name);
            $args = [];

            foreach ($simpleCommandNode->args as $arg) {
                array_push($args, ...$this->expandWordList($arg));
            }

            // Handle redirections for stdin
            $redirectedStdin = $stdin;
            $redirectedStdout = null;
            $appendMode = false;
            $allowClobber = false;

            foreach ($simpleCommandNode->redirections as $redir) {
                $result = $this->processRedirection($redir);

                if ($result['stdin'] !== null) {
                    $redirectedStdin = $result['stdin'];
                }

                if ($result['stdout'] !== null) {
                    $redirectedStdout = $result['stdout'];
                    $appendMode = $result['append'];
                    $allowClobber = $result['allowClobber'];
                }
            }

            // Try builtin first
            $builtinResult = $this->tryBuiltin($commandName, $args, $redirectedStdin);

            if ($builtinResult instanceof \BashBox\ExecResult) {
                return $this->handleOutputRedirection($builtinResult, $redirectedStdout, $appendMode, $allowClobber);
            }

            // Try function
            if (isset($this->interpreterState->functions[$commandName])) {
                $result = $this->executeFunction($commandName, $args, $redirectedStdin);

                return $this->handleOutputRedirection($result, $redirectedStdout, $appendMode, $allowClobber);
            }

            // Try registered command
            $cmd = $this->commandRegistry->get($commandName);

            if ($cmd instanceof \BashBox\Commands\CommandInterface) {
                // Set prefix assignments as temporary env
                $env = $this->interpreterState->getExportedEnv();

                foreach ($prefixAssignments as $prefixAssignment) {
                    if ($prefixAssignment['type'] === 'scalar') {
                        $env[$prefixAssignment['name']] = $prefixAssignment['value'] ?? '';
                    }
                }

                $commandContext = new CommandContext(
                    fs: $this->fileSystem,
                    cwd: $this->interpreterState->cwd,
                    env: $env,
                    stdin: $redirectedStdin,
                    limits: $this->interpreterState->limits,
                    exec: fn (string $script): ExecResult => $this->execSubcommand($script),
                    fetch: $this->secureHttpClient,
                    registry: $this->commandRegistry,
                );

                $result = $cmd->execute($args, $commandContext);

                return $this->handleOutputRedirection($result, $redirectedStdout, $appendMode, $allowClobber);
            }

            $stderr = "bash: {$commandName}: command not found\n";

            return new ExecResult(stderr: $stderr, exitCode: 127);
        } catch (UnboundVariableException $unboundVariableException) {
            return new ExecResult(stderr: $unboundVariableException->getMessage()."\n", exitCode: 1);
        }
    }

    // =========================================================================
    // BUILTINS
    // =========================================================================

    /** @param array<int, string> $args */
    private function tryBuiltin(string $name, array $args, string $stdin): ?ExecResult
    {
        if (isset($this->interpreterState->disabledBuiltins[$name])) {
            return null;
        }

        return match ($name) {
            'exit' => $this->builtinExit($args),
            'export' => $this->builtinExport($args),
            'unset' => $this->builtinUnset($args),
            'local' => $this->builtinLocal($args),
            'set' => $this->builtinSet($args),
            'shopt' => $this->builtinShopt(),
            'cd' => $this->builtinCd($args),
            'source', '.' => $this->builtinSource($args),
            'eval' => $this->builtinEval($args),
            'declare', 'typeset' => $this->builtinDeclare($args),
            'read' => $this->builtinRead($args, $stdin),
            'break' => $this->builtinBreak($args),
            'continue' => $this->builtinContinue($args),
            'return' => $this->builtinReturn($args),
            'shift' => $this->builtinShift($args),
            'let' => $this->builtinLet($args),
            'getopts' => $this->builtinGetopts(),
            'mapfile', 'readarray' => $this->builtinMapfile($args, $stdin),
            ':' => new ExecResult(exitCode: 0),
            'type' => $this->builtinType($args),
            'command' => $this->builtinCommand($args, $stdin),
            'alias' => $this->builtinAlias($args),
            'unalias' => $this->builtinUnalias($args),
            'hash' => new ExecResult(exitCode: 0),
            'readonly' => $this->builtinReadonly($args),
            'trap' => $this->builtinTrap($args),
            'builtin' => $this->builtinBuiltin($args, $stdin),
            'exec' => $this->builtinExec($args, $stdin),
            'pushd' => $this->builtinPushd($args),
            'popd' => $this->builtinPopd(),
            'dirs' => $this->builtinDirs($args),
            'caller' => $this->builtinCaller($args),
            'help' => $this->builtinHelp($args),
            'enable' => $this->builtinEnable($args),
            'wait', 'disown', 'complete', 'compopt' => new ExecResult(exitCode: 0),
            'jobs' => new ExecResult(exitCode: 0),
            'fg' => new ExecResult(stderr: "bash: fg: no job control\n", exitCode: 1),
            'bg' => new ExecResult(stderr: "bash: bg: no job control\n", exitCode: 1),
            'kill' => $this->builtinKill($args),
            'suspend' => new ExecResult(stderr: "bash: suspend: cannot suspend\n", exitCode: 1),
            'logout' => $this->builtinExit($args),
            'times' => new ExecResult(stdout: "0m0.000s 0m0.000s\n0m0.000s 0m0.000s\n", exitCode: 0),
            'ulimit' => $this->builtinUlimit($args),
            'umask' => $this->builtinUmask($args),
            'compgen' => new ExecResult(exitCode: 1),
            default => null,
        };
    }

    /** @param array<int, string> $args */
    private function builtinExit(array $args): ExecResult
    {
        $code = $args !== [] ? (int) $args[0] : $this->interpreterState->lastExitCode;

        throw new ExitException($code);
    }

    /** @param array<int, string> $args */
    private function builtinExport(array $args): ExecResult
    {
        foreach ($args as $arg) {
            if (str_contains((string) $arg, '=')) {
                [$name, $value] = explode('=', (string) $arg, 2);
                // Strip -n flag
                $name = ltrim($name, '-');

                if (str_starts_with($name, 'n')) {
                    $name = substr($name, 1);
                    unset($this->interpreterState->exportedVars[$name]);

                    continue;
                }

                if ($this->interpreterState->isReadonly($name)) {
                    $this->writeStderr("bash: {$name}: readonly variable\n");

                    return new ExecResult(exitCode: 1);
                }

                $this->interpreterState->setVar($name, $value);
                $this->interpreterState->exportedVars[$name] = $value;
            } else {
                $name = ltrim((string) $arg, '-');
                $val = $this->interpreterState->getVar($name) ?? '';
                $this->interpreterState->exportedVars[$name] = $val;
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinUnset(array $args): ExecResult
    {
        foreach ($args as $arg) {
            if ($arg === '-v') {
                continue;
            }

            if ($arg === '-f') {
                continue;
            }

            if ($this->interpreterState->isReadonly($arg)) {
                $this->writeStderr("bash: unset: {$arg}: cannot unset: readonly variable\n");

                return new ExecResult(exitCode: 1);
            }

            if (preg_match('/^([a-zA-Z_]\w*)\[(.+)\]$/', $arg, $matches) === 1) {
                $name = $matches[1];
                $key = $this->normalizeArrayKey($matches[2]);
                unset($this->interpreterState->arrays[$name][$key]);

                continue;
            }

            $this->interpreterState->unsetVar($arg);
            unset($this->interpreterState->arrays[$arg]);
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinLocal(array $args): ExecResult
    {
        foreach ($args as $arg) {
            if (str_contains((string) $arg, '=')) {
                [$name, $value] = explode('=', (string) $arg, 2);

                if ($this->interpreterState->isReadonly($name)) {
                    $this->writeStderr("bash: local: {$name}: readonly variable\n");

                    return new ExecResult(exitCode: 1);
                }

                $this->interpreterState->declareLocal($name, $value);
            } else {
                $this->interpreterState->declareLocal($arg, '');
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinSet(array $args): ExecResult
    {
        if ($args === []) {
            $output = '';

            foreach ($this->interpreterState->env as $name => $value) {
                $output .= "{$name}='{$value}'\n";
            }

            return new ExecResult(stdout: $output, exitCode: 0);
        }

        $i = 0;

        while ($i < count($args)) {
            $arg = $args[$i];

            if ($arg === '--') {
                $this->interpreterState->positionalParams = array_slice($args, $i + 1);

                break;
            }

            if (str_starts_with((string) $arg, '-o')) {
                $opt = $args[++$i] ?? '';
                $this->interpreterState->shellOpts[$opt] = true;
            } elseif (str_starts_with((string) $arg, '+o')) {
                $opt = $args[++$i] ?? '';
                $this->interpreterState->shellOpts[$opt] = false;
            } elseif (str_starts_with((string) $arg, '-')) {
                $flags = substr((string) $arg, 1);

                for ($j = 0; $j < strlen($flags); $j++) {
                    $flag = $flags[$j];
                    match ($flag) {
                        'e' => $this->interpreterState->shellOpts['errexit'] = true,
                        'u' => $this->interpreterState->shellOpts['nounset'] = true,
                        'x' => $this->interpreterState->shellOpts['xtrace'] = true,
                        'v' => $this->interpreterState->shellOpts['verbose'] = true,
                        'f' => $this->interpreterState->shellOpts['noglob'] = true,
                        'C' => $this->interpreterState->shellOpts['noclobber'] = true,
                        default => null,
                    };
                }
            } elseif (str_starts_with((string) $arg, '+')) {
                $flags = substr((string) $arg, 1);

                for ($j = 0; $j < strlen($flags); $j++) {
                    $flag = $flags[$j];
                    match ($flag) {
                        'e' => $this->interpreterState->shellOpts['errexit'] = false,
                        'u' => $this->interpreterState->shellOpts['nounset'] = false,
                        'x' => $this->interpreterState->shellOpts['xtrace'] = false,
                        'v' => $this->interpreterState->shellOpts['verbose'] = false,
                        'f' => $this->interpreterState->shellOpts['noglob'] = false,
                        'C' => $this->interpreterState->shellOpts['noclobber'] = false,
                        default => null,
                    };
                }
            } else {
                $this->interpreterState->positionalParams = array_slice($args, $i);

                break;
            }

            $i++;
        }

        return new ExecResult(exitCode: 0);
    }

    private function builtinShopt(): ExecResult
    {
        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinCd(array $args): ExecResult
    {
        $target = $args[0] ?? $this->interpreterState->getVar('HOME') ?? '/';

        if ($target === '-') {
            $target = $this->interpreterState->getVar('OLDPWD') ?? $this->interpreterState->cwd;
        }

        if (! str_starts_with((string) $target, '/')) {
            $target = $this->fileSystem->resolvePath($this->interpreterState->cwd, $target);
        }

        try {
            $stat = $this->fileSystem->stat($target);
        } catch (RuntimeException) {
            return new ExecResult(stderr: "bash: cd: {$target}: No such file or directory\n", exitCode: 1);
        }

        if (! $stat->isDirectory) {
            return new ExecResult(stderr: "bash: cd: {$target}: Not a directory\n", exitCode: 1);
        }

        $this->interpreterState->setVar('OLDPWD', $this->interpreterState->cwd);
        $this->interpreterState->cwd = $target;
        $this->interpreterState->setVar('PWD', $target);

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinSource(array $args): ExecResult
    {
        if ($args === []) {
            return new ExecResult(stderr: "bash: source: filename argument required\n", exitCode: 2);
        }

        $path = $args[0];

        if (! str_starts_with((string) $path, '/')) {
            $path = $this->fileSystem->resolvePath($this->interpreterState->cwd, $path);
        }

        try {
            $content = $this->fileSystem->readFile($path);
        } catch (RuntimeException) {
            return new ExecResult(stderr: "bash: {$args[0]}: No such file or directory\n", exitCode: 1);
        }

        return $this->execSubcommand($content);
    }

    /** @param array<int, string> $args */
    private function builtinEval(array $args): ExecResult
    {
        $script = implode(' ', $args);

        return $this->execSubcommand($script);
    }

    /** @param array<int, string> $args */
    private function builtinDeclare(array $args): ExecResult
    {
        $isArray = false;
        $isAssoc = false;
        $isExport = false;
        $isReadonly = false;

        $vars = [];

        foreach ($args as $arg) {
            if (str_starts_with((string) $arg, '-')) {
                $flags = substr((string) $arg, 1);

                if (str_contains($flags, 'a')) {
                    $isArray = true;
                }

                if (str_contains($flags, 'A')) {
                    $isAssoc = true;
                }

                if (str_contains($flags, 'x')) {
                    $isExport = true;
                }

                if (str_contains($flags, 'r')) {
                    $isReadonly = true;
                }
            } else {
                $vars[] = $arg;
            }
        }

        foreach ($vars as $var) {
            if (str_contains((string) $var, '=')) {
                [$name, $value] = explode('=', (string) $var, 2);

                if ($this->interpreterState->isReadonly($name)) {
                    $this->writeStderr("bash: declare: {$name}: readonly variable\n");

                    return new ExecResult(exitCode: 1);
                }

                if ($isArray || $isAssoc) {
                    $this->interpreterState->arrays[$name] = [];
                }

                $this->interpreterState->setVar($name, $value);

                if ($isExport) {
                    $this->interpreterState->exportedVars[$name] = $value;
                }

                if ($isReadonly) {
                    $this->interpreterState->markReadonly($name);
                }
            } else {
                if ($isArray || $isAssoc) {
                    $this->interpreterState->arrays[$var] = [];
                }

                $this->interpreterState->setVar($var, '');

                if ($isReadonly) {
                    $this->interpreterState->markReadonly($var);
                }
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinRead(array $args, string $stdin): ExecResult
    {
        $prompt = '';
        $delimiter = "\n";
        $varNames = [];
        $raw = false;
        $isArray = false;

        $i = 0;

        while ($i < count($args)) {
            $arg = $args[$i];

            if ($arg === '-r') {
                $raw = true;
            } elseif ($arg === '-p') {
                $i++;
                $prompt = $args[$i] ?? '';
            } elseif ($arg === '-d') {
                $i++;
                $delimiter = $args[$i] ?? "\n";
            } elseif ($arg === '-a') {
                $isArray = true;
                $i++;
                $varNames[] = $args[$i] ?? 'REPLY';
            } elseif (! str_starts_with((string) $arg, '-')) {
                $varNames[] = $arg;
            }

            $i++;
        }

        if ($varNames === []) {
            $varNames = ['REPLY'];
        }

        // Read one line from stdin
        $line = '';
        $newlinePos = strpos($stdin, (string) $delimiter);
        $line = $newlinePos !== false ? substr($stdin, 0, $newlinePos) : $stdin;

        if ($isArray && count($varNames) === 1) {
            $ifs = $this->interpreterState->getVar('IFS') ?? " \t\n";
            $parts = $this->splitByIFS($line, $ifs);
            $this->interpreterState->arrays[$varNames[0]] = array_combine(
                array_keys($parts),
                $parts,
            );

            return new ExecResult(exitCode: $stdin === '' ? 1 : 0);
        }

        // Split line by IFS into variables
        $ifs = $this->interpreterState->getVar('IFS') ?? " \t\n";
        $parts = $this->splitByIFS($line, $ifs);
        $counter = count($varNames);

        for ($j = 0; $j < $counter; $j++) {
            if ($j < count($varNames) - 1) {
                $this->interpreterState->setVar($varNames[$j], $parts[$j] ?? '');
            } else {
                // Last variable gets the rest
                $this->interpreterState->setVar($varNames[$j], implode(' ', array_slice($parts, $j)));
            }
        }

        return new ExecResult(exitCode: $stdin === '' ? 1 : 0);
    }

    /** @param array<int, string> $args */
    private function builtinBreak(array $args): ExecResult
    {
        $levels = $args !== [] ? max(1, (int) $args[0]) : 1;

        throw new BreakException($levels);
    }

    /** @param array<int, string> $args */
    private function builtinContinue(array $args): ExecResult
    {
        $levels = $args !== [] ? max(1, (int) $args[0]) : 1;

        throw new ContinueException($levels);
    }

    /** @param array<int, string> $args */
    private function builtinReturn(array $args): ExecResult
    {
        $code = $args !== [] ? (int) $args[0] : $this->interpreterState->lastExitCode;

        throw new ReturnException($code);
    }

    /** @param array<int, string> $args */
    private function builtinShift(array $args): ExecResult
    {
        $n = $args !== [] ? (int) $args[0] : 1;

        if ($n > count($this->interpreterState->positionalParams)) {
            return new ExecResult(exitCode: 1);
        }

        $this->interpreterState->positionalParams = array_slice($this->interpreterState->positionalParams, $n);

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinLet(array $args): ExecResult
    {
        $lastResult = 0;

        foreach ($args as $arg) {
            $lastResult = $this->evaluateArithmeticString($arg);
        }

        return new ExecResult(exitCode: $lastResult === 0 ? 1 : 0);
    }

    private function builtinGetopts(): ExecResult
    {
        return new ExecResult(exitCode: 1);
    }

    /** @param array<int, string> $args */
    private function builtinMapfile(array $args, string $stdin): ExecResult
    {
        $varName = 'MAPFILE';
        $delimiter = "\n";

        $remaining = [];
        $i = 0;

        while ($i < count($args)) {
            if ($args[$i] !== '-t' && $args[$i] === '-d') {
                $i++;
                $delimiter = $args[$i] ?? "\n";
            } elseif (! str_starts_with($args[$i], '-')) {
                $remaining[] = $args[$i];
            }

            $i++;
        }

        if ($remaining !== []) {
            $varName = $remaining[0];
        }

        if ($delimiter === '') {
            $delimiter = "\n";
        }

        $lines = $stdin !== '' ? explode($delimiter, $stdin) : [];

        // Remove trailing empty element from explode
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $this->interpreterState->arrays[$varName] = array_combine(
            array_keys($lines),
            $lines,
        );

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinType(array $args): ExecResult
    {
        $output = '';

        foreach ($args as $arg) {
            if (isset($this->interpreterState->functions[$arg])) {
                $output .= $arg.' is a function
';
            } elseif ($this->isBuiltin($arg)) {
                $output .= $arg.' is a shell builtin
';
            } elseif ($this->commandRegistry->has($arg)) {
                $output .= sprintf('%s is /usr/bin/%s%s', $arg, $arg, PHP_EOL);
            } else {
                $output .= "bash: type: {$arg}: not found\n";

                return new ExecResult(stdout: $output, exitCode: 1);
            }
        }

        return new ExecResult(stdout: $output, exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinCommand(array $args, string $stdin): ExecResult
    {
        if ($args === []) {
            return new ExecResult(exitCode: 0);
        }

        if ($args[0] === '-v') {
            $name = $args[1] ?? '';

            if ($this->isBuiltin($name) || $this->commandRegistry->has($name)) {
                return new ExecResult(stdout: $name.PHP_EOL, exitCode: 0);
            }

            return new ExecResult(exitCode: 1);
        }

        // Execute command bypassing functions
        $commandName = $args[0];
        $commandArgs = array_slice($args, 1);

        $builtinResult = $this->tryBuiltin($commandName, $commandArgs, $stdin);

        if ($builtinResult instanceof \BashBox\ExecResult) {
            return $builtinResult;
        }

        $cmd = $this->commandRegistry->get($commandName);

        if ($cmd instanceof \BashBox\Commands\CommandInterface) {
            $commandContext = new CommandContext(
                fs: $this->fileSystem,
                cwd: $this->interpreterState->cwd,
                env: $this->interpreterState->getExportedEnv(),
                stdin: $stdin,
                limits: $this->interpreterState->limits,
                exec: fn (string $script): ExecResult => $this->execSubcommand($script),
                fetch: $this->secureHttpClient,
                registry: $this->commandRegistry,
            );

            return $cmd->execute($commandArgs, $commandContext);
        }

        return new ExecResult(stderr: "bash: {$commandName}: command not found\n", exitCode: 127);
    }

    /** @param array<int, string> $args */
    private function builtinAlias(array $args): ExecResult
    {
        if ($args === []) {
            $output = '';

            foreach ($this->interpreterState->aliases as $name => $value) {
                $output .= "alias {$name}='{$value}'\n";
            }

            return new ExecResult(stdout: $output, exitCode: 0);
        }

        foreach ($args as $arg) {
            if (str_contains((string) $arg, '=')) {
                [$name, $value] = explode('=', (string) $arg, 2);
                $this->interpreterState->aliases[$name] = $value;
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinUnalias(array $args): ExecResult
    {
        foreach ($args as $arg) {
            if ($arg === '-a') {
                $this->interpreterState->aliases = [];

                return new ExecResult(exitCode: 0);
            }

            unset($this->interpreterState->aliases[$arg]);
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinReadonly(array $args): ExecResult
    {
        if ($args === [] || ($args === ['-p'])) {
            $output = '';

            foreach (array_keys($this->interpreterState->readonlyVars) as $name) {
                $val = $this->interpreterState->getVar($name) ?? '';
                $output .= "declare -r {$name}=\"{$val}\"\n";
            }

            return new ExecResult(stdout: $output, exitCode: 0);
        }

        foreach ($args as $arg) {
            if ($arg === '-p') {
                continue;
            }

            if (str_contains((string) $arg, '=')) {
                [$name, $value] = explode('=', (string) $arg, 2);

                if ($this->interpreterState->isReadonly($name)) {
                    $this->writeStderr("bash: readonly: {$name}: readonly variable\n");

                    return new ExecResult(exitCode: 1);
                }

                $this->interpreterState->setVar($name, $value);
                $this->interpreterState->markReadonly($name);
            } else {
                $this->interpreterState->markReadonly($arg);
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinTrap(array $args): ExecResult
    {
        if ($args === []) {
            $output = '';

            foreach ($this->interpreterState->traps as $signal => $command) {
                $output .= sprintf("trap -- '%s' %s%s", $command, $signal, PHP_EOL);
            }

            return new ExecResult(stdout: $output, exitCode: 0);
        }

        $command = $args[0];
        $signals = array_slice($args, 1);

        if ($signals === []) {
            return new ExecResult(exitCode: 0);
        }

        foreach ($signals as $signal) {
            $signal = strtoupper($signal);

            if ($command === '-') {
                unset($this->interpreterState->traps[$signal]);
            } else {
                $this->interpreterState->traps[$signal] = $command;
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinBuiltin(array $args, string $stdin): ExecResult
    {
        if ($args === []) {
            return new ExecResult(exitCode: 0);
        }

        $name = $args[0];
        $builtinArgs = array_slice($args, 1);

        // Temporarily remove disabled check for this call
        $result = match ($name) {
            'exit' => $this->builtinExit($builtinArgs),
            'export' => $this->builtinExport($builtinArgs),
            'unset' => $this->builtinUnset($builtinArgs),
            'local' => $this->builtinLocal($builtinArgs),
            'set' => $this->builtinSet($builtinArgs),
            'shopt' => $this->builtinShopt(),
            'cd' => $this->builtinCd($builtinArgs),
            'source', '.' => $this->builtinSource($builtinArgs),
            'eval' => $this->builtinEval($builtinArgs),
            'declare', 'typeset' => $this->builtinDeclare($builtinArgs),
            'read' => $this->builtinRead($builtinArgs, $stdin),
            'break' => $this->builtinBreak($builtinArgs),
            'continue' => $this->builtinContinue($builtinArgs),
            'return' => $this->builtinReturn($builtinArgs),
            'shift' => $this->builtinShift($builtinArgs),
            'let' => $this->builtinLet($builtinArgs),
            'readonly' => $this->builtinReadonly($builtinArgs),
            'trap' => $this->builtinTrap($builtinArgs),
            'echo' => null,
            default => null,
        };

        if (! $result instanceof \BashBox\ExecResult) {
            if ($this->isBuiltin($name)) {
                return $this->tryBuiltin($name, $builtinArgs, $stdin) ?? new ExecResult(stderr: "bash: builtin: {$name}: not a shell builtin\n", exitCode: 1);
            }

            return new ExecResult(stderr: "bash: builtin: {$name}: not a shell builtin\n", exitCode: 1);
        }

        return $result;
    }

    /** @param array<int, string> $args */
    private function builtinExec(array $args, string $stdin): ExecResult
    {
        if ($args === []) {
            return new ExecResult(exitCode: 0);
        }

        $commandName = $args[0];
        $commandArgs = array_slice($args, 1);

        $builtinResult = $this->tryBuiltin($commandName, $commandArgs, $stdin);

        if ($builtinResult instanceof \BashBox\ExecResult) {
            $this->writeStdout($builtinResult->stdout);

            if ($builtinResult->stderr !== '') {
                $this->writeStderr($builtinResult->stderr);
            }

            throw new ExitException($builtinResult->exitCode);
        }

        if (isset($this->interpreterState->functions[$commandName])) {
            $result = $this->executeFunction($commandName, $commandArgs, $stdin);
            $this->writeStdout($result->stdout);

            if ($result->stderr !== '') {
                $this->writeStderr($result->stderr);
            }

            throw new ExitException($result->exitCode);
        }

        $cmd = $this->commandRegistry->get($commandName);

        if ($cmd instanceof \BashBox\Commands\CommandInterface) {
            $commandContext = new CommandContext(
                fs: $this->fileSystem,
                cwd: $this->interpreterState->cwd,
                env: $this->interpreterState->getExportedEnv(),
                stdin: $stdin,
                limits: $this->interpreterState->limits,
                exec: fn (string $script): ExecResult => $this->execSubcommand($script),
                fetch: $this->secureHttpClient,
                registry: $this->commandRegistry,
            );
            $result = $cmd->execute($commandArgs, $commandContext);
            $this->writeStdout($result->stdout);

            if ($result->stderr !== '') {
                $this->writeStderr($result->stderr);
            }

            throw new ExitException($result->exitCode);
        }

        $this->writeStderr("bash: exec: {$commandName}: not found\n");

        throw new ExitException(127);
    }

    /** @param array<int, string> $args */
    private function builtinPushd(array $args): ExecResult
    {
        $hasArg = isset($args[0]);

        if (! $hasArg) {
            if ($this->interpreterState->directoryStack === []) {
                return new ExecResult(stderr: "bash: pushd: no other directory\n", exitCode: 1);
            }

            $lastIdx = count($this->interpreterState->directoryStack) - 1;
            $dir = $this->interpreterState->directoryStack[$lastIdx];
            $this->interpreterState->directoryStack[$lastIdx] = $this->interpreterState->cwd;
        } else {
            $dir = $args[0];
            $this->interpreterState->directoryStack = [...$this->interpreterState->directoryStack, $this->interpreterState->cwd];
        }

        $execResult = $this->builtinCd([$dir]);

        if ($execResult->exitCode !== 0) {
            if ($hasArg) {
                array_pop($this->interpreterState->directoryStack);
            }

            return $execResult;
        }

        $stack = $this->interpreterState->cwd;

        foreach (array_reverse($this->interpreterState->directoryStack) as $d) {
            $stack .= ' '.$d;
        }

        return new ExecResult(stdout: $stack."\n", exitCode: 0);
    }

    private function builtinPopd(): ExecResult
    {
        if ($this->interpreterState->directoryStack === []) {
            return new ExecResult(stderr: "bash: popd: directory stack empty\n", exitCode: 1);
        }

        $dir = array_pop($this->interpreterState->directoryStack);
        $this->builtinCd([$dir]);
        $stack = $this->interpreterState->cwd;

        foreach (array_reverse($this->interpreterState->directoryStack) as $d) {
            $stack .= ' '.$d;
        }

        return new ExecResult(stdout: $stack."\n", exitCode: 0);
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
        $depth = $args !== [] ? (int) $args[0] : 0;

        if ($depth >= count($this->interpreterState->callStack)) {
            return new ExecResult(exitCode: 1);
        }

        $index = count($this->interpreterState->callStack) - 1 - $depth;
        $frame = $this->interpreterState->callStack[$index];

        return new ExecResult(stdout: sprintf('%s %s %s%s', $frame['line'], $frame['function'], $frame['file'], PHP_EOL), exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinHelp(array $args): ExecResult
    {
        $builtins = [
            ':' => 'Null command.',
            '.' => 'Execute commands from a file in the current shell.',
            'alias' => 'Define or display aliases.',
            'bg' => 'Move jobs to the background.',
            'break' => 'Exit for, while, or until loops.',
            'builtin' => 'Execute shell builtins.',
            'caller' => 'Return the context of the current subroutine call.',
            'cd' => 'Change the shell working directory.',
            'command' => 'Execute a simple command or display information about commands.',
            'compgen' => 'Display possible completions depending on the options.',
            'complete' => 'Specify how arguments are to be completed.',
            'compopt' => 'Modify or display completion options.',
            'continue' => 'Resume for, while, or until loops.',
            'declare' => 'Set variable values and attributes.',
            'dirs' => 'Display directory stack.',
            'disown' => 'Remove jobs from current shell.',
            'echo' => 'Write arguments to the standard output.',
            'enable' => 'Enable and disable shell builtins.',
            'eval' => 'Execute arguments as a shell command.',
            'exec' => 'Replace the shell with the given command.',
            'exit' => 'Exit the shell.',
            'export' => 'Set export attribute for shell variables.',
            'fg' => 'Move job to the foreground.',
            'getopts' => 'Parse option arguments.',
            'hash' => 'Remember or display program locations.',
            'help' => 'Display information about builtin commands.',
            'jobs' => 'Display status of jobs.',
            'kill' => 'Send a signal to a job.',
            'let' => 'Evaluate arithmetic expressions.',
            'local' => 'Define local variables.',
            'logout' => 'Exit a login shell.',
            'mapfile' => 'Read lines from the standard input into an indexed array variable.',
            'popd' => 'Remove directories from stack.',
            'pushd' => 'Add directories to stack.',
            'read' => 'Read a line from the standard input.',
            'readarray' => 'Read lines from a file into an array variable.',
            'readonly' => 'Mark shell variables as unchangeable.',
            'return' => 'Return from a shell function.',
            'set' => 'Set or unset values of shell options and positional parameters.',
            'shift' => 'Shift positional parameters.',
            'shopt' => 'Set and unset shell options.',
            'source' => 'Execute commands from a file in the current shell.',
            'suspend' => 'Suspend shell execution.',
            'times' => 'Display process times.',
            'trap' => 'Trap signals and other events.',
            'type' => 'Display information about command type.',
            'typeset' => 'Set variable values and attributes.',
            'ulimit' => 'Modify shell resource limits.',
            'umask' => 'Display or set file mode mask.',
            'unalias' => 'Remove alias definitions.',
            'unset' => 'Unset values and attributes of shell variables and functions.',
            'wait' => 'Wait for job completion and return exit status.',
        ];

        if ($args === []) {
            $output = "Shell builtin commands:\n\n";

            foreach ($builtins as $name => $desc) {
                $output .= sprintf(" %-16s %s\n", $name, $desc);
            }

            return new ExecResult(stdout: $output, exitCode: 0);
        }

        $pattern = $args[0];
        $output = '';
        $found = false;

        foreach ($builtins as $name => $desc) {
            if (fnmatch($pattern, $name)) {
                $output .= sprintf('%s: %s%s', $name, $desc, PHP_EOL);
                $found = true;
            }
        }

        return new ExecResult(stdout: $output, exitCode: $found ? 0 : 1);
    }

    /** @param array<int, string> $args */
    private function builtinEnable(array $args): ExecResult
    {
        if ($args === []) {
            return new ExecResult(exitCode: 0);
        }

        $disable = false;
        $names = [];

        foreach ($args as $arg) {
            if ($arg === '-n') {
                $disable = true;
            } elseif (! str_starts_with((string) $arg, '-')) {
                $names[] = $arg;
            }
        }

        foreach ($names as $name) {
            if ($disable) {
                $this->interpreterState->disabledBuiltins[$name] = true;
            } else {
                unset($this->interpreterState->disabledBuiltins[$name]);
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinKill(array $args): ExecResult
    {
        if ($args !== [] && $args[0] === '-l') {
            $signals = "HUP INT QUIT ILL TRAP ABRT BUS FPE KILL USR1 SEGV USR2 PIPE ALRM TERM\n";

            return new ExecResult(stdout: $signals, exitCode: 0);
        }

        return new ExecResult(stderr: "bash: kill: No such process\n", exitCode: 1);
    }

    /** @param array<int, string> $args */
    private function builtinUlimit(array $args): ExecResult
    {
        if ($args === [] || in_array('-a', $args, true)) {
            return new ExecResult(stdout: "unlimited\n", exitCode: 0);
        }

        // Any query flag returns unlimited
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                return new ExecResult(stdout: "unlimited\n", exitCode: 0);
            }
        }

        return new ExecResult(exitCode: 0);
    }

    /** @param array<int, string> $args */
    private function builtinUmask(array $args): ExecResult
    {
        if ($args === []) {
            return new ExecResult(stdout: $this->interpreterState->umask."\n", exitCode: 0);
        }

        $this->interpreterState->umask = $args[0];

        return new ExecResult(exitCode: 0);
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

    private function executeIf(IfNode $ifNode, string $stdin): ExecResult
    {
        foreach ($ifNode->clauses as $clause) {
            $condResult = $this->executeStatementList($clause->condition, $stdin);

            if ($condResult === 0) {
                return $this->executeStatementListResult($clause->body, $stdin);
            }
        }

        if ($ifNode->elseBody !== null) {
            return $this->executeStatementListResult($ifNode->elseBody, $stdin);
        }

        return new ExecResult(exitCode: 0);
    }

    private function executeFor(ForNode $forNode, string $stdin): ExecResult
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
            if (++$iterations > $this->interpreterState->limits->maxLoopIterations) {
                throw new ExecutionLimitException('Loop iteration limit exceeded');
            }

            $this->interpreterState->setVar($forNode->variable, $word);

            try {
                $exitCode = $this->executeStatementList($forNode->body, $stdin);
            } catch (BreakException $e) {
                if ($e->levels > 1) {
                    throw new BreakException($e->levels - 1);
                }

                break;
            } catch (ContinueException $e) {
                if ($e->levels > 1) {
                    throw new ContinueException($e->levels - 1);
                }
            }
        }

        return new ExecResult(exitCode: $exitCode);
    }

    private function executeCStyleFor(CStyleForNode $cStyleForNode, string $stdin): ExecResult
    {
        if ($cStyleForNode->init instanceof \BashBox\Ast\ArithmeticExpressionNode) {
            $this->evaluateArithmeticExpression($cStyleForNode->init);
        }

        $exitCode = 0;
        $iterations = 0;

        while (true) {
            if (++$iterations > $this->interpreterState->limits->maxLoopIterations) {
                throw new ExecutionLimitException('Loop iteration limit exceeded');
            }

            if ($cStyleForNode->condition instanceof \BashBox\Ast\ArithmeticExpressionNode) {
                $condResult = $this->evaluateArithmeticExpression($cStyleForNode->condition);

                if ($condResult === 0) {
                    break;
                }
            }

            try {
                $exitCode = $this->executeStatementList($cStyleForNode->body, $stdin);
            } catch (BreakException $e) {
                if ($e->levels > 1) {
                    throw new BreakException($e->levels - 1);
                }

                break;
            } catch (ContinueException $e) {
                if ($e->levels > 1) {
                    throw new ContinueException($e->levels - 1);
                }
            }

            if ($cStyleForNode->update instanceof \BashBox\Ast\ArithmeticExpressionNode) {
                $this->evaluateArithmeticExpression($cStyleForNode->update);
            }
        }

        return new ExecResult(exitCode: $exitCode);
    }

    private function executeWhile(WhileNode $whileNode, string $stdin): ExecResult
    {
        $exitCode = 0;
        $iterations = 0;

        while (true) {
            if (++$iterations > $this->interpreterState->limits->maxLoopIterations) {
                throw new ExecutionLimitException('Loop iteration limit exceeded');
            }

            $condResult = $this->executeStatementList($whileNode->condition, $stdin);

            if ($condResult !== 0) {
                break;
            }

            try {
                $exitCode = $this->executeStatementList($whileNode->body, $stdin);
            } catch (BreakException $e) {
                if ($e->levels > 1) {
                    throw new BreakException($e->levels - 1);
                }

                break;
            } catch (ContinueException $e) {
                if ($e->levels > 1) {
                    throw new ContinueException($e->levels - 1);
                }
            }
        }

        return new ExecResult(exitCode: $exitCode);
    }

    private function executeUntil(UntilNode $untilNode, string $stdin): ExecResult
    {
        $exitCode = 0;
        $iterations = 0;

        while (true) {
            if (++$iterations > $this->interpreterState->limits->maxLoopIterations) {
                throw new ExecutionLimitException('Loop iteration limit exceeded');
            }

            $condResult = $this->executeStatementList($untilNode->condition, $stdin);

            if ($condResult === 0) {
                break;
            }

            try {
                $exitCode = $this->executeStatementList($untilNode->body, $stdin);
            } catch (BreakException $e) {
                if ($e->levels > 1) {
                    throw new BreakException($e->levels - 1);
                }

                break;
            } catch (ContinueException $e) {
                if ($e->levels > 1) {
                    throw new ContinueException($e->levels - 1);
                }
            }
        }

        return new ExecResult(exitCode: $exitCode);
    }

    private function executeCase(CaseNode $caseNode, string $stdin): ExecResult
    {
        $word = $this->expandWord($caseNode->word);

        foreach ($caseNode->items as $item) {
            foreach ($item->patterns as $pattern) {
                $patternStr = $this->expandWord($pattern);

                if ($this->matchPattern($word, $patternStr)) {
                    $result = $this->executeStatementListResult($item->body, $stdin);

                    if ($item->terminator === ';;') {
                        return $result;
                    }

                    if ($item->terminator === ';&') {
                        // Fall through to next
                        break;
                    }

                    if ($item->terminator === ';;&') {
                        // Continue testing patterns
                        break;
                    }
                }
            }
        }

        return new ExecResult(exitCode: 0);
    }

    private function executeSubshell(SubshellNode $subshellNode, string $stdin): ExecResult
    {
        // Subshell: execute in a copy of the state
        $savedEnv = $this->interpreterState->env;
        $savedCwd = $this->interpreterState->cwd;

        $execResult = $this->executeStatementListResult($subshellNode->body, $stdin);

        // Restore state (subshell changes don't persist)
        $this->interpreterState->env = $savedEnv;
        $this->interpreterState->cwd = $savedCwd;

        return $execResult;
    }

    private function executeGroup(GroupNode $groupNode, string $stdin): ExecResult
    {
        return $this->executeStatementListResult($groupNode->body, $stdin);
    }

    private function executeArithmeticCommand(ArithmeticCommandNode $arithmeticCommandNode): ExecResult
    {
        $result = $this->evaluateArithmeticExpression($arithmeticCommandNode->expression);

        return new ExecResult(exitCode: $result !== 0 ? 0 : 1);
    }

    private function executeConditionalCommand(ConditionalCommandNode $conditionalCommandNode): ExecResult
    {
        $result = $this->evaluateConditional($conditionalCommandNode->expression);

        return new ExecResult(exitCode: $result ? 0 : 1);
    }

    private function executeFunctionDef(FunctionDefNode $functionDefNode): ExecResult
    {
        $this->interpreterState->functions[$functionDefNode->name] = [
            'body' => $functionDefNode->body,
            'sourceFile' => $functionDefNode->sourceFile,
        ];

        return new ExecResult(exitCode: 0);
    }

    /** @param list<string> $args */
    private function executeFunction(string $name, array $args, string $stdin): ExecResult
    {
        $func = $this->interpreterState->functions[$name];
        $savedParams = $this->interpreterState->positionalParams;
        $this->interpreterState->positionalParams = $args;
        $this->interpreterState->pushLocalScope();
        $this->interpreterState->callDepth++;
        $this->interpreterState->callStack[] = ['line' => 0, 'function' => $name, 'file' => $func['sourceFile'] ?? 'main'];

        if ($this->interpreterState->callDepth > $this->interpreterState->limits->maxCallDepth) {
            $this->interpreterState->callDepth--;
            $this->interpreterState->popLocalScope();
            $this->interpreterState->positionalParams = $savedParams;
            array_pop($this->interpreterState->callStack);

            throw new ExecutionLimitException('Call depth limit exceeded');
        }

        try {
            $result = $this->executeCommand($func['body'], $stdin);
        } catch (ReturnException $returnException) {
            $result = new ExecResult(exitCode: $returnException->exitCode);
        } finally {
            if (isset($this->interpreterState->traps['RETURN']) && $this->interpreterState->traps['RETURN'] !== '') {
                $returnTrap = $this->interpreterState->traps['RETURN'];
                unset($this->interpreterState->traps['RETURN']);

                try {
                    $trapResult = $this->execSubcommand($returnTrap);
                    $this->writeStdout($trapResult->stdout);

                    if ($trapResult->stderr !== '') {
                        $this->writeStderr($trapResult->stderr);
                    }
                } catch (ExitException|ErrexitException) {
                    // Ignore
                }

                $this->interpreterState->traps['RETURN'] = $returnTrap;
            }

            $this->interpreterState->callDepth--;
            $this->interpreterState->popLocalScope();
            $this->interpreterState->positionalParams = $savedParams;
            array_pop($this->interpreterState->callStack);
        }

        return $result;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * @param  list<StatementNode>  $statements
     */
    private function executeStatementList(array $statements, string $stdin): int
    {
        $exitCode = 0;

        foreach ($statements as $statement) {
            $exitCode = $this->executeStatement($statement, $stdin);
        }

        return $exitCode;
    }

    /**
     * @param  list<StatementNode>  $statements
     */
    private function executeStatementListResult(array $statements, string $stdin): ExecResult
    {
        $exitCode = 0;
        $stdout = '';
        $stderr = '';

        $savedStdout = $this->stdout;
        $savedStderr = $this->stderr;
        $this->stdout = '';
        $this->stderr = '';

        foreach ($statements as $statement) {
            $exitCode = $this->executeStatement($statement, $stdin);
        }

        $stdout = $this->stdout;
        $stderr = $this->stderr;
        $this->stdout = $savedStdout;
        $this->stderr = $savedStderr;

        return new ExecResult(stdout: $stdout, stderr: $stderr, exitCode: $exitCode);
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

    public function execSubcommand(string $script): ExecResult
    {
        $parser = new \BashBox\Parser\Parser;
        $scriptNode = $parser->parse($script);

        return $this->executeScript($scriptNode);
    }

    public function writeStdout(string $data): void
    {
        $this->stdout .= $data;

        if (strlen($this->stdout) > $this->interpreterState->limits->maxOutputSize) {
            throw new ExecutionLimitException('Output size limit exceeded');
        }
    }

    public function writeStderr(string $data): void
    {
        $this->stderr .= $data;
    }

    /**
     * @return array{stdin: ?string, stdout: ?string, append: bool, allowClobber: bool}
     */
    private function processRedirection(RedirectionNode $redirectionNode): array
    {
        $result = ['stdin' => null, 'stdout' => null, 'append' => false, 'allowClobber' => false];
        $op = $redirectionNode->operator;

        // Here-document
        if ($redirectionNode->target instanceof HereDocNode) {
            $content = $this->rawWordValue($redirectionNode->target->content);

            if (! $redirectionNode->target->quoted) {
                $content = $this->expandWord($redirectionNode->target->content);
            }

            if ($redirectionNode->target->stripTabs) {
                $content = preg_replace('/^\t/m', '', $content) ?? $content;
            }

            $result['stdin'] = $content;

            return $result;
        }

        $target = $this->expandWord($redirectionNode->target);

        // Here-string
        if ($op === '<<<') {
            $result['stdin'] = $target."\n";

            return $result;
        }

        // Input redirect
        if ($op === '<') {
            $path = str_starts_with($target, '/') ? $target : $this->fileSystem->resolvePath($this->interpreterState->cwd, $target);

            try {
                $result['stdin'] = $this->fileSystem->readFile($path);
            } catch (RuntimeException) {
                $this->writeStderr("bash: {$target}: No such file or directory\n");
            }

            return $result;
        }

        // Output redirect
        if (in_array($op, ['>', '>>', '>|'], true)) {
            $result['stdout'] = $target;
            $result['append'] = $op === '>>';
            $result['allowClobber'] = $op === '>|';

            return $result;
        }

        // Dup fd
        if ($op === '>&') {
            if ($target === '2') {
                // Redirect stdout to stderr (just ignore for now)
            } elseif ($target === '1' || $target === '-') {
                // Redirect stderr to stdout or close
            }

            return $result;
        }

        if ($op === '2>' || ($op === '>' && $redirectionNode->fd === 2)) {
            // Redirect stderr to file (ignore content for now)
            return $result;
        }

        // &> and &>> redirect both
        if ($op === '&>' || $op === '&>>') {
            $result['stdout'] = $target;
            $result['append'] = $op === '&>>';
            $result['allowClobber'] = $op === '&>';

            return $result;
        }

        return $result;
    }

    private function handleOutputRedirection(ExecResult $execResult, ?string $targetPath, bool $append, bool $allowClobber = false): ExecResult
    {
        if ($targetPath === null) {
            return $execResult;
        }

        $path = str_starts_with($targetPath, '/')
            ? $targetPath
            : $this->fileSystem->resolvePath($this->interpreterState->cwd, $targetPath);

        if (
            ! $append
            && ! $allowClobber
            && ($this->interpreterState->shellOpts['noclobber'] ?? false)
            && $this->fileSystem->exists($path)
        ) {
            return new ExecResult(
                stdout: '',
                stderr: "bash: {$targetPath}: cannot overwrite existing file\n",
                exitCode: 1,
            );
        }

        if ($append) {
            $this->fileSystem->appendFile($path, $execResult->stdout);
        } else {
            $this->fileSystem->writeFile($path, $execResult->stdout);
        }

        return new ExecResult(stdout: '', stderr: $execResult->stderr, exitCode: $execResult->exitCode);
    }

    public function resolvePath(string $base, string $path): string
    {
        return $this->fileSystem->resolvePath($base, $path);
    }

    /**
     * @return list<string>
     */
    public function listDirectory(string $path): array
    {
        return $this->fileSystem->readdir($path);
    }

    /**
     * @param  array<int, array{
     *     type: 'scalar'|'array'|'element',
     *     name: string,
     *     value?: string,
     *     append: bool,
     *     elements?: list<string>,
     *     subscript?: int|string,
     * } >  $assignments
     */
    private function formatTraceCommand(SimpleCommandNode $simpleCommandNode, array $assignments): string
    {
        $parts = [];

        foreach ($assignments as $assignment) {
            $parts[] = match ($assignment['type']) {
                'array' => sprintf(
                    '%s%s(%s)',
                    $assignment['name'],
                    $assignment['append'] ? '+' : '',
                    implode(' ', $assignment['elements'] ?? []),
                ),
                'element' => sprintf(
                    '%s[%s]%s=%s',
                    $assignment['name'],
                    (string) ($assignment['subscript'] ?? ''),
                    $assignment['append'] ? '+' : '',
                    $assignment['value'] ?? '',
                ),
                default => sprintf(
                    '%s%s=%s',
                    $assignment['name'],
                    $assignment['append'] ? '+' : '',
                    $assignment['value'] ?? '',
                ),
            };
        }

        if ($simpleCommandNode->name instanceof WordNode) {
            $parts[] = $this->expandWord($simpleCommandNode->name);

            foreach ($simpleCommandNode->args as $arg) {
                array_push($parts, ...$this->expandWordList($arg));
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{
     *     type: 'scalar'|'array'|'element',
     *     name: string,
     *     value?: string,
     *     append: bool,
     *     elements?: list<string>,
     *     subscript?: int|string,
     * }
     */
    private function resolveAssignment(\BashBox\Ast\AssignmentNode $assignmentNode): array
    {
        if ($assignmentNode->array !== null) {
            $elements = [];

            foreach ($assignmentNode->array as $element) {
                array_push($elements, ...$this->expandWordList($element));
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
                'subscript' => $this->normalizeArrayKey($matches[2]),
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
     *     elements?: list<string>,
     *     subscript?: int|string,
     * }  $assignment
     */
    private function applyAssignment(array $assignment): ?ExecResult
    {
        if ($this->interpreterState->isReadonly($assignment['name'])) {
            $this->writeStderr("bash: {$assignment['name']}: readonly variable\n");

            return new ExecResult(exitCode: 1);
        }

        if ($assignment['type'] === 'array') {
            $existing = $this->interpreterState->arrays[$assignment['name']] ?? [];
            $elements = $assignment['elements'] ?? [];

            if ($assignment['append']) {
                $nextIndex = $this->nextArrayIndex($existing);

                foreach ($elements as $element) {
                    $existing[$nextIndex++] = $element;
                }

                $this->interpreterState->arrays[$assignment['name']] = $existing;
            } else {
                $this->interpreterState->arrays[$assignment['name']] = array_combine(
                    range(0, max(count($elements) - 1, 0)),
                    $elements,
                ) ?: [];
            }

            return null;
        }

        if ($assignment['type'] === 'element') {
            $name = $assignment['name'];
            $subscript = $assignment['subscript'] ?? 0;
            $value = $assignment['value'] ?? '';
            $array = $this->interpreterState->arrays[$name] ?? [];

            if ($assignment['append'] && array_key_exists($subscript, $array)) {
                $array[$subscript] .= $value;
            } else {
                $array[$subscript] = $value;
            }

            $this->interpreterState->arrays[$name] = $array;

            return null;
        }

        $name = $assignment['name'];
        $value = $assignment['value'] ?? '';

        if ($assignment['append']) {
            $value = ($this->interpreterState->getVar($name) ?? '').$value;
        }

        $this->interpreterState->setVar($name, $value);

        return null;
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

    private function rawWordValue(WordNode $wordNode): string
    {
        $result = '';

        foreach ($wordNode->parts as $part) {
            $result .= match (true) {
                $part instanceof \BashBox\Ast\Parts\LiteralPart => $part->value,
                $part instanceof \BashBox\Ast\Parts\SingleQuotedPart => $part->value,
                $part instanceof \BashBox\Ast\Parts\EscapedPart => $part->value,
                $part instanceof \BashBox\Ast\Parts\DoubleQuotedPart => implode('', array_map(
                    fn (\BashBox\Ast\WordPart $wordPart): string => $wordPart instanceof \BashBox\Ast\Parts\LiteralPart ? $wordPart->value : '',
                    $part->parts,
                )),
                default => '',
            };
        }

        return $result;
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    public function evaluateArithmeticExpression(\BashBox\Ast\ArithmeticExpressionNode $arithmeticExpressionNode): int
    {
        if ($arithmeticExpressionNode->originalText !== null) {
            return $this->evaluateArithmeticString($arithmeticExpressionNode->originalText);
        }

        return $this->evaluateArithExpr($arithmeticExpressionNode->expression);
    }

    public function evaluateArithmeticString(string $expr): int
    {
        // Expand variables in the expression
        $expanded = preg_replace_callback('/\$\{([^}]+)\}|\$([a-zA-Z_]\w*)/', function (array $matches): string {
            $name = $matches[1] !== '' ? $matches[1] : $matches[2];

            return $this->interpreterState->getVar($name) ?? $this->interpreterState->getSpecialVar($name) ?? '0';
        }, $expr) ?? $expr;

        $arithmeticParser = new \BashBox\Parser\ArithmeticParser($expanded);
        $arithExpr = $arithmeticParser->parse();

        return $this->evaluateArithExpr($arithExpr);
    }

    public function evaluateArithExpr(ArithExpr $arithExpr): int
    {
        if ($arithExpr instanceof ArithNumberNode) {
            return (int) $arithExpr->value;
        }

        if ($arithExpr instanceof ArithVariableNode) {
            $val = $this->interpreterState->getVar($arithExpr->name) ?? $this->interpreterState->getSpecialVar($arithExpr->name) ?? '0';

            // Recursive arithmetic evaluation of variable values
            if ($val !== '' && ! ctype_digit(ltrim($val, '-'))) {
                return $this->evaluateArithmeticString($val);
            }

            return (int) $val;
        }

        if ($arithExpr instanceof ArithBinaryNode) {
            $left = $this->evaluateArithExpr($arithExpr->left);
            $right = $this->evaluateArithExpr($arithExpr->right);

            return match ($arithExpr->operator) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => $right !== 0 ? intdiv($left, $right) : throw new \BashBox\Exceptions\ArithmeticException('division by zero'),
                '%' => $right !== 0 ? $left % $right : throw new \BashBox\Exceptions\ArithmeticException('division by zero'),
                '**' => (int) $left ** $right,
                '<<' => $left << $right,
                '>>' => $left >> $right,
                '<' => $left < $right ? 1 : 0,
                '<=' => $left <= $right ? 1 : 0,
                '>' => $left > $right ? 1 : 0,
                '>=' => $left >= $right ? 1 : 0,
                '==' => $left === $right ? 1 : 0,
                '!=' => $left !== $right ? 1 : 0,
                '&' => $left & $right,
                '|' => $left | $right,
                '^' => $left ^ $right,
                '&&' => ($left !== 0 && $right !== 0) ? 1 : 0,
                '||' => ($left !== 0 || $right !== 0) ? 1 : 0,
                ',' => $right,
                default => 0,
            };
        }

        if ($arithExpr instanceof ArithUnaryNode) {
            if (($arithExpr->operator === '++' || $arithExpr->operator === '--') && $arithExpr->operand instanceof ArithVariableNode) {
                $varName = $arithExpr->operand->name;
                $val = (int) ($this->interpreterState->getVar($varName) ?? '0');

                if ($arithExpr->prefix) {
                    $val = $arithExpr->operator === '++' ? $val + 1 : $val - 1;
                    $this->interpreterState->setVar($varName, (string) $val);

                    return $val;
                }

                $oldVal = $val;
                $val = $arithExpr->operator === '++' ? $val + 1 : $val - 1;
                $this->interpreterState->setVar($varName, (string) $val);

                return $oldVal;
            }

            $operand = $this->evaluateArithExpr($arithExpr->operand);

            return match ($arithExpr->operator) {
                '-' => -$operand,
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
            $current = (int) ($this->interpreterState->getVar($arithExpr->variable) ?? '0');

            $newValue = match ($arithExpr->operator) {
                '=' => $value,
                '+=' => $current + $value,
                '-=' => $current - $value,
                '*=' => $current * $value,
                '/=' => $value !== 0 ? intdiv($current, $value) : throw new \BashBox\Exceptions\ArithmeticException('division by zero'),
                '%=' => $value !== 0 ? $current % $value : throw new \BashBox\Exceptions\ArithmeticException('division by zero'),
                '<<=' => $current << $value,
                '>>=' => $current >> $value,
                '&=' => $current & $value,
                '|=' => $current | $value,
                '^=' => $current ^ $value,
                default => $value,
            };

            $this->interpreterState->setVar($arithExpr->variable, (string) $newValue);

            return $newValue;
        }

        if ($arithExpr instanceof ArithGroupNode) {
            return $this->evaluateArithExpr($arithExpr->expression);
        }

        return 0;
    }

    // =========================================================================
    // CONDITIONALS
    // =========================================================================

    public function evaluateConditional(ConditionalExpressionNode $conditionalExpressionNode): bool
    {
        if ($conditionalExpressionNode instanceof CondBinaryNode) {
            $left = $this->expandWord($conditionalExpressionNode->left);
            $right = $this->expandWord($conditionalExpressionNode->right);

            return match ($conditionalExpressionNode->operator) {
                '=', '==' => $this->matchPattern($left, $right),
                '!=' => ! $this->matchPattern($left, $right),
                '<' => strcmp($left, $right) < 0,
                '>' => strcmp($left, $right) > 0,
                '-eq' => (int) $left === (int) $right,
                '-ne' => (int) $left !== (int) $right,
                '-lt' => (int) $left < (int) $right,
                '-le' => (int) $left <= (int) $right,
                '-gt' => (int) $left > (int) $right,
                '-ge' => (int) $left >= (int) $right,
                '=~' => (bool) @preg_match('/'.$right.'/', $left),
                '-nt', '-ot', '-ef' => false,
                default => false,
            };
        }

        if ($conditionalExpressionNode instanceof CondUnaryNode) {
            $operand = $this->expandWord($conditionalExpressionNode->operand);

            return match ($conditionalExpressionNode->operator) {
                '-z' => $operand === '',
                '-n' => $operand !== '',
                '-e' => $this->fileSystem->exists($this->resolveFsPath($operand)),
                '-f' => $this->checkFileStat($operand, 'isFile'),
                '-d' => $this->checkFileStat($operand, 'isDirectory'),
                '-s' => $this->checkFileSize($operand),
                '-r', '-w', '-x' => $this->fileSystem->exists($this->resolveFsPath($operand)),
                '-L', '-h' => $this->checkFileStat($operand, 'isSymbolicLink'),
                '-v' => $this->interpreterState->getVar($operand) !== null,
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

        if ($conditionalExpressionNode instanceof CondWordNode) {
            $word = $this->expandWord($conditionalExpressionNode->word);

            return $word !== '';
        }

        return false;
    }

    private function resolveFsPath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->fileSystem->resolvePath($this->interpreterState->cwd, $path);
    }

    private function checkFileStat(string $path, string $property): bool
    {
        $resolved = $this->resolveFsPath($path);

        try {
            $stat = $this->fileSystem->stat($resolved);
        } catch (RuntimeException) {
            return false;
        }

        return match ($property) {
            'isFile' => $stat->isFile,
            'isDirectory' => $stat->isDirectory,
            'isSymbolicLink' => $stat->isSymbolicLink,
            default => false,
        };
    }

    private function checkFileSize(string $path): bool
    {
        $resolved = $this->resolveFsPath($path);

        try {
            $stat = $this->fileSystem->stat($resolved);
        } catch (RuntimeException) {
            return false;
        }

        return $stat->size > 0;
    }

    public function matchPattern(string $str, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        $regex = $this->patternToRegex($pattern);

        return (bool) preg_match('/^'.$regex.'$/', $str);
    }

    private function patternToRegex(string $pattern): string
    {
        $result = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];

            $result .= match ($ch) {
                '*' => '.*',
                '?' => '.',
                '[' => $this->parseCharacterClass($pattern, $i),
                '\\' => $i + 1 < $len ? preg_quote($pattern[++$i], '/') : '\\\\',
                default => preg_quote($ch, '/'),
            };
        }

        return $result;
    }

    private function parseCharacterClass(string $pattern, int &$i): string
    {
        $i++; // Skip [
        $class = '[';

        if ($i < strlen($pattern) && $pattern[$i] === '!') {
            $class .= '^';
            $i++;
        }

        while ($i < strlen($pattern) && $pattern[$i] !== ']') {
            $class .= preg_quote($pattern[$i], '/');
            $i++;
        }

        return $class.']';
    }

    /**
     * @return list<string>
     */
    private function splitByIFS(string $str, string $ifs): array
    {
        if ($str === '') {
            return [];
        }

        if ($ifs === '') {
            return [$str];
        }

        $parts = [];
        $current = '';
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            if (str_contains($ifs, $str[$i])) {
                if ($current !== '' || ! ctype_space($str[$i])) {
                    $parts[] = $current;
                    $current = '';
                }
            } else {
                $current .= $str[$i];
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
