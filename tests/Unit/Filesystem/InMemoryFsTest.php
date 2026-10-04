<?php

declare(strict_types=1);

use BashBox\Filesystem\DiskQuota;
use BashBox\Filesystem\FsStat;
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

test('operations on a missing path fail with ENOENT naming the operation', function (Closure $operation, string $message): void {
    expect(fn () => $operation($this->fs))->toThrow(RuntimeException::class, $message);
})->with([
    'readFile' => [fn (InMemoryFs $inMemoryFs): string => $inMemoryFs->readFile('/missing'), "ENOENT: no such file or directory, open '/missing'"],
    'stat' => [fn (InMemoryFs $inMemoryFs): FsStat => $inMemoryFs->stat('/missing'), "ENOENT: no such file or directory, stat '/missing'"],
    'lstat' => [fn (InMemoryFs $inMemoryFs): FsStat => $inMemoryFs->lstat('/missing'), "ENOENT: no such file or directory, lstat '/missing'"],
    'readdir' => [fn (InMemoryFs $inMemoryFs): array => $inMemoryFs->readdir('/missing'), "ENOENT: no such file or directory, scandir '/missing'"],
    'rm' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->rm('/missing'), "ENOENT: no such file or directory, rm '/missing'"],
    'cp' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->cp('/missing', '/dest'), "ENOENT: no such file or directory, cp '/missing'"],
    'chmod' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->chmod('/missing', 0600), "ENOENT: no such file or directory, chmod '/missing'"],
    'link' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->link('/missing', '/new'), "ENOENT: no such file or directory, link '/missing'"],
    'readlink' => [fn (InMemoryFs $inMemoryFs): string => $inMemoryFs->readlink('/missing'), "ENOENT: no such file or directory, readlink '/missing'"],
    'realpath' => [fn (InMemoryFs $inMemoryFs): string => $inMemoryFs->realpath('/missing'), "ENOENT: no such file or directory, realpath '/missing'"],
    'utimes' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->utimes('/missing', 1), "ENOENT: no such file or directory, utimes '/missing'"],
    'null byte' => [fn (InMemoryFs $inMemoryFs): FsStat => $inMemoryFs->stat("/a\0b"), 'ENOENT: path contains null byte, stat'],
]);

test('file operations on a directory fail with EISDIR', function (Closure $operation, string $message): void {
    $this->fs->mkdir('/dir');

    expect(fn () => $operation($this->fs))->toThrow(RuntimeException::class, $message);
})->with([
    'readFile' => [fn (InMemoryFs $inMemoryFs): string => $inMemoryFs->readFile('/dir'), "EISDIR: illegal operation on a directory, read '/dir'"],
    'writeFile' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->writeFile('/dir', 'x'), "EISDIR: illegal operation on a directory, open '/dir'"],
    'appendFile' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->appendFile('/dir', 'x'), "EISDIR: illegal operation on a directory, open '/dir'"],
    'cp without recursive' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->cp('/dir', '/copy'), "EISDIR: is a directory, cp '/dir'"],
    'cp file over directory' => [function (InMemoryFs $inMemoryFs): void {
        $inMemoryFs->writeFile('/file', 'x');
        $inMemoryFs->cp('/file', '/dir');
    }, "EISDIR: cannot overwrite directory with non-directory, cp '/dir'"],
]);

test('creating entries below a regular file fails with ENOTDIR', function (Closure $operation, string $message): void {
    $this->fs->writeFile('/file', 'x');

    expect(fn () => $operation($this->fs))->toThrow(RuntimeException::class, $message);
    expect($this->fs->getAllPaths())->toBe(['/', '/file']);
})->with([
    'writeFile' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->writeFile('/file/child', 'x'), "ENOTDIR: not a directory, open '/file/child'"],
    'mkdir' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->mkdir('/file/child'), "ENOTDIR: not a directory, mkdir '/file/child'"],
    'mkdir recursive' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->mkdir('/file/a/b', ['recursive' => true]), "ENOTDIR: not a directory, mkdir '/file/a/b'"],
    'symlink' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->symlink('/x', '/file/link'), "ENOTDIR: not a directory, symlink '/file/link'"],
    'readdir' => [fn (InMemoryFs $inMemoryFs): array => $inMemoryFs->readdir('/file'), "ENOTDIR: not a directory, scandir '/file'"],
]);

