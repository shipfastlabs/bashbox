<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('tac', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/a.txt', "one\ntwo\nthree\n");
    $bash->writeFile('/home/user/b.txt', "x\ny");
    $bash->writeFile('/home/user/d/f', '');

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'reverses lines' => ['tac a.txt', "three\ntwo\none\n"],
    'a last line without newline is joined to the one before' => ['tac b.txt', "yx\n"],
    'reads stdin' => ["printf '1\\n2\\n' | tac", "2\n1\n"],
    'dash is stdin' => ["printf '1\\n2\\n' | tac - a.txt", "2\n1\nthree\ntwo\none\n"],
    'each file reversed on its own' => ['tac a.txt b.txt', "three\ntwo\none\nyx\n"],
    'custom separator' => ["printf 'a,b,c,' | tac -s ,", 'c,b,a,'],
    'separator before' => ["printf 'a\\nb\\nc' | tac -b", "\nc\nba"],
    'separator before with -s' => ["printf ',a,b,c' | tac -b -s ,", ',c,b,a'],
    'multi-char separator' => ["printf 'aXYbXYc' | tac --separator=XY", 'cbXYaXY'],
    'empty separator is NUL' => ["printf 'a\\0b\\0' | tac -s '' | od -c", "0000000   b  \\0   a  \\0\n0000004\n"],
    'empty input' => ["printf '' | tac", ''],
    'missing file' => ['tac nope a.txt', "three\ntwo\none\n", "tac: failed to open 'nope' for reading: No such file or directory\n", 1],
    'directory' => ['tac d', '', "tac: d: read error: Is a directory\n", 1],
    'bad option' => ['tac -x', '', "tac: invalid option -- 'x'\nTry 'tac --help' for more information.\n", 1],
]);
