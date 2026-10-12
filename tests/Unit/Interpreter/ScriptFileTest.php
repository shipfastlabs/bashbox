<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecOptions;
use BashBox\Limits;

// Expected values come from GNU bash 5.3 (`bash -c`), with "line N:" and the quoted source line left out of syntax errors.

test('a command named by path runs the file as a script', function (string $script, string $stdout, string $stderr = ''): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, 0]);
})->with([
    'a missing file' => ['./nope; echo $?', "127\n", "bash: ./nope: No such file or directory\n"],
    'a directory' => ['mkdir d; ./d; echo $?', "126\n", "bash: ./d: Is a directory\n"],
    'a file without execute permission' => ["echo 'echo hi' > s; ./s; echo \$?", "126\n", "bash: ./s: Permission denied\n"],
    'a script gets its arguments and $0, and only exported variables' => ["X=2; export Y=3; printf 'echo \"\$0 \$# \$1 [\$X][\$Y][\$FOO]\"; exit 3' > s; chmod +x s; FOO=f ./s a b; echo \$?", "./s 2 a [][3][f]\n3\n"],
    // bash would run python; the sandbox reports the interpreter missing, as bash does for one that isn't installed.
    'a bash or sh shebang is honoured, others have no interpreter' => ["printf '#!/bin/bash\\necho bash \$1\\n' > s; printf '#!/usr/bin/env sh\\necho env\\n' > e; printf '#!/usr/bin/python3\\nprint(1)\\n' > p; chmod +x s e p; ./s x; ./e; ./p; echo \$?", "bash x\nenv\n126\n", "bash: ./p: /usr/bin/python3: bad interpreter: No such file or directory\n"],
    "the script's changes stay in the child" => ["printf 'f(){ :; }; x=1; cd /; exit 4' > s; chmod 755 s; ./s; echo \$? \"[\$x]\"; type -t f; [ \"\$PWD\" != / ] && echo kept", "4 []\nkept\n"],
    "the script reads the caller's stdin" => ["printf 'read l; echo got \$l' > s; chmod +x s; printf 'a\\nb\\n' | { ./s; read m; echo m=\$m; }", "got a\nm=b\n"],
    'a script that does not parse is reported under its name' => ["printf 'if then' > s; chmod +x s; ./s; echo \$?", "2\n", "./s: syntax error near unexpected token `then'\n"],
    'command runs a script file too' => ["printf 'echo in' > s; chmod +x s; command ./s", "in\n"],
    'sourcing /dev/null does nothing' => ['source /dev/null; echo $?', "0\n"],
]);

test('a script running itself stops at the nesting limit', function (): void {
    expect(fn (): BashExecResult => (new Bash)->exec("printf './s' > s; chmod +x s; ./s"))
        ->toThrow(ExecutionLimitException::class, 'Substitution depth limit exceeded (50)');
});

test('bash and sh run isolated noninteractive child shells', function (string $script, string $stdout, string $stderr = '', int $status = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $status]);
})->with([
    'bash positional arguments' => ['bash -c \'printf "[%s]\\n" "$0" "$@"; echo "$#"\' agent "two words" ""', "[agent]\n[two words]\n[]\n2\n"],
    'sh positional arguments' => ['sh -c \'echo "$0|$1|$#"\' agent value', "agent|value|1\n"],
    'default and empty zero' => ['bash -c \'echo "$0|$#"\'; sh -c \'echo "$0|$#"\' ""', "bash|0\n|0\n"],
    'dash zero is an argument not a shell flag' => ['bash -c \'echo "$0|$1"\' -zero one', "-zero|one\n"],
    'only exported variables and no parent functions or options' => ['x=private; export y=public; f(){ :; }; set -u; Z=temp bash -c \'echo "[$x][$y][$Z]"; type -t f; echo "$-"\'', "[][public][temp]\nhBc\n"],
    'child mutations stay in the child' => ['x=parent; bash -c \'x=child; export y=child; cd /; exit 7\'; echo "$?|$x|$y"; pwd', "7|parent|\n/home/user\n"],
    'child stdin leaves unread data for the parent' => ["printf 'a\\nb\\n' | { bash -c 'read x; echo \"child=\$x\"'; read x; echo \"parent=\$x\"; }", "child=a\nparent=b\n"],
    'redirections and pipelines' => ["sh -c 'echo out; echo err >&2' 2>err | tr a-z A-Z; cat err", "OUT\nerr\n"],
    'env can invoke a shell' => ['env -i X=passed sh -c \'echo "[$X][$USER]"\'', "[passed][user]\n"],
    'xargs can invoke a shell' => ["printf 'one\\ntwo words\\n' | xargs -I{} bash -c 'echo \"\$1\"' agent '{}'", "one\ntwo words\n"],
    'a file does not need execute permission' => ["printf 'echo \"\$0|\$1\"' > script; bash script hi", "script|hi\n"],
    'read a script from stdin' => ["printf 'echo from-stdin' | sh", "from-stdin\n"],
    'stdin script with lone dash' => ["printf 'echo from-stdin' | bash -", "from-stdin\n"],
    'stdin script has arguments with -s' => ["printf 'echo \"\$0|\$1\"' | bash -s value", "bash|value\n"],
    '-- ends options' => ["printf 'echo file' > ./-file; sh -- -file", "file\n"],
    'a missing script' => ['bash missing', '', "bash: missing: No such file or directory\n", 127],
    'a directory is not a script' => ['mkdir directory; sh directory', '', "sh: directory: Is a directory\n", 126],
    'missing -c argument' => ['sh -c', '', "sh: -c requires a command string\nTry 'sh --help' for more information.\n", 2],
    'unsupported shell options fail explicitly' => ['bash -i', '', "bash: unsupported option: -i\nTry 'bash --help' for more information.\n", 2],
    'invalid child script does not run a prefix or end the parent' => ['bash -c \'echo bad; if then\'; echo "$?"; echo parent', "2\nparent\n", "bash: syntax error near unexpected token `then'\n"],
    'child inherits umask' => ['umask 077; bash -c umask', "0077\n"],
]);

