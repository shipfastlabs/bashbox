<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\ReadWriteFs;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "apple\nBanana\ncherry pie\n");
    $this->bash->writeFile('/home/user/b.txt', "banana split\n");
    $this->bash->writeFile('/home/user/dir/c.txt', "apple tart\n");
    $this->bash->writeFile('/home/user/dir/sub/d.txt', "no fruit\n");
    $this->bash->getFilesystem()->symlink('/home/user/a.txt', '/home/user/dir/link');
});

// Expected output was produced by GNU grep 3.11 in the C locale.
test('grep matches like GNU grep', function (string $script, string $expected, string $stderr = '', int $exitCode = 0): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'stdin' => ["printf 'apple\\nbanana\\napricot' | grep ap", "apple\napricot\n"],
    'no match' => ['echo x | grep y', '', '', 1],
    'file' => ['grep an a.txt', "Banana\n"],
    'several files are labelled' => ['grep -i banana a.txt b.txt', "a.txt:Banana\nb.txt:banana split\n"],
    '-h drops file names' => ['grep -h apple a.txt dir/c.txt', "apple\napple tart\n"],
    '-H forces file names' => ['grep -H apple a.txt', "a.txt:apple\n"],
    '-H names stdin' => ['echo apple | grep -Hc apple', "(standard input):1\n"],
    'line numbers' => ['grep -n e a.txt', "1:apple\n3:cherry pie\n"],
    'invert' => ['grep -v an a.txt', "apple\ncherry pie\n"],
    'count' => ['grep -c a a.txt', "2\n"],
    'count zero' => ['grep -c zzz a.txt', "0\n", '', 1],
    'count per file' => ['grep -ci banana a.txt b.txt', "a.txt:1\nb.txt:1\n"],
    'files with matches' => ['grep -l apple a.txt b.txt dir/c.txt', "a.txt\ndir/c.txt\n"],
    'files with non-matching lines' => ['grep -lv zzz a.txt b.txt', "a.txt\nb.txt\n"],
    'quiet' => ['grep -q cherry a.txt', ''],
    'quiet no match' => ['echo x | grep -q y', '', '', 1],
    'quiet stops at first match' => ['grep -q apple a.txt nope', ''],
    'only matching' => ['grep -o an a.txt', "an\nan\n"],
    'only matching with prefixes' => ['grep -on an a.txt b.txt', "a.txt:2:an\na.txt:2:an\nb.txt:1:an\nb.txt:1:an\n"],
    'only matching skips empty matches' => ["echo aaa | grep -o 'b*'", ''],
    'only matching with -v prints nothing' => ['grep -ov an a.txt', ''],
    'only matching counts lines' => ['grep -oc an a.txt', "1\n"],
    'several -e' => ['grep -e apple -e cherry a.txt', "apple\ncherry pie\n"],
    'attached -e' => ['grep -eapple a.txt', "apple\n"],
    'pattern starting with -' => ['echo x-v | grep -e -v', "x-v\n"],
    'operands after --' => ['grep -- -v a.txt', '', '', 1],
    'newline separates patterns' => ["printf 'a\\nb\\nc\\n' | grep \"\$(printf 'a\\nc')\"", "a\nc\n"],
    'fixed strings' => ["printf 'a.c\\nabc\\n' | grep -F 'a.c'", "a.c\n"],
    'whole line' => ['grep -x apple a.txt', "apple\n"],
    'whole line ignoring case' => ['grep -ix APPLE a.txt', "apple\n"],
    'word match' => ["printf 'pie\\npies\\n_pie\\n' | grep -w pie", "pie\n"],
    'word match with alternation' => ["printf 'ab\\nxa\\nb\\n' | grep -wE 'a|b'", "b\n"],
    'word match with fixed string' => ["printf 'foo.bar\\nfoo.\\n' | grep -wF 'foo.'", "foo.\n"],
    'BRE + is literal' => ["printf 'a+b\\nab\\n' | grep 'a+b'", "a+b\n"],
    'BRE alternation and groups' => ["printf 'cat\\ndog\\ncow\\n' | grep '^\\(cat\\|dog\\)\$'", "cat\ndog\n"],
    'BRE back-reference' => ["printf 'abab\\nabba\\n' | grep '\\(ab\\)\\1'", "abab\n"],
    'BRE leading star is literal' => ["printf '*a\\nb\\n' | grep '*a'", "*a\n"],
    'ERE' => ["printf 'aa\\na\\n' | grep -E '^a{2}\$'", "aa\n"],
    '-G resets to BRE' => ["printf 'a|b\\na\\n' | grep -G 'a|b'", "a|b\n"],
    'slash in pattern' => ["echo 'a/b' | grep 'a/b'", "a/b\n"],
    'recursive with operand' => ['grep -r apple dir', "dir/c.txt:apple tart\n"],
    'recursive defaults to . without prefix' => ['grep -r apple', "a.txt:apple\ndir/c.txt:apple tart\n"],
    'recursive with . keeps prefix' => ['grep -rl fruit .', "./dir/sub/d.txt\n"],
    'recursive on a file is unlabelled' => ['grep -r split b.txt', "banana split\n"],
    'recursive with trailing slash' => ['grep -rn fruit dir/', "dir/sub/d.txt:1:no fruit\n"],
    'recursive with -h' => ['grep -rh apple .', "apple\napple tart\n"],
    'missing file' => ['grep apple nope a.txt', "a.txt:apple\n", "grep: nope: No such file or directory\n", 2],
    'missing file with -r' => ['grep -r apple nope', '', "grep: nope: No such file or directory\n", 2],
    'directory without -r' => ['grep apple dir', '', "grep: dir: Is a directory\n", 2],
    'missing -e argument' => ['grep -e', '', "grep: option requires an argument -- 'e'\nUsage: grep [OPTION]... PATTERNS [FILE]...\nTry 'grep --help' for more information.\n", 2],
    'invalid option' => ['grep -k apple a.txt', '', "grep: invalid option -- 'k'\nUsage: grep [OPTION]... PATTERNS [FILE]...\nTry 'grep --help' for more information.\n", 2],
    'conflicting matchers' => ['grep -EF apple a.txt', '', "grep: conflicting matchers specified\n", 2],
    'unmatched bracket' => ["echo x | grep '[ab'", '', "grep: Unmatched [, [^, [:, [., or [=\n", 2],
    'unmatched parenthesis' => ["echo x | grep -E '(a'", '', "grep: Unmatched ( or \\(\n", 2],
    'trailing backslash' => ["echo x | grep 'a\\'", '', "grep: Trailing backslash\n", 2],
    'invalid range' => ["echo x | grep '[[:alpha:]-z]'", '', "grep: Invalid range end\n", 2],
    'invalid back reference' => ["echo x | grep '\\(a\\)\\2'", '', "grep: Invalid back reference\n", 2],
    'no pattern' => ['grep', '', "Usage: grep [OPTION]... PATTERNS [FILE]...\nTry 'grep --help' for more information.\n", 2],
]);

