<?php

declare(strict_types=1);

use BashBox\Bash;

test('basename and dirname', function (string $script, string $stdout): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, '', 0]);
})->with([
    'basename strips directories and trailing slashes' => ['basename a/b/; basename b.txt; basename /', "b\nb.txt\n/\n"],
    'basename strips a suffix unless it is the whole name' => ['basename a/b.txt .txt; basename .txt .txt', "b\n.txt\n"],
    'dirname' => ['dirname /a/b/c; dirname a//b/; dirname /a; dirname //a; dirname /; dirname a; dirname ""', "/a/b\na\n/\n/\n/\n.\n.\n"],
]);

test('basename and dirname need an operand', function (string $command): void {
    $bashExecResult = (new Bash)->exec($command);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])
        ->toBe(['', "{$command}: missing operand\nTry '{$command} --help' for more information.\n", 1]);
})->with(['basename', 'dirname']);

test('basename and dirname reject unknown options', function (string $command): void {
    $bashExecResult = (new Bash)->exec($command.' -q x');

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])
        ->toBe(['', "{$command}: invalid option -- 'q'\nTry '{$command} --help' for more information.\n", 1]);
})->with(['basename', 'dirname']);

test('basename takes at most a name and a suffix', function (): void {
    $bashExecResult = (new Bash)->exec('basename a b c');

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])
        ->toBe(['', "basename: extra operand 'c'\nTry 'basename --help' for more information.\n", 1]);
});
