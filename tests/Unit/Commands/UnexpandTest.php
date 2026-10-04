<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('unexpand', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/s', "        a       b\n    c   d  e\n");
    $bash->writeFile('/home/user/m', "  \t  x       y\n");
    $bash->writeFile('/home/user/bs', "ab\x08      c\n");

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'names are quoted when needed' => ["unexpand 'a b'", '', "unexpand: 'a b': No such file or directory\n", 1],
    'leading blanks only' => ['unexpand s', "\ta       b\n    c   d  e\n"],
    'all blanks' => ['unexpand -a s', "\ta\tb\n    c\td  e\n"],
    'tab size implies -a' => ['unexpand -t 4 s', "\t\ta\t\tb\n\tc\td  e\n"],
    'first-only overrides' => ['unexpand -t 4 --first-only s', "\t\ta       b\n\tc   d  e\n"],
    'mixed tabs and spaces' => ['unexpand -a m', "\t  x\t  y\n"],
    'single space before stop' => ["printf 'abcdefg h\\n' | unexpand -a", "abcdefg h\n"],
    'single blank becomes tab' => ["printf 'abcdefg \\tx\\n' | unexpand -a", "abcdefg\t\tx\n"],
    'list' => ["printf '  a   b      c\\n' | unexpand -t 2,6", "\ta\tb      c\n"],
    'list exhausted' => ["printf '  a   b      c   d\\n' | unexpand -t 2,6", "\ta\tb      c   d\n"],
    'extend' => ["printf '  a  b  c  d\\n' | unexpand -t 2,/3", "\ta  b  c  d\n"],
    'increment' => ["printf '  a  b  c  d\\n' | unexpand -t 2,+3", "\ta\tb\tc\td\n"],
    'obsolete' => ['unexpand -4 s', "\t\ta       b\n\tc   d  e\n"],
    'obsolete list' => ["printf '  a   b      c\\n' | unexpand -2,6", "\ta   b      c\n"],
    'obsolete trailing comma' => ["printf '  a   b      c\\n' | unexpand -2,6,", "\ta   b      c\n"],
    'backspace' => ['unexpand -a bs', "ab\x08      c\n"],
    'trailing blanks without newline' => ["printf 'a       ' | unexpand -a | od -c", "0000000   a  \\t\n0000002\n"],
    'files are one stream' => ["printf '   ' > p; unexpand p s", "\t   a       b\n    c   d  e\n"],
    'zero tab size' => ['unexpand -t 0 s', '', "unexpand: tab size cannot be 0\n", 1],
    'not ascending' => ['unexpand -t 4,2 s', '', "unexpand: tab sizes must be ascending\n", 1],
    'invalid' => ['unexpand -t a s', '', "unexpand: tab size contains invalid character(s): 'a'\n", 1],
    'missing file' => ['unexpand nope s', "\ta       b\n    c   d  e\n", "unexpand: nope: No such file or directory\n", 1],
    'bad option' => ['unexpand -x', '', "unexpand: invalid option -- 'x'\nTry 'unexpand --help' for more information.\n", 1],
    'long options' => ['unexpand --all --tabs=4 s', "\t\ta\t\tb\n\tc\td  e\n"],
    'non-blank stops leading conversion' => ["printf 'x       y\\n' | unexpand", "x       y\n"],
    'tab at stop' => ["printf '\\t\\t  a\\n' | unexpand -t 4", "\t\t  a\n"],
    'spaces then tab' => ["printf '   \\ta\\n' | unexpand", "\ta\n"],
    'one space then tab' => ["printf 'abcdefg \\t \\tx\\n' | unexpand -a", "abcdefg\t\t\tx\n"],
    'blank past last stop' => ["printf 'abc     d\\n' | unexpand -t 2", "abc\t\t\td\n"],
]);
