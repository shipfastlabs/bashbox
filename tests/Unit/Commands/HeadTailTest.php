<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "one\ntwo\nthree\n");
    $this->bash->writeFile('/home/user/b.txt', "x y\nz");
});

test('head and tail', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'head defaults to 10 lines' => ['seq 12 | head', "1\n2\n3\n4\n5\n6\n7\n8\n9\n10\n"],
    'head -n, -nN and -N' => ['head -n 1 a.txt; head -n2 a.txt; head -1 a.txt', "one\none\ntwo\none\n"],
    'head -n 0 and more lines than exist' => ['head -n 0 a.txt; head -n 9 b.txt', "x y\nz"],
    'head -n -N drops the last N lines' => ['head -n -1 a.txt', "one\ntwo\n"],
    'head -c, and -c -N' => ['head -c 5 a.txt; echo; head -c -9 a.txt', "one\nt\none\nt"],
    'tail defaults to 10 lines' => ['seq 12 | tail', "3\n4\n5\n6\n7\n8\n9\n10\n11\n12\n"],
    'tail -n, -N and -n -N' => ['tail -n 2 a.txt; tail -1 a.txt; tail -n -1 b.txt', "two\nthree\nthree\nz"],
    'tail -n +N starts at line N' => ['tail -n +2 a.txt; tail -n +0 b.txt', "two\nthree\nx y\nz"],
    'tail -n 0 and more lines than exist' => ['tail -n 0 a.txt; tail -n 5 b.txt', "x y\nz"],
    'tail -c and -c +N' => ['tail -c 4 a.txt; tail -c +11 a.txt', "ree\nree\n"],
    'multiple files get headers' => ['head -n 1 a.txt b.txt; tail -n 1 a.txt b.txt', "==> a.txt <==\none\n\n==> b.txt <==\nx y\n==> a.txt <==\nthree\n\n==> b.txt <==\nz"],
    'unreadable files are reported and skipped' => [
        'head -n 1 nope a.txt; tail -n 1 a.txt nope',
        "==> a.txt <==\none\n==> a.txt <==\nthree\n",
        "head: cannot open 'nope' for reading: No such file or directory\ntail: cannot open 'nope' for reading: No such file or directory\n",
        1,
    ],
    'invalid line count' => ['head -n abc a.txt', '', "head: invalid number of lines: 'abc'\n", 1],
    'invalid byte count' => ['tail -c 1x a.txt', '', "tail: invalid number of bytes: '1x'\n", 1],
    'head quotes names always' => ["head 'n o' \"it's\" x", '', "head: cannot open 'n o' for reading: No such file or directory\nhead: cannot open \"it's\" for reading: No such file or directory\nhead: cannot open 'x' for reading: No such file or directory\n", 1],
]);
