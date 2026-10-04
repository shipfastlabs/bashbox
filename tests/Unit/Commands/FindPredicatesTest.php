<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

/*
 * Symbolic link following, ownership, inodes, -newerXY, -daystart, -ls and the -f* output files.
 * Checked against GNU findutils 4.10 on the same tree (LC_ALL=C TZ=UTC). GNU lists a directory in the order the
 * filesystem keeps it; BashBox sorts, so the expected order (of output and of errors) here is that one.
 */
beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->fs = $this->bash->getFilesystem();
    $this->bash->writeFile('/home/user/t/d/f', "hi\n");
    $this->fs->mkdir('/home/user/t/d/sub');
    $this->fs->mkdir('/home/user/t/e');
    $this->fs->symlink('..', '/home/user/t/d/sub/up');
    $this->fs->symlink('d', '/home/user/t/dl');
    $this->fs->symlink('nowhere', '/home/user/t/broken');
    $this->fs->symlink('self', '/home/user/t/self');
    $this->fs->symlink('d/f', '/home/user/t/fl');
    $this->fs->link('/home/user/t/d/f', '/home/user/t/hard');

    $this->bash->writeFile('/home/user/t/big', str_repeat('x', 2000));
    $this->bash->writeFile('/home/user/t/sp ace', "q\n");

    $this->fs->utimes('/home/user/t/d/f', 1577934245);
    $this->fs->utimes('/home/user/t/big', 1600000000);
});

$loops = "find: File system loop detected; 't/d/sub/up' is part of the same file system loop as 't/d'.\n"
    ."find: File system loop detected; 't/dl/sub/up' is part of the same file system loop as 't/dl'.\n"
    ."find: 't/self': Too many levels of symbolic links\n";

