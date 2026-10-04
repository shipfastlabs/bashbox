<?php

declare(strict_types=1);

namespace BashBox\Interpreter;

use BashBox\Ast\CompoundCommandNode;
use BashBox\Exceptions\AssignmentException;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;
use BashBox\Parser\Int64;
use Closure;

final class InterpreterState
{
    /** @var array<string, array{body: CompoundCommandNode, sourceFile: string}> */
    public array $functions = [];

    /**
     * Function scopes, innermost last: what each local hid (value, array, export attribute and other attributes).
     *
     * @var list<array<string, array{?string, ?array<int|string, string>, bool, string}>>
     */
    public array $localScopes = [];

    public int $lastExitCode = 0;

    /** Status of the most recent $(...), so a bare `x=$(cmd)` can report it. */
    public ?int $substitutionStatus = null;

    public int $commandCount = 0;

    public int $callDepth = 0;

    public int $subshellDepth = 0;

    /** $0: the script file a child shell runs */
    public string $scriptName = 'bashbox';

    /** @var list<string> */
    public array $positionalParams = [];

    /** @var array<string, bool> */
    public array $shellOpts = [
        'errexit' => false,
        'nounset' => false,
        'pipefail' => false,
        'noclobber' => false,
        'noglob' => false,
        'xtrace' => false,
        'verbose' => false,
        'errtrace' => false,
        'functrace' => false,
        'allexport' => false,
        'keyword' => false,
        'noexec' => false,
    ];

    /**
     * shopt options with bash's defaults for a non-interactive shell. Only the glob and match ones,
     * expand_aliases and lastpipe change anything here; the rest are listed and stored.
     *
     * @var array<string, bool>
     */
    public array $shopt = [
        'array_expand_once' => false, 'assoc_expand_once' => false, 'autocd' => false, 'bash_source_fullpath' => false,
        'cdable_vars' => false, 'cdspell' => false, 'checkhash' => false, 'checkjobs' => false, 'checkwinsize' => true,
        'cmdhist' => true, 'compat31' => false, 'compat32' => false, 'compat40' => false, 'compat41' => false,
        'compat42' => false, 'compat43' => false, 'compat44' => false, 'complete_fullquote' => true, 'direxpand' => false,
        'dirspell' => false, 'dotglob' => false, 'execfail' => false, 'expand_aliases' => false, 'extdebug' => false,
        'extglob' => false, 'extquote' => true, 'failglob' => false, 'force_fignore' => true, 'globasciiranges' => true,
        'globskipdots' => true, 'globstar' => false, 'gnu_errfmt' => false, 'histappend' => false, 'histreedit' => false,
        'histverify' => false, 'hostcomplete' => true, 'huponexit' => false, 'inherit_errexit' => false,
        'interactive_comments' => true, 'lastpipe' => false, 'lithist' => false, 'localvar_inherit' => false,
        'localvar_unset' => false, 'login_shell' => false, 'mailwarn' => false, 'no_empty_cmd_completion' => false,
        'nocaseglob' => false, 'nocasematch' => false, 'noexpand_translation' => false, 'nullglob' => false,
        'patsub_replacement' => true, 'progcomp' => true, 'progcomp_alias' => false, 'promptvars' => true,
        'restricted_shell' => false, 'shift_verbose' => false, 'sourcepath' => true, 'varredir_close' => false,
        'xpg_echo' => false,
    ];

    /** The line of the command being run, for $LINENO; a global LINENO assignment doesn't stick, as in bash */
    public int $currentLine = 0;

    /** False once LINENO is unset: it is then an ordinary variable */
    private bool $linenoSpecial = true;

    /** @var array<string, array<int|string, string>> */
    public array $arrays = ['BASH_LINENO' => [], 'BASH_SOURCE' => []];

    /** @var array<string, true> names with the export attribute: only these reach commands' environment */
    public array $exported = [];

    /** @var array<string, string> the a, A, i, l, n and u attribute letters of each variable that has any */
    public array $attributes = [];

    /** @var Closure(string): int evaluates an -i variable's new value, without expanding it */
    public Closure $arithmetic;

    /** @var Closure(string): void reports a warning on the shell's stderr */
    public Closure $warn;

    /** @var array<string, string> */
    public array $aliases = [];

    /** @var array<string, true> */
    public array $readonlyVars = [];

    /** @var array<string, string> */
    public array $traps = [];

    /** @var list<string> */
    public array $directoryStack = [];

    /** @var list<array{line: int, function: string, file: string}> Function and `source` frames, innermost last */
    public array $callStack = [];

    /** @var array<string, true> */
    public array $disabledBuiltins = [];

    public string $umask = '0022';

