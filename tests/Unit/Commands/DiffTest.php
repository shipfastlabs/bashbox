<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;

beforeEach(function (): void {
    $this->bash = new Bash;
});

test('diff normal and unified hunks', function (string $a, string $b, string $normal, string $unified): void {
    $this->bash->writeFile('/home/user/a', $a);
    $this->bash->writeFile('/home/user/b', $b);

    foreach (['diff a b' => $normal, 'diff -U1 --label=old --label=new a b' => $unified] as $script => $expected) {
        $result = $this->bash->exec($script);
        expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$expected, '', (int) ($a !== $b)]);
    }
})->with([
    'empty equal' => ['', '', '', ''],
    'identical unterminated' => ['a', 'a', '', ''],
    'insert into empty' => ['', "a\nb\n", "0a1,2\n> a\n> b\n", "--- old\n+++ new\n@@ -0,0 +1,2 @@\n+a\n+b\n"],
    'delete to empty' => ["a\nb\n", '', "1,2d0\n< a\n< b\n", "--- old\n+++ new\n@@ -1,2 +0,0 @@\n-a\n-b\n"],
    'asymmetric insertion' => ["a\nz\n", "a\nb\nc\nz\n", "1a2,3\n> b\n> c\n", "--- old\n+++ new\n@@ -1,2 +1,4 @@\n a\n+b\n+c\n z\n"],
    'asymmetric deletion' => ["a\nb\nc\nz\n", "a\nz\n", "2,3d1\n< b\n< c\n", "--- old\n+++ new\n@@ -1,4 +1,2 @@\n a\n-b\n-c\n z\n"],
    'asymmetric replacement' => ["a\nb\nc\nz\n", "a\nx\nz\n", "2,3c2\n< b\n< c\n---\n> x\n", "--- old\n+++ new\n@@ -1,4 +1,3 @@\n a\n-b\n-c\n+x\n z\n"],
    'adjacent context merges' => ["a\nb\nc\nd\ne\n", "a\nx\nc\ny\ne\n", "2c2\n< b\n---\n> x\n4c4\n< d\n---\n> y\n", "--- old\n+++ new\n@@ -1,5 +1,5 @@\n a\n-b\n+x\n c\n-d\n+y\n e\n"],
    'touching context merges' => ["a\nb\nc\nd\ne\nf\n", "a\nx\nc\nd\ny\nf\n", "2c2\n< b\n---\n> x\n5c5\n< e\n---\n> y\n", "--- old\n+++ new\n@@ -1,6 +1,6 @@\n a\n-b\n+x\n c\n d\n-e\n+y\n f\n"],
    'disjoint context' => ["a\nb\nc\nd\ne\nf\ng\n", "a\nx\nc\nd\ne\ny\ng\n", "2c2\n< b\n---\n> x\n6c6\n< f\n---\n> y\n", "--- old\n+++ new\n@@ -1,3 +1,3 @@\n a\n-b\n+x\n c\n@@ -5,3 +5,3 @@\n e\n-f\n+y\n g\n"],
    'both unterminated' => ['a', 'b', "1c1\n< a\n\\ No newline at end of file\n---\n> b\n\\ No newline at end of file\n", "--- old\n+++ new\n@@ -1 +1 @@\n-a\n\\ No newline at end of file\n+b\n\\ No newline at end of file\n"],
    'newline only' => ['a', "a\n", "1c1\n< a\n\\ No newline at end of file\n---\n> a\n", "--- old\n+++ new\n@@ -1 +1 @@\n-a\n\\ No newline at end of file\n+a\n"],
    'unterminated context' => ["a\nb", "x\nb", "1c1\n< a\n---\n> x\n", "--- old\n+++ new\n@@ -1,2 +1,2 @@\n-a\n+x\n b\n\\ No newline at end of file\n"],
    'leading insertion' => ["z\n", "a\nz\n", "0a1\n> a\n", "--- old\n+++ new\n@@ -1 +1,2 @@\n+a\n z\n"],
    'trailing deletion' => ["a\nz\n", "a\n", "2d1\n< z\n", "--- old\n+++ new\n@@ -1,2 +1 @@\n a\n-z\n"],
    'mixed asymmetric edits' => ["a\nb\nc\nd\ne\nf\ng\n", "x\na\nc\ny\nz\nd\ne\ng\nh\n", "0a1\n> x\n2d2\n< b\n3a4,5\n> y\n> z\n6d7\n< f\n7a9\n> h\n", "--- old\n+++ new\n@@ -1,7 +1,9 @@\n+x\n a\n-b\n c\n+y\n+z\n d\n e\n-f\n g\n+h\n"],
]);

