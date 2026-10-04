<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Commands\CommandContext;
use BashBox\Commands\CommandInterface;
use BashBox\Commands\Curl_;
use BashBox\ExecOptions;
use BashBox\ExecResult;
use BashBox\Network\NetworkConfig;

test('isSuccess reflects the exit code of the script', function (): void {
    $bash = new Bash;

    expect($bash->exec('true')->isSuccess())->toBeTrue()
        ->and($bash->exec('false')->isSuccess())->toBeFalse()
        ->and($bash->exec('exit 3')->isSuccess())->toBeFalse();
});

test('curl exists only when network access is configured', function (): void {
    $bashExecResult = (new Bash)->exec('curl http://example.com/');
    $online = new Bash(new BashOptions(network: new NetworkConfig))->exec('curl http://example.com/');

    expect($bashExecResult->exitCode)->toBe(127)
        ->and($online->exitCode)->toBe(6)
        ->and($online->stderr)->toContain('is not in the allowed URL prefixes');
});

test('a manually registered curl refuses to run without network access', function (): void {
    $bash = new Bash;
    $bash->registerCommand(new Curl_);

    $bashExecResult = $bash->exec('curl http://example.com/');

    expect($bashExecResult->stderr)->toBe("curl: network is not configured\n")
        ->and($bashExecResult->exitCode)->toBe(1);
});

test('registered commands receive arguments, stdin and cwd', function (): void {
    $bash = new Bash;
    $bash->registerCommand(new class implements CommandInterface
    {
        public function getName(): string
        {
            return 'greet';
        }

        public function execute(array $args, CommandContext $commandContext): ExecResult
        {
            return new ExecResult(stdout: sprintf("%s %s from %s\n", trim($commandContext->stdin), implode(' ', $args), $commandContext->cwd));
        }
    });

    $bashExecResult = $bash->exec('cd /tmp && echo hello | greet big world');

    expect($bashExecResult->stdout)->toBe("hello big world from /tmp\n")
        ->and($bashExecResult->isSuccess())->toBeTrue();
});

test('a cwd that does not exist yet is created, per exec as in the constructor', function (): void {
    $bash = new Bash(new BashOptions(cwd: '/work/a'));
    $bashExecResult = $bash->exec('pwd; touch f; cd ..; ls', new ExecOptions(cwd: '/nope/b'));

    expect([$bashExecResult->stdout, $bashExecResult->exitCode])->toBe(["/nope/b\nb\n", 0])
        ->and($bash->getFilesystem()->exists('/nope/b/f'))->toBeTrue()
        ->and($bash->getFilesystem()->exists('/work/a'))->toBeTrue();
});
