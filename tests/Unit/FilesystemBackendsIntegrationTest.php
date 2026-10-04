<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\InMemoryFs;
use BashBox\Filesystem\MountableFs;
use BashBox\Filesystem\OverlayFs;
use BashBox\Filesystem\ReadWriteFs;
use BashBox\Limits;

beforeEach(function (): void {
    $this->tmpDirs = [];
});

afterEach(function (): void {
    foreach ($this->tmpDirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iter as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
});

function makeTempDir(object $test, string $prefix): string
{
    $dir = sys_get_temp_dir().'/'.$prefix.'_'.uniqid();
    mkdir($dir, 0755, true);
    $test->tmpDirs[] = $dir;

    return $dir;
}

test('bash with OverlayFs reads disk files and keeps shell writes off disk', function (): void {
    $root = makeTempDir($this, 'bash_overlay_fs');
    file_put_contents($root.'/seed.txt', 'from disk');

    $bash = new Bash(new BashOptions(
        fs: new OverlayFs($root),
    ));

    $bashExecResult = $bash->exec('cat /seed.txt && echo overlay > /new.txt && cat /new.txt');

    expect($bashExecResult->stdout)->toBe("from diskoverlay\n");
    expect(file_exists($root.'/new.txt'))->toBeFalse();
    expect($bash->readFile('/new.txt'))->toBe("overlay\n");
});

test('bash with ReadWriteFs persists shell writes to disk', function (): void {
    $root = makeTempDir($this, 'bash_read_write_fs');

    $bash = new Bash(new BashOptions(
        fs: new ReadWriteFs($root),
    ));

    $bashExecResult = $bash->exec('echo first > notes.txt; echo second >> notes.txt; cat notes.txt');

    expect($bashExecResult->stdout)->toBe("first\nsecond\n");
    expect(file_get_contents($root.'/home/user/notes.txt'))->toBe("first\nsecond\n");
    expect(is_dir($root.'/tmp'))->toBeTrue();
});

test('bash with MountableFs routes commands to mounted backend and supports cross mount copy', function (): void {
    $root = makeTempDir($this, 'bash_mountable_fs');
    $defaultFs = new InMemoryFs([
        '/home/user/local.txt' => 'local data',
    ]);
    $mountable = new MountableFs($defaultFs);
    $mountable->mount('/data', new ReadWriteFs($root));

    $bash = new Bash(new BashOptions(fs: $mountable));

    $bashExecResult = $bash->exec('cp /home/user/local.txt /data/copied.txt; echo mounted >> /data/copied.txt; cp /data/copied.txt /home/user/roundtrip.txt; cat /home/user/roundtrip.txt');

    expect($bashExecResult->stdout)->toBe("local datamounted\n");
    expect(file_get_contents($root.'/copied.txt'))->toBe("local datamounted\n");
    expect($bash->readFile('/home/user/roundtrip.txt'))->toBe("local datamounted\n");
});

/** @return array<string, Closure(object): BashBox\Filesystem\FileSystemInterface> */
function everyBackend(): array
{
    return [
        'InMemoryFs' => fn (object $test): InMemoryFs => new InMemoryFs,
        'OverlayFs' => fn (object $test): OverlayFs => new OverlayFs(makeTempDir($test, 'overlay'), denySymlinks: false),
        'ReadWriteFs' => fn (object $test): ReadWriteFs => new ReadWriteFs(makeTempDir($test, 'read_write')),
        'MountableFs' => fn (object $test): MountableFs => new MountableFs(new InMemoryFs),
    ];
}

test('/dev stays in memory: /dev/null, links to it and process substitution leave every backend untouched', function (Closure $makeFs): void {
    $fs = $makeFs($this);
    $bash = new Bash(new BashOptions(fs: $fs));

    $bashExecResult = $bash->exec(<<<'SH'
        echo a > f
        cat /dev/null; echo "cat=$?"
        echo hi > /dev/null
        echo x | tee /dev/null
        echo y | sed 'w /dev/null'
        source /dev/null; echo "source=$?"
        cp f /dev/null; echo "cp=$?"
        cat /dev/null/x
        cat f/x
        cd f/x
        ln -s /dev/null n; echo x > n; echo y >> n; cat n; echo "n=$?"
        cat <(echo ps) <(echo two); ls /dev/fd
        SH);

    expect($bashExecResult->stdout)->toBe("cat=0\nx\ny\nsource=0\ncp=0\nn=0\nps\ntwo\n")
        ->and($bashExecResult->stderr)->toBe("cat: /dev/null/x: Not a directory\ncat: f/x: Not a directory\nbash: cd: f/x: Not a directory\n")
        ->and($fs->exists('/dev'))->toBeFalse();
})->with(everyBackend());

test('a regular file in the middle of a path is ENOTDIR on every backend', function (Closure $makeFs): void {
    $fs = $makeFs($this);
    $fs->writeFile('/dir/file', 'data');
    $fs->symlink('/dir/file', '/link');

    foreach (['/dir/file/x', '/dir/file/x/y', '/link/x'] as $path) {
        expect(fn (): string => $fs->readFile($path))->toThrow(RuntimeException::class, 'ENOTDIR');
    }

    expect(fn (): bool => $fs->exists('/dir/file/x'))->not->toThrow(RuntimeException::class)
        ->and($fs->exists('/dir/file/x'))->toBeFalse()
        ->and(fn () => $fs->stat('/dir/file/x'))->toThrow(RuntimeException::class, 'ENOTDIR')
        ->and(fn () => $fs->writeFile('/dir/file/x', ''))->toThrow(RuntimeException::class, 'ENOTDIR')
        ->and(fn () => $fs->mkdir('/dir/file/x', ['recursive' => true]))->toThrow(RuntimeException::class, 'ENOTDIR')
        ->and(fn () => $fs->readFile('/dir/missing/x'))->toThrow(RuntimeException::class, 'ENOENT');
})->with(everyBackend());

test('a file on disk in the middle of a path is ENOTDIR through OverlayFs', function (): void {
    $root = makeTempDir($this, 'overlay_enotdir');
    file_put_contents($root.'/file', 'data');

    expect(fn (): string => new OverlayFs($root)->readFile('/file/x'))->toThrow(RuntimeException::class, "ENOTDIR: not a directory, open '/file/x'");
});

// A full disk can't be made in real bash, so these use its ENOSPC messages: `<builtin>: write error: ...` on a write, `<file>: ...` on opening a redirection.
test('a full filesystem fails the command the way a full disk does', function (string $script, string $stdout, string $stderr, int $exitCode = 0): void {
    $bashExecResult = new Bash(new BashOptions(limits: new Limits(maxFilesystemBytes: 60, maxFilesystemFiles: 8)))->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'a write past the byte quota' => ['echo 0123456789012345678901234567890123456789012345678901234567890 > f; echo $?; cat f | wc -c', "1\n0\n", "bash: echo: write error: No space left on device\n"],
    'through a read-write fd' => ['echo x > g; exec 3<>g; echo 0123456789012345678901234567890123456789012345678901234567890 >&3; echo $?', "1\n", "bash: echo: write error: No space left on device\n"],
    'a command writing past it' => ['seq 100 > h; echo $?', "1\n", "seq: write error: No space left on device\n"],
    'a file past the entry quota' => ['echo > a; echo $?; echo > b; echo $?; echo > c; echo $?', "0\n0\n1\n", "bash: c: No space left on device\n"],
    'a process substitution with no room for its file' => ["touch a b\ncat <(echo hi); echo same\necho next \$?", "next 1\n", "bash: cannot make pipe for process substitution: No space left on device\n"],
]);

test('Limits sets one quota for the default filesystem and its /dev', function (): void {
    $bashExecResult = new Bash(new BashOptions(limits: new Limits(maxFilesystemFiles: 8)))->exec('cat <(echo dev); echo > f; echo $?; echo > g; echo $?');

    expect([$bashExecResult->stdout, $bashExecResult->stderr])->toBe(["dev\n0\n1\n", "bash: g: No space left on device\n"]);
});

test('a backend that refuses an operation fails it like bash', function (string $script, string $stdout, string $stderr, int $exitCode = 0): void {
    $root = makeTempDir($this, 'refusing');
    mkdir($root.'/ro');
    file_put_contents($root.'/ro/f', 'x');
    file_put_contents($root.'/s', "echo run\n");
    chmod($root.'/s', 0311);
    chmod($root.'/ro', 0555);
    symlink($root.'/ro/f', $root.'/link');

    try {
        $bashExecResult = new Bash(new BashOptions(fs: new ReadWriteFs($root, allowSymlinks: false), cwd: '/'))->exec($script);
    } finally {
        chmod($root.'/ro', 0755);
    }

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'a file that may not be created' => ['echo x > ro/new; echo $?', "1\n", "bash: ro/new: Permission denied\n"],
    'a symlink the backend denies' => ['cat < link; echo $?; echo y >> link; echo $?', "1\n1\n", "bash: link: Operation not permitted\nbash: link: Operation not permitted\n"],
    'a script that may not be read' => ['./s; echo $?', "126\n", "bash: ./s: Permission denied\n"],
]);
