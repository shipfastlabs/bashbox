<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('nl', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/a.txt', "one\n\ntwo\n\n\n\nthree");
    $bash->writeFile('/home/user/sec.txt', "\\:\\:\\:\nhead\n\\:\\:\nbody\n\nbody2\n\\:\nfoot\n\\:\\:\nb3\n");
    $bash->writeFile('/home/user/x.txt', "@@@@@@\nh\n@@@@\nb\n@@\nf\n");

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'names are quoted when needed' => ["mkdir 'd d'; nl 'd d' ''", '', "nl: 'd d': Is a directory\nnl: '': No such file or directory\n", 1],
    'numbers non-empty lines' => ['nl a.txt', "     1\tone\n       \n     2\ttwo\n       \n       \n       \n     3\tthree\n"],
    'all lines' => ['nl -ba a.txt', "     1\tone\n     2\t\n     3\ttwo\n     4\t\n     5\t\n     6\t\n     7\tthree\n"],
    'no lines' => ['nl -bn a.txt', "       one\n       \n       two\n       \n       \n       \n       three\n"],
    'regex' => ["nl -b 'pt[wh]' a.txt", "       one\n       \n     1\ttwo\n       \n       \n       \n     2\tthree\n"],
    'regex anchors' => ["nl -bp'^o' a.txt", "     1\tone\n       \n       two\n       \n       \n       \n       three\n"],
    'bad regex' => ["nl -bp'a\\{' a.txt", '', "nl: Unmatched \\{\n", 1],
    'join blank lines' => ['nl -ba -l2 a.txt', "     1\tone\n       \n     2\ttwo\n       \n     3\t\n       \n     4\tthree\n"],
    'join blank lines 3' => ['nl -ba -l 3 a.txt', "     1\tone\n       \n     2\ttwo\n       \n       \n     3\t\n     4\tthree\n"],
    'start and increment' => ['nl -v 10 -i 5 a.txt', "    10\tone\n       \n    15\ttwo\n       \n       \n       \n    20\tthree\n"],
    'negative start' => ['nl -v -2 a.txt', "    -2\tone\n       \n    -1\ttwo\n       \n       \n       \n     0\tthree\n"],
    'separator and width' => ["nl -s ': ' -w 3 a.txt", "  1: one\n     \n  2: two\n     \n     \n     \n  3: three\n"],
    'left format' => ['nl -n ln a.txt', "1     \tone\n       \n2     \ttwo\n       \n       \n       \n3     \tthree\n"],
    'zero format' => ['nl -nrz -w4 a.txt', "0001\tone\n     \n0002\ttwo\n     \n     \n     \n0003\tthree\n"],
    'sections' => ['nl sec.txt', "\n       head\n\n     1\tbody\n       \n     2\tbody2\n\n       foot\n\n     1\tb3\n"],
    'sections all styles' => ['nl -ha -fa sec.txt', "\n     1\thead\n\n     1\tbody\n       \n     2\tbody2\n\n     1\tfoot\n\n     1\tb3\n"],
    'no renumber' => ['nl -p -ha -fa sec.txt', "\n     1\thead\n\n     2\tbody\n       \n     3\tbody2\n\n     4\tfoot\n\n     5\tb3\n"],
    'custom delimiter' => ['nl -d @@ -ha -fa x.txt', "\n     1\th\n\n     1\tb\n\n     1\tf\n"],
    'one-char delimiter' => ["printf '@:@:\\nh\\n@:\\nb\\n' | nl -d @ -ha", "\n     1\th\n\n       b\n"],
    'empty delimiter disables sections' => ["nl -d '' sec.txt", "     1\t\\:\\:\\:\n     2\thead\n     3\t\\:\\:\n     4\tbody\n       \n     5\tbody2\n     6\t\\:\n     7\tfoot\n     8\t\\:\\:\n     9\tb3\n"],
    'stdin and files' => ["printf 'in\\n' | nl a.txt - a.txt", "     1\tone\n       \n     2\ttwo\n       \n       \n       \n     3\tthree\n     4\tin\n     5\tone\n       \n     6\ttwo\n       \n       \n       \n     7\tthree\n"],
    'missing file' => ['nl nope a.txt', "     1\tone\n       \n     2\ttwo\n       \n       \n       \n     3\tthree\n", "nl: nope: No such file or directory\n", 1],
    'directory' => ['mkdir d; nl d', '', "nl: d: Is a directory\n", 1],
    'bad style' => ['nl -bx -hy a.txt', '', "nl: invalid body numbering style: 'x'\nnl: invalid header numbering style: 'y'\nTry 'nl --help' for more information.\n", 1],
    'bad format' => ['nl -n xx a.txt', '', "nl: invalid line numbering format: 'xx'\nTry 'nl --help' for more information.\n", 1],
    'bad style and format' => ['nl -bq -n xx a.txt', '', "nl: invalid body numbering style: 'q'\nnl: invalid line numbering format: 'xx'\nTry 'nl --help' for more information.\n", 1],
    'bad start' => ['nl -v x a.txt', '', "nl: invalid starting line number: 'x'\n", 1],
    'bad increment' => ['nl -i 1.5 a.txt', '', "nl: invalid line number increment: '1.5'\n", 1],
    'bad width' => ['nl -w x a.txt', '', "nl: invalid line number field width: 'x'\n", 1],
    'zero width' => ['nl -w 0 a.txt', '', "nl: invalid line number field width: '0': Result too large\n", 1],
    'zero join' => ['nl -l 0 a.txt', "     1\tone\n       \n     2\ttwo\n       \n       \n       \n     3\tthree\n"],
    'bad option' => ['nl -x', '', "nl: invalid option -- 'x'\nTry 'nl --help' for more information.\n", 1],
    'negative join' => ['nl -l -1 a.txt', '', "nl: invalid line number of blank lines: '-1': Result too large\n", 1],
    'huge width' => ['nl -w 99999999999 a.txt', '', "nl: invalid line number field width: '99999999999': Value too large to be stored in data type\n", 1],
    'huge start' => ['nl -v 99999999999999999999 a.txt', '', "nl: invalid starting line number: '99999999999999999999': Value too large to be stored in data type\n", 1],
    'padded start' => ["nl -v ' +5' -i -3 a.txt", "     5\tone\n       \n     2\ttwo\n       \n       \n       \n    -1\tthree\n"],
    'empty start' => ["nl -v '' a.txt", '', "nl: invalid starting line number: ''\n", 1],
    'empty style' => ["nl -b '' a.txt", '', "nl: invalid body numbering style: ''\nTry 'nl --help' for more information.\n", 1],
    'empty regex matches everything' => ['nl -bp a.txt', "     1\tone\n     2\t\n     3\ttwo\n     4\t\n     5\t\n     6\t\n     7\tthree\n"],
    'three-char delimiter' => ["printf 'abcabc\\nh\\nabc\\nf\\n' | nl -d abc -ha -fa", "\n     1\th\n\n     1\tf\n"],
    'line with delimiter and more text is a body line' => ["printf '\\\\:x\\n' | nl", "     1\t\\:x\n"],
    'join counts across sections' => ["printf 'a\\n\\n\\\\:\\:\\n\\nb\\n' | nl -ba -l2", "     1\ta\n       \n\n     1\t\n     2\tb\n"],
    'long options' => ['nl --body-numbering=a --number-width=2 --number-separator=. a.txt', " 1.one\n 2.\n 3.two\n 4.\n 5.\n 6.\n 7.three\n"],
]);
