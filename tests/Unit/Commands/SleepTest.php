<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('sleep', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'zero' => ['sleep 0', ''],
    'valid intervals return at once instead of sleeping' => ['sleep 1.5 2m inf; echo done', "done\n"],
    'fraction' => ['sleep 0.001', ''],
    'units' => ['sleep 0s 0m 0h 0d', ''],
    'leading dot' => ['sleep .0', ''],
    'exponent' => ['sleep 0e5', ''],
    'hex' => ['sleep 0x0', ''],
    'negative zero' => ['sleep -0', '', "sleep: invalid option -- '0'\nTry 'sleep --help' for more information.\n", 1],
    'negative zero fraction' => ['sleep -0.0s', '', "sleep: invalid option -- '0'\nTry 'sleep --help' for more information.\n", 1],
    'plus' => ['sleep +0', ''],
    'leading blank' => ["sleep ' 0'", ''],
    'negative' => ['sleep -1', '', "sleep: invalid option -- '1'\nTry 'sleep --help' for more information.\n", 1],
    'invalid' => ['sleep abc', '', "sleep: invalid time interval 'abc'\nTry 'sleep --help' for more information.\n", 1],
    'invalid unit' => ['sleep 1x', '', "sleep: invalid time interval '1x'\nTry 'sleep --help' for more information.\n", 1],
    'trailing blank' => ["sleep '0 '", '', "sleep: invalid time interval '0 '\nTry 'sleep --help' for more information.\n", 1],
    'several invalid' => ['sleep x 0 y', '', "sleep: invalid time interval 'x'\nsleep: invalid time interval 'y'\nTry 'sleep --help' for more information.\n", 1],
    'missing operand' => ['sleep', '', "sleep: missing operand\nTry 'sleep --help' for more information.\n", 1],
    'bad option' => ['sleep -q', '', "sleep: invalid option -- 'q'\nTry 'sleep --help' for more information.\n", 1],
    'dash dash' => ['sleep -- 0', ''],
    'empty' => ["sleep ''", '', "sleep: invalid time interval ''\nTry 'sleep --help' for more information.\n", 1],
    'unit only' => ['sleep s', '', "sleep: invalid time interval 's'\nTry 'sleep --help' for more information.\n", 1],
    'dot only' => ['sleep .', '', "sleep: invalid time interval '.'\nTry 'sleep --help' for more information.\n", 1],
    'hex fraction' => ['sleep 0x.0p1', ''],
    'nan' => ['sleep nan', '', "sleep: invalid time interval 'nan'\nTry 'sleep --help' for more information.\n", 1],
    'infinity spelled out' => ['sleep -infinity', '', "sleep: invalid option -- 'i'\nTry 'sleep --help' for more information.\n", 1],
]);