    /**
     * $env is the process environment, all exported; HOME, USER, PATH and (unexported) IFS get defaults.
     *
     * @param  array<string, string>  $env
     */
    public function __construct(public array $env = [], public string $cwd = '/home/user', public readonly Limits $limits = new Limits)
    {
        $this->env += ['HOME' => '/home/user', 'USER' => 'user', 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
        $this->exported = array_fill_keys(array_keys($this->env), true);
        $this->env['IFS'] ??= " \t\n";
    }

    public function markReadonly(string $name): void
    {
        $this->readonlyVars[$name] = true;
    }

    public function isReadonly(string $name): bool
    {
        return isset($this->readonlyVars[$name]);
    }

    /** The innermost function scope that has $name as a local, or null for a global. */
    private function localScopeOf(string $name): ?int
    {
        for ($i = count($this->localScopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->localScopes[$i])) {
                return $i;
            }
        }

        return null;
    }

    public function isLocal(string $name): bool
    {
        return $this->localScopeOf($name) !== null;
    }

    public function hasAttribute(string $name, string $letter): bool
    {
        return str_contains($this->attributes[$name] ?? '', $letter);
    }

    public function setAttribute(string $name, string $letter, bool $on = true): void
    {
        $letters = str_replace($letter, '', $this->attributes[$name] ?? '').($on ? $letter : '');

        if ($letters === '') {
            unset($this->attributes[$name]);
        } else {
            $this->attributes[$name] = $letters;
        }
    }

    public function isArray(string $name): bool
    {
        return isset($this->arrays[$name]) || $this->hasAttribute($name, 'a') || $this->hasAttribute($name, 'A');
    }

    /**
     * The variable a name stands for: itself, or the end of its nameref chain, which may be an element (`a[1]`).
     * A chain that comes back to $name is null, and reported unless $quiet.
     */
    public function resolve(string $name, bool $quiet = false): ?string
    {
        $target = $name;

        // bash gives up after NAMEREF_MAX (8) links
        for ($links = 0; $this->hasAttribute($target, 'n') && ($this->env[$target] ?? '') !== ''; $links++) {
            $target = $this->env[$target];

            if ($target === $name || $links === 8) {
                if (! $quiet) {
                    ($this->warn)($name.': circular name reference');
                }

                return null;
            }
        }

        return $target;
    }

    /**
     * The variable and subscript `name` or `name[sub]` stands for once namerefs are followed; null for a circular one.
     *
     * @return array{string, int|string|null}|null
     */
    private function target(string $name, bool $quiet = false): ?array
    {
        [$name, $key] = $this->element($name);
        $target = $this->resolve($name, $quiet);

        if ($target === null) {
            return null;
        }

        [$target, $targetKey] = $this->element($target);

        return [$target, $key ?? $targetKey];
    }

    /** @return array{string, int|string|null} */
    private function element(string $name): array
    {
        if (preg_match('/^(\w+)\[(.*)\]$/s', $name, $m) !== 1) {
            return [$name, null];
        }

        return [$m[1], preg_match('/^-?\d+$/', $m[2]) === 1 ? (int) $m[2] : $m[2]];
    }

    /** @return array{string, int|string|null} */
    private function assignee(string $name): array
    {
        return $this->target($name, quiet: true) ?? throw new AssignmentException(sprintf('bash: warning: %s: circular name reference', $name));
    }

    public function getVar(string $name): ?string
    {
        [$name, $key] = $this->target($name) ?? ['', null];

        if ($key !== null) {
            return $this->arrays[$name][$key] ?? null;
        }

        if ($name === 'LINENO' && $this->linenoSpecial && ! $this->isLocal($name)) {
            return (string) $this->currentLine;
        }

        // An array's bare name means its element 0
        return $this->env[$name] ?? $this->arrays[$name][0] ?? null;
    }

    /** Assigning to an array's bare name sets its element 0; $append is `+=`, and $plain skips -i, -l and -u. */
    public function setVar(string $name, string $value, bool $append = false, bool $plain = false): void
    {
        [$name, $key] = $this->assignee($name);

        if ($key !== null || $this->isArray($name)) {
            $this->setElement($name, $key ?? 0, $value, $append);
        } else {
            $this->refuseReadonly($name);
            $old = $append ? $this->env[$name] ?? null : null;
            $this->env[$name] = $this->limitString($plain ? $old.$value : $this->attributeValue($name, $value, $old));
        }

        if ($key === null && $this->shellOpts['allexport']) {
            $this->exported[$name] = true;
        }
    }

    /** Sets a nameref itself rather than what it points to, as `for r in a b` does */
    public function setReference(string $name, string $value): void
    {
        $this->refuseReadonly($name);
        $this->env[$name] = $value;
    }

