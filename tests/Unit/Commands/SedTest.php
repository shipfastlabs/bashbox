<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

// Expected output was produced by GNU sed 4.9 in the C locale.

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "hello\nworld\n");
    $this->bash->writeFile('/home/user/partial', 'no newline');
    $this->bash->writeFile('/home/user/b.txt', "one\ntwo\nthree\n");
});

test('sed edits streams like GNU sed', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe('')
        ->and($result->exitCode)->toBe(0);
})->with([
    'first match only' => ["echo aaa | sed 's/a/b/'", "baa\n"],
    'global' => ["echo aaa | sed 's/a/b/g'", "bbb\n"],
    'nth occurrence' => ["echo aaaa | sed 's/a/b/3'", "aaba\n"],
    'nth occurrence onwards' => ["echo aaaa | sed 's/a/b/2g'", "abbb\n"],
    'case insensitive, blanks between flags' => ["echo HeLLo | sed 's/l/x/I g'", "Hexxo\n"],
    'multiline flag' => ["printf 'a\\nb\\n' | sed 'N;s/^/>/Mg'", ">a\n>b\n"],
    'BRE groups and back-references' => ["echo 'hello world' | sed 's/\\(hello\\) \\(world\\)/\\2 \\1/'", "world hello\n"],
    'BRE + ? | ( ) { } are literal' => ["echo 'a+b? (c|d) {e}' | sed 's/a+b? (c|d) {e}/ok/'", "ok\n"],
    'BRE escaped operators' => ["echo 'aaa-xy' | sed 's/a\\+-\\(x\\|z\\)y\\?/!/'", "!\n"],
    'BRE interval' => ["echo aaaa | sed 's/a\\{2\\}/X/'", "Xaa\n"],
    'BRE leading star is literal' => ["echo '*a' | sed 's/*a/x/'", "x\n"],
    'BRE ^ and $ are literal mid-pattern' => ["echo 'a^b\$c' | sed 's/a^b\$c/x/'", "x\n"],
    'BRE anchors' => ["printf 'ab\\nba\\n' | sed 's/^a/X/;s/a\$/Y/'", "Xb\nbY\n"],
    'BRE anchor before group end' => ["echo ab | sed 's/\\(b\$\\)/[\\1]/'", "a[b]\n"],
    'BRE star after group start is literal' => ["echo 'a*' | sed 's/a\\(*\\)/<\\1>/'", "<*>\n"],
    'word boundaries' => ["echo 'cat concat' | sed 's/\\<cat\\>/dog/g'", "dog concat\n"],
    'buffer anchors' => ["echo abc | sed 's/\\`a/X/;s/c\\'\"'\"'/Z/'", "XbZ\n"],
    'bracket with ] and backslash' => ["echo 'a]b\\c[d' | sed 's/[]\\\\[]/_/g'", "a_b_c_d\n"],
    'negated bracket with class' => ["echo 'a1-b2' | sed 's/[^[:alpha:]]//g'", "ab\n"],
    'delimiter inside a bracket' => ["echo 'a/b' | sed 's/[/]/-/'", "a-b\n"],
    'ERE with -E' => ["echo abc | sed -E 's/(b|c)+/[&]/'", "a[bc]\n"],
    'ERE with -r' => ["echo aa | sed -r 's/a{2}/x/'", "x\n"],
    'ERE escaped operators are literal' => ["echo 'a+' | sed -E 's/a\\+/x/'", "x\n"],
    'replacement escapes' => ["echo 'a.b' | sed 's/\\./\\n\\t\\&\\\\/'", "a\n\t&\\b\n"],
    'whole match as \\0' => ["echo ab | sed 's/b/<\\0>/'", "a<b>\n"],
    'unmatched group is empty' => ["echo ab | sed -E 's/a|(z)/[\\1]/'", "[]b\n"],
    'dollar in replacement is literal' => ["echo a | sed 's/a/\$1/'", "\$1\n"],
    'case conversion \\U' => ["echo 'hello world' | sed 's/\\(.*\\)/\\U\\1/'", "HELLO WORLD\n"],
    'case conversion \\u per word' => ["echo 'hello world' | sed 's/\\w\\+/\\u&/g'", "Hello World\n"],
    'case conversion \\L\\u' => ["echo 'HELLO WORLD' | sed 's/.*/\\L\\u&/'", "Hello world\n"],
    'case conversion ends at \\E' => ["echo ab | sed 's/\\(a\\)\\(b\\)/\\U\\1\\E\\2/'", "Ab\n"],
    'case conversion \\l' => ["echo ABC | sed 's/.*/\\l&/'", "aBC\n"],
    'dangling \\u' => ["echo x | sed 's/x/\\u/'", "\n"],
    'custom delimiter and escaped delimiter' => ["echo 'a|b' | sed 's|a\\|b|X|'", "X\n"],
    'tab escape in regex' => ["printf 'a\\tb\\n' | sed 's/\\t/T/'", "aTb\n"],
    'escaped slash with another delimiter' => ["echo 'a/b' | sed 's|a\\/b|X|'", "X\n"],
    'stray backslash before a letter is literal' => ["echo 'ad' | sed 's/a\\d/X/'", "X\n"],
    'escaped slash' => ["echo 'a/b' | sed 's/\\//\\/\\//'", "a//b\n"],
    'newline escape in regex' => ["printf 'a\\nb\\n' | sed 'N;s/a\\nb/x/'", "x\n"],
    'escaped newline in replacement' => ["echo a | sed 's/a/x\\\ny/'", "x\ny\n"],
    'empty regex matches' => ["echo abc | sed 's/x*/-/g'", "-a-b-c-\n"],
    'empty regex reuses the last one' => ["echo xaa | sed '/x/s//y/;s//z/'", "yaa\n"],
    'p flag' => ["printf 'a\\nb\\n' | sed -n 's/b/B/p'", "B\n"],
    'p flag keeps a missing newline last' => ["printf 'a' | sed 's/a/b/p'", "b\nb"],
    'commands separated by ; and newlines' => ["echo abc | sed 's/a/1/; s/b/2/ g\ns/c/3/'", "123\n"],
    'comments' => ["echo a | sed '# note\np#c'", "a\na\n"],
    'several -e' => ["echo abc | sed -e 's/a/1/' -e's/c/3/'", "1b3\n"],
    'clustered -ne' => ["printf 'a\\nb\\n' | sed -ne 's/b/B/p'", "B\n"],
    '-n without p prints nothing' => ["echo a | sed -n 's/a/b/'", ''],
    '-u is accepted' => ['echo a | sed -u s/a/b/', "b\n"],
    'empty script' => ["echo a | sed ''", "a\n"],
    'missing final newline kept' => ['sed s/no/NO/ partial', 'NO newline'],
    'files form one stream' => ["sed -n '\$=;s/o/0/gp' partial a.txt", "n0 newline\nhell0\n3\nw0rld\n"],
    '-s keeps files separate' => ["sed -s -n '\$p' a.txt b.txt", "world\nthree\n"],
    '-- ends options' => ['sed -- s/one/1/ b.txt', "1\ntwo\nthree\n"],
    'empty input' => ["printf '' | sed p", ''],
    '- reads stdin' => ['echo x | sed s/x/y/ - partial', "y\nno newline"],
    'line' => ["printf 'a\\nb\\nc\\n' | sed 2d", "a\nc\n"],
    'last line' => ["printf 'a\\nb\\nc\\n' | sed '\$d'", "a\nb\n"],
    'last line without newline' => ["printf 'a\\nb' | sed '\$!d'", 'b'],
    'negation with blanks' => ["printf 'a\\nb\\nc\\n' | sed '2 ! d'", "b\n"],
    'regex' => ["printf 'a\\nb\\n' | sed '/b/d'", "a\n"],
    'custom regex delimiter' => ["printf 'a\\nb\\n' | sed '\\%a%d'", "b\n"],
    'regex with I' => ["printf 'a\\nB\\n' | sed '/b/Id'", "a\n"],
    'regex with M' => ["printf 'a\\nb\\n' | sed -n 'N;/^b/Mp'", "a\nb\n"],
    'delimiter inside bracket' => ["printf 'a/b\\nc\\n' | sed '/[/]/d'", "c\n"],
    'first~step' => ["printf '1\\n2\\n3\\n4\\n5\\n' | sed '1~3d'", "2\n3\n5\n"],
    'zero first~step' => ["printf '1\\n2\\n3\\n4\\n' | sed '0~2d'", "1\n3\n"],
    'first~0' => ["printf '1\\n2\\n3\\n' | sed -n '2~0p'", "2\n"],
    'line range' => ["printf 'a\\nb\\nc\\n' | sed '2,\$d'", "a\n"],
    'regex range' => ["printf 'a\\nb\\nc\\nd\\n' | sed '/b/,/c/d'", "a\nd\n"],
    'range end is checked from the next line' => ["printf '1\\n2\\n3\\n4\\n' | sed '2,/./d'", "1\n4\n"],
    'range end before start is one line' => ["printf 'a\\nb\\nc\\n' | sed -n '2,1p'", "b\n"],
    'range restarts' => ["printf 'a\\nb\\na\\nc\\n' | sed -n '/a/,/b/p'", "a\nb\na\nc\n"],
    'negated range' => ["printf '1\\n2\\n3\\n' | sed '1,2!d'", "1\n2\n"],
    '0,/re/ can end on line 1' => ["printf '1\\n2\\n1\\n' | sed '0,/1/d'", "2\n1\n"],
    'addr,+N' => ["printf '1\\n2\\n3\\n4\\n' | sed '/2/,+1d'", "1\n4\n"],
    'addr,+0' => ["printf '1\\n2\\n3\\n' | sed '/2/,+0d'", "1\n3\n"],
    'addr,~N' => ["printf '1\\n2\\n3\\n4\\n5\\n' | sed '2,~4d'", "1\n5\n"],
    'addr,~N on a multiple' => ["printf '1\\n2\\n3\\n4\\n5\\n6\\n7\\n' | sed '4,~2d'", "1\n2\n3\n7\n"],
    'addr,~0' => ["printf '1\\n2\\n3\\n' | sed '2,~0d'", "1\n3\n"],
    'p' => ["echo a | sed 'p;p'", "a\na\na\n"],
    'p on a last line without newline' => ["printf 'a' | sed p", "a\na"],
    '= prints line numbers' => ["printf 'a\\nb' | sed =", "1\na\n2\nb"],
    '-n \\$=' => ["printf 'a\\nb\\nc\\n' | sed -n '\$='", "3\n"],
    'a one-liner' => ["printf 'a\\nb\\n' | sed '1a X'", "a\nX\nb\n"],
    'a with backslash-newline' => ["printf 'a\\nb\\n' | sed 'a\\\nX'", "a\nX\nb\nX\n"],
    'a with several lines' => ["printf 'a\\nb\\n' | sed '1a\\\nfoo\\\nbar'", "a\nfoo\nbar\nb\n"],
    'a keeps blanks after backslash' => ["echo a | sed 'a\\  X'", "a\n  X\n"],
    'a drops a trailing backslash' => ["echo a | sed 'a X\\'", "a\nX\n"],
    'a with nothing after the backslash' => ["echo a | sed 'a\\'", "a\n"],
    'a after a last line without newline' => ["printf 'a' | sed '\$a END'", "a\nEND\n"],
    'a text runs to the end of line' => ["printf '1\\n2\\n' | sed '1a X;q'", "1\nX;q\n2\n"],
    'i' => ["printf 'a' | sed 'i X'", "X\na"],
    'c' => ["printf 'a\\nb\\n' | sed '\$c\\\nZ'", "a\nZ\n"],
    'c on a range prints once' => ["printf '1\\n2\\n3\\n4\\n' | sed '2,3c Z'", "1\nZ\n4\n"],
    'c with negation' => ["printf '1\\n2\\n3\\n' | sed '2!c X'", "X\n2\nX\n"],
    'y' => ["echo aabbcc | sed 'y/abc/xyz/'", "xxyyzz\n"],
    'y escapes' => ["echo 'a/b' | sed 'y/a\\/b/x\\\\y/'", "x\\y\n"],
    'y with newline' => ["printf 'a\\nb\\n' | sed 'N;y/\\n/ /'", "a b\n"],
    'q prints and stops' => ["printf 'a\\nb\\n' | sed 1q", "a\n"],
    'Q does not print' => ["printf 'a\\nb\\nc\\n' | sed 2Q", "a\n"],
    'Q drops queued appends' => ["echo a | sed 'a X\nQ'", ''],
    'n' => ["printf '1\\n2\\n3\\n4\\n5\\n' | sed 'n;d'", "1\n3\n5\n"],
    'n quiet' => ["printf 'a\\nb\\nc\\n' | sed -n 'n;p'", "b\n"],
    'n at the last line' => ["echo a | sed 'n;s/a/x/'", "a\n"],
    'N joins lines' => ["printf '1\\n2\\n3\\n' | sed '\$!N;s/\\n/-/'", "1-2\n3\n"],
    'N at the last line prints' => ["printf '1\\n2\\n3\\n' | sed 'N;s/\\n/,/'", "1,2\n3\n"],
    'P and D' => ["printf '1\\n2\\n3\\n' | sed '\$!N;P;D'", "1\n2\n3\n"],
    'D without newline acts like d' => ["printf '1\\n2\\n' | sed D", ''],
    'D restarts the cycle' => ["printf '1\\n2\\n3\\n' | sed 'N;D'", "3\n"],
    'h and G' => ["printf 'a\\nb\\n' | sed 'h;G'", "a\na\nb\nb\n"],
    'H, x and g' => ["printf 'a\\nb\\nc\\n' | sed '1h;2,\$H;\$!d;x;s/\\n/,/g'", "a,b,c\n"],
    'reverse lines' => ["printf '1\\n2\\n3\\n' | sed '1!G;h;\$!d'", "3\n2\n1\n"],
    'g with empty hold space' => ['echo a | sed g', "\n"],
    'x' => ["printf 'a\\nb\\n' | sed x", "\na\n"],
    'P without newline' => ["printf '1\\n2' | sed -n P", "1\n2"],
]);

