<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Commands\CommandContext;
use BashBox\Commands\CommandInterface;
use BashBox\Commands\Curl_;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecOptions;
use BashBox\ExecResult;
use BashBox\Limits;
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

test('exec arguments are literal data and apply only once', function (): void {
    $bash = new Bash;
    $args = ['', 'two words', "quote'\"", '$HOME', '$(touch /tmp/injected)', '; touch /tmp/injected', '*', "line\nbreak", "nul\0byte"];
    $bashExecResult = $bash->exec("printf '<%s>\\n'; echo after", new ExecOptions(args: $args));

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])
        ->toBe([implode('', array_map(fn (string $arg): string => '<'.$arg.">\n", $args))."after\n", '', 0])
        ->and($bash->getFilesystem()->exists('/tmp/injected'))->toBeFalse();
});

test('exec arguments reach the first command rather than expansions or later commands', function (string $script, string $stdout): void {
    $bashExecResult = (new Bash)->exec($script, new ExecOptions(args: ['literal $HOME']));

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, '', 0]);
})->with([
    'argument substitution' => ["printf '<%s>\\n' \"\$(echo inner)\"; echo after", "<inner>\n<literal \$HOME>\nafter\n"],
    'assignment without a command' => ['x=$(echo inner); echo "$x"; echo after', "inner literal \$HOME\nafter\n"],
    'compound condition skips a branch' => ['if [[ x == y ]]; then echo skipped; else echo chosen; fi; echo after', "chosen literal \$HOME\nafter\n"],
    'for word substitution' => ['for x in $(echo item); do echo "$x"; done; echo after', "item literal \$HOME\nafter\n"],
    'compound redirection substitution' => ['{ echo chosen; } >$(echo file); cat file', "chosen literal \$HOME\n"],
    'function invocation' => ['f(){ printf "<%s>\\n" "$@"; }; f existing; f later', "<existing>\n<literal \$HOME>\n<later>\n"],
    'a pipeline consumes arguments once' => ['echo first | cat; echo after', "first literal \$HOME\nafter\n"],
]);

test('literal arguments can supply a nested shell command and positional data safely', function (): void {
    $bashExecResult = (new Bash)->exec('bash', new ExecOptions(args: ['-c', 'printf "[%s]\\n" "$0" "$@"', 'agent', 'a b', '$(echo injected)', '']));

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])
        ->toBe(["[agent]\n[a b]\n[\$(echo injected)]\n[]\n", '', 0]);
});

test('literal arguments respect string and count limits', function (Limits $limits, string $script, array $args, string $message): void {
    expect(fn (): BashExecResult => (new Bash)->exec($script, new ExecOptions(limits: $limits, args: $args)))
        ->toThrow(ExecutionLimitException::class, $message);
})->with([
    [new Limits(maxStringLength: 3), 'echo', ['four'], 'Argument size limit exceeded'],
    [new Limits(maxArrayElements: 1), 'echo', ['one', 'two'], 'Argument count limit exceeded'],
    [new Limits(maxArrayElements: 1), 'echo one', ['two'], 'Argument count limit exceeded'],
]);

test('literal arguments reject nonstrings and named keys at the API boundary', function (array $args): void {
    expect(fn (): BashExecResult => (new Bash)->exec('echo', new ExecOptions(args: $args)))
        ->toThrow(InvalidArgumentException::class, 'ExecOptions args must be a list of strings');
})->with([[[null]], [['name' => 'value']]]);

test('an expansion error does not consume literal arguments before a command runs', function (): void {
    $bashExecResult = (new Bash)->exec("x=\$((1/0))\necho recovered", new ExecOptions(args: ['literal']));

    expect($bashExecResult->stdout)->toBe("recovered literal\n")
        ->and($bashExecResult->stderr)->toContain('division by 0')
        ->and($bashExecResult->exitCode)->toBe(0);
});
