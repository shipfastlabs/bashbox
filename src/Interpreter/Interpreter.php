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
use BashBox\ExecResult;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Interpreter\Expansion\WordExpander;
use BashBox\Network\SecureHttpClient;

final class Interpreter
{
    private readonly WordExpander $wordExpander;

    private string $stdout = '';

    private string $stderr = '';

    public function __construct(
        private readonly InterpreterState $state,
        private readonly FileSystemInterface $fs,
        private readonly CommandRegistry $registry,
        private readonly ?SecureHttpClient $httpClient = null,
    ) {
        $this->wordExpander = new WordExpander($this->state, $this);
    }

    public function getState(): InterpreterState
    {
        return $this->state;
    }

    public function executeScript(ScriptNode $script, string $stdin = ''): ExecResult
    {
        $exitCode = 0;

        try {
            foreach ($script->statements as $statement) {
                $exitCode = $this->executeStatement($statement, $stdin);
            }
        } catch (ExitException|ErrexitException $e) {
            $exitCode = $e->exitCode;
        }

        $stdout = $this->stdout;
        $stderr = $this->stderr;
        $this->stdout = '';
        $this->stderr = '';

        return new ExecResult(stdout: $stdout, stderr: $stderr, exitCode: $exitCode);
    }

    public function executeStatement(StatementNode $statement, string $stdin = ''): int
    {
        if ($statement->deferredError !== null) {
            $this->writeStderr('bash: '.$statement->deferredError['message']."\n");

            return 2;
        }

        $exitCode = 0;
        $counter = count($statement->pipelines);

        for ($i = 0; $i < $counter; $i++) {
            $pipeline = $statement->pipelines[$i];
            $exitCode = $this->executePipeline($pipeline, $stdin);
            $this->state->lastExitCode = $exitCode;

            if ($i < count($statement->operators)) {
                $op = $statement->operators[$i];
                if ($op === '&&' && $exitCode !== 0) {
                    break;
                }

                if ($op === '||' && $exitCode === 0) {
                    break;
                }
            }
        }

        // Check errexit
        // Don't trigger errexit for conditions in if/while/until or negated pipelines
        if (($this->state->shellOpts['errexit'] ?? false) && $exitCode !== 0 && $statement->operators === []) {
            throw new ErrexitException($exitCode);
        }

        return $exitCode;
    }

    public function executePipeline(PipelineNode $pipeline, string $stdin = ''): int
    {
        $commands = $pipeline->commands;

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

        if ($pipeline->negated) {
            return $lastExitCode === 0 ? 1 : 0;
        }

        return $lastExitCode;
    }

    public function executeCommand(Node $command, string $stdin = ''): ExecResult
    {
        $this->state->incrementCommandCount();

        if ($command instanceof SimpleCommandNode) {
            return $this->executeSimpleCommand($command, $stdin);
        }

        if ($command instanceof IfNode) {
            return $this->executeIf($command, $stdin);
        }

        if ($command instanceof ForNode) {
            return $this->executeFor($command, $stdin);
        }

        if ($command instanceof CStyleForNode) {
            return $this->executeCStyleFor($command, $stdin);
        }

        if ($command instanceof WhileNode) {
            return $this->executeWhile($command, $stdin);
        }

        if ($command instanceof UntilNode) {
            return $this->executeUntil($command, $stdin);
        }

        if ($command instanceof CaseNode) {
            return $this->executeCase($command, $stdin);
        }

        if ($command instanceof SubshellNode) {
            return $this->executeSubshell($command, $stdin);
        }

        if ($command instanceof GroupNode) {
            return $this->executeGroup($command, $stdin);
        }

        if ($command instanceof ArithmeticCommandNode) {
            return $this->executeArithmeticCommand($command);
        }

        if ($command instanceof ConditionalCommandNode) {
            return $this->executeConditionalCommand($command);
        }

        if ($command instanceof FunctionDefNode) {
            return $this->executeFunctionDef($command);
        }

        return new ExecResult(exitCode: 1);
    }

    // =========================================================================
    // SIMPLE COMMAND
    // =========================================================================

