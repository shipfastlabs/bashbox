<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('environment commands', function (array $env, string $script, string $stdout, int $exitCode = 0): void {
    $bashExecResult = new Bash(new BashOptions(env: $env))->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, '', $exitCode]);
})->with([
    'printenv lists the environment, with the default HOME, USER and PATH' => [['A' => '1', 'B' => 'x y'], 'printenv', "A=1\nB=x y\nHOME=/home/user\nUSER=user\nPATH=/usr/local/bin:/usr/bin:/bin\n"],
    'printenv prints the requested values' => [['A' => '1', 'B' => '2'], 'printenv B A', "2\n1\n"],
    'printenv fails if any variable is unset' => [['A' => '1'], 'printenv A NOPE', "1\n", 1],
    'whoami reads USER' => [['USER' => 'alice'], 'whoami', "alice\n"],
    'whoami defaults to root' => [[], 'unset USER; whoami', "root\n"],
    'hostname reads HOSTNAME' => [['HOSTNAME' => 'box'], 'hostname', "box\n"],
    'hostname defaults to localhost' => [[], 'hostname', "localhost\n"],
    'which prints a path per known command' => [[], 'which cat echo', "/usr/bin/cat\n/usr/bin/echo\n"],
    'which fails if any command is unknown' => [[], 'which cat nope', "/usr/bin/cat\n", 1],
    'which fails without operands' => [[], 'which', '', 1],
]);

test('env', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = new Bash(new BashOptions(env: ['A' => '1'], initialFiles: ['/d/f' => '']))->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'prints the environment' => ['env | grep ^A=', "A=1\n"],
    'runs a command with assignments' => ['env FOO=bar B=x=y printenv FOO B A', "bar\nx=y\n1\n"],
    '-i starts empty' => ['env -i X=1; env - Y=2', "X=1\nY=2\n"],
    '-u unsets' => ['env -u A --unset=PATH printenv A PATH', '', '', 1],
    'the command keeps its options and stdin' => ['echo hi | env -i cat -n', "     1\thi\n"],
    'passes the exit status through' => ['env false', '', '', 1],
    'a missing command' => ['env -i nosuch', '', "env: 'nosuch': No such file or directory\n", 127],
    'an unrunnable file' => ['env /d/f', '', "env: '/d/f': Permission denied\n", 126],
    'a directory' => ['env /d', '', "env: '/d': Permission denied\n", 126],
    'a missing path' => ['env ./nope', '', "env: './nope': No such file or directory\n", 127],
    'a script by path' => ["printf 'echo ran \$1\\n' > /d/s; chmod +x /d/s; env /d/s hi", "ran hi\n"],
    'invalid unset' => ['env -u A=b true; env -u "" true', '', "env: cannot unset 'A=b': Invalid argument\nenv: cannot unset '': Invalid argument\n", 125],
    'an empty name' => ['env =x', '', "env: cannot set '=x': Invalid argument\n", 125],
    'an invalid option' => ['env -x', '', "env: invalid option -- 'x'\nTry 'env --help' for more information.\n", 125],
]);
