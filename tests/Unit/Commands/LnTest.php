<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\InMemoryFs;

beforeEach(function (): void {
    $this->fs = new InMemoryFs([
        '/home/user/a' => "a\n",
        '/home/user/d/x' => "x\n",
    ]);
    $this->fs->symlink('d', '/home/user/dl');
    $this->fs->symlink('nowhere', '/home/user/dangling');

    $this->bash = new Bash(new BashOptions(fs: $this->fs, cwd: '/home/user', env: ['HOME' => '/home/user']));
});

// Expected output from GNU coreutils 9 `ln` (gln), LC_ALL=C, run on the same tree
test('ln behaves like GNU', function (string $script, string $stdout, string $stderr, int $exitCode): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($stdout)
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'missing operand' => ['ln', '', "ln: missing file operand\nTry 'ln --help' for more information.\n", 1],
    'symbolic link' => ['ln -s a s; cat s; [[ -L s ]] && echo link', "a\nlink\n", '', 0],
    'hard link shares writes' => ['ln a h; echo more >> h; cat a', "a\nmore\n", '', 0],
    'verbose hard' => ['ln -v a h', "'h' => 'a'\n", '', 0],
    'verbose symbolic' => ['ln -sv a s', "'s' -> 'a'\n", '', 0],
    'link onto itself' => ['ln a a', '', "ln: failed to create hard link 'a': File exists\n", 1],
    'single operand links into cwd' => ['ln a', '', "ln: failed to create hard link './a': File exists\n", 1],
    'single operand symbolic' => ['ln -sv d/x; cat x', "'./x' -> 'd/x'\nx\n", '', 0],
    'hard link to a directory' => ['ln d dh', '', "ln: d: hard link not allowed for directory\n", 1],
    'missing target' => ['ln nope h', '', "ln: failed to access 'nope': No such file or directory\n", 1],
    'dangling symlink allowed' => ['ln -s nope dang2; [[ -L dang2 ]] && echo link', "link\n", '', 0],
    'into a directory' => ['ln a d; cat d/a', "a\n", '', 0],
    'into a directory with trailing slash' => ['ln -v a d/', "'d/a' => 'a'\n", '', 0],
    'several into a directory' => ['mkdir e; ln -v a d/x e', "'e/a' => 'a'\n'e/x' => 'd/x'\n", '', 0],
    'several with missing last' => ['ln a d/x c', '', "ln: target 'c': No such file or directory\n", 1],
    'several with file last' => ['ln a d/x a', '', "ln: target 'a': Not a directory\n", 1],
    '-t' => ['ln -t d -v a', "'d/a' => 'a'\n", '', 0],
    '--target-directory=' => ['ln --target-directory=d a; cat d/a', "a\n", '', 0],
    '-t missing' => ['ln -t nod a', '', "ln: failed to access 'nod': No such file or directory\n", 1],
    '-t not a directory' => ['ln -t a d', '', "ln: target 'a' is not a directory\n", 1],
    '-tDIR' => ['ln -vtd a', "'d/a' => 'a'\n", '', 0],
    '-T onto a directory' => ['ln -T a d', '', "ln: failed to create hard link 'd': File exists\n", 1],
    '-f -T onto a directory' => ['ln -sfT a d', '', "ln: d: cannot overwrite directory\n", 1],
    'symlink to dir is entered' => ['ln -sv ../a dl; cat d/a', "'dl/a' -> '../a'\na\n", '', 0],
    '-n does not enter' => ['ln -snv a dl', '', "ln: failed to create symbolic link 'dl': File exists\n", 1],
    '-nf replaces the link' => ['ln -snfv a dl; cat dl', "'dl' -> 'a'\na\n", '', 0],
    '-sf same file' => ['ln -sf a a', '', "ln: 'a' and 'a' are the same file\n", 1],
    '-f same file' => ['ln -f a ./a', '', "ln: 'a' and './a' are the same file\n", 1],
    '-f replaces a file' => ['echo b > b; ln -fv a b; cat b', "'b' => 'a'\na\n", '', 0],
    '-f onto another hard link' => ['ln a h; ln -fv a h; echo more >> h; cat a', "'h' => 'a'\na\nmore\n", '', 0],
    '-f replaces a dangling link' => ['ln -sfv a dangling; cat dangling', "'dangling' -> 'a'\na\n", '', 0],
    'dangling symlink exists' => ['ln -s a dangling', '', "ln: failed to create symbolic link 'dangling': File exists\n", 1],
    'missing parent' => ['ln -s a nonexist/l', '', "ln: failed to create symbolic link 'nonexist/l': No such file or directory\n", 1],
    'parent is a file' => ['ln -s a a/b', '', "ln: failed to create symbolic link 'a/b': Not a directory\n", 1],
    '-r needs -s' => ['ln -r a rr', '', "ln: cannot do --relative without --symbolic\n", 1],
    '-r up' => ['mkdir -p e/f; ln -srv a e/f/r; cat e/f/r', "'e/f/r' -> '../../a'\na\n", '', 0],
    '-r same dir' => ['ln -srv d/x d/y; cat d/y', "'d/y' -> 'x'\nx\n", '', 0],
    '-r through a symlink' => ['ln -srv dl/x y; cat y', "'y' -> 'd/x'\nx\n", '', 0],
    '-r to the current directory' => ['ln -srv . self', "'self' -> '.'\n", '', 0],
    'invalid option' => ['ln -x a', '', "ln: invalid option -- 'x'\nTry 'ln --help' for more information.\n", 1],
    'unrecognized option' => ['ln --bogus a', '', "ln: unrecognized option '--bogus'\nTry 'ln --help' for more information.\n", 1],
    '-T one operand' => ['ln -T a', '', "ln: missing destination file operand after 'a'\nTry 'ln --help' for more information.\n", 1],
    '-T extra operand' => ['ln -T a b c', '', "ln: extra operand 'c'\nTry 'ln --help' for more information.\n", 1],
    '-t and -T' => ['ln -t d -T a', '', "ln: cannot combine --target-directory and --no-target-directory\n", 1],
    '-t without argument' => ['ln -t', '', "ln: option requires an argument -- 't'\nTry 'ln --help' for more information.\n", 1],
    '--target-directory without argument' => ['ln --target-directory', '', "ln: option '--target-directory' requires an argument\nTry 'ln --help' for more information.\n", 1],
    'options after operands' => ['ln -n a -s nn; cat nn', "a\n", '', 0],
    '-- ends options' => ['ln -- -s', '', "ln: failed to access '-s': No such file or directory\n", 1],
    '-n with several operands' => ['ln -nv a d/x dl', '', "ln: target 'dl': Not a directory\n", 1],
    'keeps going past failures' => ['mkdir e; ln a nope2 e; echo "rc=$?"; cat e/a', "rc=1\na\n", "ln: failed to access 'nope2': No such file or directory\n", 0],
    'long options' => ['ln --symbolic --verbose --force --no-dereference a dl', "'dl' -> 'a'\n", '', 0],
    '--relative' => ['ln --relative -s d/x y --verbose', "'y' -> 'd/x'\n", '', 0],
    '-r to a missing target' => ['ln -srv nope/deep d/l', "'d/l' -> '../nope/deep'\n", '', 0],
]);

test('ln makes real hard links and stores the symlink text as given', function (): void {
    $this->bash->exec('ln a h; ln -s ../a d/up; ln -sr d/x rel');

    expect($this->fs->stat('/home/user/h'))->toMatchObject(['nlink' => 2, 'ino' => $this->fs->stat('/home/user/a')->ino])
        ->and($this->fs->readlink('/home/user/d/up'))->toBe('../a')
        ->and($this->fs->readlink('/home/user/rel'))->toBe('d/x');
});

test('ln -s through a dangling parent fails like Linux, without creating the target directory', function (): void {
    $result = $this->bash->exec('ln -s a dangling/q');

    expect($result->stderr)->toBe("ln: failed to create symbolic link 'dangling/q': No such file or directory\n")
        ->and($result->exitCode)->toBe(1)
        ->and($this->fs->exists('/home/user/nowhere'))->toBeFalse();
});

test('a hard link made by ln is a hard link to the symlink itself', function (): void {
    $this->bash->exec('ln dl hard');

    expect($this->fs->lstat('/home/user/hard')->isSymbolicLink)->toBeTrue()
        ->and($this->fs->readlink('/home/user/hard'))->toBe('d');
});