// GNU grep also warns "* at start of expression" on stderr; the operator is ignored either way.
test('grep ignores a repetition operator at the start of an ERE', function (string $pattern, string $expected): void {
    $result = $this->bash->exec(sprintf("echo 'x(*a)' | grep -oE '%s'", $pattern));

    expect($result->stdout)->toBe($expected)
        ->and($result->exitCode)->toBe(0);
})->with([
    'in a group' => ['(*a)', "a\n"],
    'after an alternation' => ['x|+a', "x\na\n"],
]);

test('grep reports unreadable files and directories', function (): void {
    $root = sys_get_temp_dir().'/bashbox-grep-'.uniqid();
    mkdir($root.'/locked', 0777, true);
    file_put_contents($root.'/secret.txt', "hit\n");
    file_put_contents($root.'/open.txt', "hit\n");
    chmod($root.'/locked', 0);
    chmod($root.'/secret.txt', 0);

    try {
        $result = new Bash(new BashOptions(fs: new ReadWriteFs($root), cwd: '/'))->exec('grep -r hit .');
    } finally {
        chmod($root.'/locked', 0755);
        rmdir($root.'/locked');
        unlink($root.'/secret.txt');
        unlink($root.'/open.txt');
        @rmdir($root.'/tmp'); // Created by Bash on startup
        rmdir($root);
    }

    expect($result->stdout)->toBe("./open.txt:hit\n")
        ->and($result->stderr)->toBe("grep: ./locked: Permission denied\ngrep: ./secret.txt: Permission denied\n")
        ->and($result->exitCode)->toBe(2);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can read anything');
