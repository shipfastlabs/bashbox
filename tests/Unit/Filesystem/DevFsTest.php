<?php

declare(strict_types=1);

use BashBox\Filesystem\DevFs;
use BashBox\Filesystem\DirentEntry;
use BashBox\Filesystem\DiskQuota;
use BashBox\Filesystem\InMemoryFs;

beforeEach(function (): void {
    $this->inner = new InMemoryFs(['/home/f' => 'data']);
    $this->fs = new DevFs($this->inner);
});

test('/dev/null reads empty and discards writes without touching the wrapped filesystem', function (): void {
    $this->fs->writeFile('/dev/null', 'x');
    $this->fs->appendFile('/dev/../dev/null', 'y');
    $this->fs->cp('/home/f', '/dev/null');
    $this->fs->utimes('/dev/null', 5);

    expect($this->fs->readFile('/dev/null'))->toBe('')
        ->and($this->fs->exists('/dev/null'))->toBeTrue()
        ->and($this->fs->realpath('/dev/null'))->toBe('/dev/null')
        ->and($this->inner->exists('/dev'))->toBeFalse();
});

test('copying /dev/null empties the destination', function (): void {
    $this->fs->cp('/dev/null', '/home/f');

    expect($this->fs->readFile('/home/f'))->toBe('');
});

test('/dev/null stats as a world-writable device', function (string $method): void {
    $stat = $this->fs->{$method}('/dev/null');

    expect([$stat->isFile, $stat->isDirectory, $stat->isSymbolicLink, $stat->mode, $stat->size])->toBe([false, false, false, 0o666, 0]);
})->with(['stat', 'lstat']);

test('/dev/null and /dev cannot be replaced, removed or changed', function (string $method, array $args, string $error): void {
    expect(fn () => $this->fs->{$method}(...$args))->toThrow(RuntimeException::class, $error);
})->with([
    ['createExclusive', ['/dev/null'], "EEXIST: file already exists, open '/dev/null'"],
    ['createExclusive', ['/dev/null', true], "EEXIST: file already exists, mkdir '/dev/null'"],
    ['mkdir', ['/dev/null', ['recursive' => true]], "EEXIST: file already exists, mkdir '/dev/null'"],
    ['mkdir', ['/dev'], "EEXIST: file already exists, mkdir '/dev'"],
    ['rm', ['/dev/null', ['force' => true]], "EACCES: permission denied, rm '/dev/null'"],
    ['rm', ['/dev', ['recursive' => true]], "EACCES: permission denied, rm '/dev'"],
    ['mv', ['/home/f', '/dev/null'], "EACCES: permission denied, rename '/dev/null'"],
    ['mv', ['/dev/null', '/home/g'], "EACCES: permission denied, rename '/dev/null'"],
    ['mv', ['/dev', '/home/g'], "EACCES: permission denied, rename '/dev'"],
    ['chmod', ['/dev/null', 0o600], "EPERM: operation not permitted, chmod '/dev/null'"],
    ['symlink', ['/home/f', '/dev/null'], "EEXIST: file already exists, symlink '/dev/null'"],
    ['link', ['/dev/null', '/home/g'], "EPERM: operation not permitted, link '/dev/null'"],
    ['link', ['/home/f', '/dev/null'], "EEXIST: file already exists, link '/dev/null'"],
    ['link', ['/home/f', '/dev/f'], "EXDEV: cross-device link not permitted, link '/home/f' -> '/dev/f'"],
    ['readlink', ['/dev/null'], "EINVAL: invalid argument, readlink '/dev/null'"],
    ['readdir', ['/dev/null'], "ENOTDIR: not a directory, scandir '/dev/null'"],
]);

test('paths through /dev/null are ENOTDIR', function (string $method, array $args): void {
    expect(fn () => $this->fs->{$method}(...$args))->toThrow(RuntimeException::class, 'ENOTDIR: not a directory')
        ->and($this->fs->exists('/dev/null/x'))->toBeFalse();
})->with([
    ['readFile', ['/dev/null/x']],
    ['writeFile', ['/dev/null/x', '']],
    ['stat', ['/dev/null/x/y']],
    ['mkdir', ['/dev/null/x']],
]);

test('everything under /dev lives in memory, never in the wrapped filesystem', function (): void {
    $this->inner->writeFile('/dev/hidden', 'wrapped');
    $this->fs->mkdir('/dev/fd', ['recursive' => true]);
    $this->fs->writeFile('/dev/fd/63', 'a');
    $this->fs->appendFile('/dev/fd/63', 'b');
    $this->fs->createExclusive('/dev/fd/62');
    $this->fs->chmod('/dev/fd', 0o700);
    $this->fs->utimes('/dev/fd/63', 9);

    expect($this->fs->readFile('/dev/fd/63'))->toBe('ab')
        ->and($this->fs->stat('/dev/fd')->mode)->toBe(0o700)
        ->and($this->fs->lstat('/dev/fd/63')->mtime)->toBe(9)
        ->and($this->fs->readdir('/dev'))->toBe(['fd', 'null'])
        ->and($this->fs->readdirWithFileTypes('/dev/'))->toContainEqual(new DirentEntry('null', false, false, false))
        ->and($this->fs->readdir('/dev/fd'))->toBe(['62', '63'])
        ->and($this->fs->readdir('/home'))->toBe(['f'])
        ->and($this->fs->exists('/dev/hidden'))->toBeFalse()
        ->and($this->fs->getAllPaths())->toBe(['/', '/home', '/home/f', '/dev', '/dev/fd', '/dev/fd/63', '/dev/fd/62', '/dev/null'])
        ->and($this->inner->readdir('/dev'))->toBe(['hidden']);

    $this->fs->rm('/dev/fd', ['recursive' => true]);

    expect($this->fs->exists('/dev/fd'))->toBeFalse()
        ->and($this->fs->readdir('/dev'))->toBe(['null']);
});

