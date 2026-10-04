<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/a.txt', "one\n");
    $this->bash->writeFile('/home/user/b.txt', "two\n");
    $this->bash->exec('mkdir d');
});

test('file operation errors', function (string $script, string $stderr): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe(['', $stderr, 1]);
})->with([
    'cp without operands' => ['cp', "cp: missing file operand\nTry 'cp --help' for more information.\n"],
    'cp without destination' => ['cp a.txt', "cp: missing destination file operand after 'a.txt'\nTry 'cp --help' for more information.\n"],
    'cp to a missing target directory' => ['cp a.txt b.txt e', "cp: target 'e': No such file or directory\n"],
    'cp to a file target' => ['cp a.txt d b.txt', "cp: target 'b.txt': Not a directory\n"],
    'cp a missing source' => ['cp nope x', "cp: cannot stat 'nope': No such file or directory\n"],
    'cp a directory without -r' => ['cp d e', "cp: -r not specified; omitting directory 'd'\n"],
    'cp onto itself' => ['cp a.txt a.txt; cp a.txt .', "cp: 'a.txt' and 'a.txt' are the same file\ncp: 'a.txt' and './a.txt' are the same file\n"],
    'cp a directory into itself' => ['cp -r d d', "cp: cannot copy a directory, 'd', into itself, 'd/d'\n"],
    'mv without destination' => ['mv a.txt', "mv: missing destination file operand after 'a.txt'\nTry 'mv --help' for more information.\n"],
    'mv a missing source' => ['mv nope x', "mv: cannot stat 'nope': No such file or directory\n"],
    'mv a directory into itself' => ['mv d d', "mv: cannot move 'd' to a subdirectory of itself, 'd/d'\n"],
    'cp and mv into a missing directory' => ['cp a.txt x/y; cp -r d x/y; mv a.txt x/y', "cp: cannot create regular file 'x/y': No such file or directory\ncp: cannot create directory 'x/y': No such file or directory\nmv: cannot move 'a.txt' to 'x/y': No such file or directory\n"],
    'cp and mv under a file' => ['cp a.txt b.txt/y; mv a.txt a.txt/y', "cp: cannot stat 'b.txt/y': Not a directory\nmv: cannot stat 'a.txt/y': Not a directory\n"],
    'cp and mv a file onto a directory' => ['mkdir -p e/a.txt; cp a.txt e; mv a.txt e', "cp: cannot overwrite directory 'e/a.txt' with non-directory 'a.txt'\nmv: cannot overwrite directory 'e/a.txt' with non-directory 'a.txt'\n"],
    'cp and mv a directory onto a file' => ['cp -r d a.txt; mv d a.txt', "cp: cannot overwrite non-directory 'a.txt' with directory 'd'\nmv: cannot overwrite non-directory 'a.txt' with directory 'd'\n"],
    'mv onto a non-empty directory' => ['mkdir -p e/d/x; mv d e', "mv: cannot overwrite 'e/d': Directory not empty\n"],
    'rm without operands' => ['rm', "rm: missing operand\nTry 'rm --help' for more information.\n"],
    'rm a missing file' => ['rm nope', "rm: cannot remove 'nope': No such file or directory\n"],
    'rm a directory without -r, even with -f' => ['rm d; rm -f d', "rm: cannot remove 'd': Is a directory\nrm: cannot remove 'd': Is a directory\n"],
    'mkdir without operands' => ['mkdir', "mkdir: missing operand\nTry 'mkdir --help' for more information.\n"],
    'mkdir an existing path' => ['mkdir d; mkdir -p a.txt', "mkdir: cannot create directory 'd': File exists\nmkdir: cannot create directory 'a.txt': File exists\n"],
    'mkdir under a missing or non-directory parent' => ['mkdir x/y a.txt/y', "mkdir: cannot create directory 'x/y': No such file or directory\nmkdir: cannot create directory 'a.txt/y': Not a directory\n"],
    'touch without operands' => ['touch', "touch: missing file operand\nTry 'touch --help' for more information.\n"],
    'touch in a missing directory' => ['touch x/y', "touch: cannot touch 'x/y': No such file or directory\n"],
    'the null device can not be removed or replaced' => ['rm /dev/null; rm -rf /dev/null; ln -sf a.txt /dev/null; cp -r d /dev/null; mv /dev/null x', "rm: cannot remove '/dev/null': Permission denied\nrm: cannot remove '/dev/null': Permission denied\nln: cannot remove '/dev/null': Permission denied\ncp: cannot overwrite non-directory '/dev/null' with directory 'd'\nmv: cannot create special file 'x': Operation not permitted\n"],
    'the null device mode can not change' => ['chmod 600 /dev/null', "chmod: changing permissions of '/dev/null': Operation not permitted\n"],
]);

