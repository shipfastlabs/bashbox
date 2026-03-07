<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
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
