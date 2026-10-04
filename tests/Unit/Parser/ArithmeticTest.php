<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Exceptions\ArithmeticException;
use BashBox\Parser\ArithmeticParser;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions);
});

test('arithmetic expansion evaluates operators with bash precedence', function (string $expr, string $expected): void {
    expect($this->bash->exec(sprintf('echo $(( %s ))', $expr))->stdout)->toBe($expected."\n");
})->with([
    ['1 + 2 * 3', '7'],
    ['(1 + 2) * 3', '9'],
    ['7 - 2 - 1', '4'],
    ['7 / 2', '3'],
    ['7 % 3', '1'],
    ['2 ** 3 ** 2', '512'],
    ['-2 ** 2', '4'],
    ['5 - -1', '6'],
    ['1 << 3', '8'],
    ['16 >> 2', '4'],
    ['1 < 2', '1'],
    ['2 > 1', '1'],
    ['3 <= 3', '1'],
    ['3 >= 4', '0'],
    ['1 == 1', '1'],
    ['1 != 1', '0'],
    ['5 & 3', '1'],
    ['5 | 3', '7'],
    ['5 ^ 3', '6'],
    ['1 && 0', '0'],
    ['0 || 1', '1'],
    ['!0', '1'],
    ['~0', '-1'],
    ['+3', '3'],
    ['++5', '5'],
    ['--5', '5'],
    ['1 ? 2 : 3', '2'],
    ['0 ? 2 : 3', '3'],
    ['1, 2', '2'],
    ['', '0'],
]);

test('integer constants support octal, hex and base#digits', function (string $expr, string $expected): void {
    expect($this->bash->exec(sprintf('echo $(( %s ))', $expr))->stdout)->toBe($expected."\n");
})->with([
    ['0x1F', '31'],
    ['0X1f', '31'],
    ['0x', '0'],
    ['010', '8'],
    ['0', '0'],
    ['2#101', '5'],
    ['16#ff', '255'],
    ['36#Zz', '1295'],
    ['64#@_', '4031'],
]);

test('assignment and increment operators update the variable', function (string $expr, string $expected): void {
    expect($this->bash->exec(sprintf('x=7; echo $(( %s )) $x', $expr))->stdout)->toBe($expected."\n");
})->with([
    ['x = 5', '5 5'],
    ['x += 2', '9 9'],
    ['x -= 2', '5 5'],
    ['x *= 3', '21 21'],
    ['x /= 2', '3 3'],
    ['x %= 3', '1 1'],
    ['x <<= 2', '28 28'],
    ['x >>= 1', '3 3'],
    ['x &= 6', '6 6'],
    ['x |= 8', '15 15'],
    ['x ^= 1', '6 6'],
    ['x = y = 9', '9 9'],
    ['x++', '7 8'],
    ['x--', '7 6'],
    ['++x', '8 8'],
    ['--x', '6 6'],
]);

test('special parameters can be used inside arithmetic', function (): void {
    expect($this->bash->exec('set -- a b; echo $(($# + 1))')->stdout)->toBe("3\n");
    expect($this->bash->exec('false; echo $(($? * 10))')->stdout)->toBe("10\n");
});

test('arithmetic syntax errors are rejected', function (string $expr, string $message): void {
    expect(fn (): \BashBox\Ast\Arithmetic\ArithExpr => new ArithmeticParser($expr)->parse())
        ->toThrow(ArithmeticException::class, $message);
})->with([
    'missing operand' => ['1 +', 'operand expected'],
    'unknown operand' => ['1 + @', 'operand expected'],
    'trailing tokens' => ['1 2', 'syntax error in expression (error token is "2")'],
    'postfix on a constant' => ['5++', 'arithmetic syntax error'],
    'unclosed group' => ['(1 + 2', "missing `)'"],
    'ternary without colon' => ['1 ? 2', "`:' expected for conditional expression"],
    'no **= operator' => ['x **= 2', 'operand expected'],
    'digit out of range' => ['08', 'value too great for base (error token is "08")'],
    'letter after decimal' => ['3x', 'value too great for base'],
    'digit beyond base 36' => ['37#Z', 'value too great for base'],
    'base too small' => ['1#1', 'invalid arithmetic base'],
    'base too large' => ['65#1', 'invalid arithmetic base'],
    'missing digits' => ['2#', 'invalid integer constant'],
    'second hash' => ['2#1#1', 'invalid number'],
]);

test('nesting deeper than the limit is an arithmetic error', function (string $expr): void {
    expect(fn (): \BashBox\Ast\Arithmetic\ArithExpr => new ArithmeticParser($expr, new \BashBox\Limits(maxAstDepth: 5))->parse())
        ->toThrow(ArithmeticException::class, 'expression recursion level exceeded');
})->with([
    'parentheses' => [str_repeat('(', 6).'1'.str_repeat(')', 6)],
    'unary operators' => [str_repeat('!', 6).'1'],
    'exponents' => ['1'.str_repeat('**1', 6)],
    'assignments' => [str_repeat('a=', 6).'1'],
    'ternaries' => [str_repeat('1?1:', 6).'1'],
]);

test('deep arithmetic nesting is reported instead of overflowing the stack', function (): void {
    $result = $this->bash->exec('(( '.str_repeat('(', 3000).'1'.str_repeat(')', 3000).' )); echo $?');

    expect($result->stdout)->toBe("1\n");
    expect($result->stderr)->toContain('expression recursion level exceeded');
});