test('find predicates', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    '-L follows links and reports loops' => ['find -L t', "t\nt/big\nt/broken\nt/d\nt/d/f\nt/d/sub\nt/dl\nt/dl/f\nt/dl/sub\nt/e\nt/fl\nt/hard\nt/sp ace\n", $loops, 1],
    '-H follows start points only' => ['find -H t/dl t/fl t/broken t/self', "t/dl\nt/dl/f\nt/dl/sub\nt/dl/sub/up\nt/fl\nt/broken\n", "find: 't/self': Too many levels of symbolic links\n", 1],
    '-L on start points' => ['find -L t/dl t/self t/broken', "t/dl\nt/dl/f\nt/dl/sub\nt/broken\n", "find: File system loop detected; 't/dl/sub/up' is part of the same file system loop as 't/dl'.\nfind: 't/self': Too many levels of symbolic links\n", 1],
    '-L with -depth' => ['find -L t/d -depth', "t/d/f\nt/d/sub\nt/d\n", "find: File system loop detected; 't/d/sub/up' is part of the same file system loop as 't/d'.\n", 1],
    'a loop back above the start point' => ['find -L t/d/sub', "t/d/sub\nt/d/sub/up\nt/d/sub/up/f\n", "find: File system loop detected; 't/d/sub/up/sub' is part of the same file system loop as 't/d/sub'.\n", 1],
    'the last of -P and -L wins' => ['find -P -L t -maxdepth 1 -type l', "t/broken\n", "find: 't/self': Too many levels of symbolic links\n", 1],
    '-P after -L' => ['find -L -P t -maxdepth 1 -type l', "t/broken\nt/dl\nt/fl\nt/self\n"],
    '-- ends the options' => ['find -L -- t/dl -maxdepth 0 -printf "%y\n"', "d\n"],
    '-follow anywhere is -L' => ['find t -maxdepth 1 -type l -follow', "t/broken\n", "find: 't/self': Too many levels of symbolic links\n", 1],
    '-xtype with -L looks at the link' => ['find -L t -xtype l', "t/broken\nt/dl\nt/fl\n", $loops, 1],
    '-xtype with -H on start points' => ['find -H t/dl t/fl -xtype l', "t/dl\nt/fl\n"],
    '-xtype on a loop' => ['find t -maxdepth 1 -xtype l', "t/broken\nt/self\n"],
    '%y %Y and %l' => ["find t -maxdepth 1 -printf '%p %y %Y [%l]\\n'", "t d d []\nt/big f f []\nt/broken l N [nowhere]\nt/d d d []\nt/dl l d [d]\nt/e d d []\nt/fl l f [d/f]\nt/hard f f []\nt/self l L [self]\nt/sp ace f f []\n"],
    '%y %Y and %l with -L' => ["find -L t -maxdepth 1 -printf '%p %y %Y [%l]\\n'", "t d d []\nt/big f f []\nt/broken l N [nowhere]\nt/d d d []\nt/dl d d []\nt/e d d []\nt/fl f f []\nt/hard f f []\nt/sp ace f f []\n", "find: 't/self': Too many levels of symbolic links\n", 1],
    '-samefile finds hard links' => ['find t -samefile t/d/f', "t/d/f\nt/hard\n"],
    '-samefile on a link is the link' => ['find t -samefile t/fl', "t/fl\n"],
    '-samefile with -L' => ['find -L t -samefile t/fl', "t/d/f\nt/dl/f\nt/fl\nt/hard\n", $loops, 1],
    '-samefile with -H follows the reference' => ['find -H t -samefile t/fl', "t/d/f\nt/hard\n"],
    '-samefile with a missing file' => ['find t -samefile nope', '', "find: 'nope': No such file or directory\n", 1],
    '-samefile with a loop' => ['find -L t -samefile t/self', '', "find: 't/self': Too many levels of symbolic links\n", 1],
    '-newer with -L follows the reference' => ['find -L t -maxdepth 1 -newer t/fl -name "[bs]*"', "t/big\nt/broken\nt/sp ace\n", "find: 't/self': Too many levels of symbolic links\n", 1],
    '-newer with -H follows the reference' => ['find -H t/fl -newer t/fl', ''],
    '-links' => ['find t -links 2 -type f', "t/d/f\nt/hard\n"],
    '-links -N' => ['find t -links -2', "t/big\nt/broken\nt/d/sub/up\nt/dl\nt/fl\nt/self\nt/sp ace\n"],
    '-links +N counts directories' => ['find t -links +3', "t\n"],
    '-links with spaces and +' => ["find t -type f -links ' +2'", "t/d/f\nt/hard\n"],
    '-inum 0' => ['find t -inum 0', ''],
    '-lname' => ["find t -lname 'd*'", "t/dl\nt/fl\n"],
    '-ilname' => ["find t -ilname 'D*'", "t/dl\nt/fl\n"],
    '-lname with -L sees only broken links' => ["find -L t -lname '*'", "t/broken\n", $loops, 1],
    '-user and -group by name' => ['find t/big -user user -group user', "t/big\n"],
    '-user and -group by number' => ["find t/big -user 1000 -group ' +1000'", "t/big\n"],
    '-user root' => ['find t -user root', ''],
    '-uid and -gid' => ['find t/big -uid 1000 -gid -1001 -gid +999', "t/big\n"],
    '-nouser and -nogroup' => ['find t -nouser -o -nogroup', ''],
    'owner directives' => ["find t/big -printf '%u:%g:%U:%G\\n'", "user:user:1000:1000\n"],
    '-newerXY against a file' => ['find t -newermm t/d/f -name big', "t/big\n"],
    '-newerXY maps a and c to the modification time' => ['find t -neweram t/d/f -newercc t/d/f -name big', "t/big\n"],
    '-newermt with a date' => ["find t -newermt '2020-06-01' -type f", "t/big\nt/sp ace\n"],
    '-newermt is strictly newer' => ["find t -newermt '2020-01-02 03:04:05' -name f", ''],
    '-newermt a second before' => ["find t -newermt '2020-01-02 03:04:04' -name f", "t/d/f\n"],
    '-newermt with @seconds' => ['find t -newermt @1599999999 -name big', "t/big\n"],
    'bad -user' => ['find t -user bob', '', "find: invalid user name or UID argument to -user: 'bob'\n", 1],
    'bad -group' => ['find t -group 12x', '', "find: invalid group name or GID argument to -group: '12x'\n", 1],
    '-user out of range' => ['find t -user 4294967296', '', "find: invalid user name or UID argument to -user: '4294967296'\n", 1],
    'bad -uid' => ['find t -uid --5', '', "find: non-numeric argument to -uid: '--5'\n", 1],
    'bad -links' => ['find t -links +-3', '', "find: non-numeric argument to -links: '+-3'\n", 1],
    'bad -inum' => ['find t -inum 1.5', '', "find: non-numeric argument to -inum: '1.5'\n", 1],
    'bad -newerXY letter' => ['find t -newerxm t', '', "find: invalid predicate `-newerxm'\n", 1],
    '-newertY is invalid' => ['find t -newertm t', '', "find: invalid predicate `-newertm'\n", 1],
    '-newerXY without a reference' => ['find t -newermm', '', "find: The '-newermm' test needs an argument\n", 1],
    '-newerXY with a missing file' => ['find t -newercm nope', '', "find: 'nope': No such file or directory\n", 1],
    '-newerXt with a bad date' => ['find t -newermt garbage', '', "find: I cannot figure out how to interpret 'garbage' as a date or time\n", 1],
    'no birth times' => ['find t -newerBt 2020-01-01', '', "find: This system does not provide a way to find the birth time of a file.\nfind: invalid predicate `-newerBt'\n", 1],
    'no birth time of a reference' => ['find t -newermB t', '', "find: This system does not provide a way to find the birth time of a file.\nfind: invalid predicate `-newermB'\n", 1],
    '-lname without a pattern' => ['find t -lname', '', "find: missing argument to `-lname'\n", 1],
    'block, link, device and sparseness directives' => ["find t/big t/d/f t/e t/fl -printf '%n %k %b %D %F %S %.2S %.1S %.S %10S|%-6S|%5k|\\n'", "1 2 4 0 unknown 1.024 1 1 1      1.024|1.024 |    2|\n2 1 2 0 unknown 341.333 3.4e+02 3e+02 3e+02    341.333|341.333|    1|\n2 0 0 0 unknown 1 1 1 1          1|1     |    0|\n1 1 2 0 unknown 341.333 3.4e+02 3e+02 3e+02    341.333|341.333|    1|\n"],
    "-L blocks are the target's" => ["find -L t/fl -printf '%k %b %S\\n'", "1 2 341.333\n"],
    '%B without birth times' => ["find t/big -printf '[%BY]\\n'", "[]\n"],
    '%Z without SELinux' => ["find t/d -maxdepth 1 -printf '%5Z|%p\\n'", "     |t/d\n     |t/d/f\n     |t/d/sub\n", "find: getfilecon failed: 't/d': Operation not supported\nfind: getfilecon failed: 't/d/f': Operation not supported\nfind: getfilecon failed: 't/d/sub': Operation not supported\n", 1],
    '-fprint creates the file even without matches' => ['echo old > out; find t -name nomatch -fprint out; wc -c < out', "0\n"],
    '-fprint, -fprint0 and -fprintf share a file' => ["find t/d -fprint out -fprint0 ./out -fprintf out '<%f>\\n'; cat -v out", "t/d\nt/d^@<d>\nt/d/f\nt/d/f^@<f>\nt/d/sub\nt/d/sub^@<sub>\nt/d/sub/up\nt/d/sub/up^@<up>\n"],
    'output files are written at the end' => ['find t/d -name f -print -fprint out -exec cat out \\; ; cat out', "t/d/f\nt/d/f\n"],
    'an output file deleted meanwhile stays deleted' => ['find t/d -fprint t/d/out -delete; ls t', "big\nbroken\ndl\ne\nfl\nhard\nself\nsp ace\n"],
    '/dev/stdout and /dev/stderr' => ["find t -name f -fprint /dev/stdout -fprintf /dev/stderr 'E%p\\n' -fprint0 /dev/stdout", "t/d/f\nt/d/f\0", "Et/d/f\n"],
    '-fprint into a directory' => ['find t -fprint t', '', "find: 't': Is a directory\n", 1],
    '-fprint into a missing directory' => ['find t -fprint nodir/x', '', "find: 'nodir/x': No such file or directory\n", 1],
    '-fprint below a file' => ['find t -fprint t/d/f/x', '', "find: 't/d/f/x': Not a directory\n", 1],
    '-fprintf without a format' => ['find t -fprintf out3; ls out3', '', "find: invalid argument `out3' to `-fprintf'\nls: cannot access 'out3': No such file or directory\n", 2],
    '-fprintf opens the file before reading the format' => ["find t -fprintf out4 '%'; ls out4", "out4\n", "find: error: % at end of format string\n", 0],
    'an output file is opened as it is parsed' => ['find t -fprint out5 -bogus; ls out5', "out5\n", "find: unknown predicate `-bogus'\n", 0],
    '-fprint without a file' => ['find t -fprint', '', "find: missing argument to `-fprint'\n", 1],
    '-fprint0 without a file' => ['find t -fprint0', '', "find: missing argument to `-fprint0'\n", 1],
    '-fls without a file' => ['find t -fls', '', "find: missing argument to `-fls'\n", 1],
    '-fprintf without a file' => ['find t -fprintf', '', "find: missing argument to `-fprintf'\n", 1],
]);

