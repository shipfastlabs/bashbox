<?php

declare(strict_types=1);

use BashBox\Filesystem\DiskQuota;
use BashBox\Filesystem\OverlayFs;

beforeEach(function (): void {
    $this->tmpDir = sys_get_temp_dir().'/overlay_fs_test_'.uniqid();
    $this->outside = $this->tmpDir.'_outside';
    mkdir($this->tmpDir, 0755, true);
    mkdir($this->outside, 0755, true);
    file_put_contents($this->outside.'/secret', 'host secret');
});

afterEach(function (): void {
    $remove = function (string $dir) use (&$remove): void {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            $path = $dir.'/'.$name;
            is_dir($path) && ! is_link($path) ? $remove($path) : unlink($path);
        }

        rmdir($dir);
    };

    $remove($this->tmpDir);
    $remove($this->outside);
});

// ---------------------------------------------------------------
//  Basic COW read/write
// ---------------------------------------------------------------

test('reads file from real filesystem when not in COW layer', function (): void {
    file_put_contents($this->tmpDir.'/hello.txt', 'from disk');

    $fs = new OverlayFs($this->tmpDir);

    expect($fs->readFile('/hello.txt'))->toBe('from disk');
});

test('writeFile stores in COW layer and readFile returns it', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    $fs->writeFile('/new.txt', 'in memory');

    expect($fs->readFile('/new.txt'))->toBe('in memory');
});

test('writeFile overrides real filesystem content (COW)', function (): void {
    file_put_contents($this->tmpDir.'/data.txt', 'original');

    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/data.txt', 'modified');

    expect($fs->readFile('/data.txt'))->toBe('modified');

    // Real file on disk is unchanged
    expect(file_get_contents($this->tmpDir.'/data.txt'))->toBe('original');
});

test('appendFile pulls real content then appends in COW', function (): void {
    file_put_contents($this->tmpDir.'/log.txt', 'line1');

    $fs = new OverlayFs($this->tmpDir);
    $fs->appendFile('/log.txt', "\nline2");

    expect($fs->readFile('/log.txt'))->toBe("line1\nline2");

    // Disk unchanged
    expect(file_get_contents($this->tmpDir.'/log.txt'))->toBe('line1');
});

test('appendFile creates new file if it does not exist', function (): void {
    $fs = new OverlayFs($this->tmpDir);
    $fs->appendFile('/brand_new.txt', 'hello');

    expect($fs->readFile('/brand_new.txt'))->toBe('hello');
});

// ---------------------------------------------------------------
//  Deleted files must not show through
// ---------------------------------------------------------------

test('deleted file does not show through from real filesystem', function (): void {
    file_put_contents($this->tmpDir.'/secret.txt', 'hidden');

    $fs = new OverlayFs($this->tmpDir);

    expect($fs->exists('/secret.txt'))->toBeTrue();

    $fs->rm('/secret.txt');

    expect($fs->exists('/secret.txt'))->toBeFalse();

    // Real file still on disk
    expect(file_exists($this->tmpDir.'/secret.txt'))->toBeTrue();
});

test('readFile on deleted file throws ENOENT', function (): void {
    file_put_contents($this->tmpDir.'/gone.txt', 'data');

    $fs = new OverlayFs($this->tmpDir);
    $fs->rm('/gone.txt');

    $fs->readFile('/gone.txt');
})->throws(RuntimeException::class, 'ENOENT');

test('deleted file does not appear in readdir', function (): void {
    file_put_contents($this->tmpDir.'/a.txt', 'a');
    file_put_contents($this->tmpDir.'/b.txt', 'b');

    $fs = new OverlayFs($this->tmpDir);
    $fs->rm('/a.txt');

    $entries = $fs->readdir('/');
    expect($entries)->not->toContain('a.txt');
    expect($entries)->toContain('b.txt');
});

test('writing to a previously deleted path makes it visible again', function (): void {
    file_put_contents($this->tmpDir.'/revive.txt', 'old');

    $fs = new OverlayFs($this->tmpDir);
    $fs->rm('/revive.txt');

    expect($fs->exists('/revive.txt'))->toBeFalse();

    $fs->writeFile('/revive.txt', 'new');

    expect($fs->exists('/revive.txt'))->toBeTrue();
    expect($fs->readFile('/revive.txt'))->toBe('new');
});

// ---------------------------------------------------------------
//  Path traversal rejection
// ---------------------------------------------------------------

test('null byte in path is rejected', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    $fs->readFile("/etc/passwd\0");
})->throws(RuntimeException::class, 'null byte');

test('null byte in path makes exists return false', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    expect($fs->exists("/test\0"))->toBeFalse();
});

