<?php

declare(strict_types=1);

use BashBox\Filesystem\DiskQuota;
use BashBox\Filesystem\ReadWriteFs;

beforeEach(function (): void {
    $this->tmpDir = sys_get_temp_dir().'/read_write_fs_test_'.uniqid();
    $this->outside = $this->tmpDir.'_outside';
    mkdir($this->tmpDir, 0755, true);
    mkdir($this->outside, 0755, true);
    file_put_contents($this->outside.'/secret', 'host secret');
    $this->fs = new ReadWriteFs($this->tmpDir);
});

afterEach(function (): void {
    $remove = function (string $dir) use (&$remove): void {
        chmod($dir, 0755);

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            $path = $dir.'/'.$name;
            is_dir($path) && ! is_link($path) ? $remove($path) : unlink($path);
        }

        rmdir($dir);
    };

    $remove($this->tmpDir);
    $remove($this->outside);
});

$runsAsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

test('constructor rejects a root that is not an existing directory', function (string $root): void {
    new ReadWriteFs($root);
})->with(['/definitely/does/not/exist', __FILE__])->throws(RuntimeException::class, 'root directory does not exist');

test('writeFile persists content to disk and readFile returns it', function (): void {
    $this->fs->writeFile('/docs/note.txt', 'hello');

    expect($this->fs->readFile('/docs/note.txt'))->toBe('hello');
    expect(file_get_contents($this->tmpDir.'/docs/note.txt'))->toBe('hello');
});

test('appendFile appends to existing file and creates missing ones', function (): void {
    file_put_contents($this->tmpDir.'/log.txt', 'line1');

    $this->fs->appendFile('/log.txt', "\nline2");
    $this->fs->appendFile('/new.txt', 'fresh');

    expect($this->fs->readFile('/log.txt'))->toBe("line1\nline2");
    expect($this->fs->readFile('/new.txt'))->toBe('fresh');
});

test('mkdir creates directories and readdir returns sorted entries', function (): void {
    $this->fs->mkdir('/data/nested', ['recursive' => true]);
    $this->fs->mkdir('/data/nested', ['recursive' => true]);
    $this->fs->writeFile('/data/b.txt', 'b');
    $this->fs->writeFile('/data/a.txt', 'a');

    expect($this->fs->readdir('/data'))->toBe(['a.txt', 'b.txt', 'nested']);
});

test('readdirWithFileTypes reports files, directories and symlinks', function (): void {
    $this->fs->mkdir('/data/subdir', ['recursive' => true]);
    $this->fs->writeFile('/data/file.txt', 'data');
    $this->fs->symlink('file.txt', '/data/link');

    $types = array_map(
        fn ($entry): array => [$entry->name, $entry->isFile, $entry->isDirectory, $entry->isSymbolicLink],
        $this->fs->readdirWithFileTypes('/data'),
    );

    expect($types)->toBe([
        ['file.txt', true, false, false],
        ['link', false, false, true],
        ['subdir', false, true, false],
    ]);
});

test('cp copies files and mv relocates them', function (): void {
    $this->fs->writeFile('/source.txt', 'copy me');

    $this->fs->cp('/source.txt', '/copies/destination.txt');
    $this->fs->mv('/copies/destination.txt', '/moved/final.txt');

    expect($this->fs->readFile('/source.txt'))->toBe('copy me');
    expect($this->fs->readFile('/moved/final.txt'))->toBe('copy me');
    expect($this->fs->exists('/copies/destination.txt'))->toBeFalse();
});

test('cp recursive copies a tree and preserve keeps mode and mtime', function (): void {
    $this->fs->writeFile('/src/run.sh', 'echo hi');
    $this->fs->writeFile('/src/deep/data.txt', 'data');
    $this->fs->chmod('/src/run.sh', 0700);
    $this->fs->utimes('/src/run.sh', 1_000_000);

    $this->fs->cp('/src', '/dst', ['recursive' => true, 'preserve' => true]);

    expect($this->fs->readFile('/dst/deep/data.txt'))->toBe('data');
    expect($this->fs->stat('/dst/run.sh')->mode)->toBe(0700);
    expect($this->fs->stat('/dst/run.sh')->mtime)->toBe(1_000_000);
});

