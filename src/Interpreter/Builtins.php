<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\FunctionPrinter;
use BashBox\Commands\CommandRegistry;
use BashBox\Exceptions\ArithmeticException;
use BashBox\Exceptions\AssignmentException;
use BashBox\Exceptions\BreakException;
use BashBox\Exceptions\ContinueException;
use BashBox\Exceptions\ExitException;
use BashBox\Exceptions\ReturnException;
use BashBox\ExecResult;
use BashBox\Filesystem\FileSystemInterface;
use Closure;
use RuntimeException;

/**
 * The commands the shell runs itself, such as cd, declare, read and trap.
 *
 * @phpstan-import-type Assignment from Assignments
 */
final class Builtins
{
    /** Commands BashBox implements in the registry that real bash runs as builtins */
    public const array REGISTRY_BUILTINS = ['echo', 'printf', 'test', 'true', 'false', 'pwd'];

    /** `set -o` options in bash's order, with its defaults for a non-interactive shell */
    private const array SET_OPTIONS = [
        'allexport' => false, 'braceexpand' => true, 'emacs' => false, 'errexit' => false, 'errtrace' => false,
        'functrace' => false, 'hashall' => true, 'histexpand' => false, 'history' => false, 'ignoreeof' => false,
        'interactive-comments' => true, 'keyword' => false, 'monitor' => false, 'noclobber' => false, 'noexec' => false,
        'noglob' => false, 'nolog' => false, 'notify' => false, 'nounset' => false, 'onecmd' => false, 'physical' => false,
        'pipefail' => false, 'posix' => false, 'privileged' => false, 'verbose' => false, 'vi' => false, 'xtrace' => false,
    ];

    /** @var array<string, Assignment> the `name=(...)` operands of the declaration builtin being run, by name */
    public array $arrayOperands = [];

    /** `declare: ` while a builtin that names itself in assignment errors is assigning, else '' */
    public private(set) string $assigning = '';

    private int $sourceDepth = 0;

    /** getopts' position inside a grouped option argument such as -abc */
    private int $getoptsCharIndex = 1;

    public function __construct(
        private readonly InterpreterState $interpreterState,
        private readonly FileSystemInterface $fileSystem,
        private readonly CommandRegistry $commandRegistry,
        private readonly Interpreter $interpreter,
        private readonly Redirections $redirections,
        private readonly Assignments $assignments,
        private readonly ArithmeticEvaluator $arithmeticEvaluator,
    ) {}

    /**
     * Runs $name if it's an enabled builtin; null when it isn't one.
     *
     * @param  list<string>  $args
     */
    public function run(string $name, array $args, StdinStream $stdinStream): ?ExecResult
    {
        $builtin = $this->builtin($name);

        if (! $builtin instanceof Closure || isset($this->interpreterState->disabledBuiltins[$name])) {
            return null;
        }

        $assigning = $this->assigning;
        $this->assigning = in_array($name, ['declare', 'typeset', 'local', 'export', 'readonly', 'read', 'printf'], true) ? $name.': ' : '';

        try {
            $result = $builtin($args, $stdinStream);
        } catch (AssignmentException $assignmentException) {
            // A builtin assigning to a readonly variable just fails, but a failed `declare a=(...)` abandons its line like a plain assignment
            $result = $this->arrayOperands === [] ? new ExecResult(stderr: $assignmentException->getMessage()."\n", exitCode: 1) : throw $assignmentException;
        } finally {
            $this->assigning = $assigning;
        }

        // Builtins that run other commands return output those already routed through the fd table
        return ! $result instanceof ExecResult || in_array($name, ['source', '.', 'eval', 'command', 'builtin', 'exec'], true)
            ? $result
            : $this->redirections->route($result, 'bash: '.$name);
    }