    /**
     * The variable as an array: a scalar is one element at index 0.
     *
     * @return array<int|string, string>
     */
    public function getArray(string $name): array
    {
        $name = $this->target($name)[0] ?? '';

        return $this->arrays[$name] ?? (isset($this->env[$name]) ? [$this->env[$name]] : []);
    }

    /**
     * Replaces the whole array, each value taking the variable's attributes.
     *
     * @param  array<int|string, string>  $values
     */
    public function setArray(string $name, array $values): void
    {
        [$name] = $this->assignee($name);
        $this->refuseReadonly($name);
        $this->limitCount(count($values));
        unset($this->env[$name]);
        $this->arrays[$name] = array_map(fn (string $value): string => $this->attributeValue($name, $value, null), $values);
    }

    /** Sets one element, a scalar becoming element 0 of the array; $append is `+=`. */
    public function setElement(string $name, int|string $key, string $value, bool $append = false): void
    {
        [$name] = $this->assignee($name);
        $this->refuseReadonly($name);

        if (! isset($this->arrays[$name])) {
            $this->arrays[$name] = isset($this->env[$name]) ? [$this->env[$name]] : [];
            unset($this->env[$name]);
        }

        $old = $append ? $this->arrays[$name][$key] ?? null : null;
        $this->arrays[$name][$key] = $this->limitString($this->attributeValue($name, $value, $old));
        $this->limitCount(count($this->arrays[$name]));
    }

    /** What an assignment stores under -i (arithmetic, with `+=` adding), -u and -l; $old is the value `+=` extends */
    private function attributeValue(string $name, string $value, ?string $old): string
    {
        if ($this->hasAttribute($name, 'i')) {
            $value = (string) Int64::add($old === null ? 0 : ($this->arithmetic)($old), ($this->arithmetic)($value));
        } elseif ($old !== null) {
            $value = $old.$value;
        }

        return match (true) {
            $this->hasAttribute($name, 'u') => mb_strtoupper($value),
            $this->hasAttribute($name, 'l') => mb_strtolower($value),
            default => $value,
        };
    }

    private function refuseReadonly(string $name): void
    {
        if ($this->isReadonly($name)) {
            throw AssignmentException::readonly($name);
        }
    }

    /** Refuses a value longer than maxStringLength. */
    public function limitString(string $value): string
    {
        $this->limitLength(strlen($value));

        return $value;
    }

    public function limitLength(int $length): void
    {
        if ($length > $this->limits->maxStringLength) {
            throw new ExecutionLimitException(sprintf('String length limit exceeded (%d bytes)', $this->limits->maxStringLength));
        }
    }

    /** Refuses an array or word list longer than maxArrayElements. */
    public function limitCount(int $count): void
    {
        if ($count > $this->limits->maxArrayElements) {
            throw new ExecutionLimitException(sprintf('Array or word list limit exceeded (%d elements)', $this->limits->maxArrayElements));
        }
    }

    /** Removes the variable, scalar and array alike, or with $reference a nameref itself rather than its target. */
    public function unsetVar(string $name, bool $reference = false): void
    {
        [$name, $key] = $reference ? [$name, null] : $this->target($name) ?? ['', null];

        if ($key !== null) {
            unset($this->arrays[$name][$key]);

            return;
        }

        $scope = $this->localScopeOf($name);

        if ($scope === null || $scope === count($this->localScopes) - 1) {
            // Unset in its own function, a local stays local (and unset), still hiding the outer variable
            unset($this->env[$name], $this->arrays[$name], $this->exported[$name], $this->attributes[$name]);
            $this->linenoSpecial = $this->linenoSpecial && ($name !== 'LINENO' || $scope !== null);
        } else {
            // Unset from a function it called, it's removed, uncovering the outer value
            $this->restoreLocal($name, $this->localScopes[$scope][$name]);
            unset($this->localScopes[$scope][$name]);
        }
    }

    /** `declare -g x=v`: the variable outside every function, even while a local of the same name hides it. */
    public function setGlobal(string $name, string $value): void
    {
        foreach ($this->localScopes as $i => $scope) {
            if (array_key_exists($name, $scope)) {
                $this->localScopes[$i][$name][0] = $value;

                return;
            }
        }

        $this->setVar($name, $value);
    }

    public function pushLocalScope(): void
    {
        $this->localScopes[] = [];
    }

    /** Leaving a function puts back what its locals hid. */
    public function popLocalScope(): void
    {
        foreach (array_pop($this->localScopes) ?? [] as $name => $saved) {
            $this->restoreLocal($name, $saved);
        }
    }

    /** Makes $name local to the innermost function, unset until assigned, inheriting the export attribute but no other. */
    public function declareLocal(string $name): void
    {
        $top = count($this->localScopes) - 1;

        if (! array_key_exists($name, $this->localScopes[$top])) {
            $this->localScopes[$top][$name] = [$this->env[$name] ?? null, $this->arrays[$name] ?? null, isset($this->exported[$name]), $this->attributes[$name] ?? ''];
            unset($this->env[$name], $this->arrays[$name], $this->attributes[$name]);
        }
    }

