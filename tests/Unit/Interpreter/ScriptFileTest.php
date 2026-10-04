<?php

declare(strict_types=1);

use BashBox\Bash;

// Expected values come from GNU bash 5.3 (`bash -c`), with "line N:" and the quoted source line left out of syntax errors.

test('a command named by path runs the file as a script', function (string $script, string $stdout, string $stderr = ''): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, 0]);
})->with([
    'a missing file' => ['./nope; echo $?', "127\n", "bash: ./nope: No such file or directory\n"],
    'a directory' => ['mkdir d; ./d; echo $?', "126\n", "bash: ./d: Is a directory\n"],
    'a file without execute permission' => ["echo 'echo hi' > s; ./s; echo \$?", "126\n", "bash: ./s: Permission denied\n"],
    'a script gets its arguments and $0, and only exported variables' => ["X=2; export Y=3; printf 'echo \"\$0 \$# \$1 [\$X][\$Y][\$FOO]\"; exit 3' > s; chmod +x s; FOO=f ./s a b; echo \$?", "./s 2 a [][3][f]\n3\n"],
    // bash would run python; the sandbox reports the interpreter missing, as bash does for one that isn't installed
    'a bash or sh shebang is honoured, others have no interpreter' => ["printf '#!/bin/bash\\necho bash \$1\\n' > s; printf '#!/usr/bin/env sh\\necho env\\n' > e; printf '#!/usr/bin/python3\\nprint(1)\\n' > p; chmod +x s e p; ./s x; ./e; ./p; echo \$?", "bash x\nenv\n126\n", "bash: ./p: /usr/bin/python3: bad interpreter: No such file or directory\n"],
    "the script's changes stay in the child" => ["printf 'f(){ :; }; x=1; cd /; exit 4' > s; chmod 755 s; ./s; echo \$? \"[\$x]\"; type -t f; [ \"\$PWD\" != / ] && echo kept", "4 []\nkept\n"],
    "the script reads the caller's stdin" => ["printf 'read l; echo got \$l' > s; chmod +x s; printf 'a\\nb\\n' | { ./s; read m; echo m=\$m; }", "got a\nm=b\n"],
    'a script that does not parse is reported under its name' => ["printf 'if then' > s; chmod +x s; ./s; echo \$?", "2\n", "./s: syntax error near unexpected token `then'\n"],
    'command runs a script file too' => ["printf 'echo in' > s; chmod +x s; command ./s", "in\n"],
    'sourcing /dev/null does nothing' => ['source /dev/null; echo $?', "0\n"],
]);

test('a script running itself stops at the nesting limit', function (): void {
    expect(fn (): \BashBox\BashExecResult => (new Bash)->exec("printf './s' > s; chmod +x s; ./s"))
        ->toThrow(\BashBox\Exceptions\ExecutionLimitException::class, 'Substitution depth limit exceeded (50)');
});
