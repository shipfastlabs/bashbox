<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Filesystem\InMemoryFs;

beforeEach(function (): void {
    $this->fs = new InMemoryFs;
    $this->bash = new Bash(new BashOptions(fs: $this->fs, cwd: '/home/user', env: ['HOME' => '/home/user']));
});

// ===== realpath (expected outputs from GNU coreutils grealpath) =====
test('realpath resolves like GNU', function (string $script, string $stdout, string $stderr, int $exitCode): void {
    $this->fs->mkdir('/home/user/d', ['recursive' => true]);
    $this->fs->symlink('d', '/home/user/link');

    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($stdout)
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'symlink' => ['realpath link', "/home/user/d\n", '', 0],
    'missing last component' => ['realpath nope', "/home/user/nope\n", '', 0],
    '-e requires existence' => ['realpath -e nope', '', "realpath: nope: No such file or directory\n", 1],
    '-e existing' => ['realpath -e d', "/home/user/d\n", '', 0],
    '-m allows missing parents' => ['realpath -m a/b/c', "/home/user/a/b/c\n", '', 0],
    'missing parent' => ['realpath x/y', '', "realpath: x/y: No such file or directory\n", 1],
    '-q silences errors' => ['realpath -q x/y', '', '', 1],
    'keeps going past failures' => ['realpath nope/z d', "/home/user/d\n", "realpath: nope/z: No such file or directory\n", 1],
    'missing operand' => ['realpath', '', "realpath: missing operand\nTry 'realpath --help' for more information.\n", 1],
]);

// ===== mktemp (expected outputs from GNU coreutils gmktemp, LC_ALL=C quoting) =====
test('mktemp creates a private file or directory', function (string $script, string $pattern, bool $directory, int $mode): void {
    $this->bash->exec('mkdir -p /x');
    $result = $this->bash->exec($script);
    $path = rtrim((string) $result->stdout);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toMatch($pattern);

    $stat = $this->fs->stat($path[0] === '/' ? $path : '/home/user/'.$path);
    expect($stat->isDirectory)->toBe($directory)
        ->and($stat->mode & 0777)->toBe($mode);
})->with([
    'default in /tmp' => ['mktemp', '#^/tmp/tmp\.[A-Za-z0-9]{10}\n$#', false, 0600],
    'directory' => ['mktemp -d', '#^/tmp/tmp\.[A-Za-z0-9]{10}\n$#', true, 0700],
    'long directory flag' => ['mktemp --directory -p /x cXXX', '#^/x/c[A-Za-z0-9]{3}\n$#', true, 0700],
    'template relative to cwd' => ['mktemp aXXXX', '#^a[A-Za-z0-9]{4}\n$#', false, 0600],
    '-p dir' => ['mktemp -p /x aXXX', '#^/x/a[A-Za-z0-9]{3}\n$#', false, 0600],
    '--tmpdir=dir' => ['mktemp --tmpdir=/x bXXX', '#^/x/b[A-Za-z0-9]{3}\n$#', false, 0600],
    '--suffix' => ['mktemp --suffix=.txt -p /x aXXX', '#^/x/a[A-Za-z0-9]{3}\.txt\n$#', false, 0600],
    '-t uses TMPDIR' => ['TMPDIR=/x mktemp -t fXXX', '#^/x/f[A-Za-z0-9]{3}\n$#', false, 0600],
    'empty -p falls back to /tmp' => ['mktemp -p "" gXXX', '#^/tmp/g[A-Za-z0-9]{3}\n$#', false, 0600],
]);

test('mktemp dry run prints a name without creating it', function (string $script, string $pattern): void {
    $result = $this->bash->exec($script.'; echo "rc=$?"; ls /x 2>&1 | wc -l');

    expect($result->stdout)->toMatch($pattern);
})->with([
    '-u' => ['mkdir /x; mktemp -u -p /x eXXX', '#^/x/e[A-Za-z0-9]{3}\nrc=0\n0\n$#'],
    '--dry-run into a missing dir' => ['mktemp --dry-run -p /x fooXXX', '#^/x/foo[A-Za-z0-9]{3}\nrc=0\n1\n$#'],
]);

