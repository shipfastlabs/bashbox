<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('comm', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/a', "apple\nbanana\ncherry\n");
    $bash->writeFile('/home/user/b', "banana\ncherry\ndate\n");
    $bash->writeFile('/home/user/u', "b\na\nc\n");
    $bash->writeFile('/home/user/u2', "a\nb\nc\n");
    $bash->writeFile('/home/user/n', "x\ny");

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'three columns' => ['comm a b', "apple\n\t\tbanana\n\t\tcherry\n\tdate\n"],
    'suppress columns' => ['comm -12 a b', "banana\ncherry\n"],
    'only first' => ['comm -23 a b', "apple\n"],
    'only second' => ['comm -13 a b', "date\n"],
    'suppress all' => ['comm -123 a b', ''],
    'only third' => ['comm -3 a b', "apple\n\tdate\n"],
    'output delimiter' => ['comm --output-delimiter=:: a b', "apple\n::::banana\n::::cherry\n::date\n"],
    'empty output delimiter is NUL' => ["comm --output-delimiter='' a b | od -c", "0000000   a   p   p   l   e  \\n  \\0  \\0   b   a   n   a   n   a  \\n  \\0\n0000020  \\0   c   h   e   r   r   y  \\n  \\0   d   a   t   e  \\n\n0000036\n"],
    'same delimiter twice' => ['comm --output-delimiter=, --output-delimiter=, a b', "apple\n,,banana\n,,cherry\n,date\n"],
    'different delimiters' => ['comm --output-delimiter=, --output-delimiter=: a b', '', "comm: multiple output delimiters specified\n", 1],
    'total' => ['comm --total a b', "apple\n\t\tbanana\n\t\tcherry\n\tdate\n1\t1\t2\ttotal\n"],
    'total with suppression' => ['comm --total -12 --output-delimiter=: a b', "banana\ncherry\n1:1:2:total\n"],
    'zero terminated' => ["printf 'a\\0b\\0' > z1; printf 'b\\0c\\0' > z2; comm -z z1 z2 | od -c", "0000000   a  \\0  \\t  \\t   b  \\0  \\t   c  \\0\n0000011\n"],
    'stdin' => ["printf 'banana\\n' | comm - b", "\t\tbanana\n\tcherry\n\tdate\n"],
    'stdin twice' => ["printf 'x\\n' | comm - -", "x\n", "comm: -: Bad file descriptor\n", 1],
    'unsorted warns' => ['comm u u2', "\ta\n\t\tb\na\n\t\tc\n", "comm: file 1 is not in sorted order\ncomm: input is not in sorted order\n", 1],
    'unsorted second file' => ['comm u2 u', "a\n\t\tb\n\ta\n\t\tc\n", "comm: file 2 is not in sorted order\ncomm: input is not in sorted order\n", 1],
    'unsorted matched lines are not checked' => ['comm u u', "\t\tb\n\t\ta\n\t\tc\n"],
    'check-order fails' => ['comm --check-order u u2', "\ta\n\t\tb\n", "comm: file 1 is not in sorted order\n", 1],
    'check-order on paired lines' => ['comm --check-order u u', "\t\tb\n", "comm: file 1 is not in sorted order\n", 1],
    'nocheck-order' => ['comm --nocheck-order u u2', "\ta\n\t\tb\na\n\t\tc\n"],
    'no trailing newline' => ['comm n a', "\tapple\n\tbanana\n\tcherry\nx\ny\n"],
    'missing operand' => ['comm', '', "comm: missing operand\nTry 'comm --help' for more information.\n", 1],
    'one operand' => ['comm a', '', "comm: missing operand after 'a'\nTry 'comm --help' for more information.\n", 1],
    'extra operand' => ['comm a b c', '', "comm: extra operand 'c'\nTry 'comm --help' for more information.\n", 1],
    'missing file' => ['comm a nope', '', "comm: nope: No such file or directory\n", 1],
    'directory' => ['mkdir d; comm d a', '', "comm: d: Is a directory\n", 1],
    'bad option' => ['comm -4 a b', '', "comm: invalid option -- '4'\nTry 'comm --help' for more information.\n", 1],
    'unsorted at end of file' => ["printf 'a\\nc\\nb\\n' > s; comm s a", "a\n\tapple\n\tbanana\nc\nb\n\tcherry\n", "comm: file 1 is not in sorted order\ncomm: input is not in sorted order\n", 1],
    'empty files' => ["printf '' > e; comm e e", ''],
]);