    /** @param array{?string, ?array<int|string, string>, bool, string} $saved */
    private function restoreLocal(string $name, array $saved): void
    {
        [$value, $array, $exported, $attributes] = $saved;
        // A local made readonly stops being so
        unset($this->env[$name], $this->arrays[$name], $this->readonlyVars[$name], $this->exported[$name], $this->attributes[$name]);

        if ($value !== null) {
            $this->env[$name] = $value;
        }

        if ($array !== null) {
            $this->arrays[$name] = $array;
        }

        if ($exported) {
            $this->exported[$name] = true;
        }

        if ($attributes !== '') {
            $this->attributes[$name] = $attributes;
        }
    }

    public function incrementCommandCount(): void
    {
        $this->commandCount++;

        if ($this->commandCount > $this->limits->maxCommandCount) {
            throw new ExecutionLimitException(
                sprintf('Command count limit exceeded (%d)', $this->limits->maxCommandCount),
            );
        }
    }

    /**
     * Commands' environment: the exported variables' current values (arrays are never exported).
     *
     * @return array<string, string>
     */
    public function getExportedEnv(): array
    {
        return array_intersect_key($this->env, $this->exported);
    }

    /**
     * Enters a function or sourced file, keeping FUNCNAME, BASH_LINENO and BASH_SOURCE in step (innermost first).
     * The frame records the current line as the call's; $file is where the function was defined, or the sourced file.
     * Leaving it with popFrame() puts $LINENO back on the calling line.
     */
    public function pushFrame(string $function, string $file): void
    {
        $this->callStack[] = ['line' => $this->currentLine, 'function' => $function, 'file' => $file];
        $this->syncFrameArrays();
    }

    public function popFrame(): void
    {
        $this->currentLine = array_pop($this->callStack)['line'] ?? $this->currentLine;
        $this->syncFrameArrays();
    }

    /** The file being run: the innermost function's or sourced file's, else $0 */
    public function currentSource(): string
    {
        return $this->callStack === [] ? (string) $this->getSpecialVar('0') : $this->callStack[count($this->callStack) - 1]['file'];
    }

    private function syncFrameArrays(): void
    {
        $frames = array_reverse($this->callStack);
        $this->arrays['BASH_LINENO'] = array_map(strval(...), array_column($frames, 'line'));
        $this->arrays['BASH_SOURCE'] = array_column($frames, 'file');
        $this->arrays['FUNCNAME'] = array_column($frames, 'function');

        if ($frames === []) {
            unset($this->arrays['FUNCNAME']);
        }
    }

    /**
     * Values the shell provides when no variable of that name is set. `$!` and `$_` stay unset: there are no
     * background jobs, and the last argument isn't tracked.
     */
    public function getSpecialVar(string $name): ?string
    {
        return match ($name) {
            '?' => (string) $this->lastExitCode,
            '#' => (string) count($this->positionalParams),
            '0' => $this->scriptName,
            '$', 'BASHPID' => '1',
            '-' => $this->getSetFlags(),
            'RANDOM' => (string) random_int(0, 32767),
            'SECONDS' => '0',
            'BASH_VERSION' => '5.2.0(1)-release',
            'BASH_VERSINFO' => '5',
            'HOSTNAME' => 'localhost',
            'BASH_SUBSHELL' => (string) $this->subshellDepth,
            'PWD' => $this->cwd,
            default => ctype_digit($name) ? $this->positionalParams[(int) $name - 1] ?? null : null,
        };
    }

    private function getSetFlags(): string
    {
        // In bash's order; h (hashall), B (braceexpand) and c (a script given as a string, like `bash -c`) are always on
        $options = ['a' => 'allexport', 'e' => 'errexit', 'f' => 'noglob', 'h' => null, 'k' => 'keyword', 'n' => 'noexec', 'u' => 'nounset', 'v' => 'verbose', 'x' => 'xtrace', 'B' => null, 'C' => 'noclobber', 'E' => 'errtrace', 'T' => 'functrace', 'c' => null];
        $flags = '';

        foreach ($options as $flag => $option) {
            if ($option === null || ($this->shellOpts[$option] ?? false)) {
                $flags .= $flag;
            }
        }

        return $flags;
    }

    /** Undoes everything a subshell changed; the command counter keeps running so limits span the whole script. */
    public function restore(self $snapshot): void
    {
        foreach (get_object_vars($snapshot) as $property => $value) {
            if ($property !== 'limits' && $property !== 'commandCount') {
                $this->{$property} = $value;
            }
        }
    }
}