test('creating over an existing entry fails with EEXIST', function (Closure $operation, string $message): void {
    $this->fs->writeFile('/file', 'x');
    $this->fs->mkdir('/dir');

    expect(fn () => $operation($this->fs))->toThrow(RuntimeException::class, $message);
})->with([
    'mkdir existing dir' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->mkdir('/dir'), "EEXIST: file already exists, mkdir '/dir'"],
    'mkdir -p existing file' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->mkdir('/file', ['recursive' => true]), "EEXIST: file already exists, mkdir '/file'"],
    'symlink' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->symlink('/x', '/file'), "EEXIST: file already exists, symlink '/file'"],
    'link' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->link('/file', '/dir'), "EEXIST: file already exists, link '/dir'"],
]);

test('mkdir recursive on an existing directory keeps its contents', function (): void {
    $this->fs->writeFile('/dir/keep.txt', 'kept');
    $this->fs->mkdir('/dir', ['recursive' => true]);

    expect($this->fs->readFile('/dir/keep.txt'))->toBe('kept');
});

test('writeFile keeps the mode of an existing file', function (): void {
    $this->fs->writeFile('/script.sh', 'v1');
    $this->fs->chmod('/script.sh', 0755);
    $this->fs->writeFile('/script.sh', 'v2');
    $this->fs->appendFile('/script.sh', '+');

    expect($this->fs->stat('/script.sh')->mode)->toBe(0755);
    expect($this->fs->readFile('/script.sh'))->toBe('v2+');
});

test('readdir lists root entries sorted and typed, without descendants', function (): void {
    $this->fs->writeFile('/b/nested.txt', '');
    $this->fs->writeFile('/a.txt', '');
    $this->fs->symlink('/a.txt', '/c');

    $entries = $this->fs->readdirWithFileTypes('/');

    expect(array_map(fn ($e): array => [$e->name, $e->isFile, $e->isDirectory, $e->isSymbolicLink], $entries))->toBe([
        ['a.txt', true, false, false],
        ['b', false, true, false],
        ['c', false, false, true],
    ]);
});

test('rm', function (Closure $setup, string $path, array $options, array $remaining): void {
    $this->fs->writeFile('/dir/a.txt', 'a');
    $this->fs->writeFile('/dir2/b.txt', 'b');
    $setup($this->fs);
    $this->fs->rm($path, $options);

    expect($this->fs->getAllPaths())->toEqualCanonicalizing($remaining);
})->with([
    'recursive leaves sibling sharing the name prefix' => [fn (): null => null, '/dir', ['recursive' => true], ['/', '/dir2', '/dir2/b.txt']],
    'empty directory without recursive' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->mkdir('/empty'), '/empty', [], ['/', '/dir', '/dir/a.txt', '/dir2', '/dir2/b.txt']],
    'missing path with force' => [fn (): null => null, '/missing', ['force' => true], ['/', '/dir', '/dir/a.txt', '/dir2', '/dir2/b.txt']],
    'symlink to directory removes only the link' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->symlink('/dir', '/link'), '/link', [], ['/', '/dir', '/dir/a.txt', '/dir2', '/dir2/b.txt']],
]);

test('rm refuses non-empty directories without recursive and the root', function (string $path, array $options, string $message): void {
    $this->fs->writeFile('/dir/a.txt', 'a');

    expect(fn () => $this->fs->rm($path, $options))->toThrow(RuntimeException::class, $message);
    expect($this->fs->readFile('/dir/a.txt'))->toBe('a');
})->with([
    ['/dir', [], "ENOTEMPTY: directory not empty, rm '/dir'"],
    ['/', ['recursive' => true, 'force' => true], "EPERM: operation not permitted, rm '/'"],
]);