    private function executeSimpleCommand(SimpleCommandNode $command, string $stdin = ''): ExecResult
    {
        // Process assignments
        $prefixAssignments = [];
        foreach ($command->assignments as $assignment) {
            $name = $assignment->name;
            $value = $assignment->value !== null
                ? $this->expandWord($assignment->value)
                : '';
            $prefixAssignments[$name] = $value;
        }

        // No command name - just assignments
        if (! $command->name instanceof \BashBox\Ast\WordNode) {
            foreach ($prefixAssignments as $name => $value) {
                $this->state->setVar($name, $value);
            }

            return new ExecResult(exitCode: 0);
        }

        $commandName = $this->expandWord($command->name);
        $args = array_map($this->expandWord(...), $command->args);

        // Handle redirections for stdin
        $redirectedStdin = $stdin;
        $redirectedStdout = null;
        $appendMode = false;

        foreach ($command->redirections as $redir) {
            $result = $this->processRedirection($redir);
            if ($result['stdin'] !== null) {
                $redirectedStdin = $result['stdin'];
            }

            if ($result['stdout'] !== null) {
                $redirectedStdout = $result['stdout'];
                $appendMode = $result['append'];
            }
        }

        // Try builtin first
        $builtinResult = $this->tryBuiltin($commandName, $args, $redirectedStdin);
        if ($builtinResult instanceof \BashBox\ExecResult) {
            return $this->handleOutputRedirection($builtinResult, $redirectedStdout, $appendMode);
        }

        // Try function
        if (isset($this->state->functions[$commandName])) {
            $result = $this->executeFunction($commandName, $args, $redirectedStdin);

            return $this->handleOutputRedirection($result, $redirectedStdout, $appendMode);
        }

        // Try registered command
        $cmd = $this->registry->get($commandName);
        if ($cmd instanceof \BashBox\Commands\CommandInterface) {
            // Set prefix assignments as temporary env
            $env = $this->state->getExportedEnv();
            foreach ($prefixAssignments as $name => $value) {
                $env[$name] = $value;
            }

            $ctx = new CommandContext(
                fs: $this->fs,
                cwd: $this->state->cwd,
                env: $env,
                stdin: $redirectedStdin,
                limits: $this->state->limits,
                exec: fn (string $script): ExecResult => $this->execSubcommand($script),
                fetch: $this->httpClient,
            );

            $result = $cmd->execute($args, $ctx);

            return $this->handleOutputRedirection($result, $redirectedStdout, $appendMode);
        }

        $stderr = "bash: {$commandName}: command not found\n";

        return new ExecResult(stderr: $stderr, exitCode: 127);
    }

    // =========================================================================
    // BUILTINS
    // =========================================================================