test('sed q and Q set the exit code', function (string $script, string $expected, string $stderr, int $exitCode): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'q with exit code' => ["printf 'a\\nb\\nc\\n' | sed '2q5'", "a\nb\n", '', 5],
    'q with blank before exit code' => ["printf 'a\\nb\\n' | sed 'q 3'", "a\n", '', 3],
]);

test('sed -i edits each file in place', function (): void {
    $result = $this->bash->exec("sed -i -n '1p;\$=' a.txt partial");

    expect($result->stdout)->toBe('')
        ->and($result->exitCode)->toBe(0)
        ->and($this->bash->readFile('/home/user/a.txt'))->toBe("hello\n2\n")
        ->and($this->bash->readFile('/home/user/partial'))->toBe("no newline\n1\n");
});

test('sed -i with a suffix keeps a backup', function (): void {
    $result = $this->bash->exec("sed -Ei.bak 's/(w)orld/\\1/' a.txt");

    expect($result->exitCode)->toBe(0)
        ->and($this->bash->readFile('/home/user/a.txt'))->toBe("hello\nw\n")
        ->and($this->bash->readFile('/home/user/a.txt.bak'))->toBe("hello\nworld\n");
});

test('sed reports unreadable files and continues', function (string $script): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe(str_starts_with($script, 'sed -i') ? '' : "hell0\nw0rld\n")
        ->and($result->stderr)->toBe("sed: can't read nope: No such file or directory\n")
        ->and($result->exitCode)->toBe(2)
        ->and($this->bash->readFile('/home/user/a.txt'))->toBe(str_starts_with($script, 'sed -i') ? "hell0\nw0rld\n" : "hello\nworld\n");
})->with(['sed s/o/0/ nope a.txt', 'sed -i s/o/0/ nope a.txt']);

