<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\ReadWriteFs;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "a\n");
    $this->bash->writeFile('/home/user/d/b.txt', "b\n");
    $this->bash->writeFile('/home/user/d/.hid', "h\n");
    $this->bash->writeFile('/home/user/d/sub/c.txt', "c\n");
    $this->bash->exec('mkdir /home/user/empty /home/user/hidden-only && touch /home/user/hidden-only/.x');

    $fileSystem = $this->bash->getFilesystem();
    $fileSystem->symlink('../d', '/home/user/links/to-dir');
    $fileSystem->symlink('/home/user/a.txt', '/home/user/links/to-file');
    $fileSystem->symlink('missing', '/home/user/links/dangling');
});

test('tree draws the hierarchy like tree 2.x with ASCII lines', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe('')
        ->and($result->exitCode)->toBe(0);
})->with([
    'named directory counts itself' => ['tree d', "d\n|-- b.txt\n`-- sub\n    `-- c.txt\n\n2 directories, 2 files\n"],
    '-a shows dotfiles' => ['tree -a d/sub d', "d/sub\n`-- c.txt\nd\n|-- .hid\n|-- b.txt\n`-- sub\n    `-- c.txt\n\n3 directories, 4 files\n"],
    'empty directory is not counted' => ['tree empty', "empty\n\n0 directories, 0 files\n"],
    'only hidden entries' => ['tree hidden-only', "hidden-only\n\n0 directories, 0 files\n"],
    'symlinks show targets and are not followed' => ['tree links', "links\n|-- dangling -> missing\n|-- to-dir -> ../d\n`-- to-file -> /home/user/a.txt\n\n2 directories, 2 files\n"],
    'file operand counts as a file' => ['tree a.txt', "a.txt  [error opening dir]\n\n0 directories, 1 file\n"],
]);

test('tree defaults to the current directory', function (): void {
    $result = $this->bash->exec('cd d && tree');

    expect($result->stdout)->toBe(".\n|-- b.txt\n`-- sub\n    `-- c.txt\n\n2 directories, 2 files\n");
});

test('tree reports a missing operand with exit code 2', function (): void {
    $result = $this->bash->exec('tree nope empty');

    expect($result->stdout)->toBe("nope  [error opening dir]\nempty\n\n0 directories, 0 files\n")
        ->and($result->exitCode)->toBe(2);
});

test('tree marks unreadable subdirectories', function (): void {
    $root = sys_get_temp_dir().'/bashbox-tree-'.uniqid();
    mkdir($root.'/top/locked', 0777, true);
    chmod($root.'/top/locked', 0);

    try {
        $result = new Bash(new BashOptions(fs: new ReadWriteFs($root), cwd: '/'))->exec('tree top');
    } finally {
        chmod($root.'/top/locked', 0755);
        rmdir($root.'/top/locked');
        rmdir($root.'/top');
        @rmdir($root.'/tmp'); // created by Bash on startup
        rmdir($root);
    }

    expect($result->stdout)->toBe("top\n`-- locked  [error opening dir]\n\n2 directories, 0 files\n")
        ->and($result->exitCode)->toBe(2);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can read any directory');
