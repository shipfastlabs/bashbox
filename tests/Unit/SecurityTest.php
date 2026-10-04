<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecOptions;
use BashBox\Limits;

test('infinite loop is caught by iteration limit', function (): void {
    $bash = new Bash(new BashOptions(
        limits: new Limits(maxLoopIterations: 50),
    ));

    expect(fn (): \BashBox\BashExecResult => $bash->exec('while true; do echo x; done'))
        ->toThrow(ExecutionLimitException::class);
});

test('infinite recursion is caught by call depth limit', function (): void {
    $bash = new Bash(new BashOptions(
        limits: new Limits(maxCallDepth: 10),
    ));

    expect(fn (): \BashBox\BashExecResult => $bash->exec('f() { f; }; f'))
        ->toThrow(ExecutionLimitException::class);
});

test('command count limit prevents command bombs', function (): void {
    $bash = new Bash(new BashOptions(
        limits: new Limits(maxCommandCount: 10),
    ));

    expect(fn (): \BashBox\BashExecResult => $bash->exec('for i in $(seq 1 20); do echo $i; done'))
        ->toThrow(ExecutionLimitException::class);
});

test('output size limit prevents output bombs', function (): void {
    $bash = new Bash(new BashOptions(
        limits: new Limits(maxOutputSize: 100),
    ));

    expect(fn (): \BashBox\BashExecResult => $bash->exec('for i in $(seq 1 100); do echo "aaaaaaaaaaaaaaaaaaaaaaaaa"; done'))
        ->toThrow(ExecutionLimitException::class);
});

test('each limit stops a script that goes over it', function (array $limits, string $script, string $message, ?string $stdin = null): void {
    $bash = new Bash(new BashOptions(limits: new Limits(...$limits)));

    expect(fn (): \BashBox\BashExecResult => $bash->exec($script, $stdin === null ? null : new ExecOptions(stdin: $stdin)))
        ->toThrow(ExecutionLimitException::class, $message);
})->with([
    'a value built by expansion' => [['maxStringLength' => 100], 'x=0123456789; x=$x$x$x$x$x$x$x$x$x$x$x', 'String length limit exceeded (100 bytes)'],
    'a value read from input' => [['maxStringLength' => 100], 'read -r x', 'String length limit exceeded (100 bytes)', str_repeat('y', 101)],
    'a replacement that keeps growing' => [['maxStringLength' => 50], 'x=0123456789; echo ${x//?/$x}', 'String length limit exceeded (50 bytes)'],
    'an array' => [['maxArrayElements' => 5], 'a=(1 2 3 4 5 6)', 'Array or word list limit exceeded (5 elements)'],
    'a word split into many fields' => [['maxArrayElements' => 5], 'echo $(echo 1 2 3 4 5 6)', 'Array or word list limit exceeded (5 elements)'],
    'a word list from braces' => [['maxArrayElements' => 5], 'echo x{1,2,3,4,5,6}', 'Array or word list limit exceeded (5 elements)'],
    'a brace range, before it is built' => [['maxBraceExpansionResults' => 5], 'a=({1..100000000})', 'Brace expansion limit exceeded (5 words)'],
    'brace combinations' => [['maxBraceExpansionResults' => 5], 'echo {a,b}{c,d}{e,f}', 'Brace expansion limit exceeded (5 words)'],
    'a glob over a big directory' => [['maxGlobOperations' => 5], 'touch a{1..9}; echo a*', 'Glob operation limit exceeded (5 directory entries)'],
    'nested command substitutions' => [['maxSubstitutionDepth' => 3], 'echo $(echo $(echo $(echo $(echo hi))))', 'Substitution depth limit exceeded (3)'],
    'nested process substitutions' => [['maxSubstitutionDepth' => 3], 'cat <(cat <(cat <(cat <(echo hi))))', 'Substitution depth limit exceeded (3)'],
    'eval calling itself' => [[], 'x=\'eval "$x"\'; eval "$x"', 'Substitution depth limit exceeded (50)'],
    'a high file descriptor' => [['maxFileDescriptors' => 20], 'exec 20>/dev/null', 'File descriptor limit exceeded (20)'],
    'named file descriptors piling up' => [['maxFileDescriptors' => 20], 'for i in {1..11}; do exec {fd}>/dev/null; done', 'File descriptor limit exceeded (20)'],
    'a long pipeline' => [['maxPipelineDepth' => 3], 'echo | cat | cat | cat', 'Pipeline limit exceeded (3 commands)'],
    'a here-document that expands past the limit' => [['maxHereDocSize' => 20], "x=0123456789; cat <<EOF\n\$x\$x\$x\nEOF", 'Here-document size limit exceeded (20 bytes)'],
    'stderr counts toward the output' => [['maxOutputSize' => 50], 'for i in 1 2 3 4 5; do echo 0123456789 >&2; done', 'Output size limit exceeded (50 bytes)'],
    'commands a script file runs' => [['maxCommandCount' => 10], "printf 'echo; echo; echo; echo' > s; chmod +x s; ./s; echo; echo; echo; echo", 'Command count limit exceeded (10)'],
    'so does a write to a file' => [['maxOutputSize' => 50], 'x=0123456789; echo $x$x$x$x$x$x > f', 'Output size limit exceeded (50 bytes)'],
]);