test('sed rejects bad scripts and usage', function (string $script, string $stderr, int $exitCode): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe('')
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'invalid reference with reused regex' => ["echo a | sed '/a/s//[\\1]/'", "sed: -e expression #1, char 0: invalid reference \\1 on `s' command's RHS\n", 1],
    '-i without files' => ['sed -i s/a/b/', "sed: no input files\n", 4],
    'unknown s flag' => ["sed 's/a/b/x' a.txt", "sed: -e expression #1, char 7: unknown option to `s'\n", 1],
    'repeated g' => ["sed 's/a/b/gg' a.txt", "sed: -e expression #1, char 8: multiple `g' options to `s' command\n", 1],
    'repeated p' => ["sed 's/a/b/pp' a.txt", "sed: -e expression #1, char 8: multiple `p' options to `s' command\n", 1],
    'repeated number' => ["sed 's/a/b/2g3' a.txt", "sed: -e expression #1, char 9: multiple number options to `s' command\n", 1],
    'zero number' => ["sed 's/a/b/0' a.txt", "sed: -e expression #1, char 7: number option to `s' command may not be zero\n", 1],
    'unterminated s' => ["sed 's/a/b' a.txt", "sed: -e expression #1, char 5: unterminated `s' command\n", 1],
    'unterminated s at a newline' => ["sed 's/a/b\n/' a.txt", "sed: -e expression #1, char 5: unterminated `s' command\n", 1],
    'bare s' => ['sed s a.txt', "sed: -e expression #1, char 1: unterminated `s' command\n", 1],
    'unterminated bracket' => ["sed 's/[a/x/' a.txt", "sed: -e expression #1, char 7: unterminated `s' command\n", 1],
    'error names the expression' => ["sed -e s/a/b/ -e 's/a/b' a.txt", "sed: -e expression #2, char 5: unterminated `s' command\n", 1],
    'unknown command' => ['sed k a.txt', "sed: -e expression #1, char 1: unknown command: `k'\n", 1],
    'missing command' => ['sed 1 a.txt', "sed: -e expression #1, char 1: missing command\n", 1],
    'unexpected comma' => ["sed '1,' a.txt", "sed: -e expression #1, char 2: unexpected `,'\n", 1],
    'multiple !' => ["sed '1!!d' a.txt", "sed: -e expression #1, char 3: multiple `!'s\n", 1],
    'line address 0' => ['sed 0p a.txt', "sed: -e expression #1, char 2: invalid usage of line address 0\n", 1],
    'extra characters' => ["sed 'pq' a.txt", "sed: -e expression #1, char 2: extra characters after command\n", 1],
    'extra characters after y' => ["sed 'y/a/b/ x' a.txt", "sed: -e expression #1, char 8: extra characters after command\n", 1],
    'extra characters after q' => ["sed 'qx' a.txt", "sed: -e expression #1, char 2: extra characters after command\n", 1],
    'unterminated address regex' => ["sed '/x' a.txt", "sed: -e expression #1, char 2: unterminated address regex\n", 1],
    'unterminated y' => ["sed 'y/abc/' a.txt", "sed: -e expression #1, char 6: unterminated `y' command\n", 1],
    'y lengths differ' => ["sed 'y/ab/x/' a.txt", "sed: -e expression #1, char 7: strings for `y' command are different lengths\n", 1],
    'a without text' => ["sed 'a' a.txt", "sed: -e expression #1, char 1: expected \\ after `a', `c' or `i'\n", 1],
    'invalid back reference in RHS' => ["sed 's/\\(a\\)/\\1\\2/' a.txt", "sed: -e expression #1, char 13: invalid reference \\2 on `s' command's RHS\n", 1],
    'no previous regex' => ["sed 's//x/' a.txt", "sed: -e expression #1, char 0: no previous regular expression\n", 1],
    'unmatched (' => ["sed 's/\\(a/x/' a.txt", "sed: -e expression #1, char 8: Unmatched ( or \\(\n", 1],
    'unmatched ) in ERE' => ["sed -E 's/a)/x/' a.txt", "sed: -e expression #1, char 7: Unmatched ) or \\)\n", 1],
    'invalid preceding regex' => ["sed -E 's/*a/x/' a.txt", "sed: -e expression #1, char 7: Invalid preceding regular expression\n", 1],
    'invalid interval' => ["sed 's/a\\{2,1\\}/x/' a.txt", "sed: -e expression #1, char 13: Invalid content of \\{\\}\n", 1],
    'invalid back reference' => ["sed 's/\\(a\\)\\2/x/' a.txt", "sed: -e expression #1, char 12: Invalid back reference\n", 1],
    'invalid range' => ["sed 's/[b-a]/x/' a.txt", "sed: -e expression #1, char 10: Invalid range end\n", 1],
    'invalid class' => ["sed 's/[[:foo:]]/x/' a.txt", "sed: -e expression #1, char 14: Invalid character class name\n", 1],
]);
