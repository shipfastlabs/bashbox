<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\InMemoryFs;

// Expected outputs and modes were produced by GNU coreutils chmod on the same tree, with umask 022.
beforeEach(function (): void {
    $this->fs = new InMemoryFs;
    $this->bash = new Bash(new BashOptions(fs: $this->fs, cwd: '/w'));
    $this->bash->exec('echo x > f; chmod 644 f; mkdir -p d/in; echo y > d/in/g; chmod 755 d d/in; chmod 640 d/in/g; echo z > x; chmod 755 x; ln -s f link');

    $this->modes = fn (): string => implode(' ', array_map(
        fn (string $file): string => sprintf('%04o', $this->fs->stat('/w/'.$file)->mode & 07777),
        ['f', 'd', 'd/in', 'd/in/g', 'x'],
    ));
});

test('chmod applies modes like GNU', function (string $script, string $modes): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe(['', '', 0])
        ->and(($this->modes)())->toBe($modes);
})->with([
    '+x honours the umask' => ['chmod +x f', '0755 0755 0755 0640 0755'],
    '-w' => ['chmod -w f', '0444 0755 0755 0640 0755'],
    'explicit classes' => ['chmod u=rwx,go= f', '0700 0755 0755 0640 0755'],
    'octal' => ['chmod 600 f', '0600 0755 0755 0640 0755'],
    'short octal' => ['chmod 75 f', '0075 0755 0755 0640 0755'],
    'X keeps directories searchable' => ['chmod a-x+X d', '0644 0755 0755 0640 0755'],
    'recursive X' => ['chmod -R a+X d', '0644 0755 0755 0640 0755'],
    'recursive removal' => ['chmod -R go-rwx d', '0644 0700 0700 0600 0755'],
    'recursive octal' => ['chmod -R 700 d', '0644 0700 0700 0700 0755'],
    'recursion does not follow symlinks' => ['ln -s ../f d/l; chmod -R 700 d', '0644 0700 0700 0700 0755'],
    'copy from a class' => ['chmod g=u f', '0664 0755 0755 0640 0755'],
    'copy then remove' => ['chmod o=g-w f', '0644 0755 0755 0640 0755'],
    'sticky bit' => ['chmod +t d', '0644 1755 0755 0640 0755'],
    'setuid' => ['chmod u+s x', '0644 0755 0755 0640 4755'],
    'four-digit octal' => ['chmod 4755 x', '0644 0755 0755 0640 4755'],
    'several clauses' => ['chmod +rwx,o-w f', '0755 0755 0755 0640 0755'],
    '= without classes' => ['chmod =r f', '0444 0755 0755 0640 0755'],
    'clear everything' => ['chmod a= f', '0000 0755 0755 0640 0755'],
    'a symlink operand changes its target' => ['chmod 600 link', '0600 0755 0755 0640 0755'],
    'mode after --' => ['chmod -- -w f', '0444 0755 0755 0640 0755'],
    'short octal keeps directory set-id bits' => ['chmod 2755 d; chmod 755 d', '0644 2755 0755 0640 0755'],
]);

test('chmod -v and -c describe changes', function (): void {
    $verbose = $this->bash->exec('chmod -v 644 f x');
    $this->bash->exec('chmod 755 x');
    $changes = $this->bash->exec('chmod -c 644 f x; chmod -cR o-r d');

    expect($verbose->stdout)->toBe("mode of 'f' retained as 0644 (rw-r--r--)\nmode of 'x' changed from 0755 (rwxr-xr-x) to 0644 (rw-r--r--)\n")
        ->and($changes->stdout)->toBe("mode of 'x' changed from 0755 (rwxr-xr-x) to 0644 (rw-r--r--)\n"
            ."mode of 'd' changed from 0755 (rwxr-xr-x) to 0751 (rwxr-x--x)\n"
            ."mode of 'd/in' changed from 0755 (rwxr-xr-x) to 0751 (rwxr-x--x)\n");
});

test('chmod reports errors like GNU', function (string $script, string $stderr): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe(['', $stderr, 1]);
})->with([
    'no operands' => ['chmod', "chmod: missing operand\nTry 'chmod --help' for more information.\n"],
    'no file' => ['chmod +w', "chmod: missing operand after '+w'\nTry 'chmod --help' for more information.\n"],
    'invalid mode' => ['chmod zz f', "chmod: invalid mode: 'zz'\nTry 'chmod --help' for more information.\n"],
    'octal digit out of range' => ['chmod 8 f', "chmod: invalid mode: '8'\nTry 'chmod --help' for more information.\n"],
    'invalid option' => ['chmod -q f', "chmod: invalid option -- 'q'\nTry 'chmod --help' for more information.\n"],
    'missing file' => ['chmod 644 nope', "chmod: cannot access 'nope': No such file or directory\n"],
    'dangling symlink' => ['ln -s nope dl; chmod 644 dl', "chmod: cannot operate on dangling symlink 'dl'\n"],
    '-f stays quiet but still fails' => ['chmod -f 644 nope; ln -s nope dl; chmod -f 644 dl', ''],
]);
