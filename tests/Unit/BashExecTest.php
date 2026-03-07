<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Exceptions\ParseException;
use BashBox\ExecOptions;
use BashBox\Limits;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(
        cwd: '/home/user',
        env: ['USER' => 'testuser', 'HOME' => '/home/user'],
    ));
});

test('echo hello world', function (): void {
    $result = $this->bash->exec('echo hello world');

    expect($result->stdout)->toBe("hello world\n");
    expect($result->exitCode)->toBe(0);
});

test('echo with -n flag', function (): void {
    $result = $this->bash->exec('echo -n hello');

    expect($result->stdout)->toBe('hello');
});

test('variable assignment and expansion', function (): void {
    $result = $this->bash->exec('x=hello; echo $x');

    expect($result->stdout)->toBe("hello\n");
});

test('multiple variable assignments', function (): void {
    $result = $this->bash->exec('a=1; b=2; echo $a $b');

    expect($result->stdout)->toBe("1 2\n");
});

test('command not found', function (): void {
    $result = $this->bash->exec('nonexistent_command');

    expect($result->exitCode)->toBe(127);
    expect($result->stderr)->toContain('command not found');
});

test('exit code propagation', function (): void {
    $result = $this->bash->exec('true');
    expect($result->exitCode)->toBe(0);

    $result = $this->bash->exec('false');
    expect($result->exitCode)->toBe(1);
});

test('pipe between commands', function (): void {
    $result = $this->bash->exec('echo "hello world" | cat');

    expect($result->stdout)->toBe("hello world\n");
});

test('and operator', function (): void {
    $result = $this->bash->exec('true && echo success');

    expect($result->stdout)->toBe("success\n");
});

test('and operator with failure', function (): void {
    $result = $this->bash->exec('false && echo should_not_appear');

    expect($result->stdout)->toBe('');
});

test('or operator', function (): void {
    $result = $this->bash->exec('false || echo fallback');

    expect($result->stdout)->toBe("fallback\n");
});

test('write and read file', function (): void {
    $result = $this->bash->exec('echo "file content" > /tmp/test.txt && cat /tmp/test.txt');

    expect($result->stdout)->toBe("file content\n");
});

test('append to file', function (): void {
    $result = $this->bash->exec('echo line1 > /tmp/append.txt; echo line2 >> /tmp/append.txt; cat /tmp/append.txt');

    expect($result->stdout)->toBe("line1\nline2\n");
});

test('if statement true', function (): void {
    $result = $this->bash->exec('if true; then echo yes; fi');

    expect($result->stdout)->toBe("yes\n");
});

test('if statement false with else', function (): void {
    $result = $this->bash->exec('if false; then echo yes; else echo no; fi');

    expect($result->stdout)->toBe("no\n");
});

test('for loop', function (): void {
    $result = $this->bash->exec('for i in a b c; do echo $i; done');

    expect($result->stdout)->toBe("a\nb\nc\n");
});

test('while loop', function (): void {
    $result = $this->bash->exec('i=0; while [[ $i -lt 3 ]]; do echo $i; i=$((i+1)); done');

    expect($result->stdout)->toBe("0\n1\n2\n");
});

test('function definition and call', function (): void {
    $result = $this->bash->exec('greet() { echo "hello $1"; }; greet world');

    expect($result->stdout)->toBe("hello world\n");
});

test('arithmetic expansion', function (): void {
    $result = $this->bash->exec('echo $((2 + 3))');

    expect($result->stdout)->toBe("5\n");
});

test('arithmetic command', function (): void {
    $result = $this->bash->exec('(( 5 > 3 ))');

    expect($result->exitCode)->toBe(0);
});

test('conditional command string comparison', function (): void {
    $result = $this->bash->exec('[[ "hello" == "hello" ]]');

    expect($result->exitCode)->toBe(0);
});

test('conditional command file test', function (): void {
    $result = $this->bash->exec('echo test > /tmp/exists.txt; [[ -f /tmp/exists.txt ]]');

    expect($result->exitCode)->toBe(0);
});

