<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('base64', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user'));
    $bash->writeFile('/home/user/a.txt', "one\n");

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'encodes a file' => ['base64 a.txt', "b25lCg==\n"],
    'empty input encodes to nothing' => ["printf '' | base64", ''],
    'wraps at 76 columns' => [
        'seq 30 | base64',
        "MQoyCjMKNAo1CjYKNwo4CjkKMTAKMTEKMTIKMTMKMTQKMTUKMTYKMTcKMTgKMTkKMjAKMjEKMjIK\nMjMKMjQKMjUKMjYKMjcKMjgKMjkKMzAK\n",
    ],
    '-d and --decode ignore newlines' => ["printf 'aGVs\\nbG8=\\n' | base64 -d; echo aGk= | base64 --decode", 'hellohi'],
    'invalid input' => ["echo '!!!' | base64 -d", '', "base64: invalid input\n", 1],
    'missing file' => ['base64 nope', '', "base64: nope: No such file or directory\n", 1],
]);