test('-ls and -fls list files like ls -dils', function (): void {
    $now = time();
    $odd = "n \\\"q\nr\x08\r\t\f\x01\xC3\xA9";
    $this->fs->utimes('/home/user/t/sp ace', $now + 7200);
    $this->bash->writeFile('/home/user/t/'.$odd, '');
    $this->fs->utimes('/home/user/t/'.$odd, 1500000000);
    $ino = fn (string $path): string => str_pad((string) $this->fs->lstat('/home/user/'.$path)->ino, 9, ' ', STR_PAD_LEFT);
    // Recent files show the time, others (over six months old, or in the future) the year
    $when = fn (string $path, string $format = ' H:i'): string => date('M ', $mtime = $this->fs->lstat('/home/user/'.$path)->mtime).sprintf('%2d', date('j', $mtime)).date($format, $mtime);

    $result = $this->bash->exec("find t/big t/d/f t/fl 't/sp ace' t -maxdepth 0 -ls; find t -name 'n*' -fls out; cat out");

    expect($result->stdout)->toBe(
        $ino('t/big').'      2 -rw-r--r--   1 user     user         2000 Sep 13  2020 t/big'."\n"
        .$ino('t/d/f').'      1 -rw-r--r--   2 user     user            3 Jan  2  2020 t/d/f'."\n"
        .$ino('t/fl').'      1 lrwxrwxrwx   1 user     user            3 '.$when('t/fl').' t/fl -> d/f'."\n"
        .$ino('t/sp ace').'      1 -rw-r--r--   1 user     user            2 '.$when('t/sp ace', '  Y').' t/sp\\ ace'."\n"
        .$ino('t').'      0 drwxr-xr-x   4 user     user            0 '.$when('t').' t'."\n"
        .$ino('t/'.$odd).'      0 -rw-r--r--   1 user     user            0 Jul 14  2017 t/n\\ \\\\\\"q\\nr\\b\\r\\t\\f\\001\\303\\251'."\n",
    )->and($result->stderr)->toBe('');
});