test('cp recursive copies a directory tree', function (): void {
    $this->fs->writeFile('/src/a.txt', 'a');
    $this->fs->writeFile('/src/sub/b.txt', 'b');
    $this->fs->symlink('a.txt', '/src/link');

    $this->fs->cp('/src', '/dest', ['recursive' => true]);

    expect($this->fs->readdir('/dest'))->toBe(['a.txt', 'link', 'sub']);
    expect($this->fs->readFile('/dest/sub/b.txt'))->toBe('b');
    expect($this->fs->readlink('/dest/link'))->toBe('a.txt');
    expect($this->fs->readFile('/dest/link'))->toBe('a');
});

test('cp and mv refuse to copy a directory into itself', function (Closure $operation, string $message): void {
    $this->fs->writeFile('/src/a.txt', 'a');

    expect(fn () => $operation($this->fs))->toThrow(RuntimeException::class, $message);
    expect($this->fs->readFile('/src/a.txt'))->toBe('a');
})->with([
    'cp' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->cp('/src', '/src/sub', ['recursive' => true]), "EINVAL: cannot copy a directory into itself, cp '/src'"],
    'mv' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->mv('/src', '/src/sub'), "EINVAL: invalid argument, rename '/src'"],
    'mv the root' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->mv('/', '/elsewhere'), "EINVAL: invalid argument, rename '/'"],
]);

test('cp sets a fresh mtime unless preserve is requested', function (bool $preserve, bool $keepsMtime): void {
    $this->fs->writeFile('/src.txt', 'x');
    $this->fs->utimes('/src.txt', 1000);

    $this->fs->cp('/src.txt', '/dest.txt', ['preserve' => $preserve]);

    expect($this->fs->stat('/dest.txt')->mtime === 1000)->toBe($keepsMtime);
})->with([
    'default' => [false, false],
    'preserve' => [true, true],
]);

test('mv moves a directory tree', function (): void {
    $this->fs->writeFile('/src/sub/a.txt', 'a');
    $this->fs->mv('/src', '/dest');

    expect($this->fs->readFile('/dest/sub/a.txt'))->toBe('a');
    expect($this->fs->exists('/src'))->toBeFalse();
});

test('mv onto the same path keeps the file', function (): void {
    $this->fs->writeFile('/file.txt', 'data');
    $this->fs->mv('/file.txt', '/./file.txt');

    expect($this->fs->readFile('/file.txt'))->toBe('data');
});

test('resolvePath returns absolute paths normalized regardless of base', function (): void {
    expect($this->fs->resolvePath('/home', '/etc/../var//log/'))->toBe('/var/log');
});

test('symlinks are followed by reads, writes, stat, chmod and utimes', function (): void {
    $this->fs->writeFile('/real/file.txt', 'hello');
    $this->fs->symlink('/real', '/dirlink');
    $this->fs->symlink('real/file.txt', '/filelink');

    $this->fs->appendFile('/filelink', '!');
    $this->fs->writeFile('/dirlink/new.txt', 'via link');
    $this->fs->chmod('/filelink', 0600);
    $this->fs->utimes('/filelink', 1234);

    expect($this->fs->readFile('/real/file.txt'))->toBe('hello!');
    expect($this->fs->readFile('/real/new.txt'))->toBe('via link');
    expect($this->fs->readdir('/dirlink'))->toBe(['file.txt', 'new.txt']);
    expect($this->fs->stat('/filelink'))->toMatchObject(['isFile' => true, 'isSymbolicLink' => false, 'mode' => 0600, 'mtime' => 1234, 'size' => 6]);
    expect($this->fs->lstat('/filelink'))->toMatchObject(['isFile' => false, 'isSymbolicLink' => true, 'mode' => 0777, 'size' => 13]);
    expect($this->fs->lstat('/dirlink/file.txt')->isFile)->toBeTrue();
    expect($this->fs->realpath('/dirlink/file.txt'))->toBe('/real/file.txt');
});

