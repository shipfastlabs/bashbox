<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "one\ntwo\n");
    $this->bash->writeFile('/home/user/b.txt', 'no newline');
});

test('cat', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'concatenates files' => ['cat a.txt /home/user/b.txt', "one\ntwo\nno newline"],
    'reads stdin without operands or for -' => ['echo in | cat; echo in | cat a.txt - b.txt', "in\none\ntwo\nin\nno newline"],
    '-u is accepted and ignored' => ['cat -u b.txt', 'no newline'],
    '-- ends options' => ['cat a.txt -- -n', "one\ntwo\n", "cat: -n: No such file or directory\n", 1],
    'options may follow operands' => ['cat a.txt -E', "one\$\ntwo\$\n"],
    '-b numbers only non-blank lines and wins over -n' => ["printf 'a\\n\\nb\\n' | cat -nb", "     1\ta\n\n     2\tb\n"],
    '-s squeezes blank runs across files' => ["printf 'a\\n\\n' > c1; printf '\\n\\nb\\n' > c2; cat -sn c1 c2", "     1\ta\n     2\t\n     3\tb\n"],
    '-E marks line ends and shows a CR before them' => ["printf 'x\\r\\ny\\r' | cat -E", "x^M\$\ny\r"],
    '-T shows tabs' => ["printf 'a\\tb\\n' | cat -T", "a^Ib\n"],
    '-v shows control and high bytes' => ["printf 'a\\t\\001\\177\\200\\211\\240\\377\\n' | cat -v", "a\t^A^?M-^@M-^IM- M-^?\n"],
    '-A is -vET' => ["printf 'a\\tb\\r\\n' | cat -A", "a^Ib^M\$\n"],
    '-e is -vE' => ["printf '\\ta\\r\\n' | cat -e", "\ta^M\$\n"],
    '-t is -vT' => ["printf '\\ta\\r\\n' | cat -t", "^Ia^M\n"],
    'long options and unambiguous prefixes' => ['cat --show-ends --number-n a.txt', "     1\tone\$\n     2\ttwo\$\n"],
    'invalid short option' => ['cat -x a.txt', '', "cat: invalid option -- 'x'\nTry 'cat --help' for more information.\n", 1],
    'unrecognized long option' => ['cat --foo=1', '', "cat: unrecognized option '--foo=1'\nTry 'cat --help' for more information.\n", 1],
    'ambiguous long option' => ['cat --sh', '', "cat: option '--sh' is ambiguous; possibilities: '--show-nonprinting' '--show-ends' '--show-tabs' '--show-all'\nTry 'cat --help' for more information.\n", 1],
    'long option with an argument' => ['cat --number=3', '', "cat: option '--number' doesn't allow an argument\nTry 'cat --help' for more information.\n", 1],
    '-n numbers lines across files' => ['cat -n b.txt a.txt', "     1\tno newlineone\n     2\ttwo\n"],
    '-n numbers blank lines and a final partial line' => ["printf 'a\\n\\nb' | cat -n", "     1\ta\n     2\t\n     3\tb"],
    'continues past unreadable files' => [
        'mkdir d; cat nope a.txt d',
        "one\ntwo\n",
        "cat: nope: No such file or directory\ncat: d: Is a directory\n",
        1,
    ],
    'names are quoted when needed; an empty name is no file' => ["cat 'n o' \"it's\" ''", '', "cat: 'n o': No such file or directory\ncat: \"it's\": No such file or directory\ncat: '': No such file or directory\n", 1],
]);