test('path with .. is normalized and stays contained', function (): void {
    mkdir($this->tmpDir.'/subdir', 0755, true);
    file_put_contents($this->tmpDir.'/top.txt', 'at top');

    $fs = new OverlayFs($this->tmpDir);

    // /subdir/../top.txt normalizes to /top.txt which is fine
    expect($fs->readFile('/subdir/../top.txt'))->toBe('at top');
});

test('path with excessive .. normalizes to root and does not escape', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    // /../../etc/passwd normalizes to /etc/passwd which is within the virtual FS
    // but /etc/passwd does not exist in the overlay root directory
    expect($fs->exists('/../../etc/passwd'))->toBeFalse();
});

// ---------------------------------------------------------------
//  Directory listing merges COW + real files
// ---------------------------------------------------------------

test('readdir merges real and COW entries', function (): void {
    file_put_contents($this->tmpDir.'/real.txt', 'on disk');

    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/virtual.txt', 'in memory');

    $entries = $fs->readdir('/');

    expect($entries)->toContain('real.txt');
    expect($entries)->toContain('virtual.txt');
});

test('readdir returns sorted entries', function (): void {
    file_put_contents($this->tmpDir.'/c.txt', 'c');
    file_put_contents($this->tmpDir.'/a.txt', 'a');

    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/b.txt', 'b');

    $entries = $fs->readdir('/');

    expect($entries)->toBe(['a.txt', 'b.txt', 'c.txt']);
});

test('readdirWithFileTypes returns correct type info', function (): void {
    file_put_contents($this->tmpDir.'/file.txt', 'data');
    mkdir($this->tmpDir.'/subdir', 0755);

    $fs = new OverlayFs($this->tmpDir);

    $entries = $fs->readdirWithFileTypes('/');
    $map = [];

    foreach ($entries as $entry) {
        $map[$entry->name] = $entry;
    }

    expect($map['file.txt']->isFile)->toBeTrue();
    expect($map['file.txt']->isDirectory)->toBeFalse();
    expect($map['subdir']->isDirectory)->toBeTrue();
    expect($map['subdir']->isFile)->toBeFalse();
});

test('COW entry overrides real entry in readdir', function (): void {
    file_put_contents($this->tmpDir.'/clash.txt', 'real');

    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/clash.txt', 'cow');

    $entries = $fs->readdir('/');

    // Should appear only once
    $count = array_count_values($entries);
    expect($count['clash.txt'])->toBe(1);

    // And COW content wins
    expect($fs->readFile('/clash.txt'))->toBe('cow');
});

// ---------------------------------------------------------------
//  exists / stat
// ---------------------------------------------------------------

test('exists returns true for real file', function (): void {
    file_put_contents($this->tmpDir.'/real.txt', 'yes');

    $fs = new OverlayFs($this->tmpDir);

    expect($fs->exists('/real.txt'))->toBeTrue();
});

test('exists returns true for COW file', function (): void {
    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/cow.txt', 'yes');

    expect($fs->exists('/cow.txt'))->toBeTrue();
});

test('exists returns false for non-existent file', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    expect($fs->exists('/nope.txt'))->toBeFalse();
});

test('stat returns info for COW file', function (): void {
    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/info.txt', 'hello');

    $stat = $fs->stat('/info.txt');

    expect($stat->isFile)->toBeTrue();
    expect($stat->isDirectory)->toBeFalse();
    expect($stat->size)->toBe(5);
});

test('stat returns info for real file', function (): void {
    file_put_contents($this->tmpDir.'/disk.txt', 'content');

    $fs = new OverlayFs($this->tmpDir);
    $stat = $fs->stat('/disk.txt');

    expect($stat->isFile)->toBeTrue();
    expect($stat->size)->toBe(7);
});

// ---------------------------------------------------------------
//  mkdir / rm / cp / mv
// ---------------------------------------------------------------

test('mkdir creates directory in COW layer', function (): void {
    $fs = new OverlayFs($this->tmpDir);
    $fs->mkdir('/newdir');

    expect($fs->exists('/newdir'))->toBeTrue();
    $stat = $fs->stat('/newdir');
    expect($stat->isDirectory)->toBeTrue();

    // Not on real disk
    expect(is_dir($this->tmpDir.'/newdir'))->toBeFalse();
});

test('rm with recursive deletes directory and children', function (): void {
    mkdir($this->tmpDir.'/dir', 0755);
    file_put_contents($this->tmpDir.'/dir/child.txt', 'data');

    $fs = new OverlayFs($this->tmpDir);

    expect($fs->exists('/dir/child.txt'))->toBeTrue();

    $fs->rm('/dir', ['recursive' => true]);

    expect($fs->exists('/dir'))->toBeFalse();
    expect($fs->exists('/dir/child.txt'))->toBeFalse();
});

