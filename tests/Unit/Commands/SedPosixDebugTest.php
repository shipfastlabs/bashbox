<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

// Expected output was produced by GNU sed 4.9 (LC_ALL=C) in a directory holding rr and f2.

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/rr', "R\n");
    $this->bash->writeFile('/home/user/f2', "one\ntwo\n");
});

/** @param array<string, string> $files the files written, and what they hold */
$runsLikeGnu = function (string $script, string $stdout, string $stderr, int $exitCode, array $files = []): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($stdout)
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);

    foreach ($files as $path => $content) {
        expect($this->bash->readFile('/home/user/'.$path))->toBe($content);
    }
};

test('sed --debug prints the program and traces it like GNU sed', $runsLikeGnu)->with([
    'every command and address form' => ["printf 'ab\\ncd\\n' | sed --debug -n '/a/I,+2{s/\\(a\\)b/\\U\\1x\\E&\\n/2gpw out\nh;x;G;H;g;y/abc/xyz/;z};\$!N;l 5;tx;Tx;:x;1~3=;2,~4p;\$q3;=;F;0,/x/a\\\nfoo\\\nbar\nr rr\nR rr\nW out\ni\\\nx\nc\\\nyy\nl\nL\nD\nd\nP;n;e echo hi\ne\nQ'", "SED PROGRAM:\n  /a/I,+2 {\n    s/\\\\(a\\\\)b/\\U\\1x\\E&\n/gp2wout\n    h\n    x\n    G\n    H\n    g\n    y/abc/xyz/\n    z\n  }\n  \$! N\n  l 5\n  t x\n  T x\n  :x\n  1~3 =\n  2,~4 p\n  \$ q 3\n  =\n  F\n  0,/x/ a\\foo\nbar\n\n  r rr\n  R rr\n  Wout\n  i\\x\n\n  c\\yy\n\n  l\n  L\n  D\n  d\n  P\n  n\n  e echo hi\n\n  e \n  Q\nINPUT:   'STDIN' line 1\nPATTERN: ab\nCOMMAND: /a/I,+2 {\nCOMMAND:   s/\\\\(a\\\\)b/\\U\\1x\\E&\n/gp2wout\nMATCHED REGEX REGISTERS\n  regex[0] = 0-2 'ab'\n  regex[1] = 0-1 'a'\nPATTERN: ab\nCOMMAND:   h\nHOLD:    ab\nCOMMAND:   x\nPATTERN: ab\nHOLD:    ab\nCOMMAND:   G\nPATTERN: ab\\nab\nCOMMAND:   H\nHOLD:    ab\\nab\\nab\nCOMMAND:   g\nHOLD:    ab\\nab\\nab\nCOMMAND:   y/abc/xyz/\nPATTERN: xy\\nxy\\nxy\nCOMMAND:   z\nPATTERN: \nCOMMAND: }\nCOMMAND: \$! N\nPATTERN: \\ncd\nCOMMAND: l 5\n\\ncd\$\nCOMMAND: t x\nCOMMAND: T x\nCOMMAND: :x\nCOMMAND: 1~3 =\nCOMMAND: 2,~4 p\n\ncd\nCOMMAND: \$ q 3\n", '', 3, ['out' => '']],
    'labels, regex flags, delimiters and special files' => ["printf 'a\\n' | sed --debug '0r rr\ny/cba/zyx/;y/aa/ab/\ns|\\(a\\)/b\\t|\\l\\u\\U&\\E\\1x\\|y\\n|M3\n/x\\/y/I,\$!s/x/y/Ip\n\\,x,Mp\n\$a\\\n#comment\n1b\n:a;:b;bb;ta;b\n//p\ns//&/\n2!{p;}\nw /dev/stdout\ns/a/\\\n/w /dev/stderr'", "SED PROGRAM:\n  1 r rr\n  y/abc/xyz/\n  y/a/b/\n  s/\\\\(a\\\\)\\/b\\t/\\l\\u\\U&\\E\\1x|y\n/m3\n  /x\\/y/I,\$! s/x/y/ip\n  /x/M p\n  \$ a\\#comment\n\n  1 b\n  :a\n  :b\n  b b\n  t a\n  b\n  // p\n  s//&/\n  2! {\n    p\n  }\n  w/dev/stdout\n  s/a/\n/w/dev/stderr\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: 1 r rr\nR\nCOMMAND: y/abc/xyz/\nPATTERN: x\nCOMMAND: y/a/b/\nPATTERN: x\nCOMMAND: s/\\\\(a\\\\)\\/b\\t/\\l\\u\\U&\\E\\1x|y\n/m3\nPATTERN: x\nCOMMAND: /x\\/y/I,\$! s/x/y/ip\nMATCHED REGEX REGISTERS\n  regex[0] = 0-1 'x'\ny\nPATTERN: y\nCOMMAND: /x/M p\nCOMMAND: \$ a\\#comment\n\nCOMMAND: 1 b\nEND-OF-CYCLE:\ny\n#comment\n", '', 0],
    'unprintable bytes' => ["printf '\\xe9\\x01\\x7f\\a\\b\\f\\r\\t\\v\\\\\\n' | sed --debug -n 'l;y/\\xe9/a/;s/\\x01//'", "SED PROGRAM:\n  l\n  y/\xe9/a/\n  s/\\o001//\nINPUT:   'STDIN' line 1\nPATTERN: \\o37777777751\\o001\\o177\\a\\o010\\f\\r\\t\\v\\\\\nCOMMAND: l\n\\351\\001\\177\\a\\b\\f\\r\\t\\v\\\\\$\nCOMMAND: y/\xe9/a/\nPATTERN: a\\o001\\o177\\a\\o010\\f\\r\\t\\v\\\\\nCOMMAND: s/\\o001//\nMATCHED REGEX REGISTERS\n  regex[0] = 1-2 '\x01'\nPATTERN: a\\o177\\a\\o010\\f\\r\\t\\v\\\\\nEND-OF-CYCLE:\n", '', 0],
    'NUL-separated lines' => ["printf 'a\\0b\\0' | sed -z --debug 'N;P;D'", "SED PROGRAM:\n  N\n  P\n  D\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: N\nPATTERN: a\\o000b\nCOMMAND: P\na\x00COMMAND: D\nPATTERN: b\nCOMMAND: N\nEND-OF-CYCLE:\nb\x00", '', 0],
    'registers stop at the first unset group' => ["printf 'ab\\n' | sed --debug -n 's/\\(x\\)*\\(a\\)\\(b\\)/\\3/'", "SED PROGRAM:\n  s/\\\\(x\\\\)*\\\\(a\\\\)\\\\(b\\\\)/\\3/\nINPUT:   'STDIN' line 1\nPATTERN: ab\nCOMMAND: s/\\\\(x\\\\)*\\\\(a\\\\)\\\\(b\\\\)/\\3/\nMATCHED REGEX REGISTERS\n  regex[0] = 0-2 'ab'\nPATTERN: b\nEND-OF-CYCLE:\n", '', 0],
    'registers of a reused address regex' => ["printf 'ab\\n' | sed --debug -n '/\\(a\\)\\(b\\)/s//\\2/'", "SED PROGRAM:\n  /\\\\(a\\\\)\\\\(b\\\\)/ s//\\2/\nINPUT:   'STDIN' line 1\nPATTERN: ab\nCOMMAND: /\\\\(a\\\\)\\\\(b\\\\)/ s//\\2/\nMATCHED REGEX REGISTERS\n  regex[0] = 0-2 'ab'\n  regex[1] = 0-1 'a'\n  regex[2] = 1-2 'b'\nPATTERN: b\nEND-OF-CYCLE:\n", '', 0],
    'registers of an ERE alternative' => ["printf 'ab\\n' | sed --debug -n -E 's/(a)|(b)/[\\2]/2'", "SED PROGRAM:\n  s/(a)|(b)/[\\2]/2\nINPUT:   'STDIN' line 1\nPATTERN: ab\nCOMMAND: s/(a)|(b)/[\\2]/2\nMATCHED REGEX REGISTERS\n  regex[0] = 0-1 'a'\n  regex[1] = 0-1 'a'\nPATTERN: a[b]\nEND-OF-CYCLE:\n", '', 0],
    'trace text is not a line end' => ['printf ab | sed --debug p', "SED PROGRAM:\n  p\nINPUT:   'STDIN' line 1\nPATTERN: ab\nCOMMAND: p\nabEND-OF-CYCLE:\n\nab", '', 0],
    'no input' => ["printf '' | sed --debug p", "SED PROGRAM:\n  p\n", '', 0],
    'separate files' => ["printf 'a\\nb\\n' | sed --debug -s p - rr", "SED PROGRAM:\n  p\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: p\na\nEND-OF-CYCLE:\na\nINPUT:   'STDIN' line 2\nPATTERN: b\nCOMMAND: p\nb\nEND-OF-CYCLE:\nb\nINPUT:   'rr' line 1\nPATTERN: R\nCOMMAND: p\nR\nEND-OF-CYCLE:\nR\n", '', 0],
    'file names and line numbers' => ["printf 'a\\nb\\n' | sed --debug '\$!d' rr -", "SED PROGRAM:\n  \$! d\nINPUT:   'rr' line 1\nPATTERN: R\nCOMMAND: \$! d\nEND-OF-CYCLE:\nINPUT:   'STDIN' line 2\nPATTERN: a\nCOMMAND: \$! d\nEND-OF-CYCLE:\nINPUT:   'STDIN' line 3\nPATTERN: b\nCOMMAND: \$! d\nEND-OF-CYCLE:\nb\n", '', 0],
    'indentation follows the blocks executed' => ["printf 'a\\nb\\nc\\n' | sed --debug -n '2{p;b};p;1,+0p;0~2p;\$!{n;=}'", "SED PROGRAM:\n  2 {\n    p\n    b\n  }\n  p\n  1,[ADDR-NULL] p\n  0~2 p\n  \$! {\n    n\n    =\n  }\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: 2 {\nCOMMAND: }\nCOMMAND: p\na\nCOMMAND: 1,[ADDR-NULL] p\na\nCOMMAND: 0~2 p\nCOMMAND: \$! {\nCOMMAND:   n\nPATTERN: b\nCOMMAND:   =\n2\nCOMMAND: }\nEND-OF-CYCLE:\nINPUT:   'STDIN' line 3\nPATTERN: c\nCOMMAND: 2 {\nCOMMAND: }\nCOMMAND: p\nc\nCOMMAND: 1,[ADDR-NULL] p\nCOMMAND: 0~2 p\nCOMMAND: \$! {\nCOMMAND: }\nEND-OF-CYCLE:\n", '', 0],
    'change' => ["printf 'a\\nb\\nc\\n' | sed --debug '2,3c\\\nX'", "SED PROGRAM:\n  2,3 c\\X\n\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: 2,3 c\\X\n\nEND-OF-CYCLE:\na\nINPUT:   'STDIN' line 2\nPATTERN: b\nCOMMAND: 2,3 c\\X\n\nEND-OF-CYCLE:\nINPUT:   'STDIN' line 3\nPATTERN: c\nCOMMAND: 2,3 c\\X\n\nX\nEND-OF-CYCLE:\n", '', 0],
    'in-place output goes to the file' => ["printf '' | sed --debug -i s/o/0/ f2", "SED PROGRAM:\n  s/o/0/\nINPUT:   'f2' line 1\nPATTERN: one\nCOMMAND: s/o/0/\nMATCHED REGEX REGISTERS\n  regex[0] = 0-1 'o'\nPATTERN: 0ne\nEND-OF-CYCLE:\nINPUT:   'f2' line 2\nPATTERN: two\nCOMMAND: s/o/0/\nMATCHED REGEX REGISTERS\n  regex[0] = 2-3 'o'\nPATTERN: tw0\nEND-OF-CYCLE:\n", '', 0, ['f2' => "0ne\ntw0\n"]],
    'N at the end' => ["printf 'a\\nb\\n' | sed --debug 'N;N;s/x/y/'", "SED PROGRAM:\n  N\n  N\n  s/x/y/\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: N\nPATTERN: a\\nb\nCOMMAND: N\nEND-OF-CYCLE:\na\nb\n", '', 0],
    'D restarts the cycle' => ["printf 'a\\nb\\n' | sed --debug '\$!N;D'", "SED PROGRAM:\n  \$! N\n  D\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: \$! N\nPATTERN: a\\nb\nCOMMAND: D\nPATTERN: b\nCOMMAND: \$! N\nCOMMAND: D\n", '', 0],
    'empty appended text' => ["printf 'a\\n' | sed --debug '\$ a\\'", "SED PROGRAM:\n  \$ a\\\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: \$ a\\\nEND-OF-CYCLE:\na\n", '', 0],
    'case conversions' => ["printf 'a\\nb\\n' | sed --debug 's/a/\\u&\\lB\\L\\uxY/;s/b/\\U\\l&\\EZ/'", "SED PROGRAM:\n  s/a/\\u&\\E\\lB\\L\\uxY/\n  s/b/\\U\\l&\\U\\EZ/\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: s/a/\\u&\\E\\lB\\L\\uxY/\nMATCHED REGEX REGISTERS\n  regex[0] = 0-1 'a'\nPATTERN: AbXy\nCOMMAND: s/b/\\U\\l&\\U\\EZ/\nMATCHED REGEX REGISTERS\n  regex[0] = 1-2 'b'\nPATTERN: AbZXy\nEND-OF-CYCLE:\nAbZXy\nINPUT:   'STDIN' line 2\nPATTERN: b\nCOMMAND: s/a/\\u&\\E\\lB\\L\\uxY/\nPATTERN: b\nCOMMAND: s/b/\\U\\l&\\U\\EZ/\nMATCHED REGEX REGISTERS\n  regex[0] = 0-1 'b'\nPATTERN: bZ\nEND-OF-CYCLE:\nbZ\n", '', 0],
    'q' => ["printf 'a\\nb\\n' | sed --debug q", "SED PROGRAM:\n  q\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: q\na\n", '', 0],
    'Q with an exit code' => ["printf 'a\\nb\\n' | sed --debug 2Q5", "SED PROGRAM:\n  2 Q 5\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: 2 Q 5\nEND-OF-CYCLE:\na\nINPUT:   'STDIN' line 2\nPATTERN: b\nCOMMAND: 2 Q 5\n", '', 5],
    'e replaces the pattern space' => ["printf 'echo hi\\n' | sed --debug e", "SED PROGRAM:\n  e \nINPUT:   'STDIN' line 1\nPATTERN: echo hi\nCOMMAND: e \nEND-OF-CYCLE:\nhi\n", '', 0],
    'unchanged bytes of y are left out' => ["printf 'abc\\n' | sed --debug y/123/456/", "SED PROGRAM:\n  y/123/456/\nINPUT:   'STDIN' line 1\nPATTERN: abc\nCOMMAND: y/123/456/\nPATTERN: abc\nEND-OF-CYCLE:\nabc\n", '', 0],
    'escaped slashes' => ["printf 'abc\\n' | sed --debug 's/[\\n]/x/;s/\\//x/;s,a/,b,'", "SED PROGRAM:\n  s/[\\n]/x/\n  s/\\//x/\n  s/a\\//b/\nINPUT:   'STDIN' line 1\nPATTERN: abc\nCOMMAND: s/[\\n]/x/\nPATTERN: abc\nCOMMAND: s/\\//x/\nPATTERN: abc\nCOMMAND: s/a\\//b/\nPATTERN: abc\nEND-OF-CYCLE:\nabc\n", '', 0],
    'abbreviated long options' => ["printf 'a\\n' | sed --deb --pos p", "SED PROGRAM:\n  p\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: p\na\nEND-OF-CYCLE:\na\n", '', 0],
]);

