<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "one\ntwo\nthree\n");
    $this->bash->writeFile('/home/user/b.txt', "x y\nz");
});

test('wc', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'all counts, sized to the file' => ['wc a.txt', " 3  3 14 a.txt\n"],
    'a single count is not padded' => ['wc -l a.txt; echo hi | wc -w', "3 a.txt\n1\n"],
    'stdin reserves seven columns' => ['echo hi | wc; echo hi | wc -lc', "      1       1       3\n      1       3\n"],
    'columns keep l, w, c order' => ['wc -cl b.txt', "1 5 b.txt\n"],
    'totals for several files' => ['wc -w a.txt b.txt', " 3 a.txt\n 3 b.txt\n 6 total\n"],
    'unreadable files are reported and skipped' => ['wc nope a.txt', " 3  3 14 a.txt\n 3  3 14 total\n", "wc: nope: No such file or directory\n", 1],
]);