    private function tryBuiltin(string $name, array $args, string $stdin): ?ExecResult
    {
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
            default => null,
        };
    }

    private function builtinExit(array $args): ExecResult
    {
        $code = $args !== [] ? (int) $args[0] : $this->state->lastExitCode;
        throw new ExitException($code);
    }

    private function builtinExport(array $args): ExecResult
    {
        foreach ($args as $arg) {
            if (str_contains((string) $arg, '=')) {
                [$name, $value] = explode('=', (string) $arg, 2);
                // Strip -n flag
                $name = ltrim($name, '-');
                if (str_starts_with($name, 'n')) {
                    $name = substr($name, 1);
                    unset($this->state->exportedVars[$name]);

                    continue;
                }

                $this->state->setVar($name, $value);
                $this->state->exportedVars[$name] = $value;
            } else {
                $name = ltrim((string) $arg, '-');
                $val = $this->state->getVar($name) ?? '';
                $this->state->exportedVars[$name] = $val;
            }
        }

        return new ExecResult(exitCode: 0);
    }

    private function builtinUnset(array $args): ExecResult
    {
        foreach ($args as $arg) {
            if ($arg === '-v') {
                continue;
            }
            if ($arg === '-f') {
                continue;
            }

            $this->state->unsetVar($arg);
            unset($this->state->arrays[$arg]);
        }

        return new ExecResult(exitCode: 0);
    }

    private function builtinLocal(array $args): ExecResult
    {
        foreach ($args as $arg) {
            if (str_contains((string) $arg, '=')) {
                [$name, $value] = explode('=', (string) $arg, 2);
                $this->state->declareLocal($name, $value);
            } else {
                $this->state->declareLocal($arg, '');
            }
        }

        return new ExecResult(exitCode: 0);
    }

    private function builtinSet(array $args): ExecResult
    {
        if ($args === []) {
            $output = '';
            foreach ($this->state->env as $name => $value) {
                $output .= "{$name}='{$value}'\n";
            }

            return new ExecResult(stdout: $output, exitCode: 0);
        }

        $i = 0;
        while ($i < count($args)) {
            $arg = $args[$i];

            if ($arg === '--') {
                $this->state->positionalParams = array_slice($args, $i + 1);

                break;
            }

            if (str_starts_with((string) $arg, '-o')) {
                $opt = $args[++$i] ?? '';
                $this->state->shellOpts[$opt] = true;
            } elseif (str_starts_with((string) $arg, '+o')) {
                $opt = $args[++$i] ?? '';
                $this->state->shellOpts[$opt] = false;
            } elseif (str_starts_with((string) $arg, '-')) {
                $flags = substr((string) $arg, 1);
                for ($j = 0; $j < strlen($flags); $j++) {
                    $flag = $flags[$j];
                    match ($flag) {
                        'e' => $this->state->shellOpts['errexit'] = true,
                        'u' => $this->state->shellOpts['nounset'] = true,
                        'x' => $this->state->shellOpts['xtrace'] = true,
                        'v' => $this->state->shellOpts['verbose'] = true,
                        'f' => $this->state->shellOpts['noglob'] = true,
                        'C' => $this->state->shellOpts['noclobber'] = true,
                        default => null,
                    };
                }
            } elseif (str_starts_with((string) $arg, '+')) {
                $flags = substr((string) $arg, 1);
                for ($j = 0; $j < strlen($flags); $j++) {
                    $flag = $flags[$j];
                    match ($flag) {
                        'e' => $this->state->shellOpts['errexit'] = false,
                        'u' => $this->state->shellOpts['nounset'] = false,
                        'x' => $this->state->shellOpts['xtrace'] = false,
                        'v' => $this->state->shellOpts['verbose'] = false,
                        'f' => $this->state->shellOpts['noglob'] = false,
                        'C' => $this->state->shellOpts['noclobber'] = false,
                        default => null,
                    };
                }
            } else {
                $this->state->positionalParams = array_slice($args, $i);

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

    private function builtinCd(array $args): ExecResult
    {
        $target = $args[0] ?? $this->state->getVar('HOME') ?? '/';

        if ($target === '-') {
            $target = $this->state->getVar('OLDPWD') ?? $this->state->cwd;
        }

        if (! str_starts_with($target, '/')) {
            $target = $this->fs->resolvePath($this->state->cwd, $target);
        }

        try {
            $stat = $this->fs->stat($target);
        } catch (\RuntimeException) {
            return new ExecResult(stderr: "bash: cd: {$target}: No such file or directory\n", exitCode: 1);
        }

        if (! $stat->isDirectory) {
            return new ExecResult(stderr: "bash: cd: {$target}: Not a directory\n", exitCode: 1);
        }

        $this->state->setVar('OLDPWD', $this->state->cwd);
        $this->state->cwd = $target;
        $this->state->setVar('PWD', $target);

        return new ExecResult(exitCode: 0);
    }

    private function builtinSource(array $args): ExecResult
    {
        if ($args === []) {
            return new ExecResult(stderr: "bash: source: filename argument required\n", exitCode: 2);
        }

        $path = $args[0];
        if (! str_starts_with((string) $path, '/')) {
            $path = $this->fs->resolvePath($this->state->cwd, $path);
        }

        try {
            $content = $this->fs->readFile($path);
        } catch (\RuntimeException) {
            return new ExecResult(stderr: "bash: {$args[0]}: No such file or directory\n", exitCode: 1);
        }

        return $this->execSubcommand($content);
    }

    private function builtinEval(array $args): ExecResult
    {
        $script = implode(' ', $args);

        return $this->execSubcommand($script);
    }

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
                if ($isArray || $isAssoc) {
                    $this->state->arrays[$name] = [];
                }

                $this->state->setVar($name, $value);
                if ($isExport) {
                    $this->state->exportedVars[$name] = $value;
                }
            } else {
                if ($isArray || $isAssoc) {
                    $this->state->arrays[$var] = [];
                }

                $this->state->setVar($var, '');
            }
        }

        return new ExecResult(exitCode: 0);
    }

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
            $ifs = $this->state->getVar('IFS') ?? " \t\n";
            $parts = $this->splitByIFS($line, $ifs);
            $this->state->arrays[$varNames[0]] = array_combine(
                array_keys($parts),
                $parts,
            );

            return new ExecResult(exitCode: $stdin === '' ? 1 : 0);
        }

        // Split line by IFS into variables
        $ifs = $this->state->getVar('IFS') ?? " \t\n";
        $parts = $this->splitByIFS($line, $ifs);
        $counter = count($varNames);

        for ($j = 0; $j < $counter; $j++) {
            if ($j < count($varNames) - 1) {
                $this->state->setVar($varNames[$j], $parts[$j] ?? '');
            } else {
                // Last variable gets the rest
                $this->state->setVar($varNames[$j], implode(' ', array_slice($parts, $j)));
            }
        }

        return new ExecResult(exitCode: $stdin === '' ? 1 : 0);
    }

    private function builtinBreak(array $args): ExecResult
    {
        $levels = $args !== [] ? max(1, (int) $args[0]) : 1;
        throw new BreakException($levels);
    }

    private function builtinContinue(array $args): ExecResult
    {
        $levels = $args !== [] ? max(1, (int) $args[0]) : 1;
        throw new ContinueException($levels);
    }

    private function builtinReturn(array $args): ExecResult
    {
        $code = $args !== [] ? (int) $args[0] : $this->state->lastExitCode;
        throw new ReturnException($code);
    }

    private function builtinShift(array $args): ExecResult
    {
        $n = $args !== [] ? (int) $args[0] : 1;

        if ($n > count($this->state->positionalParams)) {
            return new ExecResult(exitCode: 1);
        }

        $this->state->positionalParams = array_slice($this->state->positionalParams, $n);

        return new ExecResult(exitCode: 0);
    }

    private function builtinLet(array $args): ExecResult
    {
        $lastResult = 0;

        foreach ($args as $expr) {
            $lastResult = $this->evaluateArithmeticString($expr);
        }

        return new ExecResult(exitCode: $lastResult === 0 ? 1 : 0);
    }

    private function builtinGetopts(): ExecResult
    {
        return new ExecResult(exitCode: 1);
    }

    private function builtinMapfile(array $args, string $stdin): ExecResult
    {
        $varName = 'MAPFILE';
        $delimiter = "\n";

        $remaining = [];
        $i = 0;
        while ($i < count($args)) {
            if ($args[$i] === '-t') {
                $i++;
            } elseif ($args[$i] === '-d') {
                $i++;
                $delimiter = $args[$i] ?? "\n";
            } elseif (! str_starts_with((string) $args[$i], '-')) {
                $remaining[] = $args[$i];
            }

            $i++;
        }

        if ($remaining !== []) {
            $varName = $remaining[0];
        }

        $lines = $stdin !== '' ? explode($delimiter, $stdin) : [];
        // Remove trailing empty element from explode
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $this->state->arrays[$varName] = array_combine(
            array_keys($lines),
            $lines,
        );

        return new ExecResult(exitCode: 0);
    }

    private function builtinType(array $args): ExecResult
    {
        $output = '';
        foreach ($args as $arg) {
            if (isset($this->state->functions[$arg])) {
                $output .= $arg.' is a function
';
            } elseif ($this->isBuiltin($arg)) {
                $output .= $arg.' is a shell builtin
';
            } elseif ($this->registry->has($arg)) {
                $output .= sprintf('%s is /usr/bin/%s%s', $arg, $arg, PHP_EOL);
            } else {
                $output .= "bash: type: {$arg}: not found\n";

                return new ExecResult(stdout: $output, exitCode: 1);
            }
        }

        return new ExecResult(stdout: $output, exitCode: 0);
    }

    private function builtinCommand(array $args, string $stdin): ExecResult
    {
        if ($args === []) {
            return new ExecResult(exitCode: 0);
        }

        if ($args[0] === '-v') {
            $name = $args[1] ?? '';
            if ($this->isBuiltin($name) || $this->registry->has($name)) {
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

        $cmd = $this->registry->get($commandName);
        if ($cmd instanceof \BashBox\Commands\CommandInterface) {
            $ctx = new CommandContext(
                fs: $this->fs,
                cwd: $this->state->cwd,
                env: $this->state->getExportedEnv(),
                stdin: $stdin,
                limits: $this->state->limits,
                exec: fn (string $script): ExecResult => $this->execSubcommand($script),
                fetch: $this->httpClient,
            );

            return $cmd->execute($commandArgs, $ctx);
        }

        return new ExecResult(stderr: "bash: {$commandName}: command not found\n", exitCode: 127);
    }

    private function builtinAlias(array $args): ExecResult
    {
        if ($args === []) {
            $output = '';
            foreach ($this->state->aliases as $name => $value) {
                $output .= "alias {$name}='{$value}'\n";
            }

            return new ExecResult(stdout: $output, exitCode: 0);
        }

        foreach ($args as $arg) {
            if (str_contains((string) $arg, '=')) {
                [$name, $value] = explode('=', (string) $arg, 2);
                $this->state->aliases[$name] = $value;
            }
        }

        return new ExecResult(exitCode: 0);
    }

    private function builtinUnalias(array $args): ExecResult
    {
        foreach ($args as $arg) {
            if ($arg === '-a') {
                $this->state->aliases = [];

                return new ExecResult(exitCode: 0);
            }

            unset($this->state->aliases[$arg]);
        }

        return new ExecResult(exitCode: 0);
    }

    private function isBuiltin(string $name): bool
    {
        return in_array($name, [
            'exit', 'export', 'unset', 'local', 'set', 'shopt', 'cd', 'source', '.',
            'eval', 'declare', 'typeset', 'read', 'break', 'continue', 'return',
            'shift', 'let', 'getopts', 'mapfile', 'readarray', ':', 'type', 'command',
            'alias', 'unalias', 'hash',
        ], true);
    }

    // =========================================================================
    // COMPOUND COMMANDS
    // =========================================================================

    private function executeIf(IfNode $node, string $stdin): ExecResult
    {
        foreach ($node->clauses as $clause) {
            $condResult = $this->executeStatementList($clause->condition, $stdin);

            if ($condResult === 0) {
                return $this->executeStatementListResult($clause->body, $stdin);
            }
        }

        if ($node->elseBody !== null) {
            return $this->executeStatementListResult($node->elseBody, $stdin);
        }

        return new ExecResult(exitCode: 0);
    }

    private function executeFor(ForNode $node, string $stdin): ExecResult
    {
        if ($node->words !== null) {
            $words = [];
            foreach ($node->words as $w) {
                $expanded = $this->expandWordList($w);
                array_push($words, ...$expanded);
            }
        } else {
            $words = $this->state->positionalParams;
        }

        $exitCode = 0;
        $iterations = 0;

        foreach ($words as $word) {
            if (++$iterations > $this->state->limits->maxLoopIterations) {
                throw new ExecutionLimitException('Loop iteration limit exceeded');
            }

            $this->state->setVar($node->variable, $word);

            try {
                $exitCode = $this->executeStatementList($node->body, $stdin);
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

    private function executeCStyleFor(CStyleForNode $node, string $stdin): ExecResult
    {
        if ($node->init instanceof \BashBox\Ast\ArithmeticExpressionNode) {
            $this->evaluateArithmeticExpression($node->init);
        }

        $exitCode = 0;
        $iterations = 0;

        while (true) {
            if (++$iterations > $this->state->limits->maxLoopIterations) {
                throw new ExecutionLimitException('Loop iteration limit exceeded');
            }

            if ($node->condition instanceof \BashBox\Ast\ArithmeticExpressionNode) {
                $condResult = $this->evaluateArithmeticExpression($node->condition);
                if ($condResult === 0) {
                    break;
                }
            }

            try {
                $exitCode = $this->executeStatementList($node->body, $stdin);
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

            if ($node->update instanceof \BashBox\Ast\ArithmeticExpressionNode) {
                $this->evaluateArithmeticExpression($node->update);
            }
        }

        return new ExecResult(exitCode: $exitCode);
    }

    private function executeWhile(WhileNode $node, string $stdin): ExecResult
    {
        $exitCode = 0;
        $iterations = 0;

        while (true) {
            if (++$iterations > $this->state->limits->maxLoopIterations) {
                throw new ExecutionLimitException('Loop iteration limit exceeded');
            }

            $condResult = $this->executeStatementList($node->condition, $stdin);
            if ($condResult !== 0) {
                break;
            }

            try {
                $exitCode = $this->executeStatementList($node->body, $stdin);
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

    private function executeUntil(UntilNode $node, string $stdin): ExecResult
    {
        $exitCode = 0;
        $iterations = 0;

        while (true) {
            if (++$iterations > $this->state->limits->maxLoopIterations) {
                throw new ExecutionLimitException('Loop iteration limit exceeded');
            }

            $condResult = $this->executeStatementList($node->condition, $stdin);
            if ($condResult === 0) {
                break;
            }

            try {
                $exitCode = $this->executeStatementList($node->body, $stdin);
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

    private function executeCase(CaseNode $node, string $stdin): ExecResult
    {
        $word = $this->expandWord($node->word);

        foreach ($node->items as $item) {
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

    private function executeSubshell(SubshellNode $node, string $stdin): ExecResult
    {
        // Subshell: execute in a copy of the state
        $savedEnv = $this->state->env;
        $savedCwd = $this->state->cwd;

        $result = $this->executeStatementListResult($node->body, $stdin);

        // Restore state (subshell changes don't persist)
        $this->state->env = $savedEnv;
        $this->state->cwd = $savedCwd;

        return $result;
    }

    private function executeGroup(GroupNode $node, string $stdin): ExecResult
    {
        return $this->executeStatementListResult($node->body, $stdin);
    }

    private function executeArithmeticCommand(ArithmeticCommandNode $node): ExecResult
    {
        $result = $this->evaluateArithmeticExpression($node->expression);

        return new ExecResult(exitCode: $result !== 0 ? 0 : 1);
    }

    private function executeConditionalCommand(ConditionalCommandNode $node): ExecResult
    {
        $result = $this->evaluateConditional($node->expression);

        return new ExecResult(exitCode: $result ? 0 : 1);
    }

    private function executeFunctionDef(FunctionDefNode $node): ExecResult
    {
        $this->state->functions[$node->name] = [
            'body' => $node->body,
            'sourceFile' => $node->sourceFile,
        ];

        return new ExecResult(exitCode: 0);
    }

    private function executeFunction(string $name, array $args, string $stdin): ExecResult
    {
        $func = $this->state->functions[$name];
        $savedParams = $this->state->positionalParams;
        $this->state->positionalParams = $args;
        $this->state->pushLocalScope();
        $this->state->callDepth++;

        if ($this->state->callDepth > $this->state->limits->maxCallDepth) {
            $this->state->callDepth--;
            $this->state->popLocalScope();
            $this->state->positionalParams = $savedParams;
            throw new ExecutionLimitException('Call depth limit exceeded');
        }

        try {
            $result = $this->executeCommand($func['body'], $stdin);
        } catch (ReturnException $returnException) {
            $result = new ExecResult(exitCode: $returnException->exitCode);
        } finally {
            $this->state->callDepth--;
            $this->state->popLocalScope();
            $this->state->positionalParams = $savedParams;
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

    public function expandWord(WordNode $word): string
    {
        return $this->wordExpander->expand($word);
    }

    /**
     * @return list<string>
     */
    public function expandWordList(WordNode $word): array
    {
        return $this->wordExpander->expandToList($word);
    }

    public function execSubcommand(string $script): ExecResult
    {
        $parser = new \BashBox\Parser\Parser;
        $ast = $parser->parse($script);

        return $this->executeScript($ast);
    }

    public function writeStdout(string $data): void
    {
        $this->stdout .= $data;

        if (strlen($this->stdout) > $this->state->limits->maxOutputSize) {
            throw new ExecutionLimitException('Output size limit exceeded');
        }
    }

    public function writeStderr(string $data): void
    {
        $this->stderr .= $data;
    }

    /**
     * @return array{stdin: ?string, stdout: ?string, append: bool}
     */
    private function processRedirection(RedirectionNode $redir): array
    {
        $result = ['stdin' => null, 'stdout' => null, 'append' => false];
        $op = $redir->operator;

        // Here-document
        if ($redir->target instanceof HereDocNode) {
            $content = $this->expandWord($redir->target->content);
            $result['stdin'] = $content;

            return $result;
        }

        $target = $this->expandWord($redir->target);

        // Here-string
        if ($op === '<<<') {
            $result['stdin'] = $target."\n";

            return $result;
        }

        // Input redirect
        if ($op === '<') {
            $path = str_starts_with($target, '/') ? $target : $this->fs->resolvePath($this->state->cwd, $target);

            try {
                $result['stdin'] = $this->fs->readFile($path);
            } catch (\RuntimeException) {
                $this->writeStderr("bash: {$target}: No such file or directory\n");
            }

            return $result;
        }

        // Output redirect
        if (in_array($op, ['>', '>>', '>|'], true)) {
            $result['stdout'] = $target;
            $result['append'] = $op === '>>';

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

        if ($op === '2>' || ($op === '>' && $redir->fd === 2)) {
            // Redirect stderr to file (ignore content for now)
            return $result;
        }

        // &> and &>> redirect both
        if ($op === '&>' || $op === '&>>') {
            $result['stdout'] = $target;
            $result['append'] = $op === '&>>';

            return $result;
        }

        return $result;
    }

    private function handleOutputRedirection(ExecResult $result, ?string $targetPath, bool $append): ExecResult
    {
        if ($targetPath === null) {
            return $result;
        }

        $path = str_starts_with($targetPath, '/')
            ? $targetPath
            : $this->fs->resolvePath($this->state->cwd, $targetPath);

        if ($append) {
            $this->fs->appendFile($path, $result->stdout);
        } else {
            $this->fs->writeFile($path, $result->stdout);
        }

        return new ExecResult(stdout: '', stderr: $result->stderr, exitCode: $result->exitCode);
    }

    // =========================================================================
    // ARITHMETIC
    // =========================================================================

    public function evaluateArithmeticExpression(\BashBox\Ast\ArithmeticExpressionNode $node): int
    {
        if ($node->originalText !== null) {
            return $this->evaluateArithmeticString($node->originalText);
        }

        return $this->evaluateArithExpr($node->expression);
    }

    public function evaluateArithmeticString(string $expr): int
    {
        // Expand variables in the expression
        $expanded = preg_replace_callback('/\$\{([^}]+)\}|\$([a-zA-Z_]\w*)/', function (array $matches): string {
            $name = $matches[1] !== '' ? $matches[1] : $matches[2];

            return $this->state->getVar($name) ?? $this->state->getSpecialVar($name) ?? '0';
        }, $expr) ?? $expr;

        $parser = new \BashBox\Parser\ArithmeticParser($expanded);
        $arithExpr = $parser->parse();

        return $this->evaluateArithExpr($arithExpr);
    }

    public function evaluateArithExpr(ArithExpr $expr): int
    {
        if ($expr instanceof ArithNumberNode) {
            return (int) $expr->value;
        }

        if ($expr instanceof ArithVariableNode) {
            $val = $this->state->getVar($expr->name) ?? $this->state->getSpecialVar($expr->name) ?? '0';

            // Recursive arithmetic evaluation of variable values
            if ($val !== '' && ! ctype_digit(ltrim($val, '-'))) {
                return $this->evaluateArithmeticString($val);
            }

            return (int) $val;
        }

        if ($expr instanceof ArithBinaryNode) {
            $left = $this->evaluateArithExpr($expr->left);
            $right = $this->evaluateArithExpr($expr->right);

            return match ($expr->operator) {
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

        if ($expr instanceof ArithUnaryNode) {
            if (($expr->operator === '++' || $expr->operator === '--') && $expr->operand instanceof ArithVariableNode) {
                $varName = $expr->operand->name;
                $val = (int) ($this->state->getVar($varName) ?? '0');
                if ($expr->prefix) {
                    $val = $expr->operator === '++' ? $val + 1 : $val - 1;
                    $this->state->setVar($varName, (string) $val);

                    return $val;
                }
                $oldVal = $val;
                $val = $expr->operator === '++' ? $val + 1 : $val - 1;
                $this->state->setVar($varName, (string) $val);

                return $oldVal;
            }

            $operand = $this->evaluateArithExpr($expr->operand);

            return match ($expr->operator) {
                '-' => -$operand,
                '+' => $operand,
                '!' => $operand === 0 ? 1 : 0,
                '~' => ~$operand,
                default => $operand,
            };
        }

        if ($expr instanceof ArithTernaryNode) {
            $cond = $this->evaluateArithExpr($expr->condition);

            return $cond !== 0
                ? $this->evaluateArithExpr($expr->consequent)
                : $this->evaluateArithExpr($expr->alternate);
        }

        if ($expr instanceof ArithAssignmentNode) {
            $value = $this->evaluateArithExpr($expr->value);
            $current = (int) ($this->state->getVar($expr->variable) ?? '0');

            $newValue = match ($expr->operator) {
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

            $this->state->setVar($expr->variable, (string) $newValue);

            return $newValue;
        }

        if ($expr instanceof ArithGroupNode) {
            return $this->evaluateArithExpr($expr->expression);
        }

        return 0;
    }

    // =========================================================================
    // CONDITIONALS
    // =========================================================================

    public function evaluateConditional(ConditionalExpressionNode $node): bool
    {
        if ($node instanceof CondBinaryNode) {
            $left = $this->expandWord($node->left);
            $right = $this->expandWord($node->right);

            return match ($node->operator) {
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

        if ($node instanceof CondUnaryNode) {
            $operand = $this->expandWord($node->operand);

            return match ($node->operator) {
                '-z' => $operand === '',
                '-n' => $operand !== '',
                '-e' => $this->fs->exists($this->resolveFsPath($operand)),
                '-f' => $this->checkFileStat($operand, 'isFile'),
                '-d' => $this->checkFileStat($operand, 'isDirectory'),
                '-s' => $this->checkFileSize($operand),
                '-r', '-w', '-x' => $this->fs->exists($this->resolveFsPath($operand)),
                '-L', '-h' => $this->checkFileStat($operand, 'isSymbolicLink'),
                '-v' => $this->state->getVar($operand) !== null,
                default => false,
            };
        }

        if ($node instanceof CondNotNode) {
            return ! $this->evaluateConditional($node->operand);
        }

        if ($node instanceof CondAndNode) {
            return $this->evaluateConditional($node->left) && $this->evaluateConditional($node->right);
        }

        if ($node instanceof CondOrNode) {
            if ($this->evaluateConditional($node->left)) {
                return true;
            }

            return $this->evaluateConditional($node->right);
        }

        if ($node instanceof CondGroupNode) {
            return $this->evaluateConditional($node->expression);
        }

        if ($node instanceof CondWordNode) {
            $word = $this->expandWord($node->word);

            return $word !== '';
        }

        return false;
    }

    private function resolveFsPath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->fs->resolvePath($this->state->cwd, $path);
    }

    private function checkFileStat(string $path, string $property): bool
    {
        $resolved = $this->resolveFsPath($path);
        try {
            $stat = $this->fs->stat($resolved);
        } catch (\RuntimeException) {
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
            $stat = $this->fs->stat($resolved);
        } catch (\RuntimeException) {
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
