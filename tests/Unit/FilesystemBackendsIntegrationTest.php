<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\InMemoryFs;
use BashBox\Filesystem\MountableFs;
use BashBox\Filesystem\OverlayFs;
use BashBox\Filesystem\ReadWriteFs;

beforeEach(function (): void {
    $this->tmpDirs = [];
});

afterEach(function (): void {
    foreach ($this->tmpDirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iter as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
});

function makeTempDir(object $test, string $prefix): string
{
    $dir = sys_get_temp_dir().'/'.$prefix.'_'.uniqid();
    mkdir($dir, 0755, true);
    $test->tmpDirs[] = $dir;

    return $dir;
}

test('bash with OverlayFs reads disk files and keeps shell writes off disk', function (): void {
    $root = makeTempDir($this, 'bash_overlay_fs');
    file_put_contents($root.'/seed.txt', 'from disk');

    $bash = new Bash(new BashOptions(
        fs: new OverlayFs($root),
    ));

    $bashExecResult = $bash->exec('cat /seed.txt && echo overlay > /new.txt && cat /new.txt');

    expect($bashExecResult->stdout)->toBe("from diskoverlay\n");
    expect(file_exists($root.'/new.txt'))->toBeFalse();
    expect($bash->readFile('/new.txt'))->toBe("overlay\n");
});

test('bash with ReadWriteFs persists shell writes to disk', function (): void {
    $root = makeTempDir($this, 'bash_read_write_fs');

    $bash = new Bash(new BashOptions(
        fs: new ReadWriteFs($root),
    ));

    $bashExecResult = $bash->exec('echo first > notes.txt; echo second >> notes.txt; cat notes.txt');

    expect($bashExecResult->stdout)->toBe("first\nsecond\n");
    expect(file_get_contents($root.'/home/user/notes.txt'))->toBe("first\nsecond\n");
    expect(is_dir($root.'/tmp'))->toBeTrue();
});

test('bash with MountableFs routes commands to mounted backend and supports cross mount copy', function (): void {
    $root = makeTempDir($this, 'bash_mountable_fs');
    $defaultFs = new InMemoryFs([
        '/home/user/local.txt' => 'local data',
    ]);
    $mountable = new MountableFs($defaultFs);
    $mountable->mount('/data', new ReadWriteFs($root));

    $bash = new Bash(new BashOptions(fs: $mountable));

    $bashExecResult = $bash->exec('cp /home/user/local.txt /data/copied.txt; echo mounted >> /data/copied.txt; cp /data/copied.txt /home/user/roundtrip.txt; cat /home/user/roundtrip.txt');

    expect($bashExecResult->stdout)->toBe("local datamounted\n");
    expect(file_get_contents($root.'/copied.txt'))->toBe("local datamounted\n");
    expect($bash->readFile('/home/user/roundtrip.txt'))->toBe("local datamounted\n");
});