test('sed --posix disables the GNU extensions like GNU sed', $runsLikeGnu)->with([
    'a missing group is empty' => ["printf 'abc\\n' | sed --posix 's/b/[\\1]/'", "a[]c\n", '', 0],
    'a missing group of a reused regex is empty' => ["printf 'abc\\n' | sed --posix '/\\(b\\)/s//[\\2]/'", "a[]c\n", '', 0],
    'case conversions are literal' => ["printf 'abc\\n' | sed --posix 's/b/\\U&x\\l\\u\\E/'", "aUbxluEc\n", '', 0],
    'BRE \\+ \\? \\| are literal' => ["printf 'a+b?c|d\\n' | sed --posix 's/a\\+b\\?c\\|d/X/'", "X\n", '', 0],
    'GNU operators are literal' => ["printf 'aw<b>s'\\''`Bb\\n' | sed --posix 's/\\w\\</X/g;s/\\>\\s/Y/;s/\\`/Z/;s/\\x27/Q/;s/\\B\\b/W/'", "aXbYQZW\n", '', 0],
    'ERE GNU operators are literal' => ["printf 'aw<b>s'\\''`Bb\\n' | sed --posix -E 's/\\w\\</X/g;s/\\>\\s/Y/;s/\\`/Z/;s/\\x27/Q/;s/\\B\\b/W/;s/a+|W/V/g'", "VXbYQZV\n", '', 0],
    'ERE unmatched ) is ordinary' => ["printf 'a)b(c)\\n' | sed --posix -E 's/(c))/X/g;s/)/Y/'", "aYb(X\n", '', 0],
    'BRE unmatched \\) is ordinary' => ["printf 'a)bc)\\n' | sed --posix 's/\\(c\\)\\)/X/g;s/\\)/Y/'", "aYbX\n", '', 0],
    'no escapes in brackets' => ["printf 'a\\nb\\\\n\\n' | sed --posix 'N;s/[\\n]/X/g'", "a\nbXX\n", '', 0],
    'escapes outside brackets' => ["printf 'a\\tb\\n' | sed --posix 'N;s/\\t/X/;s/a\\nb/Y/'", '', '', 0],
    'brackets with classes, collating elements and equivalence classes' => ["printf 'a]b:.=t\\\\t\\n' | sed --posix 's/[]]/X/;s/[[:alpha:]\\t]/Y/g;s/[[.\\t.]]/Z/;s/[[=t=]\\t]/W/g'", '', "sed: -e expression #1, char 43: Invalid collation character\n", 1],
    'replacement escapes' => ["printf 'ab\\n' | sed --posix 's/\\(a\\)\\(b\\)/\\2\\1\\n\\&\\\\/'", "ba\n&\\\n", '', 0],
    'N on the last line prints nothing' => ["printf 'a\\nb\\nc\\n' | sed --posix N", "a\nb\n", '', 0],
    'one-liner a' => ["printf 'a\\n' | sed --posix 'a foo'", '', "sed: -e expression #1, char 3: expected \\ after `a', `c' or `i'\n", 1],
    'incomplete a' => ["printf 'a\\n' | sed --posix -e 'a\\' -e foo", '', "sed: -e expression #1, char 2: incomplete command\n", 1],
    'incomplete a before --posix' => ["printf 'a\\n' | sed -e 'a\\' --posix -e foo", "a\nfoo\n", '', 0],
    'GNU flags before --posix' => ["printf 'abc\\n' | sed -e s/b/x/I --posix", "axc\n", '', 0],
    'text continued by a backslash' => ["printf 'a\\n' | sed --posix 'a\\\nfoo\\'", '', "sed: -e expression #1, char 7: incomplete command\n", 1],
    'a with one address' => ["printf 'a\\nb\\n' | sed --posix '1a\\\nfoo'", "a\nfoo\nb\n", '', 0],
    'w /dev/stdout is flushed when closed' => ["printf 'a\\nb\\n' | sed --posix 'p;w /dev/stdout'", "a\nb\na\na\nb\nb\n", '', 0],
    'w /dev/stdout with -u' => ["printf 'a\\nb\\n' | sed --posix -u 'w /dev/stdout'", "a\na\nb\nb\n", '', 0],
    'w /dev/stdout and the trace' => ["printf 'a\\nb\\n' | sed --posix --debug 's/a/x/w /dev/stdout'", "x\nSED PROGRAM:\n  s/a/x/w/dev/stdout\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: s/a/x/w/dev/stdout\nMATCHED REGEX REGISTERS\n  regex[0] = 0-1 'a'\nPATTERN: x\nEND-OF-CYCLE:\nx\nINPUT:   'STDIN' line 2\nPATTERN: b\nCOMMAND: s/a/x/w/dev/stdout\nPATTERN: b\nEND-OF-CYCLE:\nb\n", '', 0],
    'w /dev/stdout, the trace and -u' => ["printf 'a\\nb\\n' | sed --posix -u --debug '1r rr\nw /dev/stdout'", "a\nSED PROGRAM:\n  1 r rr\n  w/dev/stdout\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: 1 r rr\nCOMMAND: w/dev/stdout\nEND-OF-CYCLE:\na\nR\nb\nINPUT:   'STDIN' line 2\nPATTERN: b\nCOMMAND: 1 r rr\nCOMMAND: w/dev/stdout\nEND-OF-CYCLE:\nb\n", '', 0],
    'w /dev/stdout and -u -n' => ["printf 'a\\nb\\n' | sed --posix -u -n --debug 'w /dev/stdout'", "a\nb\nSED PROGRAM:\n  w/dev/stdout\nINPUT:   'STDIN' line 1\nPATTERN: a\nCOMMAND: w/dev/stdout\nEND-OF-CYCLE:\nINPUT:   'STDIN' line 2\nPATTERN: b\nCOMMAND: w/dev/stdout\nEND-OF-CYCLE:\n", '', 0],
    'w /dev/stderr comes after errors' => ["printf 'a\\nb\\n' | sed --posix 'w /dev/stderr' - nofile", "a\nb\n", "sed: can't read nofile: No such file or directory\na\nb\n", 2],
    'w /dev/stderr with -u' => ["printf 'a\\nb\\n' | sed --posix -u 'w /dev/stderr' - nofile", "a\nb\n", "a\nb\nsed: can't read nofile: No such file or directory\n", 2],
    'w /dev/stdout while editing in place' => ["printf '' | sed --posix -u -i --debug 'w /dev/stdout' f2", "one\ntwo\nSED PROGRAM:\n  w/dev/stdout\nINPUT:   'f2' line 1\nPATTERN: one\nCOMMAND: w/dev/stdout\nEND-OF-CYCLE:\nINPUT:   'f2' line 2\nPATTERN: two\nCOMMAND: w/dev/stdout\nEND-OF-CYCLE:\n", '', 0],
    'rejects s/b/x/I' => ["printf 'abc\\n' | sed --posix s/b/x/I", '', "sed: -e expression #1, char 7: unknown option to `s'\n", 1],
    'rejects s/b/x/m' => ["printf 'abc\\n' | sed --posix s/b/x/m", '', "sed: -e expression #1, char 7: unknown option to `s'\n", 1],
    'rejects s/b/x/e' => ["printf 'abc\\n' | sed --posix s/b/x/e", '', "sed: -e expression #1, char 7: unknown option to `s'\n", 1],
    'rejects /b/Ip' => ["printf 'abc\\n' | sed --posix /b/Ip", '', "sed: -e expression #1, char 4: unknown command: `I'\n", 1],
    'rejects /b/Mp' => ["printf 'abc\\n' | sed --posix /b/Mp", '', "sed: -e expression #1, char 4: unknown command: `M'\n", 1],
    'rejects 1~2p' => ["printf 'abc\\n' | sed --posix '1~2p'", '', "sed: -e expression #1, char 2: unknown command: `~'\n", 1],
    'rejects 1,+2p' => ["printf 'abc\\n' | sed --posix '1,+2p'", '', "sed: -e expression #1, char 3: unexpected `,'\n", 1],
    'rejects 1,~2p' => ["printf 'abc\\n' | sed --posix '1,~2p'", '', "sed: -e expression #1, char 3: unexpected `,'\n", 1],
    'rejects 0,/b/p' => ["printf 'abc\\n' | sed --posix 0,/b/p", '', "sed: -e expression #1, char 6: invalid usage of line address 0\n", 1],
    'rejects 0r rr' => ["printf 'abc\\n' | sed --posix '0r rr'", '', "sed: -e expression #1, char 2: invalid usage of line address 0\n", 1],
    'rejects q5' => ["printf 'abc\\n' | sed --posix q5", '', "sed: -e expression #1, char 2: extra characters after command\n", 1],
    'rejects l 5' => ["printf 'abc\\n' | sed --posix 'l 5'", '', "sed: -e expression #1, char 3: extra characters after command\n", 1],
    'rejects F' => ["printf 'abc\\n' | sed --posix F", '', "sed: -e expression #1, char 1: unknown command: `F'\n", 1],
    'rejects v' => ["printf 'abc\\n' | sed --posix v", '', "sed: -e expression #1, char 1: unknown command: `v'\n", 1],
    'rejects z' => ["printf 'abc\\n' | sed --posix z", '', "sed: -e expression #1, char 1: unknown command: `z'\n", 1],
    'rejects e' => ["printf 'abc\\n' | sed --posix e", '', "sed: -e expression #1, char 1: unknown command: `e'\n", 1],
    'rejects L' => ["printf 'abc\\n' | sed --posix L", '', "sed: -e expression #1, char 1: unknown command: `L'\n", 1],
    'rejects Q' => ["printf 'abc\\n' | sed --posix Q", '', "sed: -e expression #1, char 1: unknown command: `Q'\n", 1],
    'rejects T' => ["printf 'abc\\n' | sed --posix T", '', "sed: -e expression #1, char 1: unknown command: `T'\n", 1],
    'rejects R rr' => ["printf 'abc\\n' | sed --posix 'R rr'", '', "sed: -e expression #1, char 1: unknown command: `R'\n", 1],
    'rejects W out' => ["printf 'abc\\n' | sed --posix 'W out'", '', "sed: -e expression #1, char 1: unknown command: `W'\n", 1],
    'rejects 1,2=' => ["printf 'abc\\n' | sed --posix 1,2=", '', "sed: -e expression #1, char 4: command only uses one address\n", 1],
    'rejects 1,2r rr' => ["printf 'abc\\n' | sed --posix '1,2r rr'", '', "sed: -e expression #1, char 4: command only uses one address\n", 1],
    'rejects 1,2l' => ["printf 'abc\\n' | sed --posix 1,2l", '', "sed: -e expression #1, char 4: command only uses one address\n", 1],
    'rejects 1,2i\\' => ["printf 'abc\\n' | sed --posix '1,2i\\\nx'", '', "sed: -e expression #1, char 4: command only uses one address\n", 1],
    'rejects 1,2a\\' => ["printf 'abc\\n' | sed --posix '1,2a\\\nx'", '', "sed: -e expression #1, char 4: command only uses one address\n", 1],
]);