test('case statement', function (): void {
    $result = $this->bash->exec('x=hello; case $x in hello) echo matched;; world) echo no;; esac');

    expect($result->stdout)->toBe("matched\n");
});

test('subshell isolation', function (): void {
    $result = $this->bash->exec('x=before; (x=inside; echo $x); echo $x');

    expect($result->stdout)->toBe("inside\nbefore\n");
});

test('group command', function (): void {
    $result = $this->bash->exec('{ echo a; echo b; }');

    expect($result->stdout)->toBe("a\nb\n");
});

test('here string', function (): void {
    $result = $this->bash->exec('cat <<< "hello world"');

    expect($result->stdout)->toBe("hello world\n");
});

test('pwd command', function (): void {
    $result = $this->bash->exec('pwd');

    expect($result->stdout)->toBe("/home/user\n");
});

test('cd command', function (): void {
    $result = $this->bash->exec('mkdir -p /tmp/testdir && cd /tmp/testdir && pwd');

    expect($result->stdout)->toBe("/tmp/testdir\n");
});

test('parameter expansion default value', function (): void {
    $result = $this->bash->exec('echo ${UNSET:-default}');

    expect($result->stdout)->toBe("default\n");
});

test('parameter expansion length', function (): void {
    $result = $this->bash->exec('x=hello; echo ${#x}');

    expect($result->stdout)->toBe("5\n");
});

test('special variable dollar question mark', function (): void {
    $result = $this->bash->exec('true; echo $?');

    expect($result->stdout)->toBe("0\n");
});

test('special variable dollar hash', function (): void {
    $result = $this->bash->exec('echo $#');

    expect($result->stdout)->toBe("0\n");
});

test('execution limit prevents infinite loops', function (): void {
    $bash = new Bash(new BashOptions(
        limits: new Limits(maxLoopIterations: 10),
    ));

    expect(fn (): \BashBox\BashExecResult => $bash->exec('while true; do echo x; done'))
        ->toThrow(\BashBox\Exceptions\ExecutionLimitException::class);
});

test('command count limit', function (): void {
    $bash = new Bash(new BashOptions(
        limits: new Limits(maxCommandCount: 5),
    ));

    expect(fn (): \BashBox\BashExecResult => $bash->exec('echo 1; echo 2; echo 3; echo 4; echo 5; echo 6'))
        ->toThrow(\BashBox\Exceptions\ExecutionLimitException::class);
});

test('negated pipeline', function (): void {
    $result = $this->bash->exec('! false');

    expect($result->exitCode)->toBe(0);
});

test('export and env', function (): void {
    $result = $this->bash->exec('export FOO=bar; echo $FOO');

    expect($result->stdout)->toBe("bar\n");
});

test('local variables in function', function (): void {
    $result = $this->bash->exec('x=outer; f() { local x=inner; echo $x; }; f; echo $x');

    expect($result->stdout)->toBe("inner\nouter\n");
});

// =========================================================================
// READONLY
// =========================================================================

test('readonly variable cannot be reassigned', function (): void {
    $result = $this->bash->exec('readonly x=5; x=10; echo $x');

    expect($result->stderr)->toContain('readonly variable');
    expect($result->stdout)->toBe("5\n");
});

test('readonly variable cannot be unset', function (): void {
    $result = $this->bash->exec('readonly x=5; unset x');

    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('readonly variable');
});

test('readonly -p lists readonly variables', function (): void {
    $result = $this->bash->exec('readonly x=hello; readonly -p');

    expect($result->stdout)->toContain('declare -r x="hello"');
});

test('readonly marks existing variable', function (): void {
    $result = $this->bash->exec('x=5; readonly x; x=10; echo $x');

    expect($result->stderr)->toContain('readonly variable');
    expect($result->stdout)->toBe("5\n");
});

test('declare -r enforces readonly', function (): void {
    $result = $this->bash->exec('declare -r y=42; y=99; echo $y');

    expect($result->stderr)->toContain('readonly variable');
    expect($result->stdout)->toBe("42\n");
});

// =========================================================================
// TRAP
// =========================================================================