test('cp copies files and directories', function (): void {
    $this->bash->writeFile('/home/user/d/in.txt', 'in');

    $result = $this->bash->exec('cp a.txt c.txt && cp a.txt b.txt d && cp -r d e && cp -R d /');
    $fs = $this->bash->getFilesystem();

    expect($result->exitCode)->toBe(0)
        ->and($this->bash->readFile('/home/user/c.txt'))->toBe("one\n")
        ->and($this->bash->readFile('/home/user/d/b.txt'))->toBe("two\n")
        ->and($this->bash->readFile('/home/user/e/in.txt'))->toBe('in')
        ->and($this->bash->readFile('/d/a.txt'))->toBe("one\n")
        ->and($fs->exists('/home/user/a.txt'))->toBeTrue();
});

test('cp -n keeps an existing target, -f overwrites it', function (): void {
    $this->bash->exec('cp -n a.txt b.txt');
    expect($this->bash->readFile('/home/user/b.txt'))->toBe("two\n");

    $this->bash->exec('cp -f a.txt b.txt');
    expect($this->bash->readFile('/home/user/b.txt'))->toBe("one\n");
});

test('mv renames and moves into a directory', function (): void {
    $result = $this->bash->exec('mv a.txt c.txt && mv c.txt b.txt d && mv d e');
    $fs = $this->bash->getFilesystem();

    expect($result->exitCode)->toBe(0)
        ->and($this->bash->readFile('/home/user/e/c.txt'))->toBe("one\n")
        ->and($this->bash->readFile('/home/user/e/b.txt'))->toBe("two\n")
        ->and($fs->exists('/home/user/a.txt'))->toBeFalse()
        ->and($fs->exists('/home/user/d'))->toBeFalse();
});

test('rm removes files, and directories with -r', function (): void {
    $this->bash->writeFile('/home/user/d/x/f', '');
    $this->bash->writeFile('/home/user/-f', '');

    $result = $this->bash->exec('rm a.txt && rm -rf d nope && rm -R -- -f && rm -f');
    $fs = $this->bash->getFilesystem();

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe(['', '', 0])
        ->and($fs->exists('/home/user/a.txt'))->toBeFalse()
        ->and($fs->exists('/home/user/d'))->toBeFalse()
        ->and($fs->exists('/home/user/-f'))->toBeFalse()
        ->and($fs->exists('/home/user/b.txt'))->toBeTrue();
});

test('mkdir creates directories, with parents for -p', function (): void {
    $result = $this->bash->exec('mkdir x && mkdir -p y/z d');
    $fs = $this->bash->getFilesystem();

    expect($result->exitCode)->toBe(0)
        ->and($fs->stat('/home/user/x')->isDirectory)->toBeTrue()
        ->and($fs->stat('/home/user/y/z')->isDirectory)->toBeTrue();
});

test('touch creates empty files, keeps existing content, and -c does not create', function (): void {
    $result = $this->bash->exec('touch new a.txt && touch -c ghost');
    $fs = $this->bash->getFilesystem();

    expect($result->exitCode)->toBe(0)
        ->and($this->bash->readFile('/home/user/new'))->toBe('')
        ->and($this->bash->readFile('/home/user/a.txt'))->toBe("one\n")
        ->and($fs->exists('/home/user/ghost'))->toBeFalse();
});

test('cp and mv report host errors such as a read-only directory', function (): void {
    $root = sys_get_temp_dir().'/fileops_ro_'.uniqid();
    mkdir($root.'/ro', 0755, true);
    file_put_contents($root.'/f', 'x');
    chmod($root.'/ro', 0555);

    try {
        $result = new Bash(new BashOptions(fs: new BashBox\Filesystem\ReadWriteFs($root), cwd: '/'))->exec('cp f ro/; mv f ro/');

        expect($result->stderr)->toBe("cp: cannot create regular file 'ro/f': Permission denied\nmv: cannot move 'f' to 'ro/f': Permission denied\n")
            ->and($result->exitCode)->toBe(1)
            ->and(file_exists($root.'/f'))->toBeTrue();
    } finally {
        chmod($root.'/ro', 0755);
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($root);
    }
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');

test('commands report host errors instead of throwing', function (string $script, string $stderr, int $exitCode): void {
    $root = sys_get_temp_dir().'/fileops_ro_'.uniqid();
    mkdir($root.'/ro', 0755, true);
    file_put_contents($root.'/ro/g', 'x');
    file_put_contents($root.'/ro/h', 'x');
    chmod($root.'/ro/h', 0444);
    chmod($root.'/ro', 0555);

    try {
        $result = new Bash(new BashOptions(fs: new BashBox\Filesystem\ReadWriteFs($root), cwd: '/'))->exec($script);

        expect([$result->stderr, $result->exitCode])->toBe([$stderr, $exitCode]);
    } finally {
        chmod($root.'/ro', 0755);
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($root);
    }
})->with([
    'rm' => ['rm ro/g', "rm: cannot remove 'ro/g': Permission denied\n", 1],
    'touch' => ['touch ro/new', "touch: cannot touch 'ro/new': Permission denied\n", 1],
    'sed -i' => ['sed -i s/x/y/ ro/h', "sed: couldn't open temporary file ro/h: Permission denied\n", 4],
    'find -delete' => ['find ro -name g -delete', "find: cannot delete 'ro/g': Permission denied\n", 1],
]);