test('symlink targets resolve relative to the link and through other symlinks', function (string $target, string $expected): void {
    $this->fs->writeFile('/data/file.txt', 'content');
    $this->fs->symlink('/data', '/alias');
    $this->fs->mkdir('/links');
    $this->fs->symlink($target, '/links/link');

    expect($this->fs->realpath('/links/link'))->toBe($expected);
    expect($this->fs->readFile('/links/link'))->toBe('content');
})->with([
    'absolute' => ['/data/file.txt', '/data/file.txt'],
    'relative with ..' => ['../data/file.txt', '/data/file.txt'],
    'through an intermediate symlink' => ['/alias/file.txt', '/data/file.txt'],
    'relative through an intermediate symlink' => ['../alias/./file.txt', '/data/file.txt'],
]);

test('revisiting a symlink while walking a path is not a loop', function (): void {
    $this->fs->writeFile('/d/file.txt', 'ok');
    $this->fs->symlink('/d', '/l');
    $this->fs->symlink('/', '/d/up');

    expect($this->fs->readFile('/l/up/l/file.txt'))->toBe('ok');
});

test('dangling symlink exists only for lstat and readlink', function (): void {
    $this->fs->symlink('/nowhere', '/dangling');

    expect($this->fs->exists('/dangling'))->toBeFalse();
    expect($this->fs->lstat('/dangling')->isSymbolicLink)->toBeTrue();
    expect($this->fs->readlink('/dangling'))->toBe('/nowhere');
    expect(fn () => $this->fs->stat('/dangling'))->toThrow(RuntimeException::class, 'ENOENT');
});

test('symlink cycles fail with ELOOP', function (Closure $operation, string $message): void {
    $this->fs->symlink('/b', '/a');
    $this->fs->symlink('/a', '/b');

    expect(fn () => $operation($this->fs))->toThrow(RuntimeException::class, $message);
    expect($this->fs->exists('/a'))->toBeFalse();
})->with([
    [fn (InMemoryFs $inMemoryFs): string => $inMemoryFs->readFile('/a'), "ELOOP: too many levels of symbolic links, open '/a'"],
    [fn (InMemoryFs $inMemoryFs): FsStat => $inMemoryFs->lstat('/a/x'), "ELOOP: too many levels of symbolic links, lstat '/a/x'"],
]);

test('symlink chains resolve up to 40 hops', function (int $links, bool $resolves): void {
    $this->fs->writeFile('/target', 'end');
    $this->fs->symlink('/target', '/l0');

    for ($i = 1; $i < $links; $i++) {
        $this->fs->symlink('/l'.($i - 1), '/l'.$i);
    }

    expect($this->fs->exists('/l'.($links - 1)))->toBe($resolves);
})->with([
    '40 links' => [40, true],
    '41 links' => [41, false],
]);

test('link creates a second name for a file but not for a directory', function (): void {
    $this->fs->writeFile('/file', 'data');
    $this->fs->mkdir('/dir');
    $this->fs->link('/file', '/sub/hard');

    expect($this->fs->readFile('/sub/hard'))->toBe('data');
    expect(fn () => $this->fs->link('/dir', '/dirlink'))->toThrow(RuntimeException::class, "EPERM: operation not permitted, link '/dir'");
});

test('readlink on a regular file fails with EINVAL', function (): void {
    $this->fs->writeFile('/file', 'x');

    expect(fn () => $this->fs->readlink('/file'))->toThrow(RuntimeException::class, "EINVAL: invalid argument, readlink '/file'");
});