    /** @return (Closure(list<string>, StdinStream): ?ExecResult)|null */
    private function builtin(string $name): ?Closure
    {
        return match ($name) {
            'exit' => $this->builtinExit(...),
            'export' => $this->builtinExport(...),
            'unset' => $this->builtinUnset(...),
            'local' => $this->builtinLocal(...),
            'set' => $this->builtinSet(...),
            'shopt' => $this->builtinShopt(...),
            'cd' => $this->builtinCd(...),
            'source', '.' => $this->builtinSource(...),
            'eval' => fn (array $args, StdinStream $stdinStream): ExecResult => $this->interpreter->captureInShell(implode(' ', $args), $stdinStream, $this->interpreterState->currentLine, errorPrefix: 'bash: eval: '),
            'declare', 'typeset' => $this->builtinDeclare(...),
            'printf' => $this->builtinPrintf(...),
            'read' => $this->builtinRead(...),
            'break' => $this->builtinBreak(...),
            'continue' => $this->builtinContinue(...),
            'return' => $this->builtinReturn(...),
            'shift' => $this->builtinShift(...),
            'let' => $this->builtinLet(...),
            'getopts' => $this->builtinGetopts(...),
            'mapfile', 'readarray' => $this->builtinMapfile(...),
            ':' => fn (): ExecResult => new ExecResult(exitCode: 0),
            'type' => $this->builtinType(...),
            'command' => $this->builtinCommand(...),
            'alias' => $this->builtinAlias(...),
            'unalias' => $this->builtinUnalias(...),
            'hash' => $this->builtinHash(...),
            'readonly' => $this->builtinReadonly(...),
            'trap' => $this->builtinTrap(...),
            'builtin' => $this->builtinBuiltin(...),
            'exec' => $this->builtinExec(...),
            'pushd' => $this->builtinPushd(...),
            'popd' => $this->builtinPopd(...),
            'dirs' => $this->builtinDirs(...),
            'caller' => $this->builtinCaller(...),
            'help' => $this->builtinHelp(...),
            'enable' => $this->builtinEnable(...),
            // There's no job control or programmable completion: these report an empty state
            'wait', 'jobs', 'complete' => fn (): ExecResult => new ExecResult(exitCode: 0),
            'compgen' => fn (): ExecResult => new ExecResult(exitCode: 1),
            'disown' => $this->builtinDisown(...),
            'compopt' => fn (): ExecResult => new ExecResult(stderr: "bash: compopt: not currently executing completion function\n", exitCode: 1),
            'fg' => fn (): ExecResult => new ExecResult(stderr: "bash: fg: no job control\n", exitCode: 1),
            'bg' => fn (): ExecResult => new ExecResult(stderr: "bash: bg: no job control\n", exitCode: 1),
            'kill' => $this->builtinKill(...),
            'suspend' => fn (): ExecResult => new ExecResult(stderr: "bash: suspend: cannot suspend: no job control\n", exitCode: 1),
            'logout' => fn (): ExecResult => new ExecResult(stderr: "bash: logout: not login shell: use `exit'\n", exitCode: 1),
            'times' => fn (): ExecResult => new ExecResult(stdout: "0m0.000s 0m0.000s\n0m0.000s 0m0.000s\n", exitCode: 0),
            'ulimit' => $this->builtinUlimit(...),
            'umask' => $this->builtinUmask(...),
            default => null,
        };
    }

