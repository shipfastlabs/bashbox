<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "one\ntwo\nthree\n");
});

test('cut', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'character ranges' => ['cut -c2-3 a.txt; cut -c-2 a.txt; cut -c4- a.txt; cut -c1,3 a.txt', "ne\nwo\nhr\non\ntw\nth\n\n\nee\noe\nto\ntr\n"],
    '--complement' => ['cut --complement -c1 a.txt', "ne\nwo\nhree\n"],
    'fields with a delimiter' => ["printf 'a:b:c:d\\nnodelim\\n' | cut -d: -f1,3-", "a:c:d\nnodelim\n"],
    'fields default to tab-delimited' => ["printf 'a\\tb\\n' | cut -f2", "b\n"],
    'separate delimiter argument and open start range' => ['echo a:b:c | cut -d : -f -2', "a:b\n"],
    'empty input' => ["printf '' | cut -f1", ''],
    'no list' => ['cut a.txt', '', "cut: you must specify a list of bytes, characters, or fields\nTry 'cut --help' for more information.\n", 1],
    'more than one list' => ['cut -f1 -c1 a.txt', '', "cut: only one list may be specified\nTry 'cut --help' for more information.\n", 1],
    'unreadable files are reported and skipped' => ['cut -c1 nope a.txt', "o\nt\nt\n", "cut: nope: No such file or directory\n", 1],
]);