test('cp copies real file into COW', function (): void {
    file_put_contents($this->tmpDir.'/src.txt', 'copy me');

    $fs = new OverlayFs($this->tmpDir);
    $fs->cp('/src.txt', '/dest.txt');

    expect($fs->readFile('/dest.txt'))->toBe('copy me');
    expect($fs->readFile('/src.txt'))->toBe('copy me');
});

test('mv moves file in COW layer', function (): void {
    file_put_contents($this->tmpDir.'/old.txt', 'moving');

    $fs = new OverlayFs($this->tmpDir);
    $fs->mv('/old.txt', '/new.txt');

    expect($fs->exists('/old.txt'))->toBeFalse();
    expect($fs->readFile('/new.txt'))->toBe('moving');
});

// ---------------------------------------------------------------
//  Symlink denial
// ---------------------------------------------------------------

test('symlinks denied by default', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    $fs->symlink('/target', '/link');
})->throws(RuntimeException::class, 'symlinks are denied');

test('real symlinks are hidden when denySymlinks is true', function (): void {
    file_put_contents($this->tmpDir.'/target.txt', 'data');
    symlink($this->tmpDir.'/target.txt', $this->tmpDir.'/link.txt');

    $fs = new OverlayFs($this->tmpDir);

    expect($fs->exists('/link.txt'))->toBeFalse();

    $entries = $fs->readdir('/');
    expect($entries)->not->toContain('link.txt');
});

// ---------------------------------------------------------------
//  resolvePath
// ---------------------------------------------------------------

test('resolvePath with absolute path returns normalized', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    expect($fs->resolvePath('/any', '/foo/bar'))->toBe('/foo/bar');
});

test('resolvePath with relative path resolves against base', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    expect($fs->resolvePath('/home/user', 'docs/file.txt'))->toBe('/home/user/docs/file.txt');
});

test('resolvePath resolves .. correctly', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    expect($fs->resolvePath('/a/b/c', '../d'))->toBe('/a/b/d');
});

// ---------------------------------------------------------------
//  getAllPaths
// ---------------------------------------------------------------

test('getAllPaths includes both real and COW paths', function (): void {
    file_put_contents($this->tmpDir.'/disk.txt', 'real');

    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/mem.txt', 'cow');

    $paths = $fs->getAllPaths();

    expect($paths)->toContain('/');
    expect($paths)->toContain('/disk.txt');
    expect($paths)->toContain('/mem.txt');
});

// ---------------------------------------------------------------
//  Constructor validation
// ---------------------------------------------------------------

test('constructor rejects non-existent root directory', function (): void {
    new OverlayFs('/definitely/does/not/exist');
})->throws(RuntimeException::class, 'does not exist');

// ---------------------------------------------------------------
//  Sandbox containment
// ---------------------------------------------------------------

test('disk symlinks never expose files outside the root', function (bool $denySymlinks, string $method, array $args): void {
    symlink($this->outside, $this->tmpDir.'/escape');
    symlink('../'.basename($this->outside).'/secret', $this->tmpDir.'/relative');
    symlink($this->outside.'/secret', $this->tmpDir.'/absolute');
    symlink('absolute', $this->tmpDir.'/chain');
    $fs = new OverlayFs($this->tmpDir, $denySymlinks);

    expect(fn () => $fs->{$method}(...$args))
        ->toThrow(RuntimeException::class, $denySymlinks ? 'symlinks are denied' : 'path traversal denied');
    expect($fs->exists($args[0]))->toBeFalse();
})->with([
    'symlinks denied' => true,
    'symlinks allowed' => false,
])->with([
    'read through dir link' => ['readFile', ['/escape/secret']],
    'relative .. link' => ['readFile', ['/relative']],
    'absolute link' => ['readFile', ['/absolute']],
    'link chain' => ['readFile', ['/chain']],
    'list through dir link' => ['readdir', ['/escape']],
    'stat' => ['stat', ['/escape/secret']],
    'copy out' => ['cp', ['/escape/secret', '/stolen']],
    'realpath' => ['realpath', ['/escape/secret']],
]);

test('writing through an escaping disk link is denied and touches neither layer nor host', function (string $method): void {
    symlink($this->outside.'/secret', $this->tmpDir.'/absolute');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);

    expect(fn () => $fs->{$method}('/absolute', 'mine'))->toThrow(RuntimeException::class, "EACCES: path traversal denied, open '/absolute'");
    expect($fs->lstat('/absolute')->isSymbolicLink)->toBeTrue();
    expect(file_get_contents($this->outside.'/secret'))->toBe('host secret');
})->with(['appendFile', 'writeFile']);

