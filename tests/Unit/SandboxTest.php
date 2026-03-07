<?php

declare(strict_types=1);

use BashBox\Sandbox\Sandbox;
use BashBox\Sandbox\SandboxOptions;

test('sandbox create and run command', function (): void {
    $sandbox = Sandbox::create();
    $sandboxCommandFinished = $sandbox->runCommand('echo hello');

    expect($sandboxCommandFinished->stdout)->toBe("hello\n");
    expect($sandboxCommandFinished->exitCode)->toBe(0);
});

test('sandbox write and read files', function (): void {
    $sandbox = Sandbox::create();
    $sandbox->writeFiles(['/tmp/test.txt' => 'hello world']);

    $content = $sandbox->readFile('/tmp/test.txt');
    expect($content)->toBe('hello world');
});

test('sandbox run command with files', function (): void {
    $sandbox = Sandbox::create();
    $sandbox->writeFiles(['/tmp/data.txt' => "line1\nline2\nline3\n"]);

    $sandboxCommandFinished = $sandbox->runCommand('cat /tmp/data.txt');
    expect($sandboxCommandFinished->stdout)->toBe("line1\nline2\nline3\n");
});

test('sandbox mkdir', function (): void {
    $sandbox = Sandbox::create();
    $sandbox->mkDir('/tmp/nested/dir');

    $sandboxCommandFinished = $sandbox->runCommand('[[ -d /tmp/nested/dir ]]');
    expect($sandboxCommandFinished->exitCode)->toBe(0);
});

test('sandbox with custom options', function (): void {
    $sandbox = Sandbox::create(new SandboxOptions(
        cwd: '/workspace',
        env: ['PROJECT' => 'myapp'],
    ));

    $result = $sandbox->runCommand('echo $PROJECT');
    expect($result->stdout)->toBe("myapp\n");

    $result = $sandbox->runCommand('pwd');
    expect($result->stdout)->toBe("/workspace\n");
});

test('sandbox with initial files', function (): void {
    $sandbox = Sandbox::create(new SandboxOptions(
        initialFiles: ['/app/config.txt' => 'key=value'],
    ));

    $sandboxCommandFinished = $sandbox->runCommand('cat /app/config.txt');
    expect($sandboxCommandFinished->stdout)->toBe('key=value');
});

test('sandbox filesystem persists across commands', function (): void {
    $sandbox = Sandbox::create();

    $sandbox->runCommand('echo "created" > /tmp/persist.txt');

    $sandboxCommandFinished = $sandbox->runCommand('cat /tmp/persist.txt');

    expect($sandboxCommandFinished->stdout)->toBe("created\n");
});

test('sandbox command stderr', function (): void {
    $sandbox = Sandbox::create();
    $sandboxCommandFinished = $sandbox->runCommand('nonexistent_cmd');

    expect($sandboxCommandFinished->exitCode)->toBe(127);
    expect($sandboxCommandFinished->stderr)->toContain('command not found');
});
