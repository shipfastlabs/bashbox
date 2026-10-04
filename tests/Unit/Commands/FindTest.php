<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Filesystem\ReadWriteFs;
use BashBox\Limits;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "a\n");
    $this->bash->writeFile('/home/user/src/Main.php', "<?php\n");
    $this->bash->writeFile('/home/user/src/lib/util.txt', "u\n");
    $this->bash->writeFile('/home/user/src/.hidden', "h\n");
    $this->bash->getFilesystem()->symlink('a.txt', '/home/user/link');
});

test('find prints matching paths', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe('')
        ->and($result->exitCode)->toBe(0);
})->with([
    'defaults to . and does not follow symlinks' => ['find', ".\n./a.txt\n./link\n./src\n./src/.hidden\n./src/Main.php\n./src/lib\n./src/lib/util.txt\n"],
    'trailing slash on start point' => ['find src/ -name "*.txt"', "src/lib/util.txt\n"],
    'absolute start point' => ['find /home/user/src/lib', "/home/user/src/lib\n/home/user/src/lib/util.txt\n"],
    'root start point' => ['find / -maxdepth 1', "/\n/home\n/tmp\n"],
    'glob with bracket and ?' => ['find . -name "[am]*.???"', "./a.txt\n"],
    '-name is case sensitive' => ['find . -name "main.php"', ''],
    '-iname ignores case' => ['find . -iname "main.PHP"', "./src/Main.php\n"],
    '-name matches the start point' => ['find src -name src', "src\n"],
    '-type d' => ['find . -type d', ".\n./src\n./src/lib\n"],
    '-type f' => ['find src -type f', "src/.hidden\nsrc/Main.php\nsrc/lib/util.txt\n"],
    '-type l' => ['find . -type l', "./link\n"],
    '-maxdepth 0' => ['find src -maxdepth 0', "src\n"],
    '-maxdepth 1 with -type' => ['find -maxdepth 1 -type f', "./a.txt\n"],
    'file start point' => ['find a.txt src/lib -print', "a.txt\nsrc/lib\nsrc/lib/util.txt\n"],
    'file start point filtered out' => ['find a.txt -type d', ''],
]);

test('find reports a missing start point and continues', function (): void {
    $result = $this->bash->exec('find nope src/lib');

    expect($result->stdout)->toBe("src/lib\nsrc/lib/util.txt\n")
        ->and($result->stderr)->toBe("find: 'nope': No such file or directory\n")
        ->and($result->exitCode)->toBe(1);
});

test('find rejects bad expressions', function (string $script, string $stderr): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe('')
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe(1);
})->with([
    'unknown predicate' => ['find . -foo', "find: unknown predicate `-foo'\n"],
    'path after expression' => ['find -name x nope', "find: paths must precede expression: `nope'\n"],
    'existing path after expression' => ['find -name x src', "find: paths must precede expression: `src'\nfind: possible unquoted pattern after predicate `-name'?\n"],
    'missing argument' => ['find . -name', "find: missing argument to `-name'\n"],
    'bad -type' => ['find . -type x', "find: Unknown argument to -type: x\n"],
    'bad -maxdepth' => ['find . -maxdepth -1', "find: Expected a positive decimal integer argument to -maxdepth, but got '-1'\n"],
]);