test('trap EXIT runs on script exit', function (): void {
    $result = $this->bash->exec('trap "echo goodbye" EXIT; echo hello');

    expect($result->stdout)->toBe("hello\ngoodbye\n");
});

test('trap EXIT runs on explicit exit', function (): void {
    $result = $this->bash->exec('trap "echo cleanup" EXIT; exit 0');

    expect($result->stdout)->toBe("cleanup\n");
});

test('trap - removes trap', function (): void {
    $result = $this->bash->exec('trap "echo bye" EXIT; trap - EXIT; echo hello');

    expect($result->stdout)->toBe("hello\n");
});

test('trap with no args lists traps', function (): void {
    $result = $this->bash->exec('trap "echo bye" EXIT; trap');

    expect($result->stdout)->toContain("trap -- 'echo bye' EXIT");
});

test('trap ERR runs on non-zero exit', function (): void {
    $result = $this->bash->exec('trap "echo error_caught" ERR; false; echo done');

    expect($result->stdout)->toContain('error_caught');
    expect($result->stdout)->toContain('done');
});

test('trap RETURN runs at end of function', function (): void {
    $result = $this->bash->exec('trap "echo returned" RETURN; f() { echo inside; }; f; echo after');

    expect($result->stdout)->toContain('returned');
    expect($result->stdout)->toContain('inside');
});

// =========================================================================
// BUILTIN COMMAND
// =========================================================================

test('builtin command delegates to builtin', function (): void {
    $result = $this->bash->exec('builtin echo hello');

    // echo is not a builtin in our system, but type/cd etc are
    $result = $this->bash->exec('builtin cd /tmp && pwd');

    expect($result->stdout)->toBe("/tmp\n");
});

test('builtin command errors for non-builtins', function (): void {
    $result = $this->bash->exec('builtin cat');

    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('not a shell builtin');
});

// =========================================================================
// EXEC BUILTIN
// =========================================================================

test('exec replaces shell with command', function (): void {
    $result = $this->bash->exec('exec echo done; echo should_not_appear');

    expect($result->stdout)->toContain("done\n");
    expect($result->stdout)->not->toContain('should_not_appear');
});

test('exec with no args returns success', function (): void {
    $result = $this->bash->exec('exec; echo still_here');

    expect($result->stdout)->toBe("still_here\n");
});

// =========================================================================
// PUSHD / POPD / DIRS
// =========================================================================

test('pushd and popd manage directory stack', function (): void {
    $result = $this->bash->exec('mkdir -p /tmp/a /tmp/b; pushd /tmp/a; pushd /tmp/b; popd; pwd');

    expect($result->stdout)->toContain('/tmp/a');
});

test('dirs shows directory stack', function (): void {
    $result = $this->bash->exec('mkdir -p /tmp/d1; pushd /tmp/d1; dirs');

    expect($result->stdout)->toContain('/tmp/d1');
});

test('dirs -c clears stack', function (): void {
    $result = $this->bash->exec('mkdir -p /tmp/d1 /tmp/d2; pushd /tmp/d1; pushd /tmp/d2; dirs -c; dirs');

    $lines = array_filter(explode("\n", (string) $result->stdout));
    $lastLine = end($lines);
    // After dirs -c, only cwd remains (no stack entries)
    expect($lastLine)->toBe('/tmp/d2');
});

test('popd on empty stack errors', function (): void {
    $result = $this->bash->exec('popd');

    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('directory stack empty');
});

// =========================================================================
// CALLER
// =========================================================================

test('caller inside function returns frame', function (): void {
    $result = $this->bash->exec('f() { caller 0; }; f');

    expect($result->exitCode)->toBe(0);
    expect($result->stdout)->toContain('f');
});

test('caller outside function returns error', function (): void {
    $result = $this->bash->exec('caller');

    expect($result->exitCode)->toBe(1);
});

// =========================================================================
// HELP
// =========================================================================

test('help lists builtins', function (): void {
    $result = $this->bash->exec('help');

    expect($result->stdout)->toContain('cd');
    expect($result->stdout)->toContain('export');
    expect($result->stdout)->toContain('readonly');
});