test('denied disk symlinks cannot be inspected', function (string $method): void {
    symlink('target.txt', $this->tmpDir.'/link.txt');
    $fs = new OverlayFs($this->tmpDir);

    $fs->{$method}('/link.txt');
})->with(['lstat', 'readlink', 'stat'])->throws(RuntimeException::class, 'symlinks are denied');

test('allowed disk symlinks inside the root are followed', function (): void {
    file_put_contents($this->tmpDir.'/target.txt', 'data');
    symlink($this->tmpDir.'/target.txt', $this->tmpDir.'/link.txt');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);

    expect($fs->readFile('/link.txt'))->toBe('data');
    expect($fs->lstat('/link.txt')->isSymbolicLink)->toBeTrue();
    expect($fs->readlink('/link.txt'))->toBe('/target.txt');
    expect($fs->realpath('/link.txt'))->toBe('/target.txt');
    expect($fs->readdirWithFileTypes('/')[0]->isSymbolicLink)->toBeTrue();
});

test('symlinks created in the overlay live in memory only', function (): void {
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->writeFile('/real.txt', 'cow');

    $fs->symlink('/real.txt', '/link');

    expect($fs->readFile('/link'))->toBe('cow');
    expect($fs->readlink('/link'))->toBe('/real.txt');
    expect(is_link($this->tmpDir.'/link'))->toBeFalse();
});

// ---------------------------------------------------------------
//  Copy-up keeps disk semantics
// ---------------------------------------------------------------

test('changes to disk entries keep their mode and leave the disk untouched', function (): void {
    file_put_contents($this->tmpDir.'/run.sh', 'echo');
    chmod($this->tmpDir.'/run.sh', 0750);
    touch($this->tmpDir.'/run.sh', 1_000_000);
    $fs = new OverlayFs($this->tmpDir);

    $fs->appendFile('/run.sh', ' hi');
    $fs->utimes('/run.sh', 2_000_000);

    expect($fs->readFile('/run.sh'))->toBe('echo hi');
    expect($fs->stat('/run.sh')->mode)->toBe(0750);
    expect($fs->stat('/run.sh')->mtime)->toBe(2_000_000);
    clearstatcache();
    expect(filemtime($this->tmpDir.'/run.sh'))->toBe(1_000_000);
});

test('chmod and hard links on disk files happen in memory', function (): void {
    file_put_contents($this->tmpDir.'/file.txt', 'data');
    chmod($this->tmpDir.'/file.txt', 0644);
    $fs = new OverlayFs($this->tmpDir);

    $fs->chmod('/file.txt', 0600);
    $fs->link('/file.txt', '/hard.txt');

    expect($fs->stat('/file.txt')->mode)->toBe(0600);
    expect($fs->readFile('/hard.txt'))->toBe('data');
    clearstatcache();
    expect(fileperms($this->tmpDir.'/file.txt') & 0777)->toBe(0644);
    expect(file_exists($this->tmpDir.'/hard.txt'))->toBeFalse();
});

test('mkdir respects what is already on disk', function (): void {
    mkdir($this->tmpDir.'/dir/sub', 0755, true);
    file_put_contents($this->tmpDir.'/dir/sub/keep.txt', 'k');
    $fs = new OverlayFs($this->tmpDir);

    $fs->mkdir('/dir/sub', ['recursive' => true]);
    $fs->mkdir('/dir/sub/new');

    expect($fs->readdir('/dir/sub'))->toBe(['keep.txt', 'new']);
    expect(fn () => $fs->mkdir('/dir'))->toThrow(RuntimeException::class, 'EEXIST');
});

test('a directory recreated after rm does not show its old disk contents', function (): void {
    mkdir($this->tmpDir.'/dir');
    file_put_contents($this->tmpDir.'/dir/old.txt', 'old');
    $fs = new OverlayFs($this->tmpDir);

    $fs->rm('/dir', ['recursive' => true]);
    $fs->mkdir('/dir');
    $fs->writeFile('/dir/new.txt', 'new');

    expect($fs->readdir('/dir'))->toBe(['new.txt']);
    expect($fs->exists('/dir/old.txt'))->toBeFalse();
    expect($fs->getAllPaths())->toBe(['/', '/dir', '/dir/new.txt']);
});