test('hard links share one inode: writes, mode and mtime show through every name', function (Closure $change, string $content, int $mode, ?int $mtime): void {
    $this->fs->writeFile('/a', 'one');
    $this->fs->chmod('/a', 0640);
    $this->fs->utimes('/a', 100);
    $this->fs->link('/a', '/dir/b');

    $start = time();

    $change($this->fs);

    foreach (['/a', '/dir/b'] as $name) {
        $stat = $this->fs->stat($name);
        expect($this->fs->readFile($name))->toBe($content)
            ->and($stat)->toMatchObject(['mode' => $mode, 'nlink' => 2])
            // A null mtime means the write stamped a fresh one.
            ->and($mtime === null ? $stat->mtime >= $start : $stat->mtime === $mtime)->toBeTrue();
    }

    expect($this->fs->stat('/a')->ino)->toBe($this->fs->stat('/dir/b')->ino);
})->with([
    'write via the new name' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->writeFile('/dir/b', 'two'), 'two', 0640, null],
    'append via the old name' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->appendFile('/a', '+'), 'one+', 0640, null],
    'chmod one name' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->chmod('/dir/b', 0600), 'one', 0600, 100],
    'utimes one name' => [fn (InMemoryFs $inMemoryFs) => $inMemoryFs->utimes('/a', 200), 'one', 0640, 200],
    'cp onto one name' => [function (InMemoryFs $inMemoryFs): void {
        $inMemoryFs->writeFile('/src', 'copied');
        $inMemoryFs->utimes('/src', 300);
        $inMemoryFs->cp('/src', '/dir/b', ['preserve' => true]);
    }, 'copied', 0644, 300],
]);

test('rm drops one name and the file lives on until its last link goes', function (): void {
    $this->fs->writeFile('/a', 'data');
    $this->fs->link('/a', '/b');
    $this->fs->link('/b', '/c');

    expect($this->fs->stat('/a')->nlink)->toBe(3);

    $this->fs->rm('/a');
    $this->fs->writeFile('/b', 'changed');

    expect($this->fs->exists('/a'))->toBeFalse()
        ->and($this->fs->readFile('/c'))->toBe('changed')
        ->and($this->fs->stat('/c')->nlink)->toBe(2);

    $this->fs->rm('/b');
    $this->fs->rm('/c');
    $this->fs->writeFile('/a', 'new');

    expect($this->fs->stat('/a')->nlink)->toBe(1)
        ->and($this->fs->readFile('/a'))->toBe('new');
});

test('rm recursive unlinks every name below, leaving outside hard links intact', function (): void {
    $this->fs->writeFile('/dir/f', 'kept');
    $this->fs->link('/dir/f', '/outside');

    $this->fs->rm('/dir', ['recursive' => true]);

    expect($this->fs->readFile('/outside'))->toBe('kept')
        ->and($this->fs->stat('/outside')->nlink)->toBe(1);
});

test('a hard link to a symlink links the symlink itself', function (): void {
    $this->fs->writeFile('/target', 't');
    $this->fs->symlink('/target', '/sym');

    $this->fs->link('/sym', '/hard');

    expect($this->fs->readlink('/hard'))->toBe('/target')
        ->and($this->fs->lstat('/hard'))->toMatchObject(['isSymbolicLink' => true, 'nlink' => 2, 'ino' => $this->fs->lstat('/sym')->ino])
        ->and($this->fs->stat('/target')->nlink)->toBe(1);
});

test('every entry gets its own inode number and directories count their subdirectories', function (): void {
    $this->fs->mkdir('/d/sub1', ['recursive' => true]);
    $this->fs->mkdir('/d/sub2');
    $this->fs->writeFile('/d/file', 'x');

    $inodes = array_map(fn (string $p): int => $this->fs->lstat($p)->ino, ['/', '/d', '/d/sub1', '/d/sub2', '/d/file']);

    expect(array_unique($inodes))->toHaveCount(5)
        ->and($this->fs->stat('/d')->nlink)->toBe(4)
        ->and($this->fs->stat('/d/sub1')->nlink)->toBe(2)
        ->and($this->fs->stat('/')->nlink)->toBe(3);
});

test('mv renames without copying: the inode and its other links stay shared', function (): void {
    $this->fs->writeFile('/dir/a', 'data');
    $this->fs->link('/dir/a', '/other');

    $dirInode = $this->fs->stat('/dir')->ino;
    $fileInode = $this->fs->stat('/dir/a')->ino;

    $this->fs->mv('/dir', '/new/place');
    $this->fs->writeFile('/new/place/a', 'changed');

    expect($this->fs->stat('/new/place')->ino)->toBe($dirInode)
        ->and($this->fs->stat('/new/place/a')->ino)->toBe($fileInode)
        ->and($this->fs->readFile('/other'))->toBe('changed')
        ->and($this->fs->exists('/dir'))->toBeFalse()
        ->and($this->fs->getAllPaths())->toEqualCanonicalizing(['/', '/other', '/new', '/new/place', '/new/place/a']);
});

