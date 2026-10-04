<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('uniq', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = new Bash(new BashOptions(cwd: '/home/user'))->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    '-c counts runs' => ["printf 'a\\na\\nb\\na\\n' | uniq -c", "      2 a\n      1 b\n      1 a\n"],
    '-d keeps only repeated lines' => ["printf 'a\\na\\nb\\n' | uniq -d", "a\n"],
    '-u keeps only unique lines' => ["printf 'a\\na\\nb\\n' | uniq -u", "b\n"],
    'adds a missing final newline' => ["printf 'x\\nx' | uniq", "x\n"],
    'empty input' => ["printf '' | uniq", ''],
    'second operand is the output file' => ["printf 'a\\na\\n' > in; uniq in out; cat out", "a\n"],
    'missing input' => ['uniq nope', '', "uniq: nope: No such file or directory\n", 1],
    'output into a missing directory' => ["printf 'a\\n' > in; uniq in nodir/out; ls -d nodir 2>/dev/null", '', "uniq: nodir/out: No such file or directory\n", 2],
    'output under a file' => ["printf 'a\\n' > in; touch file; uniq in file/out", '', "uniq: file/out: Not a directory\n", 1],
    '-i ignores case' => ["printf 'a\\nA\\nb\\n' | uniq -ic", "      2 a\n      1 b\n"],
    '-f skips fields' => ["printf 'x a\\ny a\\nz b\\n' | uniq -f1", "x a\nz b\n"],
    '-f past the last field compares empty keys' => ["printf 'a  b\\nc b\\n' | uniq -f 5", "a  b\n"],
    '-s skips bytes' => ["printf 'xa\\nya\\nzb\\n' | uniq --skip-chars=1", "xa\nzb\n"],
    '-w compares a prefix' => ["printf 'ab1\\nab2\\nac\\n' | uniq -w2", "ab1\nac\n"],
    'invalid -w' => ['uniq -w x', '', "uniq: x: invalid number of bytes to compare\n", 1],
    'invalid -f' => ['uniq -f -1', '', "uniq: -1: invalid number of fields to skip\n", 1],
    'extra operand' => ['uniq a b c', '', "uniq: extra operand 'c'\nTry 'uniq --help' for more information.\n", 1],
    'output onto a directory' => ["printf 'a\\n' > in; mkdir dir; uniq in dir", '', "uniq: dir: Is a directory\n", 1],
]);