test('getAllPaths hides deleted disk subtrees', function (): void {
    mkdir($this->tmpDir.'/gone/deep', 0755, true);
    file_put_contents($this->tmpDir.'/gone/deep/x', 'x');
    file_put_contents($this->tmpDir.'/kept.txt', 'k');
    $fs = new OverlayFs($this->tmpDir);

    $fs->rm('/gone', ['recursive' => true]);

    expect($fs->getAllPaths())->toBe(['/', '/kept.txt']);
});

test('cp recursive copies a disk tree and mv keeps modes', function (): void {
    mkdir($this->tmpDir.'/src/deep', 0755, true);
    file_put_contents($this->tmpDir.'/src/deep/data.txt', 'data');
    file_put_contents($this->tmpDir.'/src/run.sh', 'echo');
    chmod($this->tmpDir.'/src/run.sh', 0700);
    $fs = new OverlayFs($this->tmpDir);

    $fs->cp('/src', '/copy', ['recursive' => true]);
    $fs->mv('/src', '/moved');

    expect($fs->readFile('/copy/deep/data.txt'))->toBe('data');
    expect($fs->stat('/copy/run.sh')->mode)->toBe(0644);
    expect($fs->stat('/moved/run.sh')->mode)->toBe(0700);
    expect($fs->exists('/src'))->toBeFalse();
    expect(is_dir($this->tmpDir.'/src'))->toBeTrue();
});

test('mv onto itself keeps the file', function (): void {
    file_put_contents($this->tmpDir.'/same.txt', 'same');
    $fs = new OverlayFs($this->tmpDir);

    $fs->mv('/same.txt', '/./same.txt');

    expect($fs->readFile('/same.txt'))->toBe('same');
});

test('rm with force ignores missing paths', function (): void {
    $fs = new OverlayFs($this->tmpDir);

    $fs->rm('/missing', ['force' => true]);

    expect($fs->exists('/missing'))->toBeFalse();
});

test('operations fail with the matching errno', function (string $method, array $args, string $error): void {
    mkdir($this->tmpDir.'/dir');
    file_put_contents($this->tmpDir.'/dir/child.txt', 'child');
    file_put_contents($this->tmpDir.'/file.txt', 'data');
    file_put_contents($this->tmpDir.'/deleted.txt', 'data');
    $fs = new OverlayFs($this->tmpDir);
    $fs->rm('/deleted.txt');

    expect(fn () => $fs->{$method}(...$args))->toThrow(RuntimeException::class, $error);
})->with([
    ['readFile', ['/dir'], 'EISDIR'],
    ['writeFile', ['/dir', 'x'], 'EISDIR'],
    ['writeFile', ['/file.txt/x', 'x'], 'ENOTDIR'],
    ['mkdir', ['/file.txt', ['recursive' => true]], 'EEXIST'],
    ['stat', ['/deleted.txt'], 'ENOENT'],
    ['readlink', ['/deleted.txt'], 'ENOENT'],
    ['readlink', ['/file.txt'], 'EINVAL'],
    ['realpath', ['/missing'], 'ENOENT'],
    ['readdir', ['/missing'], 'ENOENT'],
    ['readdir', ['/file.txt'], 'ENOTDIR'],
    ['rm', ['/missing'], 'ENOENT'],
    ['rm', ['/', ['recursive' => true]], 'EPERM'],
    ['rm', ['/dir'], 'ENOTEMPTY'],
    ['cp', ['/dir', '/copy'], 'EISDIR'],
    ['cp', ['/dir', '/dir/inner', ['recursive' => true]], 'EINVAL'],
    ['chmod', ['/deleted.txt', 0644], 'ENOENT'],
    ['utimes', ['/missing', 0], 'ENOENT'],
    ['link', ['/file.txt', '/dir/child.txt'], 'EEXIST'],
]);

test('removing an overridden disk file does not resurface the disk version', function (): void {
    file_put_contents($this->tmpDir.'/data.txt', 'disk');
    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/data.txt', 'memory');

    $fs->rm('/data.txt');

    expect($fs->exists('/data.txt'))->toBeFalse();
    expect(file_get_contents($this->tmpDir.'/data.txt'))->toBe('disk');
});

// ---------------------------------------------------------------
//  Symlinks resolve across both layers
// ---------------------------------------------------------------

