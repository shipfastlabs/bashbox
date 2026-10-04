<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;

test('seq', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'counting down' => ['seq 5 -2 1', "5\n3\n1\n"],
    'negative operands are not options' => ['seq -3 -1', "-3\n-2\n-1\n"],
    'empty range prints nothing' => ['seq 3 1; seq -s, 3 1', ''],
    'decimals follow FIRST and INCREMENT' => ['seq 1 0.5 2; seq 1 2.50; seq 1.0 2', "1.0\n1.5\n2.0\n1\n2\n1.0\n2.0\n"],
    'custom formats' => ["seq -f '%03g' 9 10; seq -f '%.2f' 1 0.25 1.5; seq -f '%e' 1", "009\n010\n1.00\n1.25\n1.50\n1.000000e+00\n"],
    'format with too many directives' => ["seq -f '%g %g' 1", '', "seq: format '%g %g' has too many % directives\n", 1],
    'format directive errors' => ['seq -f %5 1; seq -f a%%b 1; seq -f %l 1', '', "seq: format '%5' ends in %\nseq: format 'a%%b' has no % directive\nseq: format '%l' has unknown %l directive\n", 1],
    'format keeps %%, L and backslashes' => ["seq -f '%g%%' 1 1; seq -f %Lg 2 2; seq -f '\\t%.f' 3 3; seq -f %a 1 1", "1%\n2\n\\t3\n0x1p+0\n"],
    'separator' => ['seq -s, 1 3; seq --separator=-1 1 2', "1,2,3\n1-12\n"],
    'equal width pads after the sign' => ['seq -w 1 -0.5 -1; seq -w 1 50 100; seq --equal-width 9 10', "01.0\n00.5\n00.0\n-0.5\n-1.0\n001\n051\n09\n10\n"],
    'equal width with a format' => ['seq -w -f %g 1', '', "seq: format string may not be specified when printing equal width strings\nTry 'seq --help' for more information.\n", 1],
    'options stop at the first operand' => ['seq 3 -f', '', "seq: invalid floating point argument: '-f'\nTry 'seq --help' for more information.\n", 1],
    'invalid option' => ['seq -x -1', '', "seq: invalid option -- 'x'\nTry 'seq --help' for more information.\n", 1],
    'extra operand' => ['seq 1 2 3 4', '', "seq: extra operand '4'\nTry 'seq --help' for more information.\n", 1],
    'missing operand' => ['seq', '', "seq: missing operand\nTry 'seq --help' for more information.\n", 1],
    'non-numeric operand' => ['seq 1 abc', '', "seq: invalid floating point argument: 'abc'\nTry 'seq --help' for more information.\n", 1],
    'zero increment' => ['seq 1 0 3', '', "seq: invalid Zero increment value: '0'\nTry 'seq --help' for more information.\n", 1],
]);

test('seq stops at the output size limit instead of exhausting memory', function (string $script): void {
    expect(fn (): BashExecResult => new Bash(new BashOptions(limits: new Limits(maxOutputSize: 1000)))->exec($script))
        ->toThrow(ExecutionLimitException::class, 'seq: output size limit exceeded');
})->with([
    'a long range' => ['seq 1 5000000'],
    'an infinite one' => ['seq 1 inf'],
]);
