<?php

declare(strict_types=1);

use BashBox\Filesystem\InMemoryFs;
use BashBox\Filesystem\MountableFs;

beforeEach(function (): void {
    $this->defaultFs = new InMemoryFs([
        '/home/user/file.txt' => 'default content',
    ]);
    $this->mountableFs = new MountableFs($this->defaultFs);
});

test('operations go to default filesystem when no mounts', function (): void {
    expect($this->mountableFs->readFile('/home/user/file.txt'))->toBe('default content');
});

test('mount routes operations to mounted filesystem', function (): void {
    $mountedFs = new InMemoryFs([
        '/data.txt' => 'mounted data',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    expect($this->mountableFs->readFile('/mnt/data.txt'))->toBe('mounted data');
});

test('unmount removes the mount and falls back to default', function (): void {
    $mountedFs = new InMemoryFs([
        '/data.txt' => 'mounted data',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    expect($this->mountableFs->readFile('/mnt/data.txt'))->toBe('mounted data');

    $this->mountableFs->unmount('/mnt');

    expect($this->mountableFs->exists('/mnt/data.txt'))->toBeFalse();
});

test('mount strips prefix and forwards inner path', function (): void {
    $mountedFs = new InMemoryFs([
        '/subdir/file.txt' => 'nested content',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    expect($this->mountableFs->readFile('/mnt/subdir/file.txt'))->toBe('nested content');
});

test('longest-prefix mount wins over shorter prefix', function (): void {
    $shortFs = new InMemoryFs([
        '/data/file.txt' => 'from short mount',
    ]);
    $longFs = new InMemoryFs([
        '/file.txt' => 'from long mount',
    ]);

    $this->mountableFs->mount('/mnt', $shortFs);
    $this->mountableFs->mount('/mnt/data', $longFs);

    expect($this->mountableFs->readFile('/mnt/data/file.txt'))->toBe('from long mount');
});

test('shorter prefix still works for paths not under longer mount', function (): void {
    $shortFs = new InMemoryFs([
        '/other.txt' => 'short mount file',
        '/data/file.txt' => 'short mount data file',
    ]);
    $longFs = new InMemoryFs([
        '/file.txt' => 'from long mount',
    ]);

    $this->mountableFs->mount('/mnt', $shortFs);
    $this->mountableFs->mount('/mnt/data', $longFs);

    expect($this->mountableFs->readFile('/mnt/other.txt'))->toBe('short mount file');
});

test('readdir includes mount point directory names', function (): void {
    $this->defaultFs->mkdir('/mnt', ['recursive' => true]);
    $mountedFs = new InMemoryFs([
        '/file.txt' => 'data',
    ]);
    $this->mountableFs->mount('/mnt/external', $mountedFs);

    $entries = $this->mountableFs->readdir('/mnt');

    expect($entries)->toContain('external');
});

test('readdirWithFileTypes includes mount points as directories', function (): void {
    $this->defaultFs->mkdir('/mnt', ['recursive' => true]);
    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/mnt/usb', $mountedFs);

    $entries = $this->mountableFs->readdirWithFileTypes('/mnt');
    $names = array_map(fn ($e) => $e->name, $entries);
    expect($names)->toContain('usb');

    $usbEntry = null;

    foreach ($entries as $entry) {
        if ($entry->name === 'usb') {
            $usbEntry = $entry;

            break;
        }
    }

    expect($usbEntry?->isDirectory)->toBeTrue();
    expect($usbEntry?->isFile)->toBeFalse();
});

test('readdir merges default fs entries with mount point entries', function (): void {
    $this->defaultFs->mkdir('/mnt', ['recursive' => true]);
    $this->defaultFs->writeFile('/mnt/local.txt', 'local');

    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/mnt/remote', $mountedFs);

    $entries = $this->mountableFs->readdir('/mnt');

    expect($entries)->toContain('local.txt');
    expect($entries)->toContain('remote');
});

test('readdir does not duplicate entries that match mount point names', function (): void {
    $this->defaultFs->mkdir('/mnt/shared', ['recursive' => true]);

    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/mnt/shared', $mountedFs);

    $entries = $this->mountableFs->readdir('/mnt');
    $count = count(array_filter($entries, fn ($e): bool => $e === 'shared'));

    expect($count)->toBe(1);
});

test('readdir at root shows mount point top-level directories', function (): void {
    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/proc', $mountedFs);

    $entries = $this->mountableFs->readdir('/');

    expect($entries)->toContain('home');
    expect($entries)->toContain('proc');
});

test('writeFile to mounted filesystem stores data there', function (): void {
    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->writeFile('/mnt/new.txt', 'new content');

    expect($mountedFs->readFile('/new.txt'))->toBe('new content');
    expect($this->mountableFs->readFile('/mnt/new.txt'))->toBe('new content');
});

test('writeFile to default filesystem when path does not match any mount', function (): void {
    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->writeFile('/home/user/new.txt', 'default new');

    expect($this->defaultFs->readFile('/home/user/new.txt'))->toBe('default new');
});

test('exists works across mount boundaries', function (): void {
    $mountedFs = new InMemoryFs([
        '/file.txt' => 'data',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    expect($this->mountableFs->exists('/mnt/file.txt'))->toBeTrue();
    expect($this->mountableFs->exists('/mnt/nonexistent.txt'))->toBeFalse();
    expect($this->mountableFs->exists('/home/user/file.txt'))->toBeTrue();
});

test('exists returns true for a mount point path itself', function (): void {
    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/mnt', $mountedFs);

    expect($this->mountableFs->exists('/mnt'))->toBeTrue();
});

test('stat on a mount point returns the mounted fs root stat', function (): void {
    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/mnt', $mountedFs);

    $stat = $this->mountableFs->stat('/mnt');

    expect($stat->isDirectory)->toBeTrue();
});

test('mkdir on mounted filesystem creates directory there', function (): void {
    $mountedFs = new InMemoryFs;
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->mkdir('/mnt/subdir');

    expect($mountedFs->exists('/subdir'))->toBeTrue();
    expect($mountedFs->stat('/subdir')->isDirectory)->toBeTrue();
});

test('rm on mounted filesystem removes from the correct backend', function (): void {
    $mountedFs = new InMemoryFs([
        '/to-delete.txt' => 'delete me',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->rm('/mnt/to-delete.txt');

    expect($mountedFs->exists('/to-delete.txt'))->toBeFalse();
    expect($this->mountableFs->exists('/mnt/to-delete.txt'))->toBeFalse();
});

test('appendFile on mounted filesystem appends to correct backend', function (): void {
    $mountedFs = new InMemoryFs([
        '/log.txt' => 'line1',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->appendFile('/mnt/log.txt', "\nline2");

    expect($mountedFs->readFile('/log.txt'))->toBe("line1\nline2");
});

test('chmod on mounted filesystem modifies correct backend', function (): void {
    $mountedFs = new InMemoryFs([
        '/script.sh' => '#!/bin/bash',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->chmod('/mnt/script.sh', 0755);

    expect($mountedFs->stat('/script.sh')->mode)->toBe(0755);
});

test('getAllPaths includes paths from all mounted filesystems', function (): void {
    $mountedFs = new InMemoryFs([
        '/data.txt' => 'data',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $allPaths = $this->mountableFs->getAllPaths();

    expect($allPaths)->toContain('/home/user/file.txt');
    expect($allPaths)->toContain('/mnt');
    expect($allPaths)->toContain('/mnt/data.txt');
});

test('resolvePath resolves absolute paths', function (): void {
    expect($this->mountableFs->resolvePath('/home', '/etc/config'))->toBe('/etc/config');
});

test('resolvePath resolves relative paths against base', function (): void {
    expect($this->mountableFs->resolvePath('/home/user', 'file.txt'))->toBe('/home/user/file.txt');
});

test('resolvePath normalizes dot-dot segments', function (): void {
    expect($this->mountableFs->resolvePath('/home/user', '../other/file.txt'))->toBe('/home/other/file.txt');
});

test('cp across mount boundaries copies file content', function (): void {
    $mountedFs = new InMemoryFs([
        '/source.txt' => 'source content',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->cp('/mnt/source.txt', '/home/user/copy.txt');

    expect($this->defaultFs->readFile('/home/user/copy.txt'))->toBe('source content');
});

test('mv across mount boundaries moves file', function (): void {
    $mountedFs = new InMemoryFs([
        '/moveme.txt' => 'move content',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->mv('/mnt/moveme.txt', '/home/user/moved.txt');

    expect($this->defaultFs->readFile('/home/user/moved.txt'))->toBe('move content');
    expect($mountedFs->exists('/moveme.txt'))->toBeFalse();
});

test('utimes updates mtime on the correct backend', function (): void {
    $mountedFs = new InMemoryFs([
        '/file.txt' => 'data',
    ]);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->utimes('/mnt/file.txt', 1700000000);

    expect($mountedFs->stat('/file.txt')->mtime)->toBe(1700000000);
});

test('mounting over the root filesystem is rejected', function (): void {
    expect(fn () => $this->mountableFs->mount('/./', new InMemoryFs))
        ->toThrow(RuntimeException::class, "EINVAL: cannot mount over the root filesystem, mount '/'");
});

test('readdir lists the first component of a deeper mount point', function (): void {
    $this->mountableFs->mount('/media/usb/disk', new InMemoryFs);

    expect($this->mountableFs->readdir('/'))->toBe(['home', 'media']);
});

test('lstat on a mount point reports the mounted root directory', function (): void {
    $this->mountableFs->mount('/mnt', new InMemoryFs);

    expect($this->mountableFs->lstat('/mnt')->isDirectory)->toBeTrue();
});

test('symlinks inside a mount resolve within that mount', function (): void {
    $mountedFs = new InMemoryFs(['/real/file.txt' => 'data']);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->symlink('/real/file.txt', '/mnt/link');

    expect($this->mountableFs->readlink('/mnt/link'))->toBe('/real/file.txt');
    expect($this->mountableFs->lstat('/mnt/link')->isSymbolicLink)->toBeTrue();
    expect($this->mountableFs->readFile('/mnt/link'))->toBe('data');
    expect($mountedFs->lstat('/link')->isSymbolicLink)->toBeTrue();
});

test('realpath re-prefixes the mount point', function (string $path, string $expected): void {
    $this->mountableFs->mount('/mnt', new InMemoryFs(['/real/file.txt' => 'data']));
    $this->mountableFs->symlink('/real', '/mnt/link');

    expect($this->mountableFs->realpath($path))->toBe($expected);
})->with([
    'inside mount through symlink' => ['/mnt/link/file.txt', '/mnt/real/file.txt'],
    'mount point itself' => ['/mnt/', '/mnt'],
    'default filesystem' => ['/home/user/../user/file.txt', '/home/user/file.txt'],
    'default root' => ['/', '/'],
]);

test('link works within one filesystem and fails with EXDEV across mounts', function (): void {
    $mountedFs = new InMemoryFs(['/file.txt' => 'data']);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->link('/mnt/file.txt', '/mnt/hard.txt');

    expect($mountedFs->readFile('/hard.txt'))->toBe('data');
    expect(fn () => $this->mountableFs->link('/mnt/file.txt', '/home/user/hard.txt'))
        ->toThrow(RuntimeException::class, "EXDEV: cross-device link not permitted, link '/mnt/file.txt' -> '/home/user/hard.txt'");
});

test('cp within a single mount is delegated to that filesystem', function (): void {
    $mountedFs = new InMemoryFs(['/a.txt' => 'data']);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->cp('/mnt/a.txt', '/mnt/b.txt');

    expect($mountedFs->readFile('/b.txt'))->toBe('data');
});

test('cp across mounts copies a directory tree recursively', function (): void {
    $mountedFs = new InMemoryFs(['/src/a.txt' => 'a', '/src/sub/b.txt' => 'b']);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->cp('/mnt/src/', '/home/copy', ['recursive' => true]);

    expect($this->defaultFs->readdir('/home/copy'))->toBe(['a.txt', 'sub']);
    expect($this->defaultFs->readFile('/home/copy/sub/b.txt'))->toBe('b');
});

test('cp across mounts refuses a directory without recursive', function (): void {
    $this->mountableFs->mount('/mnt', new InMemoryFs(['/src/a.txt' => 'a']));

    expect(fn () => $this->mountableFs->cp('/mnt/src', '/home/copy'))
        ->toThrow(RuntimeException::class, "EISDIR: is a directory, cp '/mnt/src'");
    expect($this->defaultFs->exists('/home/copy'))->toBeFalse();
});

test('cp across mounts keeps the mode and keeps mtime only with preserve', function (bool $preserve, bool $keepsMtime): void {
    $mountedFs = new InMemoryFs(['/run.sh' => 'echo hi']);
    $mountedFs->chmod('/run.sh', 0755);
    $mountedFs->utimes('/run.sh', 1000);

    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->cp('/mnt/run.sh', '/home/run.sh', ['preserve' => $preserve]);

    expect($this->defaultFs->stat('/home/run.sh')->mode)->toBe(0755);
    expect($this->defaultFs->stat('/home/run.sh')->mtime === 1000)->toBe($keepsMtime);
})->with([
    'default' => [false, false],
    'preserve' => [true, true],
]);

test('mv within a mount renames on that filesystem', function (): void {
    $mountedFs = new InMemoryFs(['/dir/a.txt' => 'a']);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->mv('/mnt/dir', '/mnt/renamed');
    $this->mountableFs->mv('/mnt/renamed/a.txt', '/mnt/renamed/a.txt');

    expect($mountedFs->readFile('/renamed/a.txt'))->toBe('a');
    expect($mountedFs->exists('/dir'))->toBeFalse();
});

test('mv across mounts moves a directory tree and keeps mtimes', function (): void {
    $mountedFs = new InMemoryFs(['/dir/a.txt' => 'a']);
    $mountedFs->utimes('/dir/a.txt', 1000);

    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->mv('/mnt/dir', '/home/dir');

    expect($this->defaultFs->readFile('/home/dir/a.txt'))->toBe('a');
    expect($this->defaultFs->stat('/home/dir/a.txt')->mtime)->toBe(1000);
    expect($mountedFs->exists('/dir'))->toBeFalse();
});

test('createExclusive is routed to the mounted filesystem', function (): void {
    $mountedFs = new InMemoryFs(['/taken' => 'x']);
    $this->mountableFs->mount('/mnt', $mountedFs);

    $this->mountableFs->createExclusive('/mnt/new', true);

    expect($mountedFs->stat('/new'))->toMatchObject(['isDirectory' => true, 'mode' => 0700])
        ->and($this->defaultFs->exists('/mnt/new'))->toBeFalse()
        ->and(fn () => $this->mountableFs->createExclusive('/mnt/taken'))->toThrow(RuntimeException::class, "EEXIST: file already exists, open '/taken'");
});