    /**
     * `printf -v name`: the printf command's output, newlines and all, assigned to the variable; without -v it's left to the command.
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

        $result = $this->commandRegistry->get('printf')?->execute($args, $this->interpreter->commandContext($stdinStream)) ?? new ExecResult;
        $this->readAssign($name, $result->stdout);

        return new ExecResult(stderr: $result->stderr, exitCode: $result->exitCode);
    }

    /** @param array<int, string> $args */
    private function builtinExit(array $args): ExecResult
    {
        if (count($args) > 1) {
            $this->interpreter->writeStderr("bash: exit: too many arguments\n");

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
            $this->interpreter->writeStderr("bash: {$builtin}: {$args[0]}: numeric argument required\n");

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
        return $this->declare($args, 'local');
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
    private function builtinCd(array $args): ExecResult
    {
        return $this->changeDirectory($args, 'cd');
    }

    /** @param array<int, string> $args */
    private function changeDirectory(array $args, string $builtin): ExecResult
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
            return new ExecResult(stderr: sprintf("bash: %s: %s: %s\n", $builtin, $dir, Redirections::strerror($runtimeException)), exitCode: 1);
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
            return $this->redirections->route(new ExecResult(stderr: "bash: source: filename argument required\nsource: usage: source [-p path] filename [arguments]\n", exitCode: 2));
        }

        try {
            $content = $this->fileSystem->readFile($this->fileSystem->resolvePath($this->interpreterState->cwd, $args[0]));
        } catch (RuntimeException) {
            $error = $this->interpreter->isDirectory($args[0]) ? "bash: source: {$args[0]}: is a directory\n" : "bash: {$args[0]}: No such file or directory\n";

            return $this->redirections->route(new ExecResult(stderr: $error, exitCode: 1));
        }

        // Extra operands become $1.. for the duration of the file
        $savedParams = count($args) > 1 ? $this->interpreterState->positionalParams : null;

        if ($savedParams !== null) {
            $this->interpreterState->positionalParams = array_slice($args, 1);
        }

        $this->sourceDepth++;
        $this->interpreterState->pushFrame('source', $args[0]);

        try {
            return $this->interpreter->captureInShell($content, $stdinStream, errorPrefix: $args[0].': ');
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
    private function builtinDeclare(array $args): ExecResult
    {
        return $this->declare($args, 'declare');
    }

    /** @param array<int, string> $args */
    private function declare(array $args, string $builtin): ExecResult
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
                $this->assignments->apply(['name' => $target] + $this->arrayOperands[$name]);
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

            $this->interpreter->writeStderr("bash: {$builtin}: {$error}\n");

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
            $this->interpreter->writeStderr("bash: {$builtin}: `{$reference}': invalid variable name for name reference\n");

            return false;
        }

        // In a function the name may mean the global of that name, so bash only warns
        if ($reference === $name && ! $scoped) {
            $this->interpreter->writeStderr("bash: {$builtin}: {$name}: nameref variable self references not allowed\n");

            return false;
        }

        if ($reference === $name) {
            $this->interpreter->writeStderr("bash: {$builtin}: warning: {$name}: circular name reference\nbash: warning: {$name}: circular name reference\n");
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

        $this->interpreter->writeStderr("bash: {$builtin}: `{$operand}': not a valid identifier\n");

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

        return "$'".preg_replace_callback(
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

        $stream = (int) $fd === 0 ? $stdinStream : $this->redirections->fds[(int) $fd] ?? null;

        if ($stream === null) {
            return new ExecResult(stderr: sprintf("bash: read: %d: invalid file descriptor: Bad file descriptor\n", $fd), exitCode: 1);
        }

        if (! $stream instanceof StdinStream || ! $stream->isReadable()) {
            return new ExecResult(stderr: sprintf("bash: read: %d: read error: Bad file descriptor\n", $fd), exitCode: 1);
        }

        // Input is never a terminal and is all there already: -p, -e, -i, -s and -E do nothing, a timeout never expires
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
            $this->interpreter->writeStderr("bash: read: `{$name}': not a valid identifier\n");

            return false;
        }

        $this->assignments->apply(isset($m[2])
            ? ['type' => 'element', 'name' => $m[1], 'subscript' => $this->assignments->expandSubscript($m[2]), 'append' => false, 'value' => $value]
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
     * Splits a record as read does: runs of IFS whitespace separate, other IFS chars separate once each, a backslash escapes unless raw.
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
        if ($this->interpreter->loopDepth === 0) {
            return new ExecResult(stderr: "bash: break: only meaningful in a `for', `while', or `until' loop\n");
        }

        throw new BreakException(max(1, (int) ($args[0] ?? 1)));
    }

    /** @param array<int, string> $args */
    private function builtinContinue(array $args): ExecResult
    {
        if ($this->interpreter->loopDepth === 0) {
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
                $lastResult = $this->arithmeticEvaluator->evaluateString($arg);
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
     * declare -f prints definitions and -F names (as `declare -f name` when listing all); a missing name fails quietly.
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
            $this->builtin($name) instanceof Closure || in_array($name, self::REGISTRY_BUILTINS, true) => 'builtin',
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
            return $this->redirections->route(new ExecResult(stdout: $stdout, exitCode: $stdout === '' ? 1 : 0));
        }

        // Runs a builtin or command, skipping shell functions
        return $this->run($args[0], array_slice($args, 1), $stdinStream) ?? $this->interpreter->runCommand($args[0], array_slice($args, 1), $stdinStream);
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
            $this->assignments->apply($this->arrayOperands[$name]);
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

        return $this->run($name, $args, $stdinStream)
            ?? (in_array($name, self::REGISTRY_BUILTINS, true) && ! isset($this->interpreterState->disabledBuiltins[$name])
                ? $this->interpreter->runCommand($name, $args, $stdinStream)
                : $this->redirections->route(new ExecResult(stderr: "bash: builtin: {$name}: not a shell builtin\n", exitCode: 1)));
    }

    /**
     * exec replaces the shell with a command (never a function or builtin), so the script ends with its status.
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
            ? $this->interpreter->runCommand($name, $args, $stdinStream)
            : $this->redirections->route(new ExecResult(stderr: "bash: exec: {$name}: not found\n", exitCode: 127));

        $this->interpreter->writeStdout($result->stdout);
        $this->interpreter->appendStderr($result->stderr);

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

            $result = $this->changeDirectory([array_pop($stack)], 'pushd');
        } else {
            $result = $this->changeDirectory([$args[0]], 'pushd');
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

        $this->changeDirectory([array_pop($this->interpreterState->directoryStack)], 'popd');

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

        // Frame N's call line, then frame N+1's name and file; as in bash -c, the main script is no frame of its own
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
     * Plain `help`: the synopses in two columns of half the terminal width, cut short with `>`, `*` marking a disabled builtin.
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

    /** @param list<string> $args */
    private function builtinHash(array $args): ExecResult
    {
        // No external programs are ever looked up on PATH, so the hash table stays empty
        return new ExecResult(stdout: $args === [] ? "hash: hash table empty\n" : '');
    }

    /** @param list<string> $args */
    private function builtinDisown(array $args): ExecResult
    {
        return new ExecResult(stderr: 'bash: disown: '.($args[0] ?? 'current').": no such job\n", exitCode: 1);
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
     * `kill -l`: bash's Linux signal table (32 and 33 reserved, RTMIN/RTMAX from 34), or each operand translated between number and name.
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
}