test('a symlink to /dev/null behaves as /dev/null', function (): void {
    $this->fs->symlink('/dev/null', '/home/n');
    $this->fs->symlink('../dev/./null', '/home/rel');
    $this->fs->writeFile('/home/n', 'x');
    $this->fs->appendFile('/home/rel', 'y');

    expect($this->fs->readFile('/home/n'))->toBe('')
        ->and($this->fs->stat('/home/rel')->mode)->toBe(0o666)
        ->and($this->fs->lstat('/home/n')->isSymbolicLink)->toBeTrue()
        ->and($this->fs->readlink('/home/n'))->toBe('/dev/null')
        ->and($this->fs->realpath('/home/rel'))->toBe('/dev/null')
        ->and($this->fs->exists('/home/n'))->toBeTrue()
        ->and($this->inner->exists('/dev'))->toBeFalse();
});

test('symlinks are followed between /dev and the wrapped filesystem', function (): void {
    $this->fs->writeFile('/dev/fd/63', 'piped');
    $this->fs->symlink('/dev/fd', '/home/fd');
    $this->fs->symlink('/home', '/dev/home');

    expect($this->fs->readFile('/home/fd/63'))->toBe('piped')
        ->and($this->fs->readFile('/dev/home/f'))->toBe('data')
        ->and($this->fs->realpath('/dev/home/fd/63'))->toBe('/dev/fd/63')
        ->and($this->fs->realpath('/dev/home/f'))->toBe('/home/f');

    $this->fs->symlink('/dev/loop', '/home/loop');
    $this->fs->symlink('/home/loop', '/dev/loop');

    expect(fn () => $this->fs->stat('/home/loop'))->toThrow(RuntimeException::class, "ELOOP: too many levels of symbolic links, stat '/home/loop'")
        ->and($this->fs->exists('/home/loop'))->toBeFalse();
});

test('files and trees are copied and moved across /dev', function (): void {
    $this->fs->writeFile('/dev/fd/63', 'x');
    $this->fs->chmod('/dev/fd/63', 0o600);
    $this->fs->utimes('/dev/fd/63', 7);
    $this->fs->cp('/dev/fd/63', '/home/plain');
    $this->fs->cp('/dev/fd/63', '/home/kept', ['preserve' => true]);
    $this->fs->cp('/dev/fd', '/home/tree', ['recursive' => true]);
    $this->fs->cp('/home/f', '/dev/f');
    $this->fs->cp('/dev/f', '/dev/g');
    $this->fs->mv('/dev/g', '/dev/h');
    $this->fs->mv('/home/tree', '/dev/tree');

    expect($this->inner->readFile('/home/plain'))->toBe('x')
        ->and($this->inner->stat('/home/plain')->mode)->toBe(0o644)
        ->and($this->inner->stat('/home/kept')->mode)->toBe(0o600)
        ->and($this->inner->stat('/home/kept')->mtime)->toBe(7)
        ->and($this->fs->readFile('/dev/h'))->toBe('data')
        ->and($this->fs->readFile('/dev/tree/63'))->toBe('x')
        ->and($this->fs->exists('/dev/g'))->toBeFalse()
        ->and($this->inner->exists('/home/tree'))->toBeFalse()
        ->and(fn () => $this->fs->cp('/dev/fd', '/home/dir'))->toThrow(RuntimeException::class, "EISDIR: is a directory, cp '/dev/fd'");
});

test('/dev shares the quota it is given', function (): void {
    $fs = new DevFs(new InMemoryFs, new DiskQuota(maxBytes: 100));

    expect(fn () => $fs->writeFile('/dev/fd/63', str_repeat('x', 100)))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/dev/fd/63'");
});

test('other paths reach the wrapped filesystem', function (): void {
    $this->fs->writeFile('/home/g', 'g');
    $this->fs->appendFile('/home/g', 'h');
    $this->fs->cp('/home/g', '/home/c');
    $this->fs->mv('/home/c', '/home/d');
    $this->fs->symlink('/home/d', '/home/l');
    $this->fs->link('/home/d', '/home/hard');
    $this->fs->chmod('/home/d', 0o600);
    $this->fs->utimes('/home/d', 7);
    $this->fs->createExclusive('/home/x');
    $this->fs->mkdir('/home/sub');
    $this->fs->rm('/home/sub');

    expect($this->inner->readFile('/home/d'))->toBe('gh')
        ->and($this->fs->readFile('/home/hard'))->toBe('gh')
        ->and($this->fs->readlink('/home/l'))->toBe('/home/d')
        ->and($this->fs->realpath('/home/l'))->toBe('/home/d')
        ->and($this->fs->stat('/home/l')->mtime)->toBe(7)
        ->and($this->fs->lstat('/home/l')->isSymbolicLink)->toBeTrue()
        ->and($this->fs->stat('/home/d')->mode)->toBe(0o600)
        ->and($this->fs->exists('/home/x'))->toBeTrue()
        ->and($this->fs->exists('/home/sub'))->toBeFalse()
        ->and($this->fs->resolvePath('/home', '../dev/./null'))->toBe('/dev/null')
        ->and(fn () => $this->fs->readFile('/home/missing'))->toThrow(RuntimeException::class, "ENOENT: no such file or directory, open '/home/missing'");
});