test('an overlay symlink to a disk-only file reads, writes and stats through the merged view', function (): void {
    mkdir($this->tmpDir.'/data');
    file_put_contents($this->tmpDir.'/data/file.txt', 'disk');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->symlink('/data/file.txt', '/abs');
    $fs->symlink('./data/.', '/dirlink');

    expect($fs->readFile('/abs'))->toBe('disk')
        ->and($fs->readFile('/dirlink/file.txt'))->toBe('disk')
        ->and($fs->readdir('/dirlink'))->toBe(['file.txt'])
        ->and($fs->stat('/abs'))->toMatchObject(['isFile' => true, 'size' => 4])
        ->and($fs->realpath('/dirlink/file.txt'))->toBe('/data/file.txt')
        ->and($fs->exists('/abs'))->toBeTrue();

    $fs->appendFile('/abs', '+mem');
    $fs->writeFile('/dirlink/new.txt', 'new');
    $fs->chmod('/dirlink/file.txt', 0600);

    expect($fs->readFile('/data/file.txt'))->toBe('disk+mem')
        ->and($fs->readFile('/data/new.txt'))->toBe('new')
        ->and($fs->stat('/data/file.txt')->mode)->toBe(0600)
        ->and($fs->lstat('/abs')->isSymbolicLink)->toBeTrue()
        ->and(file_get_contents($this->tmpDir.'/data/file.txt'))->toBe('disk')
        ->and(file_exists($this->tmpDir.'/data/new.txt'))->toBeFalse();
});

test('a disk symlink resolves to entries that exist only in the overlay', function (): void {
    symlink('made-later', $this->tmpDir.'/link');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);

    expect($fs->exists('/link'))->toBeFalse();

    $fs->mkdir('/made-later');
    $fs->writeFile('/link/f', 'via disk link');

    expect($fs->readFile('/made-later/f'))->toBe('via disk link')
        ->and($fs->realpath('/link/f'))->toBe('/made-later/f')
        ->and(file_exists($this->tmpDir.'/made-later'))->toBeFalse();
});

test('a symlink to a path deleted in the overlay dangles', function (): void {
    file_put_contents($this->tmpDir.'/target', 'gone');
    symlink('target', $this->tmpDir.'/disk-link');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->symlink('/target', '/mem-link');

    $fs->rm('/target');

    foreach (['/disk-link', '/mem-link'] as $link) {
        expect($fs->exists($link))->toBeFalse()
            ->and(fn (): string => $fs->readFile($link))->toThrow(RuntimeException::class, 'ENOENT');
    }
});

test('overlay symlinks never reach the host outside the root', function (string $target, string $method, array $args): void {
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->mkdir('/a/b', ['recursive' => true]);
    $fs->symlink(strtr($target, ['{outside}' => $this->outside, '{sibling}' => basename($this->outside)]), '/a/b/link');

    expect(fn () => $fs->{$method}(...$args))->toThrow(RuntimeException::class, 'ENOENT');
    expect(file_get_contents($this->outside.'/secret'))->toBe('host secret');
})->with([
    'relative climb to a sibling of the root' => '../../../{sibling}/secret',
    'climb far above the root, then the host path' => '../../../../../../../../../..{outside}/secret',
    'absolute host path' => '{outside}/secret',
    'absolute host directory' => '{outside}',
])->with([
    'read' => ['readFile', ['/a/b/link']],
    'read below' => ['readFile', ['/a/b/link/secret']],
    'list' => ['readdir', ['/a/b/link/x']],
    'stat' => ['stat', ['/a/b/link/secret']],
    'realpath' => ['realpath', ['/a/b/link/secret']],
]);

test('a climbing overlay symlink stays at the virtual root', function (): void {
    file_put_contents($this->tmpDir.'/at-root', 'inside');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->mkdir('/a');
    $fs->symlink('../../../../at-root', '/a/up');

    expect($fs->readFile('/a/up'))->toBe('inside')
        ->and($fs->realpath('/a/up'))->toBe('/at-root');
});

test('writing through an overlay symlink to a host path creates an in-memory file only', function (): void {
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->symlink($this->outside.'/secret', '/link');

    $fs->writeFile('/link', 'mine');

    expect($fs->readFile('/link'))->toBe('mine')
        ->and($fs->realpath('/link'))->toBe($this->outside.'/secret')
        ->and(file_get_contents($this->outside.'/secret'))->toBe('host secret')
        ->and(array_diff(scandir($this->tmpDir), ['.', '..']))->toBe([]);
});