test('rm recursive removes directories', function (): void {
    $this->fs->writeFile('/tree/branch/leaf.txt', 'gone');

    $this->fs->rm('/tree', ['recursive' => true]);
    $this->fs->rm('/tree', ['force' => true]);

    expect($this->fs->exists('/tree'))->toBeFalse();
});

test('rm removes a symlink without touching its target', function (): void {
    $this->fs->writeFile('/dir/keep.txt', 'kept');
    $this->fs->symlink('/dir', '/dirlink');
    $this->fs->symlink('/missing', '/dangling');

    $this->fs->rm('/dirlink', ['recursive' => true]);
    $this->fs->rm('/dangling');

    expect(is_link($this->tmpDir.'/dirlink'))->toBeFalse();
    expect(is_link($this->tmpDir.'/dangling'))->toBeFalse();
    expect($this->fs->readFile('/dir/keep.txt'))->toBe('kept');
});

test('stat and chmod reflect file metadata', function (): void {
    $this->fs->writeFile('/script.sh', '#!/bin/bash');
    $this->fs->chmod('/script.sh', 0755);

    $stat = $this->fs->stat('/script.sh');

    expect($stat->isFile)->toBeTrue();
    expect($stat->mode)->toBe(0755);
    expect($stat->size)->toBe(strlen('#!/bin/bash'));
});

test('symlink readlink and realpath work inside the root', function (): void {
    $this->fs->writeFile('/target.txt', 'data');
    $this->fs->symlink('/target.txt', '/link.txt');

    $lstat = $this->fs->lstat('/link.txt');

    expect($lstat->isSymbolicLink)->toBeTrue();
    expect($lstat->mode)->toBe(0777);
    expect($this->fs->stat('/link.txt')->isFile)->toBeTrue();
    expect($this->fs->readlink('/link.txt'))->toBe('/target.txt');
    expect($this->fs->realpath('/link.txt'))->toBe('/target.txt');
    expect($this->fs->realpath('/'))->toBe('/');
});

test('symlinks are resolved physically and may wander anywhere inside the root', function (): void {
    $this->fs->writeFile('/a/b/file.txt', 'deep');
    $this->fs->symlink('/a/b', '/short');
    $this->fs->symlink('../a/b/file.txt', '/a/up');
    $this->fs->symlink('/', '/root');
    $this->fs->symlink('short', '/chain');

    expect($this->fs->readFile('/a/up'))->toBe('deep');
    expect($this->fs->readlink('/a/up'))->toBe('../a/b/file.txt');
    expect($this->fs->readFile('/root/a/b/file.txt'))->toBe('deep');
    expect($this->fs->readlink('/root'))->toBe('/');
    expect($this->fs->readFile('/chain/file.txt'))->toBe('deep');
    // Inside the target, ".." walks up from /a/b, not from the link's own directory
    $this->fs->symlink('/short/..', '/parent');
    expect($this->fs->readdir('/parent'))->toBe(['b', 'up']);
    // "." and empty segments in a target are no-ops
    $this->fs->symlink('./b/.//file.txt', '/a/dotted');
    expect($this->fs->readFile('/a/dotted'))->toBe('deep');
});

test('lookups that escape the root through symlinks are denied', function (string $method, array $args): void {
    symlink($this->outside, $this->tmpDir.'/escape');
    symlink('../'.basename($this->outside).'/secret', $this->tmpDir.'/relative');
    symlink($this->outside.'/secret', $this->tmpDir.'/absolute');
    symlink('absolute', $this->tmpDir.'/chain');
    $this->fs->symlink('../../../../../../../../etc/passwd', '/made-in-sandbox');

    expect(fn () => $this->fs->{$method}(...$args))->toThrow(RuntimeException::class, 'path traversal denied');
    expect(file_exists($this->outside.'/pwned'))->toBeFalse();
    expect(file_get_contents($this->outside.'/secret'))->toBe('host secret');
})->with([
    'read through dir link' => ['readFile', ['/escape/secret']],
    'relative .. link' => ['readFile', ['/relative']],
    'absolute link' => ['readFile', ['/absolute']],
    'link chain' => ['readFile', ['/chain']],
    'link made by the sandbox' => ['readFile', ['/made-in-sandbox']],
    'write through dir link' => ['writeFile', ['/escape/pwned', 'x']],
    'append through link' => ['appendFile', ['/absolute', 'x']],
    'list through dir link' => ['readdir', ['/escape']],
    'stat' => ['stat', ['/absolute']],
    'realpath' => ['realpath', ['/escape']],
    'chmod' => ['chmod', ['/absolute', 0777]],
    'mkdir through dir link' => ['mkdir', ['/escape/pwned']],
    'rm through dir link' => ['rm', ['/escape/secret']],
    'cp out' => ['cp', ['/absolute', '/copy']],
    'mv in' => ['mv', ['/escape/secret', '/stolen']],
    'hard link' => ['link', ['/absolute', '/hard']],
]);

