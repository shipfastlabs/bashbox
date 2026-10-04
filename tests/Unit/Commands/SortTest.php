<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/one', 'x');
    $this->bash->writeFile('/home/user/two', "b\na\n");
});

test('sort orders lines like GNU sort in the C locale', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe('')
        ->and($result->exitCode)->toBe(0);
})->with([
    'bytewise' => ["printf 'b\\nB\\na\\n10\\n9\\n' | sort", "10\n9\nB\na\nb\n"],
    'empty input' => ["printf '' | sort", ''],
    'missing final newline is added' => ["printf 'b\\na' | sort", "a\nb\n"],
    'files are read as separate lines' => ['sort one two', "a\nb\nx\n"],
    'stdin as -' => ['echo m | sort two - one', "a\nb\nm\nx\n"],
    'reverse' => ["printf 'a\\nc\\nb\\n' | sort -r", "c\nb\na\n"],
    'numeric with ties broken bytewise' => ["printf 'b\\na\\n10\\n9\\n-1\\n+5\\n.5\\n' | sort -n", "-1\n+5\na\nb\n.5\n9\n10\n"],
    'unique' => ["printf 'b\\na\\nb\\n' | sort -u", "a\nb\n"],
    'numeric unique keeps the first of a run' => ["printf '1\\n01\\nb\\na\\n' | sort -nu", "b\n1\n"],
    'reverse unique' => ["printf 'B\\na\\nb\\nA\\na\\n' | sort -ur", "b\na\nB\nA\n"],
    'key to end of line, ties by whole line' => ["printf 'b 1\\na 1\\n' | sort -k2", "a 1\nb 1\n"],
    'key keeps leading blanks' => ["printf 'x  b\\ny a\\n' | sort -k2", "x  b\ny a\n"],
    'key past last field is empty' => ["printf 'b a\\na b\\n' | sort -k3", "a b\nb a\n"],
    'numeric key range' => ["printf 'a 2 z\\nb 10 a\\nc 2 b\\n' | sort -k2,2n", "a 2 z\nc 2 b\nb 10 a\n"],
    'key modifiers override global -r' => ["printf 'a 2 z\\nb 10 a\\nc 2 b\\n' | sort -r -k2n", "c 2 b\na 2 z\nb 10 a\n"],
    'global options apply to a plain key' => ["printf 'a 2\\nb 10\\nc 3\\n' | sort -rn -k2", "b 10\nc 3\na 2\n"],
    'delimiter' => ["printf 'a:3\\nb:1\\nc:2\\n' | sort -t: -k2", "b:1\nc:2\na:3\n"],
    'unique by key' => ["printf 'a b c\\nz b d\\na c b\\n' | sort -u -k2,2", "a b c\na c b\n"],
]);

test('sort reports errors with exit code 2', function (string $script, string $stderr): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe('')
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe(2);
})->with([
    'missing file' => ['sort two nope', "sort: cannot read: nope: No such file or directory\n"],
    'zero field' => ['sort -k0 two', "sort: field number is zero: invalid field specification '0'\n"],
]);