test('find reports unreadable directories and keeps going', function (): void {
    $root = sys_get_temp_dir().'/bashbox-find-'.uniqid();
    mkdir($root.'/locked', 0777, true);
    touch($root.'/z.txt');
    chmod($root.'/locked', 0);

    try {
        $result = new Bash(new BashOptions(fs: new ReadWriteFs($root), cwd: '/'))->exec('find .');
    } finally {
        chmod($root.'/locked', 0755);
        rmdir($root.'/locked');
        unlink($root.'/z.txt');
        @rmdir($root.'/tmp'); // Created by Bash on startup
        rmdir($root);
    }

    expect($result->stdout)->toBe(".\n./locked\n./tmp\n./z.txt\n")
        ->and($result->stderr)->toBe("find: './locked': Permission denied\n")
        ->and($result->exitCode)->toBe(1);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can read any directory');

// Checked against GNU findutils 4.10 on the same tree, in BashBox's sorted order where GNU uses the filesystem's.
test('find expressions', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $now = time();
    $fs = $this->bash->getFilesystem();
    $this->bash->writeFile('/home/user/proj/big', str_repeat('x', 2000));
    $this->bash->writeFile('/home/user/proj/empty.txt', '');
    $this->bash->writeFile('/home/user/proj/script.sh', "#!/bin/sh\n");
    $this->bash->writeFile('/home/user/proj/secret', "s\n");
    $this->bash->writeFile('/home/user/proj/setuid', "x\n");
    $this->bash->writeFile('/home/user/proj/docs/readme.md', "r\n");
    $this->bash->writeFile('/home/user/proj/docs/guide.MD', "g\n");
    $this->bash->writeFile('/home/user/proj/lib/a/deep.txt', "d\n");

    $fs->mkdir('/home/user/proj/emptydir');
    $fs->chmod('/home/user/proj/script.sh', 0755);
    $fs->chmod('/home/user/proj/secret', 0600);
    $fs->chmod('/home/user/proj/setuid', 04755);
    $fs->symlink('secret', '/home/user/proj/lnk');
    $fs->symlink('nowhere', '/home/user/proj/broken');
    $fs->utimes('/home/user/proj/big', $now - 262800);
    $fs->utimes('/home/user/proj/docs/readme.md', $now - 90000);
    $fs->utimes('/home/user/proj/secret', $now - 1800);
    $fs->utimes('/home/user/proj/script.sh', $now - 300);
    $fs->utimes('/home/user/proj/docs/guide.MD', 1577934245);

    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    '! negates' => ["find proj ! -type d ! -name '*.*'", "proj/big\nproj/broken\nproj/lnk\nproj/secret\nproj/setuid\n"],
    '-not negates' => ['find proj -not -type d -not -type l', "proj/big\nproj/docs/guide.MD\nproj/docs/readme.md\nproj/empty.txt\nproj/lib/a/deep.txt\nproj/script.sh\nproj/secret\nproj/setuid\n"],
    '-o is lower precedence than implicit -a' => ['find proj -name big -o -type d -name docs', "proj/big\nproj/docs\n"],
    '-a and -and' => ["find proj -type f -a -name 's*' -and ! -name setuid", "proj/script.sh\nproj/secret\n"],
    '-or' => ['find proj -name big -or -name secret', "proj/big\nproj/secret\n"],
    'parentheses group' => ['find proj \\( -name big -o -name secret \\) -type f', "proj/big\nproj/secret\n"],
    'nested parentheses' => ["find proj \\( \\( -name big \\) -o \\( -name '*.md' \\) \\)", "proj/big\nproj/docs/readme.md\n"],
    'comma evaluates both sides and keeps the right one' => ['find proj -name big -print , -name secret', "proj/big\n"],
    'an action stops the default -print' => ['find proj -name big -o -name secret -print', "proj/secret\n"],
    '-print inside -o' => ['find proj -name big -print -o -name secret -print', "proj/big\nproj/secret\n"],
    '-path and -wholename match the whole path' => ["find proj -path '*docs*' -o -wholename proj/big", "proj/big\nproj/docs\nproj/docs/guide.MD\nproj/docs/readme.md\n"],
    '-ipath and -iwholename' => ["find proj -ipath '*DOCS/R*' -o -iwholename '*/BIG'", "proj/big\nproj/docs/readme.md\n"],
    '-path warns when it ends with a slash' => ["find proj -path 'proj/docs/'", '', "find: warning: -path proj/docs/ will not match anything because it ends with /.\n", 0],
    '-path ending with a slash can match a start point' => ["find proj/docs/ -path 'proj/docs/'", "proj/docs/\n"],
    '-iname' => ["find proj -iname '*.md'", "proj/docs/guide.MD\nproj/docs/readme.md\n"],
    '-regex matches the whole path in emacs syntax' => ["find proj -regex '.*/[a-z]+\\.\\(md\\|sh\\)'", "proj/docs/readme.md\nproj/script.sh\n"],
    'emacs + and ? are operators, braces are literal' => ["find proj -regex 'proj/s?e[a-z]+' -o -regex '.*{x}'", "proj/emptydir\nproj/secret\nproj/setuid\n"],
    '-iregex' => ["find proj -iregex '.*GUIDE\\..*'", "proj/docs/guide.MD\n"],
    '-regextype posix-extended' => ["find proj -regextype posix-extended -regex '.*/(big|secret)'", "proj/big\nproj/secret\n"],
    '-regextype posix-basic' => ["find proj -regextype posix-basic -regex '.*/\\(big\\|lnk\\)'", "proj/big\nproj/lnk\n"],
    '-regextype egrep' => ["find proj -regextype egrep -regex '.*e{2}.*' -o -regextype sed -regex '.*/s\\{1\\}etuid'", "proj/lib/a/deep.txt\nproj/setuid\n"],
    '-regextype is positional' => ["find proj -regex '.*\\(big\\)' -regextype posix-extended -o -regex '.*(lnk)'", "proj/big\nproj/lnk\n"],
    '-type with several letters' => ['find proj -type l,d', "proj\nproj/broken\nproj/docs\nproj/emptydir\nproj/lib\nproj/lib/a\nproj/lnk\n"],
    '-xtype follows symbolic links' => ['find proj -xtype f ! -type f', "proj/lnk\n"],
    '-xtype l finds broken links' => ['find proj -xtype l', "proj/broken\n"],
    '-size in bytes, words and blocks' => ['find proj -type f \\( -size 2000c -o -size -2w \\)', "proj/big\nproj/docs/guide.MD\nproj/docs/readme.md\nproj/empty.txt\nproj/lib/a/deep.txt\nproj/secret\nproj/setuid\n"],
    '-size rounds up to whole units' => ['find proj -type f -size 1k', "proj/docs/guide.MD\nproj/docs/readme.md\nproj/lib/a/deep.txt\nproj/script.sh\nproj/secret\nproj/setuid\n"],
    '-size + and - with k and M' => ['find proj -type f -size +1k -o -type f -size -1M -size +0', "proj/big\n"],
    '-size in blocks by default' => ['find proj -type f -size 4', "proj/big\n"],
    '-size G' => ['find proj -type f -size -1G', "proj/empty.txt\n"],
    '-empty files and directories' => ['find proj -empty', "proj/empty.txt\nproj/emptydir\n"],
    '-newer' => ['find proj -type f -newer proj/docs/readme.md', "proj/empty.txt\nproj/lib/a/deep.txt\nproj/script.sh\nproj/secret\nproj/setuid\n"],
    '-mtime' => ['find proj -type f -mtime 3 -o -type f -mtime 1', "proj/big\nproj/docs/readme.md\n"],
    '-mtime + and -' => ["find proj -type f -mtime +2 -o -type f -name 'r*' -mtime -2", "proj/big\nproj/docs/guide.MD\nproj/docs/readme.md\n"],
    '-mtime -0 and +0' => ['find proj -type f -mtime +0', "proj/big\nproj/docs/guide.MD\nproj/docs/readme.md\n"],
    'fractional -mtime' => ['find proj -type f -mtime -1.5 -mtime +0.5', ''],
    '-mmin' => ['find proj -type f -mmin -10 -mmin +2', "proj/script.sh\n"],
    '-mmin N matches the minute before' => ['find proj -type f -mmin 30 -o -type f -mmin 5', ''],
    '-atime uses the modification time' => ['find proj -type f -atime +2', "proj/big\nproj/docs/guide.MD\n"],
    '-perm exact octal' => ['find proj ! -type l -perm 600', "proj/secret\n"],
    '-perm with all bits' => ['find proj ! -type l -perm -755', "proj\nproj/docs\nproj/emptydir\nproj/lib\nproj/lib/a\nproj/script.sh\nproj/setuid\n"],
    '-perm with any bit' => ['find proj ! -type l -perm /4000', "proj/setuid\n"],
    '-perm symbolic' => ["find proj ! -type l -perm u=rw,go=r -name 's*'", ''],
    '-perm symbolic with all bits' => ['find proj ! -type l -perm -u+x,g+x -type f', "proj/script.sh\nproj/setuid\n"],
    '-perm symbolic any' => ['find proj ! -type l -perm /u+s,o+w', "proj/setuid\n"],
    '-perm X counts on directories' => ['find proj ! -type l -perm a+rX,u+w -name lib', "proj/lib\n"],
    '-perm copying another class' => ['find proj ! -type l -perm u=rw,g=u,o=u', ''],
    '-perm = with nothing' => ['find proj ! -type l -perm =', ''],
    '-perm /0 matches everything and warns' => ['find proj -perm /0 -name secret', "proj/secret\n", "find: warning: you have specified a mode pattern /0 (which is equivalent to /000). The meaning of -perm /000 has now been changed to be consistent with -perm -000; that is, while it used to match no files, it now matches all files.\n", 0],
    '-perm =t and +t' => ['find proj -perm +t', ''],
    '-readable -writable -executable' => ['find proj -maxdepth 1 -executable -o -maxdepth 1 -readable ! -writable', "proj\nproj/docs\nproj/emptydir\nproj/lib\nproj/script.sh\nproj/setuid\n"],
    '-readable follows links' => ['find proj -type l -readable', "proj/lnk\n"],
    '-true and -false' => ['find proj -false -o -name big -true', "proj/big\n"],
    '-maxdepth and -mindepth' => ['find proj -mindepth 2 -maxdepth 2', "proj/docs/guide.MD\nproj/docs/readme.md\nproj/lib/a\n"],
    '-mindepth hides shallower entries' => ['find proj -mindepth 3', "proj/lib/a/deep.txt\n"],
    '-depth lists contents first' => ['find proj/lib -depth', "proj/lib/a/deep.txt\nproj/lib/a\nproj/lib\n"],
    '-prune skips a directory' => ["find proj -name lib -prune -o -name '*.txt' -print", "proj/empty.txt\n"],
    '-prune keeps the pruned directory itself' => ['find proj -name docs -prune', "proj/docs\n"],
    '-prune does nothing with -depth' => ['find proj/lib -depth -name a -prune', "proj/lib/a\n"],
    '-quit stops at the first match' => ["find proj -type f -name '*.md' -print -quit", "proj/docs/readme.md\n"],
    '-quit alone prints nothing' => ['find proj -quit', ''],
    '-print0' => ["find proj/docs -print0 | tr '\\0' '|'", 'proj/docs|proj/docs/guide.MD|proj/docs/readme.md|'],
    '-printf directives' => ["find proj/docs proj/big -printf '%p|%f|%h|%P|%H|%d|%y|%m|%M|%%\\n'", "proj/docs|docs|proj||proj/docs|0|d|755|drwxr-xr-x|%\nproj/docs/guide.MD|guide.MD|proj/docs|guide.MD|proj/docs|1|f|644|-rw-r--r--|%\nproj/docs/readme.md|readme.md|proj/docs|readme.md|proj/docs|1|f|644|-rw-r--r--|%\nproj/big|big|proj||proj/big|0|f|644|-rw-r--r--|%\n"],
    '-printf sizes and links' => ["find proj -type f -name 's*' -printf '%s %M %#m %05m %Y\\n' -o -type l -printf '%p -> %l %Y\\n'", "proj/broken -> nowhere N\nproj/lnk -> secret f\n10 -rwxr-xr-x 0755 00755 f\n2 -rw------- 0600 00600 f\n2 -rwsr-xr-x 04755 04755 f\n"],
    '-printf widths' => ["find proj/docs -printf '[%10f|%-10f|%.3f|%+d|%05d]\\n'", "[      docs|docs      |doc|+0|00000]\n[  guide.MD|guide.MD  |gui|+1|00001]\n[ readme.md|readme.md |rea|+1|00001]\n"],
    '-printf escapes' => ["find proj/docs -maxdepth 0 -printf 'a\\tb\\\\\\101\\0102\\n\\a\\b\\f\\r\\v\\c not printed'", "a\tb\\A\x082\n\x07\x08\f\r\v"],
    '-printf warnings' => ["find proj/docs -maxdepth 0 -printf '%z \\q %T\\n'", '%z \\q \\n', "find: warning: unrecognized format directive `%z'\nfind: warning: unrecognized escape `\\q'\n", 0],
    '-printf with a trailing backslash' => ["find proj/docs -maxdepth 0 -printf 'x\\'", 'x\\', "find: warning: escape `\\' followed by nothing at all\n", 0],
    '-printf times' => ["find proj/big -printf '%TY-%Tm-%Td %TH:%TM %Tq|%A@|%C+\\n' | sed 's/[0-9]/N/g'", "NNNN-NN-NN NN:NN q|NNNNNNNNNN.NNNNNNNNNN|NNNN-NN-NN+NN:NN:NN.NNNNNNNNNN\n"],
    '-printf % at the end' => ["find proj -printf 'x%'", '', "find: error: % at end of format string\n", 1],
    '-printf reserved directives' => ["find proj -printf '%{'", '', "find: error: the format directive `%{' is reserved for future use\n", 1],
    '-printf with a dangling flag' => ["find proj -printf '%5'", '', "find: error: the format directive `%\x00' is reserved for future use\n", 1],
    '-exec runs once per file' => ['find proj/docs -type f -exec echo got {} \\;', "got proj/docs/guide.MD\ngot proj/docs/readme.md\n"],
    '-exec replaces {} inside words' => ["find proj/docs -name '*.md' -exec echo x{}y {} \\;", "xproj/docs/readme.mdy proj/docs/readme.md\n"],
    '-exec is a test' => ['find proj -maxdepth 1 -type f -exec test -s {} \\; -print', "proj/big\nproj/script.sh\nproj/secret\nproj/setuid\n"],
    '-exec + runs once at the end' => ['find proj/docs -exec echo {} + -print', "proj/docs\nproj/docs/guide.MD\nproj/docs/readme.md\nproj/docs proj/docs/guide.MD proj/docs/readme.md\n"],
    '-exec + after -quit still runs' => ['find proj -type f -exec echo {} + -quit', "proj/big\n"],
    '-exec + with a failing command' => ['find proj/docs -exec false {} +', '', '', 1],
    '-exec with a missing command' => ['find proj/docs -maxdepth 0 -exec nosuchcmd {} \\; -print', '', "find: 'nosuchcmd': No such file or directory\n", 0],
    '-exec + with a missing command' => ['find proj/docs -maxdepth 0 -exec nosuchcmd {} +', '', "find: 'nosuchcmd': No such file or directory\n", 1],
    '-exec + needs {} last' => ['find proj -exec echo {} x +', '', "find: missing argument to `-exec'\n", 1],
    '-exec + takes one {}' => ['find proj -exec echo {} {} +', '', "find: Only one instance of {} is supported with -exec ... +\n", 1],
    '-exec + with {} inside a word' => ['find proj -exec echo a{} +', '', "find: In '-exec ... {} +' the '{}' must appear by itself, but you specified 'a{}'\n", 1],
    '-exec + without {} is a plain argument' => ['find proj/docs -maxdepth 0 -exec echo + \\;', "+\n"],
    '-exec without a terminator' => ['find proj -exec echo {}', '', "find: missing argument to `-exec'\n", 1],
    '-exec without a command' => ['find proj -exec \\;', '', "find: invalid argument `;' to `-exec'\n", 1],
    '-execdir runs in the directory' => ['find proj/lib -execdir pwd \\; -execdir echo {} \\;', "/home/user/proj\n./lib\n/home/user/proj/lib\n./a\n/home/user/proj/lib/a\n./deep.txt\n"],
    '-execdir + batches per directory' => ['find proj/lib proj/docs -execdir echo {} +', "./lib\n./a\n./deep.txt\n./docs\n./guide.MD ./readme.md\n"],
    '-execdir on start points' => ['find proj/docs/ . -maxdepth 0 -execdir echo {} \\;', "./docs/\n./.\n"],
    '-ok without answers runs nothing' => ['find proj/docs -ok echo {} \\;', '', '< echo ... proj/docs > ? < echo ... proj/docs/guide.MD > ? < echo ... proj/docs/readme.md > ? ', 0],
    '-ok reads answers from stdin' => ["printf 'y\\nn\\nyes\\n' | find proj/docs -ok echo run {} \\;", "run proj/docs\nrun proj/docs/readme.md\n", '< echo ... proj/docs > ? < echo ... proj/docs/guide.MD > ? < echo ... proj/docs/readme.md > ? ', 0],
    '-okdir' => ['echo Y | find proj/docs -maxdepth 0 -okdir echo {} \\;', "./docs\n", '< echo ... proj/docs > ? ', 0],
    '-ok does not take +' => ['find proj -ok echo {} +', '', "find: missing argument to `-ok'\n", 1],
    '-delete removes files and empty directories' => ["find proj -name '*.md' -delete -o -name emptydir -delete; find proj/docs proj/emptydir", "proj/docs\nproj/docs/guide.MD\n", "find: 'proj/emptydir': No such file or directory\n", 1],
    '-delete reports non-empty directories' => ['find proj -name lib -delete', '', "find: cannot delete 'proj/lib': Directory not empty\n", 1],
    '-delete everything below a start point' => ['find proj -delete; find proj', '', "find: 'proj': No such file or directory\n", 1],
    '-delete leaves .' => ['cd proj/lib && find . -delete && ls -a', ".\n..\n"],
    '-delete conflicts with -prune' => ['find proj -prune -delete', '', "find: The -delete action automatically turns on -depth, but -prune does nothing when -depth is in effect.  If you want to carry on anyway, just explicitly use the -depth option.\n", 1],
    '-delete with explicit -depth and -prune' => ['find proj -depth -name a -prune -delete; find proj/lib', "proj/lib\nproj/lib/a\nproj/lib/a/deep.txt\n", "find: cannot delete 'proj/lib/a': Directory not empty\n", 0],
    'paths must come first' => ['find proj -name x proj', '', "find: paths must precede expression: `proj'\nfind: possible unquoted pattern after predicate `-name'?\n", 1],
    'unknown predicate' => ['find proj -bogus', '', "find: unknown predicate `-bogus'\n", 1],
    'missing argument' => ['find proj -name', '', "find: missing argument to `-name'\n", 1],
    'unmatched (' => ['find proj \\( -name x', '', "find: invalid expression; I was expecting to find a ')' somewhere but did not see one.\n", 1],
    'too many )' => ['find proj \\( -name x \\) \\)', '', "find: you have too many ')'\n", 1],
    'empty parentheses' => ['find proj \\( \\)', '', "find: invalid expression; empty parentheses are not allowed.\n", 1],
    'binary operator with nothing before' => ['find proj -o -name x', '', "find: invalid expression; you have used a binary operator '-o' with nothing before it.\n", 1],
    '-a with nothing before' => ['find proj \\( -a \\)', '', "find: invalid expression; you have used a binary operator '-a' with nothing before it.\n", 1],
    'comma with nothing before' => ['find proj , -name x', '', "find: ',': No such file or directory\n", 1],
    'nothing after -o' => ['find proj -name x -o', '', "find: expected an expression after '-o'\n", 1],
    'nothing after !' => ['find proj !', '', "find: expected an expression after '!'\n", 1],
    'nothing after -not' => ['find proj -name x -not', '', "find: expected an expression after '-not'\n", 1],
    '-o then )' => ['find proj \\( -name x -o \\)', '', "find: expected an expression between '-o' and ')'\n", 1],
    '-a then )' => ['find proj \\( -name x -a \\)', '', "find: expected an expression between '-a' and ')'\n", 1],
    '! then )' => ['find proj \\( ! \\)', '', "find: expected an expression between '!' and ')'\n", 1],
    'comma then nothing' => ['find proj -name x ,', '', "find: expected an expression after ','\n", 1],
    'a leading ) is a start point' => ['find \\) -maxdepth 0', '', "find: ')': No such file or directory\n", 1],
    'bad -type letter' => ['find proj -type x', '', "find: Unknown argument to -type: x\n", 1],
    '-type D' => ['find proj -type D', '', "find: -type D is not supported because Solaris doors are not supported on the platform find was compiled on.\n", 1],
    '-type duplicates' => ['find proj -type f,f', '', "find: Duplicate file type 'f' in the argument list to -type.\n", 1],
    '-type without a comma' => ['find proj -type fd', '', "find: Must separate multiple arguments to -type using: ','\n", 1],
    '-type ending in a comma' => ['find proj -type f,', '', "find: Last file type in list argument to -type is missing, i.e., list is ending on: ','\n", 1],
    '-type empty' => ["find proj -type ''", '', "find: Arguments to -type should contain at least one letter\n", 1],
    'bad -size suffix' => ['find proj -size 2x', '', "find: invalid -size type `x'\n", 1],
    'bad -size number' => ['find proj -size 1.5', '', "find: Invalid argument `1.5' to -size\n", 1],
    'empty -size' => ["find proj -size ''", '', "find: invalid null argument to -size\n", 1],
    'bad -mtime' => ['find proj -mtime 1x', '', "find: invalid argument `1x' to `-mtime'\n", 1],
    'bad -mmin' => ["find proj -mmin ''", '', "find: invalid argument `' to `-mmin'\n", 1],
    'bad -mindepth' => ['find proj -mindepth x', '', "find: Expected a positive decimal integer argument to -mindepth, but got 'x'\n", 1],
    'bad -perm' => ['find proj -perm 9', '', "find: invalid mode '9'\n", 1],
    'bad symbolic -perm' => ['find proj -perm u+q', '', "find: invalid mode 'u+q'\n", 1],
    '-perm +NNN is rejected' => ['find proj -perm +644', '', "find: invalid mode '+644'\n", 1],
    '-perm too large' => ['find proj -perm 77777', '', "find: invalid mode '77777'\n", 1],
    '-newer with a missing file' => ['find proj -newer nope', '', "find: 'nope': No such file or directory\n", 1],
    'bad -regextype' => ['find proj -regextype foo', '', "find: Unknown regular expression type 'foo'; valid types are 'findutils-default', 'ed', 'emacs', 'gnu-awk', 'grep', 'posix-awk', 'awk', 'posix-basic', 'posix-egrep', 'egrep', 'posix-extended', 'posix-minimal-basic', 'sed'.\n", 1],
    'bad -regex' => ["find proj -regex '\\('", '', "find: failed to compile regular expression '\\(': Unmatched ( or \\(\n", 1],
    'bad -regex in posix-extended' => ["find proj -regextype posix-extended -regex 'a{2,1}'", '', "find: failed to compile regular expression 'a{2,1}': Invalid content of \\{\\}\n", 1],
    '-quit skips the remaining start points' => ['find proj/big proj/docs -print -quit', "proj/big\n"],
    '-empty on a directory removed meanwhile' => ['find proj/emptydir -exec rm -r {} \\; -empty', '', "find: 'proj/emptydir': No such file or directory\nfind: 'proj/emptydir': No such file or directory\n", 1],
    'descending into a directory removed meanwhile' => ['find proj/lib -name a -exec rm -r {} \\; -print', "proj/lib/a\n", "find: 'proj/lib/a': No such file or directory\n", 1],
    '-perm with -' => ['find proj -type f -perm -u=rwx,go-w', "proj/script.sh\nproj/setuid\n"],
    '-execdir from the root' => ['find / -maxdepth 0 -execdir echo {} \\;', "/\n"],
    '-printf %h and %f of the root' => ["find / -maxdepth 0 -printf '[%h][%f]\\n'", "[][/]\n"],
    '-printf %T without a conversion' => ["find proj -maxdepth 0 -printf '%T'", '%T', "find: warning: format directive `%T' should be followed by another character\n", 0],
    '-printf time conversions' => ["find proj/docs/guide.MD -printf '%Te|%Tk|%Tl|%Tj|%TA %TB %TD %TF %TI %Tp %Tr %TR %Ts %Tu %Tw %Ty %Tz %TZ|%TT|%Tx|%Th|%TS|%T@|%T+|%TH:%TM:%TS\\n'", " 2| 3| 3|002|Thursday January 01/02/20 2020-01-02 03 AM 03:04:05 AM 03:04 1577934245 4 4 20 +0000 UTC|03:04:05.0000000000|01/02/20|Jan|05.0000000000|1577934245.0000000000|2020-01-02+03:04:05.0000000000|03:04:05.0000000000\n"],
    '-printf %t and %a' => ["find proj/docs/guide.MD -printf '%t|%a|%A+\\n'", "Thu Jan  2 03:04:05.0000000000 2020|Thu Jan  2 03:04:05.0000000000 2020|2020-01-02+03:04:05.0000000000\n"],
]);

test('find stops at the output size limit instead of exhausting memory', function (string $script): void {
    $bash = new Bash(new BashOptions(limits: new Limits(maxOutputSize: 1000), initialFiles: array_fill_keys(array_map(fn (int $i): string => '/d/a-rather-long-file-name-'.$i, range(1, 60)), '')));

    expect(fn (): BashExecResult => $bash->exec($script))->toThrow(ExecutionLimitException::class, 'find: output size limit exceeded');
})->with([
    'a huge -printf width' => ["find /d -printf '%999999999p'"],
    'many entries' => ['find /d -print'],
    'output of -exec' => ['find /d -exec echo {} {} {} \;'],
]);