test('help with pattern filters', function (): void {
    $result = $this->bash->exec('help cd');

    expect($result->stdout)->toContain('cd');
    expect($result->stdout)->toContain('Change');
});

// =========================================================================
// ENABLE
// =========================================================================

test('enable -n disables builtin', function (): void {
    $result = $this->bash->exec('enable -n help; help');

    expect($result->exitCode)->toBe(127);
});

test('enable re-enables builtin', function (): void {
    $result = $this->bash->exec('enable -n help; enable help; help');

    expect($result->exitCode)->toBe(0);
});

// =========================================================================
// STUB BUILTINS
// =========================================================================

test('wait returns 0', function (): void {
    $result = $this->bash->exec('wait');
    expect($result->exitCode)->toBe(0);
});

test('jobs returns 0 with no output', function (): void {
    $result = $this->bash->exec('jobs');
    expect($result->exitCode)->toBe(0);
    expect($result->stdout)->toBe('');
});

test('fg returns error', function (): void {
    $result = $this->bash->exec('fg');
    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('no job control');
});

test('bg returns error', function (): void {
    $result = $this->bash->exec('bg');
    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('no job control');
});

test('kill -l lists signals', function (): void {
    $result = $this->bash->exec('kill -l');
    expect($result->exitCode)->toBe(0);
    expect($result->stdout)->toContain('HUP');
    expect($result->stdout)->toContain('TERM');
});

test('kill without -l returns error', function (): void {
    $result = $this->bash->exec('kill 1234');
    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('No such process');
});

test('suspend returns error', function (): void {
    $result = $this->bash->exec('suspend');
    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('cannot suspend');
});

test('times prints zeros', function (): void {
    $result = $this->bash->exec('times');
    expect($result->exitCode)->toBe(0);
    expect($result->stdout)->toContain('0m0.000s');
});

test('ulimit returns unlimited', function (): void {
    $result = $this->bash->exec('ulimit');
    expect($result->exitCode)->toBe(0);
    expect($result->stdout)->toContain('unlimited');
});

test('umask get and set', function (): void {
    $result = $this->bash->exec('umask');
    expect($result->stdout)->toBe("0022\n");

    $result = $this->bash->exec('umask 0077; umask');
    expect($result->stdout)->toBe("0077\n");
});

test('disown returns 0', function (): void {
    $result = $this->bash->exec('disown');
    expect($result->exitCode)->toBe(0);
});

test('complete returns 0', function (): void {
    $result = $this->bash->exec('complete -F _foo bar');
    expect($result->exitCode)->toBe(0);
});

test('compgen returns 1', function (): void {
    $result = $this->bash->exec('compgen -c');
    expect($result->exitCode)->toBe(1);
});

test('logout exits shell', function (): void {
    $result = $this->bash->exec('logout; echo should_not_appear');
    expect($result->stdout)->not->toContain('should_not_appear');
});

test('type recognizes new builtins', function (): void {
    $result = $this->bash->exec('type readonly');
    expect($result->stdout)->toContain('readonly is a shell builtin');

    $result = $this->bash->exec('type trap');
    expect($result->stdout)->toContain('trap is a shell builtin');

    $result = $this->bash->exec('type pushd');
    expect($result->stdout)->toContain('pushd is a shell builtin');
});

// =========================================================================
// ARRAYS, SET, HEREDOCS, AND EXEC STATE
// =========================================================================

test('read -a splits stdin into an array', function (): void {
    $result = $this->bash->exec(<<<'BASH'
read -a arr <<< "one two three"
echo "${arr[0]}|${arr[1]}|${arr[2]}|${#arr[@]}"
BASH);

    expect($result->stdout)->toBe("one|two|three|3\n");
});

test('mapfile and readarray populate arrays from heredoc input', function (string $builtin): void {
    $script = $builtin.<<<'BASH'
 -t arr <<EOF
a
b
EOF
echo "${arr[0]}|${arr[1]}|${#arr[@]}"
BASH;

    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe("a|b|2\n");
})->with(['mapfile', 'readarray']);