test('exists hides paths outside the root and symlink loops', function (): void {
    symlink($this->outside, $this->tmpDir.'/escape');
    $this->fs->symlink('/loop-b', '/loop-a');
    $this->fs->symlink('/loop-a', '/loop-b');

    expect($this->fs->exists('/escape/secret'))->toBeFalse();
    expect($this->fs->exists('/loop-a'))->toBeFalse();
    expect(fn () => $this->fs->readFile('/loop-a'))->toThrow(RuntimeException::class, 'ELOOP');
});

test('virtual .. never climbs above the root', function (): void {
    $this->fs->writeFile('/top.txt', 'at top');

    expect($this->fs->readFile('/../../../top.txt'))->toBe('at top');
    expect($this->fs->resolvePath('/a/b', '../../../c'))->toBe('/c');
    expect($this->fs->resolvePath('/home', '/etc/./x/'))->toBe('/etc/x');
});

test('operations fail with the matching errno', function (string $method, array $args, string $error): void {
    $this->fs->writeFile('/file.txt', 'data');
    $this->fs->writeFile('/dir/child.txt', 'child');
    $this->fs->symlink('/file.txt', '/link');

    expect(fn () => $this->fs->{$method}(...$args))->toThrow(RuntimeException::class, $error);
})->with([
    ['readFile', ['/missing'], 'ENOENT'],
    ['readFile', ['/dir'], 'EISDIR'],
    ['readFile', ["/bad\0"], 'null byte'],
    ['writeFile', ['/dir', 'x'], 'EISDIR'],
    ['writeFile', ['/file.txt/x', 'x'], 'ENOTDIR'],
    ['stat', ['/missing'], 'ENOENT'],
    ['lstat', ['/missing'], 'ENOENT'],
    ['mkdir', ['/dir'], 'EEXIST'],
    ['mkdir', ['/file.txt', ['recursive' => true]], 'EEXIST'],
    ['mkdir', ['/no/parent'], 'ENOENT'],
    ['readdir', ['/missing'], 'ENOENT'],
    ['readdir', ['/file.txt'], 'ENOTDIR'],
    ['rm', ['/missing'], 'ENOENT'],
    ['rm', ['/', ['recursive' => true]], 'EPERM'],
    ['rm', ['/dir'], 'ENOTEMPTY'],
    ['cp', ['/dir', '/copy'], 'EISDIR'],
    ['cp', ['/dir', '/dir/inner', ['recursive' => true]], 'EINVAL'],
    ['mv', ['/missing', '/x'], 'ENOENT'],
    ['chmod', ['/missing', 0644], 'ENOENT'],
    ['utimes', ['/missing', 0], 'ENOENT'],
    ['realpath', ['/missing'], 'ENOENT'],
    ['symlink', ['/x', '/file.txt'], 'EEXIST'],
    ['link', ['/dir', '/hard'], 'EPERM'],
    ['link', ['/missing', '/hard'], 'ENOENT'],
    ['link', ['/file.txt', '/link'], 'EEXIST'],
    ['readlink', ['/missing'], 'ENOENT'],
    ['readlink', ['/file.txt'], 'EINVAL'],
]);

