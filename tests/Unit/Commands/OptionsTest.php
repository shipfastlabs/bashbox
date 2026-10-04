<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('commands parse options like GNU getopt_long', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = new Bash(new BashOptions(initialFiles: ['/d/a.txt' => "a\nb\n"]))->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'a cluster of short options' => ['echo hello-dc | tr -cd a-z', 'hellodc'],
    'ls -1 combines with other options' => ['ls -1a /d', ".\n..\na.txt\n"],
    'a long option is not a cluster' => ['ls --all /d', ".\n..\na.txt\n"],
    'an unambiguous long prefix' => ['ls --almost /d; cp --rec', "a.txt\n", "cp: missing file operand\nTry 'cp --help' for more information.\n", 1],
    'an ambiguous long prefix' => ['ls --al', '', "ls: option '--al' is ambiguous; possibilities: '--all' '--almost-all'\nTry 'ls --help' for more information.\n", 2],
    'an invalid short option' => ['ls -z', '', "ls: invalid option -- 'z'\nTry 'ls --help' for more information.\n", 2],
    'an unrecognized long option' => ['tr --foo a b', '', "tr: unrecognized option '--foo'\nTry 'tr --help' for more information.\n", 1],
    'mv has no -r' => ['mv -r a b', '', "mv: invalid option -- 'r'\nTry 'mv --help' for more information.\n", 1],
    'each command reports its own invalid options' => ['for c in rm touch mkdir rmdir date tee cut wc head tail uniq base64 tree; do $c -x; done 2>&1 | sort -u | grep -c "invalid option"', "13\n"],
    'options after operands' => ['grep a /d/a.txt -c', "1\n"],
    'grep -o is an option' => ['echo abc | grep -o b', "b\n"],
    'grep long options' => ['echo ABC | grep --ignore-case --only-matching b; echo x | grep --regexp=x', "B\nx\n"],
    'grep reports getopt errors with its usage' => ['grep --nope x', '', "grep: unrecognized option '--nope'\nUsage: grep [OPTION]... PATTERNS [FILE]...\nTry 'grep --help' for more information.\n", 2],
    'grep option argument missing' => ['grep -e', '', "grep: option requires an argument -- 'e'\nUsage: grep [OPTION]... PATTERNS [FILE]...\nTry 'grep --help' for more information.\n", 2],
    'tr complements set1' => ['echo abc | tr -c a X; echo aabbc | tr -cs a X; echo aabbc | tr --complement -s a', "aXXXaaXaabc\n"],
    'tr truncates set1' => ['echo abcd | tr -t abcd xy', "xycd\n"],
    'tr with too many strings' => ['tr -d a b; tr a b c', '', "tr: extra operand 'b'\nOnly one string may be given when deleting without squeezing repeats.\nTry 'tr --help' for more information.\ntr: extra operand 'c'\nTry 'tr --help' for more information.\n", 1],
    'wc -m counts characters as bytes' => ["printf 'a b\\n' | wc -m; printf 'a b\\n' | wc --chars --lines", "4\n      1       4\n"],
    'cut long options' => ['echo a:b | cut --delimiter=: --fields=2 --complement', "a\n"],
    'cut -s drops lines without a delimiter' => ["printf 'a\\tb\\nc\\n' | cut -sf1", "a\n"],
    'cut takes an empty delimiter as NUL' => ["printf 'a\\0b\\n' | cut -d '' -f2", "b\n"],
    'cut wants a single-character delimiter' => ['cut -d ab -f1', '', "cut: the delimiter must be a single character\nTry 'cut --help' for more information.\n", 1],
    'cut takes one list' => ['cut -c2 -b1', '', "cut: only one list may be specified\nTry 'cut --help' for more information.\n", 1],
    'head and tail headers' => ['echo h | head -v; head -q -n1 /d/a.txt /d/a.txt; tail --lines=1 --verbose /d/a.txt', "==> standard input <==\nh\na\na\n==> /d/a.txt <==\nb\n"],
    'tail --bytes' => ["printf 'a\\nb\\n' | tail --bytes=2", "b\n"],
    'base64 wrapping' => ['printf hello | base64 -w0; echo; printf hello | base64 --wrap=4', "aGVsbG8=\naGVs\nbG8=\n"],
    'base64 invalid wrap' => ['base64 -w x', '', "base64: invalid wrap size: 'x'\n", 1],
    'base64 -i ignores garbage' => ["printf 'aGVs*bG8=' | base64 -di", 'hello'],
    'date long options' => ['date --utc --date=@0 +%F; date --universal -d @86400 +%F', "1970-01-01\n1970-01-02\n"],
    'file commands long options' => ['mkdir --parents p/q; touch --no-create p/none; echo x | tee --append p/q/f >/dev/null; rm --recursive --force p/none; cp --recursive p r; ls r/q', "f\n"],
]);