test('set -e exits on command failure', function (): void {
    $result = $this->bash->exec('set -e; false; echo after');

    expect($result->exitCode)->toBe(1);
    expect($result->stdout)->toBe('');
});

test('set -u reports unbound variables', function (): void {
    $result = $this->bash->exec('set -u; echo $MISSING');

    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('MISSING');
    expect($result->stderr)->toContain('unbound variable');
});

test('set -x traces expanded commands', function (): void {
    $result = $this->bash->exec('set -x; echo hi');

    expect($result->stdout)->toBe("hi\n");
    expect($result->stderr)->toContain('+ echo hi');
});

test('set -f disables glob expansion', function (): void {
    $result = $this->bash->exec('touch a.txt b.txt; echo *.txt');
    expect($result->stdout)->toBe("a.txt b.txt\n");

    $result = $this->bash->exec('set -f; touch c.txt d.txt; echo *.txt');
    expect($result->stdout)->toBe("*.txt\n");
});

test('set -C prevents clobbering existing files', function (): void {
    $this->bash->exec('echo old > file.txt');

    $result = $this->bash->exec('set -C; echo new > file.txt');

    expect($result->exitCode)->toBe(1);
    expect($result->stderr)->toContain('cannot overwrite existing file');
    expect($this->bash->readFile('/home/user/file.txt'))->toBe("old\n");
});

test('per-exec env override does not leak', function (): void {
    $result = $this->bash->exec('echo $USER', new ExecOptions(env: ['USER' => 'override']));
    expect($result->stdout)->toBe("override\n");

    $result = $this->bash->exec('echo $USER');
    expect($result->stdout)->toBe("testuser\n");
});

test('per-exec cwd override does not leak', function (): void {
    $result = $this->bash->exec('pwd', new ExecOptions(cwd: '/tmp'));
    expect($result->stdout)->toBe("/tmp\n");

    $result = $this->bash->exec('pwd');
    expect($result->stdout)->toBe("/home/user\n");
});

test('state is restored after parse errors', function (): void {
    expect(fn (): \BashBox\BashExecResult => $this->bash->exec('echo ('))
        ->toThrow(ParseException::class);

    $result = $this->bash->exec('echo $USER; pwd');
    expect($result->stdout)->toBe("testuser\n/home/user\n");
});

test('state is restored after command errors', function (): void {
    $result = $this->bash->exec('x=1; cd /tmp; false');
    expect($result->exitCode)->toBe(1);

    $result = $this->bash->exec('echo ${x:-unset}; pwd');
    expect($result->stdout)->toBe("unset\n/home/user\n");
});

test('variables and cwd do not leak between exec calls while files persist', function (): void {
    $result = $this->bash->exec('mkdir -p /tmp/isolation && cd /tmp/isolation && x=changed && echo persisted > state.txt');
    expect($result->exitCode)->toBe(0);

    $result = $this->bash->exec('echo ${x:-unset}; pwd; cat /tmp/isolation/state.txt');
    expect($result->stdout)->toBe("unset\n/home/user\npersisted\n");
});

test('basic heredoc feeds stdin to commands', function (): void {
    $result = $this->bash->exec(<<<'BASH'
cat <<EOF
hello
EOF
BASH);

    expect($result->stdout)->toBe("hello\n");
});

test('heredoc expands variables in unquoted bodies', function (): void {
    $result = $this->bash->exec(<<<'BASH'
name=world
cat <<EOF
hello $name
EOF
BASH);

    expect($result->stdout)->toBe("hello world\n");
});

test('heredoc strips leading tabs with <<-', function (): void {
    $result = $this->bash->exec(<<<'BASH'
cat <<-EOF
	hello
EOF
BASH);

    expect($result->stdout)->toBe("hello\n");
});

test('quoted heredoc delimiters suppress expansion', function (): void {
    $result = $this->bash->exec(<<<'BASH'
cat <<'EOF'
$HOME
EOF
BASH);

    expect($result->stdout)->toBe("\$HOME\n");
});
