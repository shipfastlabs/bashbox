<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('checksums', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/a', "hello\n");
    $bash->writeFile('/home/user/b', '');
    $bash->writeFile('/home/user/we\\ird', 'x');
    $bash->writeFile('/home/user/sp ace', 'y');
    $bash->exec('md5sum a b > good.md5; sha1sum --tag a > tag.sha1; sha256sum a b > s.sha256');

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'md5' => ['md5sum a b', "b1946ac92492d2347c6235b4d2611184  a\nd41d8cd98f00b204e9800998ecf8427e  b\n"],
    'sha1' => ['sha1sum a', "f572d396fae9206628714fb2ce00f72e94f2258f  a\n"],
    'sha256' => ['sha256sum a b', "5891b5b522d5df086d0ff0b110fbd9d21bb4fc7163af34d08286a2e846f6be03  a\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855  b\n"],
    'stdin' => ["printf 'hello\\n' | md5sum", "b1946ac92492d2347c6235b4d2611184  -\n"],
    'dash' => ["printf 'x' | md5sum - a", "9dd4e461268c8034f5c8564e155c67a6  -\nb1946ac92492d2347c6235b4d2611184  a\n"],
    'binary marker' => ['md5sum -b a', "b1946ac92492d2347c6235b4d2611184 *a\n"],
    'text after binary' => ['md5sum -b -t a', "b1946ac92492d2347c6235b4d2611184  a\n"],
    'tag' => ['sha256sum --tag a', "SHA256 (a) = 5891b5b522d5df086d0ff0b110fbd9d21bb4fc7163af34d08286a2e846f6be03\n"],
    'tag binary' => ['md5sum --tag -b a', "MD5 (a) = b1946ac92492d2347c6235b4d2611184\n"],
    'escaped name' => ["md5sum 'we\\ird'", "\\9dd4e461268c8034f5c8564e155c67a6  we\\\\ird\n"],
    'escaped name with tag' => ["sha1sum --tag 'we\\ird'", "\\SHA1 (we\\\\ird) = 11f6ad8ec52a2984abaafd7c3b516503785c2072\n"],
    'newline in name' => ["printf z > \$'n\\nl'; md5sum \$'n\\nl'", "\\fbade9e36a3f36d3d676c1b808451dd7  n\\nl\n"],
    'missing file' => ['md5sum nope a', "b1946ac92492d2347c6235b4d2611184  a\n", "md5sum: nope: No such file or directory\n", 1],
    'directory' => ['mkdir d; md5sum d', '', "md5sum: d: Is a directory\n", 1],
    'quoted name in error' => ["md5sum 'no pe'", '', "md5sum: 'no pe': No such file or directory\n", 1],
    'empty name in error' => ["md5sum ''", '', "md5sum: '': No such file or directory\n", 1],
    'check ok' => ['md5sum -c good.md5', "a: OK\nb: OK\n"],
    'check tag format' => ['sha1sum -c tag.sha1', "a: OK\n"],
    'check mismatch' => ["printf 'changed' > a; md5sum -c good.md5", "a: FAILED\nb: OK\n", "md5sum: WARNING: 1 computed checksum did NOT match\n", 1],
    'check two mismatches' => ["printf 'changed' > a; printf 'x' > b; md5sum -c good.md5", "a: FAILED\nb: FAILED\n", "md5sum: WARNING: 2 computed checksums did NOT match\n", 1],
    'check quiet' => ['md5sum --quiet -c good.md5', ''],
    'check quiet mismatch' => ["printf 'changed' > a; md5sum --quiet -c good.md5", "a: FAILED\n", "md5sum: WARNING: 1 computed checksum did NOT match\n", 1],
    'check status' => ["printf 'changed' > a; md5sum --status -c good.md5", '', '', 1],
    'check status ok' => ['md5sum --status -c good.md5', ''],
    'check missing file' => ['rm b; md5sum -c good.md5', "a: OK\nb: FAILED open or read\n", "md5sum: b: No such file or directory\nmd5sum: WARNING: 1 listed file could not be read\n", 1],
    'check ignore missing' => ['rm b; md5sum -c --ignore-missing good.md5', "a: OK\n"],
    'check ignore missing all gone' => ['rm a b; md5sum -c --ignore-missing good.md5', '', "md5sum: good.md5: no file was verified\n", 1],
    'check improperly formatted' => ["printf 'garbage\\n' >> good.md5; md5sum -c good.md5", "a: OK\nb: OK\n", "md5sum: WARNING: 1 line is improperly formatted\n"],
    'check two improperly formatted' => ["printf 'garbage\\nmore\\n' >> good.md5; md5sum -c good.md5", "a: OK\nb: OK\n", "md5sum: WARNING: 2 lines are improperly formatted\n"],
    'check warn' => ["printf 'garbage\\n' >> good.md5; md5sum -c -w good.md5", "a: OK\nb: OK\n", "md5sum: good.md5: 3: improperly formatted MD5 checksum line\nmd5sum: WARNING: 1 line is improperly formatted\n"],
    'check strict' => ["printf 'garbage\\n' >> good.md5; md5sum -c --strict good.md5", "a: OK\nb: OK\n", "md5sum: WARNING: 1 line is improperly formatted\n", 1],
    'check nothing formatted' => ["printf 'garbage\\n' > bad; md5sum -c bad", '', "md5sum: bad: no properly formatted checksum lines found\n", 1],
    'check empty' => ["printf '' > bad; md5sum -c bad", '', "md5sum: bad: no properly formatted checksum lines found\n", 1],
    'check comments and blanks' => ["printf '# c\\n\\n' >> good.md5; md5sum -c good.md5", "a: OK\nb: OK\n"],
    'check stdin' => ['md5sum -c < good.md5', "a: OK\nb: OK\n"],
    'check wrong algorithm' => ['sha1sum -c good.md5', '', "sha1sum: good.md5: no properly formatted checksum lines found\n", 1],
    'check uppercase hex' => ["printf '%s  a\\n' \$(md5sum < a | cut -c1-32 | tr a-f A-F) > up; md5sum -c up", "a: OK\n"],
    'check binary marker line' => ['md5sum -b a > bin; md5sum -c bin', "a: OK\n"],
    'check escaped name' => ["md5sum 'we\\ird' > e; md5sum -c e", "\\we\\\\ird: OK\n"],
    'check bad escape' => ["printf '\\\\%s  a\\\\q\\n' \$(md5sum < a | cut -c1-32) > e; md5sum -c e", '', "md5sum: e: no properly formatted checksum lines found\n", 1],
    'check crlf' => ["printf '%s  a\\r\\n' \$(md5sum < a | cut -c1-32) > crlf; md5sum -c crlf", "a: OK\n"],
    'check leading blanks' => ["printf ' \\t%s  a\\n' \$(md5sum < a | cut -c1-32) > lb; md5sum -c lb", "a: OK\n"],
    'check missing checksum file' => ['md5sum -c nope', '', "md5sum: nope: No such file or directory\n", 1],
    'check directory checksum file' => ['mkdir d; md5sum -c d', '', "md5sum: d: read error\n", 1],
    'check space name' => ["md5sum 'sp ace' > s; md5sum -c s", "sp ace: OK\n"],
    'check unreadable listed file' => ["mkdir dd; printf '%s  dd\\n' \$(md5sum < a | cut -c1-32) > x; md5sum -c x", "dd: FAILED open or read\n", "md5sum: dd: Is a directory\nmd5sum: WARNING: 1 listed file could not be read\n", 1],
    'check single tab separator' => ["printf '%s\\ta\\n' \$(md5sum < a | cut -c1-32) > x; md5sum -c x", "a: OK\n"],
    'tag text' => ['md5sum --tag -t a', '', "md5sum: --tag does not support --text mode\nTry 'md5sum --help' for more information.\n", 1],
    'tag check' => ['md5sum --tag -c good.md5', '', "md5sum: the --tag option is meaningless when verifying checksums\nTry 'md5sum --help' for more information.\n", 1],
    'binary check' => ['md5sum -b -c good.md5', '', "md5sum: the --binary and --text options are meaningless when verifying checksums\nTry 'md5sum --help' for more information.\n", 1],
    'status without check' => ['md5sum --status a', '', "md5sum: the --status option is meaningful only when verifying checksums\nTry 'md5sum --help' for more information.\n", 1],
    'warn without check' => ['md5sum -w a', '', "md5sum: the --warn option is meaningful only when verifying checksums\nTry 'md5sum --help' for more information.\n", 1],
    'quiet without check' => ['md5sum --quiet a', '', "md5sum: the --quiet option is meaningful only when verifying checksums\nTry 'md5sum --help' for more information.\n", 1],
    'strict without check' => ['md5sum --strict a', '', "md5sum: the --strict option is meaningful only when verifying checksums\nTry 'md5sum --help' for more information.\n", 1],
    'ignore-missing without check' => ['md5sum --ignore-missing a', '', "md5sum: the --ignore-missing option is meaningful only when verifying checksums\nTry 'md5sum --help' for more information.\n", 1],
    'bad option' => ['md5sum -x', '', "md5sum: invalid option -- 'x'\nTry 'md5sum --help' for more information.\n", 1],
    'sha256 check' => ['sha256sum -c s.sha256', "a: OK\nb: OK\n"],
]);
