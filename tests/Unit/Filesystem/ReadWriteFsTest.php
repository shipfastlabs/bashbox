<?php

declare(strict_types=1);

use BashBox\Filesystem\ReadWriteFs;

beforeEach(function (): void {
    $this->tmpDir = sys_get_temp_dir().'/read_write_fs_test_'.uniqid();
    mkdir($this->tmpDir, 0755, true);
    $this->fs = new ReadWriteFs($this->tmpDir);
});

afterEach(function (): void {
    if (! is_dir($this->tmpDir)) {
        return;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iter as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($this->tmpDir);
});

test('constructor rejects missing root directory', function (): void {
    new ReadWriteFs('/definitely/does/not/exist');
})->throws(RuntimeException::class, 'ReadWriteFs root directory does not exist');

test('writeFile persists content to disk and readFile returns it', function (): void {
    $this->fs->writeFile('/docs/note.txt', 'hello');

    expect($this->fs->readFile('/docs/note.txt'))->toBe('hello');
    expect(file_get_contents($this->tmpDir.'/docs/note.txt'))->toBe('hello');
});

test('appendFile appends to existing file', function (): void {
    file_put_contents($this->tmpDir.'/log.txt', 'line1');

    $this->fs->appendFile('/log.txt', "\nline2");

    expect($this->fs->readFile('/log.txt'))->toBe("line1\nline2");
});

test('mkdir creates directories and readdir returns sorted entries', function (): void {
    $this->fs->mkdir('/data/nested', ['recursive' => true]);
    $this->fs->writeFile('/data/b.txt', 'b');
    $this->fs->writeFile('/data/a.txt', 'a');

    expect($this->fs->readdir('/data'))->toBe(['a.txt', 'b.txt', 'nested']);
});

test('readdirWithFileTypes reports file and directory entries', function (): void {
    $this->fs->mkdir('/data/subdir', ['recursive' => true]);
    $this->fs->writeFile('/data/file.txt', 'data');

    $entries = $this->fs->readdirWithFileTypes('/data');
    $map = [];

    foreach ($entries as $entry) {
        $map[$entry->name] = $entry;
    }

    expect($map['file.txt']->isFile)->toBeTrue();
    expect($map['file.txt']->isDirectory)->toBeFalse();
    expect($map['subdir']->isDirectory)->toBeTrue();
    expect($map['subdir']->isFile)->toBeFalse();
});

test('cp copies files and mv relocates them', function (): void {
    $this->fs->writeFile('/source.txt', 'copy me');

    $this->fs->cp('/source.txt', '/copies/destination.txt');
    $this->fs->mv('/copies/destination.txt', '/moved/final.txt');

    expect($this->fs->readFile('/source.txt'))->toBe('copy me');
    expect($this->fs->readFile('/moved/final.txt'))->toBe('copy me');
    expect($this->fs->exists('/copies/destination.txt'))->toBeFalse();
});

test('rm recursive removes directories', function (): void {
    $this->fs->writeFile('/tree/branch/leaf.txt', 'gone');

    $this->fs->rm('/tree', ['recursive' => true]);

    expect($this->fs->exists('/tree'))->toBeFalse();
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

    expect($this->fs->lstat('/link.txt')->isSymbolicLink)->toBeTrue();
    expect($this->fs->readlink('/link.txt'))->toBe('/target.txt');
    expect($this->fs->realpath('/link.txt'))->toBe('/target.txt');
});

test('hard links share file content', function (): void {
    $this->fs->writeFile('/source.txt', 'shared');

    $this->fs->link('/source.txt', '/linked.txt');

    expect($this->fs->readFile('/linked.txt'))->toBe('shared');
});

test('utimes updates modification time', function (): void {
    $this->fs->writeFile('/timestamp.txt', 'time');
    $mtime = time() - 3600;

    $this->fs->utimes('/timestamp.txt', $mtime);

    expect($this->fs->stat('/timestamp.txt')->mtime)->toBe($mtime);
});

test('null bytes are rejected and traversal outside the root is denied', function (): void {
    expect($this->fs->exists("/bad\0"))->toBeFalse();

    $this->fs->writeFile("/bad\0", 'fail');
})->throws(RuntimeException::class, 'null byte');

test('realpath rejects paths outside the root', function (): void {
    symlink('/etc/passwd', $this->tmpDir.'/outside-link');

    $this->fs->realpath('/outside-link');
})->throws(RuntimeException::class, 'path traversal denied');