test('mktemp reports errors like GNU', function (string $script, string $stderr): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe('')
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe(1);
})->with([
    'too few Xs' => ['mktemp aXX', "mktemp: too few X's in template 'aXX'\n"],
    '-q does not hide template errors' => ['mktemp -q aXX', "mktemp: too few X's in template 'aXX'\n"],
    '-t template with a slash' => ['mktemp -t a/XXX', "mktemp: invalid template, 'a/XXX', contains directory separator\n"],
    'missing directory' => ['mktemp -p /nonexist fooXXX', "mktemp: failed to create file via template '/nonexist/fooXXX': No such file or directory\n"],
    'missing directory for -d' => ['mktemp -d -p /nonexist fooXXX', "mktemp: failed to create directory via template '/nonexist/fooXXX': No such file or directory\n"],
    'missing directory with suffix' => ['mktemp --suffix=.txt -p /nonexist fooXXX', "mktemp: failed to create file via template '/nonexist/fooXXX.txt': No such file or directory\n"],
    '--quiet hides creation failures' => ['mktemp --quiet -p /nonexist fooXXX', ''],
    'directory is a file' => ['touch /f; mktemp -p /f fooXXX', "mktemp: failed to create file via template '/f/fooXXX': Not a directory\n"],
]);

test('mktemp gives up when every candidate name is taken', function (bool $directory, string $stderr): void {
    $fs = $this->createStub(FileSystemInterface::class);
    $fs->method('createExclusive')->willThrowException(new RuntimeException("EEXIST: file already exists, open '/t/x'"));
    $fs->method('resolvePath')->willReturnCallback(fn (string $base, string $path): string => $path);

    $bashExecResult = new Bash(new BashOptions(fs: $fs))->exec(($directory ? 'mktemp -d' : 'mktemp').' -p /t aXXX');

    expect($bashExecResult->stderr)->toBe($stderr)
        ->and($bashExecResult->exitCode)->toBe(1);
})->with([
    'file' => [false, "mktemp: failed to create file via template '/t/aXXX': File exists\n"],
    'directory' => [true, "mktemp: failed to create directory via template '/t/aXXX': File exists\n"],
]);

test('mktemp retries with a new name when another process takes its name first', function (): void {
    $fs = $this->createStub(FileSystemInterface::class);
    $fs->method('resolvePath')->willReturnCallback(fn (string $base, string $path): string => $path);
    $tried = [];
    $fs->method('createExclusive')->willReturnCallback(function (string $path) use (&$tried): void {
        $tried[] = $path;

        if (count($tried) === 1) {
            throw new RuntimeException(sprintf("EEXIST: file already exists, open '%s'", $path));
        }
    });

    $bashExecResult = new Bash(new BashOptions(fs: $fs))->exec('mktemp -p /t aXXXXXX');

    expect($bashExecResult->exitCode)->toBe(0)
        ->and($tried)->toHaveCount(2)
        ->and($bashExecResult->stdout)->toBe($tried[1]."\n")
        ->and($tried[0])->not->toBe($tried[1]);
});

test('mktemp --dry-run skips names that are taken', function (): void {
    $fs = $this->createStub(FileSystemInterface::class);
    $fs->method('resolvePath')->willReturnCallback(fn (string $base, string $path): string => $path);
    $checked = [];
    $fs->method('exists')->willReturnCallback(function (string $path) use (&$checked): bool {
        if (! str_starts_with($path, '/t/')) {
            return false;
        }

        $checked[] = $path;

        return count($checked) === 1;
    });

    $bashExecResult = new Bash(new BashOptions(fs: $fs))->exec('mktemp -u -p /t aXXXXXX');

    expect($bashExecResult->exitCode)->toBe(0)
        ->and($checked)->toHaveCount(2)
        ->and($bashExecResult->stdout)->toBe($checked[1]."\n");
});