test('sort options', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'multiple keys' => ["printf 'b 2\\na 2\\nc 1\\na 1\\n' | sort -k2,2 -k1,1r", "c 1\na 1\nb 2\na 2\n"],
    'character positions in keys' => ["printf 'xb2\\nya1\\nzb1\\n' | sort -k1.2,1.2 -k1.3n", "ya1\nzb1\nxb2\n"],
    'character position past the field end' => ["printf 'ab c\\nab a\\na b\\n' | sort -k1.3", "ab a\nab c\na b\n"],
    'end character position' => ["printf 'abc 1\\nabd 0\\nabb 2\\n' | sort -k1,1.2 -k2n", "abd 0\nabc 1\nabb 2\n"],
    'end position before start is an empty key' => ["printf 'b\\na\\nc\\n' | sort -k2,1", "a\nb\nc\n"],
    'b on the start position skips blanks' => ["printf 'x   b\\ny a\\nz  c\\n' | sort -k2b", "y a\nx   b\nz  c\n"],
    'b on the end position' => ["printf 'a  xy\\nb zz\\nc  xa\\n' | sort -k2,2.2b", "c  xa\na  xy\nb zz\n"],
    'global -b applies to keys without modifiers' => ["printf 'x   b\\ny a\\nz  c\\n' | sort -b -k2", "y a\nx   b\nz  c\n"],
    'key modifiers stop global ones being inherited' => ["printf 'x   b\\ny a\\nz  c\\n' | sort -b -k2r", "y a\nz  c\nx   b\n"],
    '-t splits on every separator' => ["printf 'a::3\\nb:1:2\\nc:2:1\\n' | sort -t: -k2,2 -k3n", "a::3\nb:1:2\nc:2:1\n"],
    '-t key ending on a whole field' => ["printf 'a:b:c\\na:a:d\\n' | sort -t: -k1,2", "a:a:d\na:b:c\n"],
    '-t with character positions' => ["printf 'k:xb\\nj:ya\\n' | sort -t: -k2.2", "j:ya\nk:xb\n"],
    '-t NUL separator' => ["printf 'b\\000a\\na\\000b\\n' | sort -t '\\0' -k2 | tr '\\0' '|'", "b|a\na|b\n"],
    '-d dictionary order' => ["printf 'a-c\\nab\\na c\\n' | sort -d", "a c\nab\na-c\n"],
    '-f folds case' => ["printf 'b\\nA\\na\\nB\\n' | sort -f", "A\na\nB\nb\n"],
    '-fu keeps the first of each folded run' => ["printf 'b\\nA\\na\\nB\\n' | sort -fu", "A\nb\n"],
    '-i ignores nonprinting characters' => ["printf 'b\\na\\001c\\nac\\n' | sort -i | tr '\\001' '^'", "a^c\nac\nb\n"],
    '-M month order' => ["printf 'mar x\\n  feb\\nJAN\\nfoo\\ndecember\\n' | sort -M", "foo\nJAN\n  feb\nmar x\ndecember\n"],
    '-g general numeric' => ["printf '1e3\\n-inf\\nnan\\nabc\\n10\\n0x10\\n-5.5\\ninf\\n+2\\n' | sort -g", "abc\nnan\n-inf\n-5.5\n+2\n10\n0x10\n1e3\ninf\n"],
    '-h human numeric' => ["printf '0K\\n1\\n-1K\\n2M\\n500K\\n0\\n1.5K\\n3k\\n1m\\n-2\\n' | sort -h", "-1K\n-2\n0\n0K\n1\n1m\n1.5K\n3k\n500K\n2M\n"],
    '-n with signs, fractions and leading zeros' => ["printf '007\\n-0\\n0.50\\n.5\\n-1.5\\n-.5\\n10\\n1e5\\n 3\\n' | sort -n", "-1.5\n-.5\n-0\n.5\n0.50\n1e5\n 3\n007\n10\n"],
    '-n compares long numbers exactly' => ["printf '100000000000000000001\\n100000000000000000000\\n99999999999999999999\\n' | sort -n", "99999999999999999999\n100000000000000000000\n100000000000000000001\n"],
    '-V version order' => ["printf 'a-1.10\\na-1.9\\na-1.9~rc\\n.b\\n..\\n.\\nfile.tar.gz\\nfile2.tar.gz\\nfile10\\nfile\\n\\nfile~\\n.a\\n1.0a\\n1.0\\n' | sort -V", "\n.\n..\n.a\n.b\n1.0\n1.0a\na-1.9~rc\na-1.9\na-1.10\nfile~\nfile\nfile.tar.gz\nfile2.tar.gz\nfile10\n"],
    '-V compares suffixes last' => ["printf 'foo.10.txt\\nfoo.9.txt\\nfoo.9.tar\\nfoo.9.tar.gz\\n' | sort -V", "foo.9.tar\nfoo.9.tar.gz\nfoo.9.txt\nfoo.10.txt\n"],
    '-s keeps input order for equal keys' => ["printf 'b 1\\na 1\\nc 0\\n' | sort -s -k2,2", "c 0\nb 1\na 1\n"],
    '-s without keys has no effect' => ["printf 'b\\na\\n' | sort -s", "a\nb\n"],
    '-u with -n treats equal numbers as duplicates' => ["printf '1\\n01\\n1.0\\n2\\n' | sort -un", "1\n2\n"],
    '-r with keys reverses the last-resort comparison' => ["printf 'a 1\\nb 1\\n' | sort -r -k2n", "b 1\na 1\n"],
    '-z uses NUL line endings' => ["printf 'b\\000a\\000c' | sort -z | tr '\\0' '\\n'", "a\nb\nc\n"],
    '-o writes to a file after reading all input' => ["printf 'b\\na\\n' > f; sort -o f f; cat f", "a\nb\n"],
    '-o into a missing directory' => ["printf 'b\\n' | sort -o nope/x", '', "sort: open failed: nope/x: No such file or directory\n", 2],
    '-c reports the first disorder' => ["printf 'a\\nc\\nb\\nd\\na\\n' | sort -c", '', "sort: -:3: disorder: b\n", 1],
    '-c on sorted input' => ["printf 'a\\nb\\nb\\n' | sort -c", ''],
    '-cu rejects equal neighbours' => ["printf 'a\\nb\\nb\\n' | sort -cu", '', "sort: -:3: disorder: b\n", 1],
    '-C is quiet' => ["printf 'b\\na\\n' | sort -C", '', '', 1],
    '--check=quiet' => ["printf 'b\\na\\n' | sort --check=q", '', '', 1],
    '--check=diagnose-first' => ["printf 'b\\na\\n' > f; sort --check=diagnose-first f", '', "sort: f:2: disorder: a\n", 1],
    '-c accepts one file' => ["printf 'a\\n' > f; sort -c f f", '', "sort: extra operand 'f' not allowed with -c\n", 2],
    '-c on a missing file' => ['sort -c nope', '', "sort: open failed: nope: No such file or directory\n", 2],
    '-c on a directory' => ['mkdir d; sort -c d', '', "sort: read failed: d: Is a directory\n", 2],
    '-c and -C together' => ['sort -c -C', '', "sort: options '-cC' are incompatible\n", 2],
    '-c and -o together' => ['sort -c -o x', '', "sort: options '-co' are incompatible\n", 2],
    '-m merges sorted inputs' => ["printf 'a\\nc\\ne\\n' > f1; printf 'b\\nc\\nd\\n' > f2; sort -m f1 f2", "a\nb\nc\nc\nd\ne\n"],
    '-m does not sort' => ["printf 'b\\na\\n' > f1; printf 'c\\n' > f2; sort -m f1 f2", "b\na\nc\n"],
    '-mu merges and deduplicates' => ["printf 'a\\nb\\n' > f1; printf 'a\\nc\\n' > f2; sort -mu f1 f2 -", "a\nb\nc\n"],
    '-m with keys and reverse' => ["printf '3 a\\n1 b\\n' > f1; printf '2 c\\n' > f2; sort -m -k1,1nr f1 f2", "3 a\n2 c\n1 b\n"],
    'long options' => ["printf 'b:2\\na:10\\n' | sort --field-separator=: --key=2 --numeric-sort --reverse", "a:10\nb:2\n"],
    '--sort selects the comparison' => ["printf '10\\n9\\n' | sort --sort=num", "9\n10\n"],
    '--sort with an invalid word' => ['sort --sort=foo', '', "sort: invalid argument 'foo' for '--sort'\nValid arguments are:\n  - 'general-numeric'\n  - 'human-numeric'\n  - 'month'\n  - 'numeric'\n  - 'random'\n  - 'version'\nTry 'sort --help' for more information.\n", 1],
    '--sort with an ambiguous word' => ['sort --sort=', '', "sort: ambiguous argument '' for '--sort'\nValid arguments are:\n  - 'general-numeric'\n  - 'human-numeric'\n  - 'month'\n  - 'numeric'\n  - 'random'\n  - 'version'\nTry 'sort --help' for more information.\n", 1],
    '--check with an invalid word' => ['sort --check=foo', '', "sort: invalid argument 'foo' for '--check'\nValid arguments are:\n  - 'quiet', 'silent'\n  - 'diagnose-first'\nTry 'sort --help' for more information.\n", 1],
    'ignored performance options' => ["printf 'b\\na\\n' | sort -S 1M -T /tmp --parallel=2 --compress-program=gzip", "a\nb\n"],
    '-R groups equal lines' => ["printf 'a\\nb\\na\\nb\\n' | sort -R | uniq | sort", "a\nb\n"],
    'incompatible orderings' => ['sort -n -g', '', "sort: options '-gn' are incompatible\n", 2],
    'incompatible orderings in a key' => ['sort -k1dM', '', "sort: options '-dM' are incompatible\n", 2],
    'global orderings incompatible through a key' => ['sort -di -M -k1', '', "sort: options '-dM' are incompatible\n", 2],
    '-d is compatible with -V' => ["printf 'a-2\\na-10\\n' | sort -dV", "a-2\na-10\n"],
    '-R with -f' => ["printf 'a\\nA\\nb\\na\\n' | sort -fR | tr A a | uniq | sort", "a\nb\n"],
    'invalid option' => ['sort -x', '', "sort: invalid option -- 'x'\nTry 'sort --help' for more information.\n", 2],
    'missing option argument' => ['sort -k', '', "sort: option requires an argument -- 'k'\nTry 'sort --help' for more information.\n", 2],
    'long option missing its argument' => ['sort --key', '', "sort: option '--key' requires an argument\nTry 'sort --help' for more information.\n", 2],
    'ambiguous long option' => ['sort --c', '', "sort: option '--c' is ambiguous; possibilities: '--check' '--compress-program'\nTry 'sort --help' for more information.\n", 2],
    'key field zero' => ['sort -k0', '', "sort: field number is zero: invalid field specification '0'\n", 2],
    'key character offset zero' => ['sort -k1.0', '', "sort: character offset is zero: invalid field specification '1.0'\n", 2],
    'key end field zero' => ['sort -k1,0', '', "sort: field number is zero: invalid field specification '1,0'\n", 2],
    'key junk' => ['sort -k1x', '', "sort: stray character in field spec: invalid field specification '1x'\n", 2],
    'key without a number' => ['sort -ka', '', "sort: invalid number at field start: invalid count at start of 'a'\n", 2],
    'key with a bad character position' => ['sort -k1.a', '', "sort: invalid number after '.': invalid count at start of 'a'\n", 2],
    'key with an empty end' => ['sort -k1,', '', "sort: invalid number after ',': invalid count at start of ''\n", 2],
    'negative key' => ['sort -k -1', '', "sort: invalid number at field start: invalid count at start of '-1'\n", 2],
    'empty tab' => ["sort -t ''", '', "sort: empty tab\n", 2],
    'multi-character tab' => ['sort -t ab', '', "sort: multi-character tab 'ab'\n", 2],
    'incompatible tabs' => ['sort -t: -t,', '', "sort: incompatible tabs\n", 2],
    'the same tab twice' => ["printf 'b:1\\na:2\\n' | sort -t: -t: -k2", "b:1\na:2\n"],
    'a directory operand' => ['mkdir d; sort d', '', "sort: read failed: d: Is a directory\n", 2],
]);

// GNU coreutils sort messages.
test('sort -o reports an output file it cannot open', function (string $output, string $stderr): void {
    $result = $this->bash->exec(sprintf("printf 'b\\na\\n' > in; touch file; mkdir dir; sort -o %s in; echo \"rc=\$?\"", $output));

    expect([$result->stdout, $result->stderr])->toBe(["rc=2\n", $stderr]);
})->with([
    'missing directory' => ['nodir/out', "sort: open failed: nodir/out: No such file or directory\n"],
    'file as directory' => ['file/x', "sort: open failed: file/x: Not a directory\n"],
    'a directory' => ['dir', "sort: open failed: dir: Is a directory\n"],
]);
