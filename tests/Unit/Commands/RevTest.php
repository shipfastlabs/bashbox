<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('rev', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user'));
    $bash->writeFile('/home/user/a.txt', "one\ntwo\n");

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'reverses characters, keeping line endings' => ["printf 'abc\\ndéf' | rev", "cba\nféd"],
    'reads files' => ['rev a.txt a.txt', "eno\nowt\neno\nowt\n"],
    'unreadable files are reported and skipped' => ['rev nope a.txt', "eno\nowt\n", "rev: cannot open nope: No such file or directory\n", 1],
]);