test('PATH locates executable scripts consistently', function (string $script, string $stdout, string $stderr = '', int $status = 0): void {
    $bash = new Bash;
    $bash->writeFile('/tools/task', 'printf "[%s]\\n" "$0" "$@"; exit 3');
    $bash->writeFile('/other/task', 'echo second');
    $bash->writeFile('/home/user/local-script', 'echo local');
    $bash->getFilesystem()->chmod('/tools/task', 0o755);
    $bash->getFilesystem()->chmod('/other/task', 0o755);
    $bash->getFilesystem()->chmod('/home/user/local-script', 0o755);
    $bashExecResult = $bash->exec($script, new ExecOptions(env: ['PATH' => '/tools:/other']));

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $status]);
})->with([
    'direct lookup and status' => ['task "a b"; echo "$?"', "[/tools/task]\n[a b]\n3\n"],
    'command lookup' => ['command task hi', "[/tools/task]\n[hi]\n", '', 3],
    'exec lookup ends the shell' => ['exec task hi; echo never', "[/tools/task]\n[hi]\n", '', 3],
    'exec by explicit path' => ['exec /tools/task hi', "[/tools/task]\n[hi]\n", '', 3],
    'env lookup with overridden PATH' => ['env PATH=/other task', "second\n"],
    'env lookup keeps argv' => ['env task "a b"', "[/tools/task]\n[a b]\n", '', 3],
    'xargs lookup keeps argv' => ["printf 'a b\\0' | xargs -0 task", "[/tools/task]\n[a b]\n", '', 123],
    'child lookup inherits PATH' => ["bash -c 'task hi'", "[/tools/task]\n[hi]\n", '', 3],
    'which type and command -v' => ['which task; type task; type -t task; command -v task', "/tools/task\ntask is /tools/task\nfile\n/tools/task\n"],
    'relative PATH entries' => ['PATH=../../tools; task', "[../../tools/task]\n", '', 3],
    'an empty PATH component is cwd' => ['PATH=; local-script', "local\n"],
    'skip unexecutable first match' => ['chmod 644 /tools/task; task', "second\n"],
    'all matches unexecutable' => ['chmod 644 /tools/task /other/task; task', '', "bash: /tools/task: Permission denied\n", 126],
    'env rejects unexecutable matches' => ['chmod 644 /tools/task /other/task; env task', '', "env: 'task': Permission denied\n", 126],
    'directory entries are not commands' => ['mkdir /tools/notcmd; notcmd', '', "bash: notcmd: command not found\n", 127],
    'unknown name remains 127' => ['unknown', '', "bash: unknown: command not found\n", 127],
    'which ignores files without execute bits' => ['chmod 644 /tools/task /other/task; which task', '', '', 1],
    'bundled commands remain available with empty PATH' => ['PATH=; env echo bundled', "bundled\n"],
]);

test('child shells share nesting and command budgets', function (Limits $limits, string $script, string $message): void {
    expect(fn (): BashExecResult => (new Bash)->exec($script, new ExecOptions(limits: $limits)))
        ->toThrow(ExecutionLimitException::class, $message);
})->with([
    'combined commands' => [new Limits(maxCommandCount: 3), 'echo one; bash -c "echo two; echo three"', 'Command count limit exceeded'],
    'nested shell commands' => [new Limits(maxSubstitutionDepth: 2), 'bash -c \'sh -c "bash -c true"\'', 'Substitution depth limit exceeded (2)'],
]);
