<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(
        cwd: '/home/user',
        env: ['USER' => 'testuser', 'HOME' => '/home/user'],
    ));
});

// ===== Variable Expansion =====

test('simple variable expansion', function (): void {
    $result = $this->bash->exec('x=hello; echo $x');
    expect($result->stdout)->toBe("hello\n");
});

test('variable in double quotes', function (): void {
    $result = $this->bash->exec('x="hello world"; echo "$x"');
    expect($result->stdout)->toBe("hello world\n");
});

test('single quotes prevent expansion', function (): void {
    $result = $this->bash->exec("x=hello; echo '\$x'");
    expect($result->stdout)->toBe("\$x\n");
});

test('braced variable expansion', function (): void {
    $result = $this->bash->exec('x=hello; echo ${x}world');
    expect($result->stdout)->toBe("helloworld\n");
});

// ===== Parameter Expansion =====

test('default value with unset', function (): void {
    $result = $this->bash->exec('echo ${unset:-default}');
    expect($result->stdout)->toBe("default\n");
});

test('default value with set', function (): void {
    $result = $this->bash->exec('x=hello; echo ${x:-default}');
    expect($result->stdout)->toBe("hello\n");
});

test('default value with empty and colon', function (): void {
    $result = $this->bash->exec('x=""; echo ${x:-default}');
    expect($result->stdout)->toBe("default\n");
});

test('default value without colon allows empty', function (): void {
    $result = $this->bash->exec('x=""; echo ${x-default}');
    expect($result->stdout)->toBe("\n");
});

test('assign default', function (): void {
    $result = $this->bash->exec('echo ${y:=assigned}; echo $y');
    expect($result->stdout)->toBe("assigned\nassigned\n");
});

test('alternative value', function (): void {
    $result = $this->bash->exec('x=hello; echo ${x:+alternative}');
    expect($result->stdout)->toBe("alternative\n");
});

test('alternative value unset', function (): void {
    $result = $this->bash->exec('echo ${unset:+alternative}');
    expect($result->stdout)->toBe("\n");
});

test('string length', function (): void {
    $result = $this->bash->exec('x=hello; echo ${#x}');
    expect($result->stdout)->toBe("5\n");
});

test('substring extraction', function (): void {
    $result = $this->bash->exec('x=helloworld; echo ${x:5}');
    expect($result->stdout)->toBe("world\n");
});

test('substring with length', function (): void {
    $result = $this->bash->exec('x=helloworld; echo ${x:0:5}');
    expect($result->stdout)->toBe("hello\n");
});

test('pattern removal prefix shortest', function (): void {
    $result = $this->bash->exec('x=hello.world.txt; echo ${x#*.}');
    expect($result->stdout)->toBe("world.txt\n");
});

test('pattern removal prefix longest', function (): void {
    $result = $this->bash->exec('x=hello.world.txt; echo ${x##*.}');
    expect($result->stdout)->toBe("txt\n");
});

test('pattern removal suffix shortest', function (): void {
    $result = $this->bash->exec('x=hello.world.txt; echo ${x%.*}');
    expect($result->stdout)->toBe("hello.world\n");
});

test('pattern removal suffix longest', function (): void {
    $result = $this->bash->exec('x=hello.world.txt; echo ${x%%.*}');
    expect($result->stdout)->toBe("hello\n");
});

test('pattern replacement single', function (): void {
    $result = $this->bash->exec('x=hello; echo ${x/l/L}');
    expect($result->stdout)->toBe("heLlo\n");
});

test('pattern replacement all', function (): void {
    $result = $this->bash->exec('x=hello; echo ${x//l/L}');
    expect($result->stdout)->toBe("heLLo\n");
});

test('case modification uppercase first', function (): void {
    $result = $this->bash->exec('x=hello; echo ${x^}');
    expect($result->stdout)->toBe("Hello\n");
});

test('case modification uppercase all', function (): void {
    $result = $this->bash->exec('x=hello; echo ${x^^}');
    expect($result->stdout)->toBe("HELLO\n");
});

test('case modification lowercase first', function (): void {
    $result = $this->bash->exec('x=HELLO; echo ${x,}');
    expect($result->stdout)->toBe("hELLO\n");
});

test('case modification lowercase all', function (): void {
    $result = $this->bash->exec('x=HELLO; echo ${x,,}');
    expect($result->stdout)->toBe("hello\n");
});

// ===== Command Substitution =====

test('command substitution with dollar-paren', function (): void {
    $result = $this->bash->exec('echo $(echo hello)');
    expect($result->stdout)->toBe("hello\n");
});

test('nested command substitution', function (): void {
    $result = $this->bash->exec('echo $(echo $(echo deep))');
    expect($result->stdout)->toBe("deep\n");
});

test('backtick command substitution', function (): void {
    $result = $this->bash->exec('echo `echo hello`');
    expect($result->stdout)->toBe("hello\n");
});

// ===== Arithmetic Expansion =====

test('arithmetic expansion basic', function (): void {
    $result = $this->bash->exec('echo $((2 + 3))');
    expect($result->stdout)->toBe("5\n");
});

test('arithmetic expansion with variables', function (): void {
    $result = $this->bash->exec('x=10; y=3; echo $((x + y))');
    expect($result->stdout)->toBe("13\n");
});

test('arithmetic expansion operators', function (): void {
    $result = $this->bash->exec('echo $((10 * 3))');
    expect($result->stdout)->toBe("30\n");

    $result = $this->bash->exec('echo $((10 / 3))');
    expect($result->stdout)->toBe("3\n");

    $result = $this->bash->exec('echo $((10 % 3))');
    expect($result->stdout)->toBe("1\n");
});

// ===== Special Variables =====

test('dollar question mark last exit code', function (): void {
    $result = $this->bash->exec('true; echo $?');
    expect($result->stdout)->toBe("0\n");

    $result = $this->bash->exec('false; echo $?');
    expect($result->stdout)->toBe("1\n");
});

test('dollar hash argument count', function (): void {
    $result = $this->bash->exec('echo $#');
    expect($result->stdout)->toBe("0\n");
});

test('positional params in function', function (): void {
    $result = $this->bash->exec('f() { echo $1 $2; }; f hello world');
    expect($result->stdout)->toBe("hello world\n");
});

test('dollar at and dollar star', function (): void {
    $result = $this->bash->exec('f() { echo "$@"; }; f a b c');
    expect($result->stdout)->toBe("a b c\n");
});

// ===== Tilde Expansion =====

test('tilde expands to HOME', function (): void {
    $result = $this->bash->exec('echo ~');
    expect($result->stdout)->toBe("/home/user\n");
});

// ===== Escape Handling =====

test('backslash escape in unquoted', function (): void {
    $result = $this->bash->exec('echo hello\\ world');
    expect($result->stdout)->toBe("hello world\n");
});
