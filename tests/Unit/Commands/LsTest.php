<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\ReadWriteFs;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "hi\n");
    $this->bash->writeFile('/home/user/d/b.txt', "x\n");
    $this->bash->writeFile('/home/user/d/.hid', "y\n");
    $this->bash->writeFile('/home/user/d/sub/c.txt', "z\n");
    $this->bash->exec('mkdir /home/user/e');
});

test('ls lists one name per line, as GNU ls does for non-terminal output', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe('')
        ->and($result->exitCode)->toBe(0);
})->with([
    'current directory' => ['ls', "a.txt\nd\ne\n"],
    '-1 is accepted' => ['ls -1 d', "b.txt\nsub\n"],
    '-a adds . and ..' => ['ls -a d', ".\n..\n.hid\nb.txt\nsub\n"],
    '-A shows dotfiles only' => ['ls -A d', ".hid\nb.txt\nsub\n"],
    'empty directory' => ['ls e', ''],
    'recursive' => ['ls -R', ".:\na.txt\nd\ne\n\n./d:\nb.txt\nsub\n\n./d/sub:\nc.txt\n\n./e:\n"],
    'recursive with -a skips . and ..' => ['ls -Ra e', "e:\n.\n..\n"],
    'recursive with trailing slash' => ['ls -R d/', "d/:\nb.txt\nsub\n\nd/sub:\nc.txt\n"],
    'operands are sorted, files first' => ['ls e d a.txt', "a.txt\n\nd:\nb.txt\nsub\n\ne:\n"],
    'file operands keep their path' => ['ls /home/user/a.txt', "/home/user/a.txt\n"],
    '-d lists directories themselves' => ['ls -d d a.txt', "a.txt\nd\n"],
    '-d disables -R' => ['ls -dR d', "d\n"],
]);

test('ls reports missing operands with exit code 2', function (): void {
    $result = $this->bash->exec('ls nope d');

    expect($result->stdout)->toBe("d:\nb.txt\nsub\n")
        ->and($result->stderr)->toBe("ls: cannot access 'nope': No such file or directory\n")
        ->and($result->exitCode)->toBe(2);
});

test('ls -l shows mode, size, date and symlink targets', function (): void {
    $fs = $this->bash->getFilesystem();
    $fs->utimes('/home/user/a.txt', 1577934240); // 2020-01-02 03:04 UTC, older than six months
    $fs->writeFile('/home/user/f/big', str_repeat('x', 1500));
    $fs->chmod('/home/user/f/big', 0751);
    $fs->utimes('/home/user/f/big', 1577934240);
    $fs->symlink('big', '/home/user/f/link');

    $linkTime = $fs->lstat('/home/user/f/link')->mtime;
    $recent = date('M', $linkTime).sprintf(' %2d ', (int) date('j', $linkTime)).date('H:i', $linkTime);

    $result = $this->bash->exec('ls -l a.txt f');

    // total counts 1K blocks: 2 for the 1500-byte file, none for the link (GNU on Linux keeps short targets in the inode)
    expect($result->stdout)->toBe(
        "-rw-r--r-- 1 user user 3 Jan  2  2020 a.txt\n\n"
        ."f:\ntotal 2\n"
        ."-rwxr-x--x 1 user user 1500 Jan  2  2020 big\n"
        ."lrwxrwxrwx 1 user user    3 {$recent} link -> big\n"
    )->and($result->exitCode)->toBe(0);
});

test('ls -ld describes the directory itself', function (): void {
    $this->bash->getFilesystem()->utimes('/home/user/e', 1577934240);

    expect($this->bash->exec('ls -ld e')->stdout)->toBe("drwxr-xr-x 2 user user 0 Jan  2  2020 e\n");
});

// As GNU ls on Linux: link counts (2 + subdirectories for a directory), set-id and sticky bits, symlinks as lrwxrwxrwx,
// and the sandbox user ($USER, as whoami reports it) owning everything
test('ls -l shows link counts, special bits and the owner', function (): void {
    $bash = new Bash(new BashOptions(cwd: '/w', env: ['USER' => 'alice']));
    $bash->exec('echo x > f; ln f h; ln -s f l; mkdir -p d/a d/b e; chmod 4755 f; chmod 1777 e; chmod 2750 d');

    foreach (['/w/d', '/w/d/a', '/w/d/b', '/w/e', '/w/f'] as $path) {
        $bash->getFilesystem()->utimes($path, 1577934240);
    }

    expect($bash->exec('ls -l d e f h')->stdout)->toBe(<<<'OUT'
        -rwsr-xr-x 2 alice alice 2 Jan  2  2020 f
        -rwsr-xr-x 2 alice alice 2 Jan  2  2020 h

        d:
        total 0
        drwxr-xr-x 2 alice alice 0 Jan  2  2020 a
        drwxr-xr-x 2 alice alice 0 Jan  2  2020 b

        e:
        total 0

        OUT)
        // utimes follows the link, so the symlink keeps its creation time
        ->and($bash->exec('ls -ld d e l')->stdout)->toMatch(
            '/^drwxr-s--- 4 alice alice 0 Jan  2  2020 d\ndrwxrwxrwt 2 alice alice 0 Jan  2  2020 e\nlrwxrwxrwx 1 alice alice 1 \w{3} [ \d]\d \d\d:\d\d l -> f\n$/',
        );
});

test('ls -l of an empty directory prints total 0', function (): void {
    expect($this->bash->exec('ls -l e')->stdout)->toBe("total 0\n");
});

test('ls -lL still lists a dangling symlink in a directory', function (): void {
    $result = (new Bash)->exec('mkdir e; ln -s nope e/x; ls -lL e');

    expect([$result->stdout, $result->stderr, $result->exitCode])
        ->toBe(["total 0\nl????????? ? ? ? ?            ? x\n", "ls: cannot access 'e/x': No such file or directory\n", 1]);
});

test('ls reports unreadable directories', function (string $script, string $expected, string $stderr, int $exitCode): void {
    $root = sys_get_temp_dir().'/bashbox-ls-'.uniqid();
    mkdir($root.'/locked', 0777, true);
    chmod($root.'/locked', 0);

    try {
        $result = new Bash(new BashOptions(fs: new ReadWriteFs($root), cwd: '/'))->exec($script);
    } finally {
        chmod($root.'/locked', 0755);
        rmdir($root.'/locked');
        @rmdir($root.'/tmp'); // created by Bash on startup
        rmdir($root);
    }

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'operand is serious trouble' => ['ls locked', '', "ls: cannot open directory 'locked': Permission denied\n", 2],
    'subdirectory is a minor problem' => ['ls -R', ".:\nlocked\ntmp\n\n./tmp:\n", "ls: cannot open directory './locked': Permission denied\n", 1],
])->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can read any directory');
