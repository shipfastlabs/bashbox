<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('catastrophic backtracking fails fast with an error instead of a silent no-match', function (string $script, string $stderr, int $exitCode): void {
    $subject = str_repeat('a', 30).'!';
    $bashExecResult = new Bash(new BashOptions(initialFiles: ['/d/'.$subject => '']))->exec(sprintf($script, $subject));

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe(['', $stderr, $exitCode]);
})->with([
    'grep' => ["echo %s | grep -E '(a+)+$'", "grep: regex match failed: Backtrack limit exhausted\n", 2],
    'grep -o' => ["echo %s | grep -oE '(a+)+$'", "grep: regex match failed: Backtrack limit exhausted\n", 2],
    'grep on files' => ["echo %s > /d/f; grep -E '(a+)+$' /d/f /d/f", "grep: regex match failed: Backtrack limit exhausted\n", 2],
    'sed' => ["echo %s | sed -E 's/(a+)+$/x/'", "sed: regex match failed: Backtrack limit exhausted\n", 4],
    'find' => ["find /d -regextype posix-extended -regex '/d/(a+)+' -name %s", "find: regex match failed: Backtrack limit exhausted\n", 1],
]);

test('commands apply their own PCRE limits and put the host ones back', function (): void {
    $previous = ini_set('pcre.backtrack_limit', '5');

    try {
        $stdout = (new Bash)->exec("echo aaaaaaaaaaaab | grep -oE '(a|aa)+b'")->stdout;

        expect([$stdout, ini_get('pcre.backtrack_limit')])->toBe(["aaaaaaaaaaaab\n", '5']);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $previous);
    }
});