test('operations the host refuses fail with EACCES', function (string $method, array $args): void {
    file_put_contents($this->tmpDir.'/secret', 'x');
    chmod($this->tmpDir.'/secret', 0);
    mkdir($this->tmpDir.'/locked');
    chmod($this->tmpDir.'/locked', 0);
    mkdir($this->tmpDir.'/ro/sub', 0755, true);
    file_put_contents($this->tmpDir.'/ro/file', 'x');
    chmod($this->tmpDir.'/ro', 0555);

    expect(fn () => $this->fs->{$method}(...$args))->toThrow(RuntimeException::class, 'EACCES');
})->with([
    ['readFile', ['/secret']],
    ['readdir', ['/locked']],
    ['writeFile', ['/ro/new', 'x']],
    ['mkdir', ['/ro/new']],
    ['rm', ['/ro/file']],
    ['rm', ['/ro/sub']],
    ['mv', ['/ro/file', '/moved']],
    ['symlink', ['/x', '/ro/link']],
    ['link', ['/ro/file', '/ro/hard']],
    ['createExclusive', ['/ro/new']],
    ['createExclusive', ['/ro/new', true]],
])->skip($runsAsRoot, 'root ignores file permissions');

test('getAllPaths lists the tree without descending into symlinks', function (): void {
    $this->fs->writeFile('/a/b.txt', 'b');
    $this->fs->symlink('/a', '/link');

    expect($this->fs->getAllPaths())->toBe(['/', '/a', '/a/b.txt', '/link']);
});

test('hard links share file content', function (): void {
    $this->fs->writeFile('/source.txt', 'shared');

    $this->fs->link('/source.txt', '/linked.txt');
    file_put_contents($this->tmpDir.'/source.txt', 'changed');

    expect($this->fs->readFile('/linked.txt'))->toBe('changed');
});

test('stat reports the host inode and link count', function (): void {
    $this->fs->writeFile('/source.txt', 'shared');
    $this->fs->link('/source.txt', '/linked.txt');

    expect($this->fs->stat('/linked.txt'))->toMatchObject(['ino' => fileinode($this->tmpDir.'/source.txt'), 'nlink' => 2]);
});

test('createExclusive makes a private file or directory whatever the umask', function (bool $directory, int $mode): void {
    $umask = umask(0);

    try {
        $this->fs->createExclusive('/new', $directory);
    } finally {
        umask($umask);
    }

    clearstatcache();
    expect(is_dir($this->tmpDir.'/new'))->toBe($directory)
        ->and(fileperms($this->tmpDir.'/new') & 0777)->toBe($mode)
        ->and($this->fs->stat('/new')->nlink)->toBe($directory ? 2 : 1)
        ->and(scandir($this->tmpDir))->toBe(['.', '..', 'new'])
        ->and(umask())->toBe($umask);
})->with([
    'file' => [false, 0600],
    'directory' => [true, 0700],
]);

test('createExclusive never reuses, truncates or follows an existing entry', function (string $path, bool $directory, string $message): void {
    file_put_contents($this->tmpDir.'/file', 'keep');
    symlink($this->outside.'/planted', $this->tmpDir.'/dangling');
    mkdir($this->tmpDir.'/dir');

    expect(fn () => $this->fs->createExclusive($path, $directory))->toThrow(RuntimeException::class, $message);
    expect(file_get_contents($this->tmpDir.'/file'))->toBe('keep')
        ->and(file_exists($this->outside.'/planted'))->toBeFalse()
        ->and(scandir($this->tmpDir))->toBe(['.', '..', 'dangling', 'dir', 'file']);
})->with([
    'existing file' => ['/file', false, "EEXIST: file already exists, open '/file'"],
    'existing directory' => ['/dir', true, "EEXIST: file already exists, mkdir '/dir'"],
    'dangling symlink pointing outside the root' => ['/dangling', false, "EEXIST: file already exists, open '/dangling'"],
    'missing parent' => ['/missing/new', false, "ENOENT: no such file or directory, open '/missing/new'"],
    'parent is a file' => ['/file/new', true, "ENOTDIR: not a directory, mkdir '/file/new'"],
]);

test('utimes updates modification time', function (): void {
    $this->fs->writeFile('/timestamp.txt', 'time');
    $mtime = time() - 3600;

    $this->fs->utimes('/timestamp.txt', $mtime);

    expect($this->fs->stat('/timestamp.txt')->mtime)->toBe($mtime);
});

