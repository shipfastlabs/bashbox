<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('rmdir', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = new Bash(new BashOptions(cwd: '/w', initialFiles: ['/w/f' => '', '/w/e/g' => '', '/w/x/z/.keep' => '']))->exec('mkdir -p a/b/c x/y; '.$script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'removes empty directories' => ['rmdir -v a/b/c; ls a/b', "rmdir: removing directory, 'a/b/c'\n"],
    '-p removes the parents too' => ['rmdir --parents a/b/c/; ls', "e\nf\nx\n"],
    '-p stops at a non-empty parent' => ['rmdir -pv x/y', "rmdir: removing directory, 'x/y'\nrmdir: removing directory, 'x'\n", "rmdir: failed to remove directory 'x': Directory not empty\n", 1],
    'errors' => ['rmdir nope f e', '', "rmdir: failed to remove 'nope': No such file or directory\nrmdir: failed to remove 'f': Not a directory\nrmdir: failed to remove 'e': Directory not empty\n", 1],
    'ignoring non-empty directories' => ['rmdir --ignore-fail-on-non-empty e; rmdir --ignore-fail-on-non-empty -p x/y; ls x', "z\n"],
    'a symlink to a directory is not one' => ['ln -s a l; rmdir l', '', "rmdir: failed to remove 'l': Not a directory\n", 1],
    'missing operand' => ['rmdir', '', "rmdir: missing operand\nTry 'rmdir --help' for more information.\n", 1],
]);