test('ordinary scripts stay under the default limits', function (string $script, string $stdout): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, '', 0]);
})->with([
    'strings, arrays and braces' => ['x=0123456789; x=$x$x; a=({1..100}); echo ${#x} ${#a[@]} {a,b}{c,d}', "20 100 ac ad bc bd\n"],
    'substitutions, fds, pipelines and here-documents' => ["exec {fd}>/dev/null; echo \$fd \$(echo \$(echo hi)) | cat | cat; cat <<EOF\nend\nEOF", "10 hi\nend\n"],
]);

test('null byte in filename is rejected', function (): void {
    $bash = new Bash;
    expect(fn (): \BashBox\BashExecResult => $bash->exec("echo test > /tmp/evil\x00.txt"))
        ->toThrow(RuntimeException::class);
});

test('no proc_open in codebase', function (): void {
    $srcDir = __DIR__.'/../../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir),
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        expect($content)->not->toContain('proc_open');
        expect($content)->not->toContain('shell_exec');
        expect($content)->not->toContain('passthru(');
        expect($content)->not->toContain('popen(');
    }
});

test('no dangerous function calls in codebase', function (): void {
    $srcDir = __DIR__.'/../../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir),
    );

    $dangerousFunctions = ['\\\\bsystem\\s*\\(', '\\\\bpassthru\\s*\\(', '\\\\bpopen\\s*\\('];

    foreach ($files as $file) {
        if ($file->isDir()) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());

        foreach ($dangerousFunctions as $dangerouFunction) {
            expect($content)->not->toMatch('/'.$dangerouFunction.'/');
        }
    }
});

test('subshell does not leak variables', function (): void {
    $bash = new Bash;
    $bashExecResult = $bash->exec('x=outer; (x=inner); echo $x');
    expect($bashExecResult->stdout)->toBe("outer\n");
});

test('function local variables do not leak', function (): void {
    $bash = new Bash;
    $bashExecResult = $bash->exec('f() { local x=secret; }; f; echo "${x:-empty}"');
    expect($bashExecResult->stdout)->toBe("empty\n");
});

test('each exec call has fresh state', function (): void {
    $bash = new Bash;
    $bash->exec('x=hello');

    $bashExecResult = $bash->exec('echo "${x:-unset}"');
    expect($bashExecResult->stdout)->toBe("unset\n");
});

test('filesystem persists across exec calls', function (): void {
    $bash = new Bash;
    $bash->exec('echo data > /tmp/persist.txt');

    $bashExecResult = $bash->exec('cat /tmp/persist.txt');
    expect($bashExecResult->stdout)->toBe("data\n");
});