test('mv replaces an existing file, which keeps living through its other links', function (): void {
    $this->fs->writeFile('/src', 'new');
    $this->fs->writeFile('/dest', 'old');
    $this->fs->link('/dest', '/dest-link');

    $this->fs->mv('/src', '/dest');

    expect($this->fs->readFile('/dest'))->toBe('new')
        ->and($this->fs->readFile('/dest-link'))->toBe('old')
        ->and($this->fs->stat('/dest-link')->nlink)->toBe(1)
        ->and($this->fs->exists('/src'))->toBeFalse();
});

test('mv moves a symlink, not its target, and a directory onto an empty one', function (): void {
    $this->fs->writeFile('/target', 't');
    $this->fs->symlink('target', '/sym');
    $this->fs->writeFile('/src/f', 'f');
    $this->fs->mkdir('/empty');

    $this->fs->mv('/sym', '/moved');
    $this->fs->mv('/src', '/empty');

    expect($this->fs->readlink('/moved'))->toBe('target')
        ->and($this->fs->readFile('/empty/f'))->toBe('f')
        ->and($this->fs->exists('/src'))->toBeFalse();
});

test('mv between two names of the same file does nothing', function (): void {
    $this->fs->writeFile('/a', 'x');
    $this->fs->link('/a', '/b');

    $this->fs->mv('/a', '/b');

    expect($this->fs->readFile('/a'))->toBe('x')
        ->and($this->fs->stat('/b')->nlink)->toBe(2);
});

test('mv fails like rename(2)', function (string $src, string $dest, string $message): void {
    $this->fs->writeFile('/file', 'f');
    $this->fs->writeFile('/full/x', 'x');
    $this->fs->mkdir('/dir');

    expect(fn () => $this->fs->mv($src, $dest))->toThrow(RuntimeException::class, $message);
    expect($this->fs->getAllPaths())->toEqualCanonicalizing(['/', '/file', '/full', '/full/x', '/dir']);
})->with([
    'missing source' => ['/missing', '/x', "ENOENT: no such file or directory, rename '/missing'"],
    'file onto a directory' => ['/file', '/dir', "EISDIR: illegal operation on a directory, rename '/dir'"],
    'directory onto a file' => ['/dir', '/file', "ENOTDIR: not a directory, rename '/file'"],
    'directory onto a non-empty one' => ['/dir', '/full', "ENOTEMPTY: directory not empty, rename '/full'"],
]);

test('cp onto an existing file rewrites it in place and keeps its mode', function (): void {
    $this->fs->writeFile('/src', 'new');
    $this->fs->chmod('/src', 0755);
    $this->fs->writeFile('/dest', 'old');
    $this->fs->chmod('/dest', 0600);

    $this->fs->cp('/src', '/dest');

    expect($this->fs->readFile('/dest'))->toBe('new')
        ->and($this->fs->stat('/dest')->mode)->toBe(0600);
});

test('cp of a symlink onto an existing file replaces it with the symlink', function (): void {
    $this->fs->writeFile('/target', 't');
    $this->fs->symlink('/target', '/sym');
    $this->fs->writeFile('/dest', 'old');
    $this->fs->link('/dest', '/dest-link');

    $this->fs->cp('/sym', '/dest');

    expect($this->fs->readlink('/dest'))->toBe('/target')
        ->and($this->fs->readFile('/dest-link'))->toBe('old');
});

test('createExclusive makes a private file or directory', function (bool $directory, int $mode): void {
    $this->fs->mkdir('/tmp');

    $this->fs->createExclusive('/tmp/new', $directory);

    expect($this->fs->stat('/tmp/new'))->toMatchObject(['isDirectory' => $directory, 'mode' => $mode, 'size' => 0]);
})->with([
    'file' => [false, 0600],
    'directory' => [true, 0700],
]);