test('chains mixing overlay and disk links are denied when a disk link escapes', function (string $method, array $args): void {
    symlink($this->outside, $this->tmpDir.'/escape');
    symlink('../'.basename($this->outside), $this->tmpDir.'/relative-escape');
    symlink('escape', $this->tmpDir.'/disk-chain');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->symlink('/disk-chain', '/mem');
    $fs->symlink('relative-escape', '/mem-relative');
    $fs->symlink('mem', '/mem-chain');

    expect(fn () => $fs->{$method}(...$args))->toThrow(RuntimeException::class, 'EACCES: path traversal denied');
    expect($fs->exists($args[0]))->toBeFalse()
        ->and(file_get_contents($this->outside.'/secret'))->toBe('host secret');
})->with([
    'overlay -> disk -> host' => ['readFile', ['/mem/secret']],
    'overlay -> relative disk escape' => ['readFile', ['/mem-relative/secret']],
    'overlay -> overlay -> disk -> host' => ['readFile', ['/mem-chain/secret']],
    'write through the chain' => ['writeFile', ['/mem-chain/secret', 'x']],
    'list through the chain' => ['readdir', ['/mem-chain']],
    'realpath' => ['realpath', ['/mem/secret']],
]);

test('a dangling disk link inside the root is followed into the overlay, not denied', function (): void {
    symlink('not-yet', $this->tmpDir.'/dangling');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);

    $fs->writeFile('/dangling', 'created');

    expect($fs->readFile('/not-yet'))->toBe('created')
        ->and($fs->lstat('/dangling')->isSymbolicLink)->toBeTrue()
        ->and(file_exists($this->tmpDir.'/not-yet'))->toBeFalse();
});

test('symlink loops across the layers fail with ELOOP', function (): void {
    symlink('mem', $this->tmpDir.'/disk');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->symlink('/disk', '/mem');

    expect(fn (): string => $fs->readFile('/mem'))->toThrow(RuntimeException::class, "ELOOP: too many levels of symbolic links, open '/mem'")
        ->and($fs->exists('/disk'))->toBeFalse();

    $fs->rm('/mem/x', ['force' => true]);
    expect($fs->lstat('/mem')->isSymbolicLink)->toBeTrue();
});

test('mkdir -p through a symlink to a disk directory', function (): void {
    mkdir($this->tmpDir.'/real');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->symlink('real', '/link');

    $fs->mkdir('/link', ['recursive' => true]);
    $fs->mkdir('/link/a/b', ['recursive' => true]);

    expect($fs->stat('/real/a/b')->isDirectory)->toBeTrue()
        ->and($fs->lstat('/link')->isSymbolicLink)->toBeTrue()
        ->and(fn () => $fs->mkdir('/link'))->toThrow(RuntimeException::class, 'EEXIST');
});

// ---------------------------------------------------------------
//  Hard links, rename and exclusive create in the overlay
// ---------------------------------------------------------------

test('hard links in the overlay share one inode, also for copied-up disk files', function (): void {
    file_put_contents($this->tmpDir.'/disk.txt', 'disk');
    $fs = new OverlayFs($this->tmpDir);

    $fs->link('/disk.txt', '/hard.txt');
    $fs->appendFile('/hard.txt', '+');

    expect($fs->readFile('/disk.txt'))->toBe('disk+')
        ->and($fs->stat('/disk.txt')->nlink)->toBe(2)
        ->and($fs->stat('/disk.txt')->ino)->toBe($fs->stat('/hard.txt')->ino)
        ->and(file_get_contents($this->tmpDir.'/disk.txt'))->toBe('disk');
});

test('a hard link to a disk symlink copies the symlink up as a symlink', function (): void {
    file_put_contents($this->tmpDir.'/target', 't');
    symlink('target', $this->tmpDir.'/sym');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);

    $fs->link('/sym', '/hard');

    expect($fs->readlink('/hard'))->toBe('target')
        ->and($fs->readFile('/hard'))->toBe('t')
        ->and($fs->lstat('/sym')->nlink)->toBe(2);
});

test('mv renames: symlinks stay symlinks, hard links stay shared, disk trees move whole', function (): void {
    mkdir($this->tmpDir.'/tree/deep', 0755, true);
    file_put_contents($this->tmpDir.'/tree/deep/f', 'f');
    symlink('deep/f', $this->tmpDir.'/tree/rel');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);
    $fs->writeFile('/a', 'a');
    $fs->link('/a', '/b');

    $fs->mv('/tree', '/moved');
    $fs->mv('/a', '/c');
    $fs->writeFile('/c', 'changed');

    expect($fs->readlink('/moved/rel'))->toBe('deep/f')
        ->and($fs->readFile('/moved/rel'))->toBe('f')
        ->and($fs->exists('/tree'))->toBeFalse()
        ->and($fs->readFile('/b'))->toBe('changed')
        ->and(is_dir($this->tmpDir.'/tree/deep'))->toBeTrue();
});

