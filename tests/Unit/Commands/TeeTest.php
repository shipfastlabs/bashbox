<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Filesystem\DiskQuota;
use BashBox\Filesystem\InMemoryFs;

test('tee writes stdin to stdout and every file, -a appends', function (): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user'));

    $bashExecResult = $bash->exec('echo hi | tee t1 t2; echo more | tee -a t1');

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe(["hi\nmore\n", '', 0])
        ->and($bash->readFile('/home/user/t1'))->toBe("hi\nmore\n")
        ->and($bash->readFile('/home/user/t2'))->toBe("hi\n");
});

test('tee reports files it cannot write but still copies stdin', function (): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user'));

    $bashExecResult = $bash->exec('mkdir d; echo x | tee nodir/f d ok');

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])
        ->toBe(["x\n", "tee: nodir/f: No such file or directory\ntee: d: Is a directory\n", 1])
        ->and($bash->readFile('/home/user/ok'))->toBe("x\n")
        ->and($bash->getFilesystem()->stat('/home/user/d')->isDirectory)->toBeTrue();
});

// GNU coreutils tee messages; the input still reaches stdout
test('tee reports files it cannot open and keeps going', function (): void {
    $bashExecResult = new Bash(new BashOptions(cwd: '/home/user'))->exec('touch file; mkdir dir; echo y | tee nodir/x file/x dir ok; echo "rc=$?"; cat ok; ls -d nodir 2>/dev/null');

    expect($bashExecResult->stdout)->toBe("y\nrc=1\ny\n")
        ->and($bashExecResult->stderr)->toBe("tee: nodir/x: No such file or directory\ntee: file/x: Not a directory\ntee: dir: Is a directory\n");
});

test('commands report a full disk', function (): void {
    $bash = new Bash(new BashOptions(fs: new InMemoryFs([], new DiskQuota(100, 100))));

    $bashExecResult = $bash->exec("printf '%60s' x > a; cp a b; printf '%50s' y | tee c");

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])
        ->toBe([str_repeat(' ', 49).'y', "cp: error writing 'b': No space left on device\ntee: c: No space left on device\n", 1]);
});
