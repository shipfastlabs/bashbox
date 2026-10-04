<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('stat', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['USER' => 'user']));
    $bash->writeFile('/home/user/f', "hello\n");
    $bash->writeFile('/home/user/g/x', '');
    $bash->writeFile('/home/user/e', '');
    $bash->writeFile("/home/user/it's", 'q');
    $bash->getFilesystem()->utimes('/home/user/f', 1704164645);
    $bash->getFilesystem()->utimes('/home/user/g', 1704164000);
    $bash->exec('mkdir d; ln -s f l; ln -s nope dangling; chmod 640 f');

    $bashExecResult = $bash->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'model: name, size and type (directories have size 0)' => ["stat -c '%n %s %F' f e d l", "f 6 regular file\ne 0 regular empty file\nd 0 directory\nl 1 symbolic link\n"],
    'permissions' => ["stat -c '%a %A %#a' f d", "640 -rw-r----- 0640\n755 drwxr-xr-x 0755\n"],
    'quoted name' => ["stat -c '%N' f l \"it's\"", "'f'\n'l' -> 'f'\n\"it's\"\n"],
    'printf escapes' => ["stat --printf '%n\\t%s\\n' f", "f\t6\n"],
    'printf octal escape' => ["stat --printf '\\101%n\\n' f", "Af\n"],
    'format adds newline' => ['stat -c %s f e', "6\n0\n"],
    'trailing percent' => ["stat -c 'x%' f", "x%\n"],
    'percent percent' => ["stat -c '%%%n' f", "%f\n"],
    'unknown directive' => ["stat -c '%q' f", "?\n"],
    'width and flags' => ["stat -c '[%10s][%-10s][%010s][%-010s]' f", "[         6][6         ][0000000006][6         ]\n"],
    'precision truncates' => ["stat -c '[%.2n][%5.1n]' f", "[f][    f]\n"],
    'mtime' => ["stat -c '%y|%Y' f", "2024-01-02 03:04:05.000000000 +0000|1704164645\n"],
    'mtime precision' => ["stat -c '%.3Y|%.Y|%.0Y|%10.2Y' f", "1704164645.000|1704164645.000000000|1704164645|1704164645.00\n"],
    'dereference' => ["stat -L -c '%n %F %s' l", "l regular file 6\n"],
    'dereference dangling' => ["stat -L -c '%n' dangling", '', "stat: cannot stat 'dangling': No such file or directory\n", 1],
    'missing' => ['stat nope f -c %n', "f\n", "stat: cannot stat 'nope': No such file or directory\n", 1],
    'missing operand' => ['stat', '', "stat: missing operand\nTry 'stat --help' for more information.\n", 1],
    'bad option' => ['stat -q f', '', "stat: invalid option -- 'q'\nTry 'stat --help' for more information.\n", 1],
    'printf replaces format' => ["stat -c '%n' --printf '%s\\n' f", "6\n"],
    'format replaces printf' => ["stat --printf '%s' -c '%n' f", "f\n"],
    'link count' => ["stat -c '%h' f", "1\n"],
    'model: symlink size, raw mode and blocks (Linux symlinks are mode 777)' => ["stat -c '%s %F %f %b' l dangling", "1 symbolic link a1ff 0\n4 symbolic link a1ff 0\n"],
    'precision' => ["stat -c '[%.3s|%.1n|%.3a|%5.3s|%-5.3s|%05.3s|%.0s|%.s|%+s|% s|%.12Y|%.3h|%.3A|%.5a|%#.5a]' f", "[006|f|640|  006|006  |  006|6|6|6|6|1704164645.000000000000|001|-rw|00640|00640]\n"],
    'name needing double quotes' => ["printf x > \"a'b c\"; stat -c %N \"a'b c\"", "\"a'b c\"\n"],
    'name needing escapes' => ["printf x > \$'a\\nb'; stat -c %N \$'a\\nb'", "'a'\$'\\n''b'\n"],
    'model: default format' => ['stat f g', "  File: f\n  Size: 6         \tBlocks: 2          IO Block: 4096   regular file\nDevice: 0,0\tInode: 5           Links: 1\nAccess: (0640/-rw-r-----)  Uid: ( 1000/    user)   Gid: ( 1000/    user)\nAccess: 2024-01-02 03:04:05.000000000 +0000\nModify: 2024-01-02 03:04:05.000000000 +0000\nChange: 2024-01-02 03:04:05.000000000 +0000\n Birth: -\n  File: g\n  Size: 0         \tBlocks: 0          IO Block: 4096   directory\nDevice: 0,0\tInode: 6           Links: 2\nAccess: (0755/drwxr-xr-x)  Uid: ( 1000/    user)   Gid: ( 1000/    user)\nAccess: 2024-01-02 02:53:20.000000000 +0000\nModify: 2024-01-02 02:53:20.000000000 +0000\nChange: 2024-01-02 02:53:20.000000000 +0000\n Birth: -\n"],
    'model: terse' => ['stat -t f g', "f 6 2 81a0 1000 1000 0 5 1 0 0 1704164645 1704164645 1704164645 0 4096\ng 0 0 41ed 1000 1000 0 6 2 0 0 1704164000 1704164000 1704164000 0 4096\n"],
    'model: every directive' => ["stat -c '%a|%A|%b|%B|%d|%D|%f|%F|%g|%G|%h|%i|%n|%N|%o|%s|%t|%T|%u|%U|%w|%W|%x|%X|%y|%Y|%z|%Z' f g", "640|-rw-r-----|2|512|0|0|81a0|regular file|1000|user|1|5|f|'f'|4096|6|0|0|1000|user|-|0|2024-01-02 03:04:05.000000000 +0000|1704164645|2024-01-02 03:04:05.000000000 +0000|1704164645|2024-01-02 03:04:05.000000000 +0000|1704164645\n755|drwxr-xr-x|0|512|0|0|41ed|directory|1000|user|2|6|g|'g'|4096|0|0|0|1000|user|-|0|2024-01-02 02:53:20.000000000 +0000|1704164000|2024-01-02 02:53:20.000000000 +0000|1704164000|2024-01-02 02:53:20.000000000 +0000|1704164000\n"],
    'model: root owns files as uid 0' => ["USER=root stat -c '%u %g %U %G' f", "0 0 root root\n"],
    'empty format' => ["stat -c '' f", "\n"],
]);
