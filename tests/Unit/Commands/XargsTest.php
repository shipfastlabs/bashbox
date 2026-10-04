<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;

test('xargs', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'defaults to echo' => ["printf 'a b\\n c' | xargs", "a b c\n"],
    'appends items to the command' => ['echo a b | xargs echo X', "X a b\n"],
    '-n limits items per run' => ['echo a b c | xargs -n 2; echo a b | xargs -n1 echo -', "a b\nc\n- a\n- b\n"],
    'options after the command belong to it' => ['echo a b | xargs -n1 echo -n; echo', "ab\n"],
    '-I runs once per line, keeping trailing blanks' => ["printf 'one\\n\\n  two  \\n' | xargs -I {} echo '<{}>' {}", "<one> one\n<two  > two  \n"],
    '-I with an attached placeholder' => ['echo x | xargs -I% echo [%]', "[x]\n"],
    'runs once on empty input' => ["printf '' | xargs echo hi", "hi\n"],
    '-r skips empty input' => ["printf '' | xargs -r echo hi", ''],
    'items are passed literally until an unmatched quote' => ["printf '%s\\n' '*' 'a\$b' \"it's\" | xargs -I{} echo {}", "*\na\$b\n", "xargs: unmatched single quote; by default quotes are special to xargs unless you use the -0 option\n", 1],
    'a failing command makes xargs exit 123' => ['echo a | xargs false', '', '', 123],
    'an unknown command exits 127' => ['echo a | xargs nope', '', "xargs: nope: No such file or directory\n", 127],
]);

test('xargs stops at the output size limit instead of exhausting memory', function (): void {
    expect(fn (): BashExecResult => new Bash(new BashOptions(limits: new Limits(maxOutputSize: 1000)))->exec('seq 1 200 | xargs -n1 echo item-item-item'))
        ->toThrow(ExecutionLimitException::class, 'Output size limit exceeded (1000 bytes)');
});