test('diff flags and stdin', function (string $a, string $b, string $script, string $stdout, int $status): void {
    $this->bash->writeFile('/home/user/a', $a);
    $this->bash->writeFile('/home/user/b', $b);

    $result = $this->bash->exec($script);
    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$stdout, '', $status]);
})->with([
    'brief' => ['a', 'b', 'diff -q a b', "Files a and b differ\n", 1],
    'brief identical' => ['a', 'a', 'diff -q a b', '', 0],
    'identical report' => ['a', 'a', 'diff -qs a b', "Files a and b are identical\n", 0],
    'case' => ["A\n", "a\n", 'diff --ignore-case -s a b', "Files a and b are identical\n", 0],
    'all whitespace' => ["a \tb\r\n", "ab\n", 'diff -w a b', '', 0],
    'whitespace change' => [" a \tb  \n", " a b\n", 'diff --ignore-space-change a b', '', 0],
    'space existence matters for b' => ["ab\n", "a b\n", 'diff -bq a b', "Files a and b differ\n", 1],
    'leading space matters for b' => [" a\n", "a\n", 'diff -bq a b', "Files a and b differ\n", 1],
    'space ignores newline' => ['a', "a\n", 'diff -b a b', '', 0],
    'composed ignores' => ['A B', "ab\n", 'diff -iw a b', '', 0],
    'stdin first' => ['', "b\n", "printf 'a\\n' | diff - b", "1c1\n< a\n---\n> b\n", 1],
    'stdin second' => ["a\n", '', "printf 'b\\n' | diff a -", "1c1\n< a\n---\n> b\n", 1],
    'stdin twice is same stream' => ['', '', "printf 'a\\n' | diff -s - -", "Files - and - are identical\n", 0],
    'zero context' => ["a\nb\n", "a\nx\n", 'diff --unified=0 -L old -L new a b', "--- old\n+++ new\n@@ -2 +2 @@\n-b\n+x\n", 1],
    'default unified' => ["a\n", "b\n", 'diff -u -L old -L new a b', "--- old\n+++ new\n@@ -1 +1 @@\n-a\n+b\n", 1],
    'optional unified' => ["a\n", "b\n", 'diff --unified -L old -L new a b', "--- old\n+++ new\n@@ -1 +1 @@\n-a\n+b\n", 1],
    'binary' => ["a\0", "b\0", 'diff a b', "Binary files a and b differ\n", 1],
    'labelled summary' => ['a', 'b', 'diff --brief --label=old --label=new a b', "Files old and new differ\n", 1],
    'end of options' => ['a', 'b', 'diff -- a b', "1c1\n< a\n\\ No newline at end of file\n---\n> b\n\\ No newline at end of file\n", 1],
]);

test('diff errors have status two', function (string $script, string $message): void {
    $this->bash->writeFile('/home/user/a', 'a');
    $result = $this->bash->exec($script);
    expect($result->exitCode)->toBe(2)
        ->and($result->stdout)->toBe('')
        ->and($result->stderr)->toContain($message);
})->with([
    ['diff', 'expected exactly two operands'],
    ['diff a', 'expected exactly two operands'],
    ['diff a a a', 'expected exactly two operands'],
    ['diff a missing', 'No such file or directory'],
    ["diff '' a", 'No such file or directory'],
    ['diff . a', 'Is a directory'],
    ['diff a .', 'Is a directory'],
    ['diff -r a a', "invalid option -- 'r'"],
    ['diff --recursive a a', 'unrecognized option'],
    ['diff -c a a', "invalid option -- 'c'"],
    ['diff -C2 a a', "invalid option -- 'C'"],
    ['diff --context a a', 'unrecognized option'],
    ['diff --side-by-side a a', 'unrecognized option'],
    ['diff -U', 'requires an argument'],
    ["diff -U '' a a", 'invalid context length'],
    ['diff -U -1 a a', 'invalid context length'],
    ['diff --unified=abc a a', 'invalid context length'],
    ['diff --unified=999999999999999999999 a a', 'invalid context length'],
    ['diff -L a -L b -L c a a', 'at most two labels'],
]);

test('diff checks limits before line, work and output allocation', function (Limits $limits, string $a, string $b, string $script, string $message): void {
    $bash = new Bash(new BashOptions(limits: $limits));
    $bash->writeFile('/home/user/a', $a);
    $bash->writeFile('/home/user/b', $b);

    expect(fn (): BashExecResult => $bash->exec($script))->toThrow(ExecutionLimitException::class, $message);
})->with([
    'input' => [new Limits(maxStringLength: 20), str_repeat('a', 21), '', 'diff a b', 'diff: input size limit exceeded'],
    'stdin input' => [new Limits(maxStringLength: 20), str_repeat('a', 21), '', 'cat a | diff - b', 'diff: input size limit exceeded'],
    'combined input' => [new Limits(maxStringLength: 20), str_repeat('a', 11), str_repeat('b', 11), 'diff a b', 'diff: input size limit exceeded'],
    'lines' => [new Limits(maxArrayElements: 4), "a\nb\nc\n", "x\ny\n", 'diff a b', 'diff: line count limit exceeded'],
    'work' => [new Limits(maxLoopIterations: 10), "a\nb\nc\n", "x\ny\nz\n", 'diff a b', 'diff: comparison work limit exceeded'],
    'output line' => [new Limits(maxOutputSize: 20), str_repeat('a', 30)."\n", "b\n", 'diff a b', 'diff: output size limit exceeded'],
    'output headers' => [new Limits(maxOutputSize: 10), "a\n", "b\n", 'diff -u -L old -L new a b', 'diff: output size limit exceeded'],
]);

test('diff trims large equal surroundings before its work budget', function (): void {
    $same = str_repeat("same\n", 2000);
    $this->bash->writeFile('/home/user/a', $same."old\n".$same);
    $this->bash->writeFile('/home/user/b', $same."new\n".$same);

    $result = $this->bash->exec('diff a b');
    expect([$result->stdout, $result->exitCode])->toBe(["2001c2001\n< old\n---\n> new\n", 1]);
});

test('diff unlabelled unified headers use virtual file timestamps in UTC', function (): void {
    $this->bash->writeFile('/home/user/a', "a\n");
    $this->bash->writeFile('/home/user/b', "b\n");

    $result = $this->bash->exec('diff -u a b');
    expect($result->stdout)->toMatch('/\A--- a\t\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.000000000 \+0000\n\+\+\+ b\t\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.000000000 \+0000\n@@ -1 \+1 @@\n-a\n\+b\n\z/')
        ->and($result->exitCode)->toBe(1);
});
