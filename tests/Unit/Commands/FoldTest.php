<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('fold', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/a', "the quick brown fox jumps over the lazy dog\nshort\n");
    $bash->writeFile('/home/user/t', "a\tb\tc\td\te\tf\tg\th\ti\tj\n");
    $bash->writeFile('/home/user/bs', "abc\x08\x08def\rghijkl\n");

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'names are quoted when needed' => ["fold \"it's\"", '', "fold: \"it's\": No such file or directory\n", 1],
    'default width 80' => ["printf '%0100d\\n' 0 | fold", "00000000000000000000000000000000000000000000000000000000000000000000000000000000\n00000000000000000000\n"],
    'width' => ['fold -w 10 a', "the quick \nbrown fox \njumps over\n the lazy \ndog\nshort\n"],
    'obsolete width' => ['fold -10 a', "the quick \nbrown fox \njumps over\n the lazy \ndog\nshort\n"],
    'obsolete width bundled' => ['fold -s10 a', "the quick \nbrown fox \njumps \nover the \nlazy dog\nshort\n"],
    'width after w is not obsolete' => ['fold -w 5 -8 a', "the quic\nk brown \nfox jump\ns over t\nhe lazy \ndog\nshort\n"],
    'spaces' => ['fold -s -w 10 a', "the quick \nbrown fox \njumps \nover the \nlazy dog\nshort\n"],
    'spaces long word' => ["printf 'abcdefghijklmnop qr\\n' | fold -s -w 5", "abcde\nfghij\nklmno\np qr\n"],
    'tabs count to next stop' => ['fold -w 10 t', "a\tb\n\tc\n\td\n\te\n\tf\n\tg\n\th\n\ti\n\tj\n"],
    'tabs with -s' => ['fold -s -w 10 t', "a\t\nb\t\nc\t\nd\t\ne\t\nf\t\ng\t\nh\t\ni\tj\n"],
    'bytes' => ['fold -b -w 10 t', "a\tb\tc\td\te\t\nf\tg\th\ti\tj\n"],
    'characters' => ['fold -c -w 4 a', "the \nquic\nk br\nown \nfox \njump\ns ov\ner t\nhe l\nazy \ndog\nshor\nt\n"],
    'backspace and carriage return' => ['fold -w 4 bs', "abc\x08\x08def\rghij\nkl\n"],
    'backspace at column 0' => ["printf '\\x08abcde\\n' | fold -w 3", "\x08abc\nde\n"],
    'bytes with backspace' => ['fold -b -w 4 bs', "abc\x08\n\x08def\n\rghi\njkl\n"],
    'tab wider than width' => ["printf '\\tab\\n' | fold -w 4", "\t\nab\n"],
    'no trailing newline' => ["printf 'abcdefg' | fold -w 3", "abc\ndef\ng"],
    'stdin and files' => ["printf 'xyz\\n' | fold -w 2 - a", "xy\nz\nth\ne \nqu\nic\nk \nbr\now\nn \nfo\nx \nju\nmp\ns \nov\ner\n t\nhe\n l\naz\ny \ndo\ng\nsh\nor\nt\n"],
    'space overflows the line' => ["printf 'abcde fgh\\n' | fold -s -w 5", "abcde\n fgh\n"],
    'line ending in a blank' => ["printf 'ab cd ef gh ij\\n' | fold -s -w 3", "ab \ncd \nef \ngh \nij\n"],
    'tab after break with -s' => ["printf 'ab\\tcdefghij\\n' | fold -s -w 6", "ab\n\t\ncdefgh\nij\n"],
    'carriage return resets with -s' => ["printf 'abc d\\refghij kl\\n' | fold -s -w 6", "abc \nd\refghij\n kl\n"],
    'long option' => ['fold --width=6 --spaces a', "the \nquick \nbrown \nfox \njumps \nover \nthe \nlazy \ndog\nshort\n"],
    'invalid width' => ['fold -w x a', '', "fold: invalid number of columns: 'x'\n", 1],
    'zero width' => ['fold -w 0 a', '', "fold: invalid number of columns: '0': Result too large\n", 1],
    'negative width' => ['fold -w -3 a', '', "fold: invalid number of columns: '-3'\n", 1],
    'missing file' => ['fold nope a', "the quick brown fox jumps over the lazy dog\nshort\n", "fold: nope: No such file or directory\n", 1],
    'bad option' => ['fold -q', '', "fold: invalid option -- 'q'\nTry 'fold --help' for more information.\n", 1],
    'huge width' => ['fold -w 99999999999999999999 a', '', "fold: invalid number of columns: '99999999999999999999': Result too large\n", 1],
    'padded width' => ["fold -w ' 5' a", "the q\nuick \nbrown\n fox \njumps\n over\n the \nlazy \ndog\nshort\n"],
    'plus width' => ['fold -w +5 a', "the q\nuick \nbrown\n fox \njumps\n over\n the \nlazy \ndog\nshort\n"],
]);
