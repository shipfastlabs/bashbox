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

// ─── Mounting and Unmounting ────────────────────────────────────────────

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

// ─── Longest-Prefix Routing ────────────────────────────────────────────

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

// ─── Directory Listing with Mount Points ────────────────────────────────

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

    expect($usbEntry)->not->toBeNull();
    expect($usbEntry->isDirectory)->toBeTrue();
    expect($usbEntry->isFile)->toBeFalse();
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

// ─── Operations Go to Correct Filesystem Backend ────────────────────────

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

// ─── Cross-Filesystem Copies ────────────────────────────────────────────

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
