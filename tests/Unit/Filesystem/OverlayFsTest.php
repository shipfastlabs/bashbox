<?php

declare(strict_types=1);

use BashBox\Filesystem\OverlayFs;

beforeEach(function (): void {
    $this->tmpDir = sys_get_temp_dir().'/overlay_fs_test_'.uniqid();
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function (): void {
    // Clean up temp directory
    if (is_dir($this->tmpDir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iter as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->tmpDir);
    }
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

    foreach ($entries as $e) {
        $map[$e->name] = $e;
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
