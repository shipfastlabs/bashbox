<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('expand', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/t', "a\tb\tc\n\tx\n");
    $bash->writeFile('/home/user/m', "  \t a\tb \tc\n");
    $bash->writeFile('/home/user/bs', "ab\x08\tc\n");

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'default 8' => ['expand t', "a       b       c\n        x\n"],
    'tab size' => ['expand -t 4 t', "a   b   c\n    x\n"],
    'tab list' => ['expand -t 2,5,9 t', "a b  c\n  x\n"],
    'tab list with spaces' => ["expand -t '2 5' t", "a b  c\n  x\n"],
    'past the last stop is one space' => ["printf 'a\\tb\\tc\\td\\n' | expand -t 2,4", "a b c d\n"],
    'extend' => ["printf 'a\\tb\\tc\\td\\te\\n' | expand -t 2,5,/3", "a b  c   d  e\n"],
    'increment' => ["printf 'a\\tb\\tc\\td\\te\\n' | expand -t 2,5,+3", "a b  c  d  e\n"],
    'only extend' => ["printf 'a\\tb\\n' | expand -t /3", "a  b\n"],
    'only increment' => ["printf 'a\\tb\\n' | expand -t +3", "a  b\n"],
    'obsolete' => ['expand -4 t', "a   b   c\n    x\n"],
    'obsolete list' => ['expand -2,6 t', "a b   c\n  x\n"],
    'obsolete with i' => ['expand -i3 t', "a\tb\tc\n   x\n"],
    'initial' => ['expand -i m', "         a\tb \tc\n"],
    'initial with list' => ['expand -i -t 3 m', "    a\tb \tc\n"],
    'backspace' => ['expand bs', "ab\x08       c\n"],
    'files are one stream' => ["printf 'ab' > p; expand p t", "aba     b       c\n        x\n"],
    'repeated -t adds stops' => ["printf 'a\\tb\\tc\\n' | expand -t 2 -t 6", "a b   c\n"],
    'zero tab size' => ['expand -t 0 t', '', "expand: tab size cannot be 0\n", 1],
    'not ascending' => ['expand -t 4,2 t', '', "expand: tab sizes must be ascending\n", 1],
    'equal stops' => ['expand -t 4,4 t', '', "expand: tab sizes must be ascending\n", 1],
    'invalid char' => ['expand -t 3x t', '', "expand: tab size contains invalid character(s): 'x'\n", 1],
    'slash not at start' => ['expand -t 3/4 t', '', "expand: '/' specifier not at start of number: '/4'\n", 1],
    'plus not at start' => ['expand -t 3+ t', '', "expand: '+' specifier not at start of number: '+'\n", 1],
    'slash not last' => ['expand -t /3,/4 t', '', "expand: '/' specifier only allowed with the last value\n", 1],
    'plus then plus' => ['expand -t +2,+4 t', '', "expand: '+' specifier only allowed with the last value\n", 1],
    'slash and plus' => ['expand -t 2,/3 -t +4 t', '', "expand: '/' specifier is mutually exclusive with '+'\n", 1],
    'missing file' => ['expand nope t', "a       b       c\n        x\n", "expand: nope: No such file or directory\n", 1],
    'stdin' => ["printf '\\tz\\n' | expand -t 3 -", "   z\n"],
    'bad option' => ['expand -x', '', "expand: invalid option -- 'x'\nTry 'expand --help' for more information.\n", 1],
    'long options' => ['expand --initial --tabs=4 m', "     a\tb \tc\n"],
    'huge tab size' => ['expand -t 99999999999999999999 t', '', "expand: tab stop is too large '99999999999999999999'\n", 1],
    'huge with leading zeros' => ['expand -t 3,0099999999999999999999 t', '', "expand: tab stop is too large '0099999999999999999999'\n", 1],
    'empty list' => ["expand -t '' t", "a       b       c\n        x\n"],
    'comma only' => ['expand -t , t', "a       b       c\n        x\n"],
    'slash then value later' => ["printf 'a\\tb\\tc\\n' | expand -t /,4", "a   b   c\n"],
]);