test('null bytes make exists false', function (): void {
    expect($this->fs->exists("/bad\0"))->toBeFalse();
});

test('with symlinks disallowed links are hidden and never followed', function (): void {
    file_put_contents($this->tmpDir.'/target.txt', 'data');
    symlink($this->tmpDir.'/target.txt', $this->tmpDir.'/link.txt');
    mkdir($this->tmpDir.'/dir');
    symlink($this->tmpDir.'/dir', $this->tmpDir.'/dirlink');
    $fs = new ReadWriteFs($this->tmpDir, allowSymlinks: false);

    expect($fs->exists('/link.txt'))->toBeFalse();
    expect($fs->readdir('/'))->toBe(['dir', 'target.txt']);
    expect($fs->getAllPaths())->toBe(['/', '/dir', '/target.txt']);
    expect(fn (): \BashBox\Filesystem\FsStat => $fs->lstat('/link.txt'))->toThrow(RuntimeException::class, 'symlinks are denied');
    expect(fn () => $fs->writeFile('/dirlink/x', 'x'))->toThrow(RuntimeException::class, 'symlinks are denied');
    expect(fn () => $fs->symlink('/target.txt', '/new'))->toThrow(RuntimeException::class, 'symlinks are denied');
});

test('the quota counts the bytes the sandbox adds to the disk', function (): void {
    file_put_contents($this->tmpDir.'/seed', str_repeat('x', 1000));
    $fs = new ReadWriteFs($this->tmpDir, diskQuota: new DiskQuota(maxBytes: 100));
    $fs->writeFile('/a', str_repeat('x', 60));
    $fs->appendFile('/a', str_repeat('x', 40));

    expect(fn () => $fs->appendFile('/a', 'x'))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/a'")
        ->and(fn () => $fs->cp('/seed', '/copy'))->toThrow(RuntimeException::class, 'ENOSPC')
        ->and(filesize($this->tmpDir.'/a'))->toBe(100)
        ->and(file_exists($this->tmpDir.'/copy'))->toBeFalse();

    // Rewriting gives back the old size; removing a file gives back its data, existing files included
    $fs->writeFile('/a', 'x');
    $fs->writeFile('/b', str_repeat('x', 90));
    $fs->rm('/seed');
    $fs->writeFile('/c', str_repeat('x', 500));

    expect(filesize($this->tmpDir.'/c'))->toBe(500);
});

test('a hard link on disk frees no data until its last name is removed', function (): void {
    $fs = new ReadWriteFs($this->tmpDir, diskQuota: new DiskQuota(maxBytes: 100));
    $fs->writeFile('/a', str_repeat('x', 100));
    $fs->link('/a', '/l');
    $fs->rm('/a');

    expect(fn () => $fs->writeFile('/b', 'x'))->toThrow(RuntimeException::class, 'ENOSPC');

    $fs->rm('/l');
    $fs->writeFile('/b', str_repeat('x', 100));

    expect(filesize($this->tmpDir.'/b'))->toBe(100);
});

test('the quota counts the entries the sandbox makes on disk', function (): void {
    $fs = new ReadWriteFs($this->tmpDir, diskQuota: new DiskQuota(maxFiles: 4));
    $fs->mkdir('/d/e', ['recursive' => true]);
    $fs->writeFile('/d/e/f', '');

    expect(fn () => $fs->createExclusive('/d/e/f'))->toThrow(RuntimeException::class, 'EEXIST')
        ->and(fn () => $fs->writeFile('/x/y', ''))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/x/y'")
        ->and(is_dir($this->tmpDir.'/x'))->toBeFalse();

    $fs->createExclusive('/g');

    expect(fn () => $fs->symlink('/g', '/s'))->toThrow(RuntimeException::class, 'ENOSPC')
        ->and(fn () => $fs->link('/g', '/h'))->toThrow(RuntimeException::class, 'ENOSPC')
        ->and(fn () => $fs->mkdir('/m'))->toThrow(RuntimeException::class, 'ENOSPC');

    $fs->rm('/d', ['recursive' => true]);
    $fs->symlink('/g', '/s');
    $fs->link('/g', '/h');
    $fs->mkdir('/m');

    expect($fs->readdir('/'))->toBe(['g', 'h', 'm', 's']);
});