test('-ls widens the owner column for a long name', function (): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'someone-long']));
    $bash->writeFile('/home/user/a', 'x');
    $bash->getFilesystem()->utimes('/home/user/a', 1500000000);
    $ino = str_pad((string) $bash->getFilesystem()->lstat('/home/user/a')->ino, 9, ' ', STR_PAD_LEFT);

    expect($bash->exec('find a -ls')->stdout)->toBe($ino."      1 -rw-r--r--   1 someone-long someone-long        1 Jul 14  2017 a\n");
});

test('-inum matches the inode number', function (): void {
    $ino = $this->fs->lstat('/home/user/t/d/f')->ino;

    expect($this->bash->exec(sprintf("find t -inum %s -printf '%%p %%i\\n'; find t -inum -%s -name f", $ino, $ino))->stdout)->toBe("t/d/f {$ino}\nt/hard {$ino}\n");
});

test('the sandbox user is root without $USER', function (): void {
    expect($this->bash->exec("unset USER; find t/big -user root -uid 0 -printf '%u %G\\n'")->stdout)->toBe("root 0\n");
});

/*
 * -daystart measures days from the start of today, and minutes from the start of tomorrow, for the tests after it.
 * GNU 4.10 on files at midnight-1s (a), midnight+1s (b), midnight-86399s (c) and midnight-86401s (d).
 */
test('-daystart', function (string $expression, string $stdout): void {
    $midnight = strtotime('today');

    // c is yesterday yet always over 24 hours old, so no expectation depends on the time of day
    foreach (['a' => -1, 'b' => 1, 'c' => -86399, 'd' => -86401] as $name => $offset) {
        $this->bash->writeFile('/home/user/ds/'.$name, '');
        $this->fs->utimes('/home/user/ds/'.$name, $midnight + $offset);
    }

    expect($this->bash->exec('find ds -type f '.$expression)->stdout)->toBe($stdout);
})->with([
    ['-mtime 0', "ds/a\nds/b\n"],
    ['-daystart -mtime 0', "ds/b\n"],
    ['-daystart -mtime 1', "ds/a\nds/c\n"],
    ['-daystart -mtime -1', "ds/b\n"],
    ['-daystart -mtime +0', "ds/a\nds/c\nds/d\n"],
    ['-mtime 0 -daystart', "ds/a\nds/b\n"],
    ['-daystart -mmin -1440', "ds/b\n"],
    ['-daystart -mmin +1440', "ds/a\nds/c\nds/d\n"],
    ['-daystart -daystart -mtime 1', "ds/a\nds/c\n"],
    ['-daystart -mtime -2', "ds/a\nds/b\nds/c\n"],
    ['-mmin -1440 -daystart -mtime 1', "ds/a\n"],
])->skip(fn (): bool => time() - strtotime('today') < 2, 'b would be in the future');
