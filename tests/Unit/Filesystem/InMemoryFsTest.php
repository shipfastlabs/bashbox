<?php

declare(strict_types=1);

use BashBox\Filesystem\InMemoryFs;

beforeEach(function (): void {
    $this->fs = new InMemoryFs;
});

test('write and read file', function (): void {
    $this->fs->writeFile('/test.txt', 'hello world');
    expect($this->fs->readFile('/test.txt'))->toBe('hello world');
});

test('overwrite file', function (): void {
    $this->fs->writeFile('/test.txt', 'first');
    $this->fs->writeFile('/test.txt', 'second');

    expect($this->fs->readFile('/test.txt'))->toBe('second');
});

test('append to file', function (): void {
    $this->fs->writeFile('/test.txt', 'hello');
    $this->fs->appendFile('/test.txt', ' world');

    expect($this->fs->readFile('/test.txt'))->toBe('hello world');
});

test('append creates file if not exists', function (): void {
    $this->fs->appendFile('/new.txt', 'content');
    expect($this->fs->readFile('/new.txt'))->toBe('content');
});

test('read nonexistent file throws', function (): void {
    expect(fn () => $this->fs->readFile('/nonexistent.txt'))
        ->toThrow(RuntimeException::class);
});

test('exists returns true for file', function (): void {
    $this->fs->writeFile('/test.txt', 'hello');
    expect($this->fs->exists('/test.txt'))->toBeTrue();
});

test('exists returns false for nonexistent', function (): void {
    expect($this->fs->exists('/nonexistent.txt'))->toBeFalse();
});

test('exists returns true for directory', function (): void {
    $this->fs->mkdir('/mydir');
    expect($this->fs->exists('/mydir'))->toBeTrue();
});

test('stat returns file info', function (): void {
    $this->fs->writeFile('/test.txt', 'hello');
    $stat = $this->fs->stat('/test.txt');

    expect($stat->isFile)->toBeTrue();
    expect($stat->isDirectory)->toBeFalse();
    expect($stat->size)->toBe(5);
});

test('stat returns dir info', function (): void {
    $this->fs->mkdir('/mydir');
    $stat = $this->fs->stat('/mydir');

    expect($stat->isFile)->toBeFalse();
    expect($stat->isDirectory)->toBeTrue();
});

test('mkdir creates directory', function (): void {
    $this->fs->mkdir('/mydir');
    expect($this->fs->exists('/mydir'))->toBeTrue();
    expect($this->fs->stat('/mydir')->isDirectory)->toBeTrue();
});

test('mkdir recursive creates parents', function (): void {
    $this->fs->mkdir('/a/b/c', ['recursive' => true]);
    expect($this->fs->exists('/a'))->toBeTrue();
    expect($this->fs->exists('/a/b'))->toBeTrue();
    expect($this->fs->exists('/a/b/c'))->toBeTrue();
});

test('mkdir without recursive fails if parent missing', function (): void {
    expect(fn () => $this->fs->mkdir('/a/b/c'))
        ->toThrow(RuntimeException::class);
});

test('readdir lists entries', function (): void {
    $this->fs->writeFile('/dir/a.txt', 'a');
    $this->fs->writeFile('/dir/b.txt', 'b');
    $this->fs->mkdir('/dir/sub');

    $entries = $this->fs->readdir('/dir');
    sort($entries);

    expect($entries)->toBe(['a.txt', 'b.txt', 'sub']);
});

test('rm removes file', function (): void {
    $this->fs->writeFile('/test.txt', 'hello');
    $this->fs->rm('/test.txt');

    expect($this->fs->exists('/test.txt'))->toBeFalse();
});

test('rm recursive removes directory', function (): void {
    $this->fs->writeFile('/dir/a.txt', 'a');
    $this->fs->writeFile('/dir/b.txt', 'b');
    $this->fs->rm('/dir', ['recursive' => true]);

    expect($this->fs->exists('/dir'))->toBeFalse();
});

test('cp copies file', function (): void {
    $this->fs->writeFile('/src.txt', 'content');
    $this->fs->cp('/src.txt', '/dst.txt');

    expect($this->fs->readFile('/dst.txt'))->toBe('content');
    expect($this->fs->readFile('/src.txt'))->toBe('content');
});

test('mv moves file', function (): void {
    $this->fs->writeFile('/src.txt', 'content');
    $this->fs->mv('/src.txt', '/dst.txt');

    expect($this->fs->readFile('/dst.txt'))->toBe('content');
    expect($this->fs->exists('/src.txt'))->toBeFalse();
});

test('touch creates empty file', function (): void {
    $this->fs->writeFile('/touch.txt', '');
    expect($this->fs->exists('/touch.txt'))->toBeTrue();
    expect($this->fs->readFile('/touch.txt'))->toBe('');
});

test('resolvePath resolves relative paths', function (): void {
    expect($this->fs->resolvePath('/home/user', 'file.txt'))->toBe('/home/user/file.txt');
    expect($this->fs->resolvePath('/home/user', '../file.txt'))->toBe('/home/file.txt');
    expect($this->fs->resolvePath('/home/user', './file.txt'))->toBe('/home/user/file.txt');
});

test('null byte in path rejected', function (): void {
    expect(fn () => $this->fs->writeFile("/test\0.txt", 'content'))
        ->toThrow(RuntimeException::class);
});

test('initial files populated on creation', function (): void {
    $fs = new InMemoryFs(['/config.txt' => 'key=value', '/data/file.csv' => 'a,b,c']);
    expect($fs->readFile('/config.txt'))->toBe('key=value');
    expect($fs->readFile('/data/file.csv'))->toBe('a,b,c');
});

test('readdirWithFileTypes returns typed entries', function (): void {
    $this->fs->writeFile('/dir/file.txt', 'hello');
    $this->fs->mkdir('/dir/subdir');

    $entries = $this->fs->readdirWithFileTypes('/dir');

    $fileEntry = null;
    $dirEntry = null;
    foreach ($entries as $entry) {
        if ($entry->name === 'file.txt') {
            $fileEntry = $entry;
        }

        if ($entry->name === 'subdir') {
            $dirEntry = $entry;
        }
    }

    expect($fileEntry)->not->toBeNull();
    expect($fileEntry->isFile)->toBeTrue();
    expect($fileEntry->isDirectory)->toBeFalse();

    expect($dirEntry)->not->toBeNull();
    expect($dirEntry->isDirectory)->toBeTrue();
    expect($dirEntry->isFile)->toBeFalse();
});