test('createExclusive never reuses or follows an existing entry', function (string $path, bool $directory, string $message): void {
    $this->fs->writeFile('/file', 'keep');
    $this->fs->mkdir('/dir');
    $this->fs->symlink('/nowhere', '/dangling');

    expect(fn () => $this->fs->createExclusive($path, $directory))->toThrow(RuntimeException::class, $message);
    expect($this->fs->readFile('/file'))->toBe('keep')
        ->and($this->fs->exists('/nowhere'))->toBeFalse()
        ->and($this->fs->getAllPaths())->toEqualCanonicalizing(['/', '/file', '/dir', '/dangling']);
})->with([
    'existing file' => ['/file', false, "EEXIST: file already exists, open '/file'"],
    'existing directory' => ['/dir', true, "EEXIST: file already exists, mkdir '/dir'"],
    'dangling symlink' => ['/dangling', false, "EEXIST: file already exists, open '/dangling'"],
    'missing parent' => ['/missing/new', false, "ENOENT: no such file or directory, open '/missing/new'"],
    'parent is a file' => ['/file/new', true, "ENOTDIR: not a directory, mkdir '/file/new'"],
]);

// The root costs 1 byte (its name) and 1 entry, so a quota of N bytes leaves N - 1 for the rest.
test('contents and names count against the byte quota, and rewriting or removing frees them', function (): void {
    $fs = new InMemoryFs([], new DiskQuota(maxBytes: 100));
    $fs->writeFile('/f', str_repeat('x', 90));
    $fs->writeFile('/small', '');

    expect(fn () => $fs->appendFile('/f', 'xxx'))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/f'")
        ->and(fn () => $fs->cp('/f', '/small'))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/small'")
        ->and($fs->readFile('/f'))->toBe(str_repeat('x', 90))
        ->and($fs->readFile('/small'))->toBe('');

    $fs->writeFile('/f', '');
    $fs->writeFile('/g', str_repeat('x', 80));
    $fs->rm('/g');
    $fs->writeFile('/h', str_repeat('x', 80));

    expect($fs->readFile('/h'))->toHaveLength(80);
});

test('a hard link costs only its name until the last one is removed', function (): void {
    $fs = new InMemoryFs([], new DiskQuota(maxBytes: 100));
    $fs->writeFile('/f', str_repeat('x', 90));
    $fs->link('/f', '/l');
    $fs->rm('/f');

    expect(fn () => $fs->writeFile('/g', str_repeat('x', 10)))->toThrow(RuntimeException::class, 'ENOSPC');

    $fs->rm('/l');
    $fs->writeFile('/g', str_repeat('x', 90));

    expect($fs->readFile('/g'))->toHaveLength(90);
});

test('moving a tree to a longer name costs the longer names', function (): void {
    $fs = new InMemoryFs([], new DiskQuota(maxBytes: 20));
    $fs->writeFile('/d/f', '');
    $fs->mv('/d', '/longer');

    expect(fn () => $fs->mv('/longer', '/longerer'))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/longerer'")
        ->and($fs->exists('/longer/f'))->toBeTrue()
        ->and($fs->exists('/longerer'))->toBeFalse();
});

test('every name counts against the entry quota', function (): void {
    $fs = new InMemoryFs(['/a' => ''], new DiskQuota(maxFiles: 3));
    $fs->mkdir('/b');

    expect(fn () => $fs->writeFile('/c', ''))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/c'")
        ->and(fn () => $fs->link('/a', '/l'))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/l'")
        ->and(fn () => $fs->symlink('/a', '/s'))->toThrow(RuntimeException::class, 'ENOSPC');

    $fs->rm('/a');
    $fs->writeFile('/c', '');

    expect($fs->readdir('/'))->toBe(['b', 'c']);
});

test('initial files count against the quota too', function (): void {
    expect(fn (): InMemoryFs => new InMemoryFs(['/f' => str_repeat('x', 100)], new DiskQuota(maxBytes: 50)))->toThrow(RuntimeException::class, "ENOSPC: no space left on device, write '/f'");
});