test('mv over disk entries follows rename(2)', function (string $src, string $dest, ?string $error): void {
    mkdir($this->tmpDir.'/full');
    file_put_contents($this->tmpDir.'/full/x', 'x');
    mkdir($this->tmpDir.'/empty');
    file_put_contents($this->tmpDir.'/file', 'old');
    $fs = new OverlayFs($this->tmpDir);
    $fs->mkdir('/dir');
    $fs->writeFile('/new', 'new');

    if ($error !== null) {
        expect(fn () => $fs->mv($src, $dest))->toThrow(RuntimeException::class, $error);
        expect($fs->exists($src))->toBeTrue();

        return;
    }

    $fs->mv($src, $dest);

    expect($fs->exists($src))->toBeFalse()
        ->and($fs->readdir('/'))->not->toContain(basename($src));
})->with([
    'directory onto a non-empty disk directory' => ['/dir', '/full', 'ENOTEMPTY'],
    'file onto a disk directory' => ['/new', '/empty', 'EISDIR'],
    'directory onto a disk file' => ['/dir', '/file', 'ENOTDIR'],
    'directory onto an empty disk directory' => ['/dir', '/empty', null],
    'file onto a disk file' => ['/new', '/file', null],
]);

test('a file moved onto a disk file hides the disk version for good', function (): void {
    file_put_contents($this->tmpDir.'/file', 'old');
    $fs = new OverlayFs($this->tmpDir);
    $fs->writeFile('/new', 'new');

    $fs->mv('/new', '/file');
    $fs->rm('/file');

    expect($fs->exists('/file'))->toBeFalse()
        ->and(file_get_contents($this->tmpDir.'/file'))->toBe('old');
});

test('createExclusive creates in memory only and refuses anything already visible', function (): void {
    file_put_contents($this->tmpDir.'/disk', 'keep');
    symlink('nowhere', $this->tmpDir.'/dangling');
    mkdir($this->tmpDir.'/dir');
    $fs = new OverlayFs($this->tmpDir, denySymlinks: false);

    $fs->createExclusive('/dir/file');
    $fs->createExclusive('/dir/sub', true);

    expect($fs->stat('/dir/file')->mode)->toBe(0600)
        ->and($fs->stat('/dir/sub')->mode)->toBe(0700)
        ->and(scandir($this->tmpDir.'/dir'))->toBe(['.', '..'])
        ->and(fn () => $fs->createExclusive('/disk'))->toThrow(RuntimeException::class, "EEXIST: file already exists, open '/disk'")
        ->and(fn () => $fs->createExclusive('/dangling'))->toThrow(RuntimeException::class, 'EEXIST')
        ->and(fn () => $fs->createExclusive('/missing/x', true))->toThrow(RuntimeException::class, "ENOENT: no such file or directory, mkdir '/missing/x'")
        ->and($fs->exists('/nowhere'))->toBeFalse()
        ->and($fs->readFile('/disk'))->toBe('keep');
});

test('createExclusive after rm reuses the name without the disk version', function (): void {
    file_put_contents($this->tmpDir.'/name', 'disk');
    $fs = new OverlayFs($this->tmpDir);
    $fs->rm('/name');

    $fs->createExclusive('/name');

    expect($fs->readFile('/name'))->toBe('');
});

test('cp keeps mode and mtime only with preserve', function (bool $preserve, int $mode, bool $keepsMtime): void {
    file_put_contents($this->tmpDir.'/src', 'data');
    chmod($this->tmpDir.'/src', 0750);
    touch($this->tmpDir.'/src', 1_000_000);
    $fs = new OverlayFs($this->tmpDir);

    $fs->cp('/src', '/dest', ['preserve' => $preserve]);

    expect($fs->readFile('/dest'))->toBe('data')
        ->and($fs->stat('/dest')->mode)->toBe($mode)
        ->and($fs->stat('/dest')->mtime === 1_000_000)->toBe($keepsMtime);
})->with([
    'default' => [false, 0644, false],
    'preserve' => [true, 0750, true],
]);

test('the in-memory layer is capped by the quota, copied-up files included', function (): void {
    file_put_contents($this->tmpDir.'/big', str_repeat('x', 100));
    $fs = new OverlayFs($this->tmpDir, diskQuota: new DiskQuota(maxBytes: 100));

    expect(fn () => $fs->appendFile('/big', 'x'))->toThrow(RuntimeException::class, 'ENOSPC')
        ->and(fn () => $fs->writeFile('/new', str_repeat('x', 100)))->toThrow(RuntimeException::class, 'ENOSPC')
        ->and($fs->readFile('/big'))->toBe(str_repeat('x', 100));

    $fs->writeFile('/new', str_repeat('x', 90));

    expect($fs->readFile('/new'))->toHaveLength(90);
});
