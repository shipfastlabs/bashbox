<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;
use BashBox\Security\SecurityViolationLogger;
use BashBox\Security\SecurityViolationType;

// ===== Execution Limits =====

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

// ===== Filesystem Security =====

test('null byte in filename is rejected', function (): void {
    $bash = new Bash;
    expect(fn (): \BashBox\BashExecResult => $bash->exec("echo test > /tmp/evil\x00.txt"))
        ->toThrow(RuntimeException::class);
});

// ===== No Shell Execution =====

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
        foreach ($dangerousFunctions as $pattern) {
            expect($content)->not->toMatch('/'.$pattern.'/');
        }
    }
});

// ===== Security Violation Logger =====

test('security violation logger tracks violations', function (): void {
    $logger = new SecurityViolationLogger;

    expect($logger->hasViolations())->toBeFalse();
    expect($logger->count())->toBe(0);

    $logger->log(SecurityViolationType::PATH_TRAVERSAL, 'Path traversal attempt', ['path' => '../etc/passwd']);
    $logger->log(SecurityViolationType::NULL_BYTE, 'Null byte in path', ['path' => "file\0.txt"]);

    expect($logger->hasViolations())->toBeTrue();
    expect($logger->count())->toBe(2);

    $violations = $logger->getViolations();
    expect($violations[0]['type'])->toBe(SecurityViolationType::PATH_TRAVERSAL);
    expect($violations[1]['type'])->toBe(SecurityViolationType::NULL_BYTE);

    $logger->clear();
    expect($logger->hasViolations())->toBeFalse();
});

// ===== Isolation =====

test('subshell does not leak variables', function (): void {
    $bash = new Bash;
    $result = $bash->exec('x=outer; (x=inner); echo $x');
    expect($result->stdout)->toBe("outer\n");
});

test('function local variables do not leak', function (): void {
    $bash = new Bash;
    $result = $bash->exec('f() { local x=secret; }; f; echo "${x:-empty}"');
    expect($result->stdout)->toBe("empty\n");
});

test('each exec call has fresh state', function (): void {
    $bash = new Bash;
    $bash->exec('x=hello');

    $result = $bash->exec('echo "${x:-unset}"');
    expect($result->stdout)->toBe("unset\n");
});

test('filesystem persists across exec calls', function (): void {
    $bash = new Bash;
    $bash->exec('echo data > /tmp/persist.txt');

    $result = $bash->exec('cat /tmp/persist.txt');
    expect($result->stdout)->toBe("data\n");
});
