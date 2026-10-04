<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected value is bash 5.3's (`bash -c`, LC_ALL=en_US.UTF-8), with "bash: line N:" shortened to "bash:".
test('process substitution behaves like bash', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'cat reads a substitution' => ['cat <(echo hi)', "hi\n"],
    'two substitutions are numbered down from 63' => ['echo <(true) <(true) >(true); cat <(echo a) <(echo b)', "/dev/fd/63 /dev/fd/62 /dev/fd/61\na\nb\n"],
    'the number is reused once the command is done' => ['echo <(true); f(){ echo <(true); }; f', "/dev/fd/63\n/dev/fd/63\n"],
    'part of a word' => ['echo x<(true)', "x/dev/fd/63\n"],
    'quoted or escaped it stays text' => ["echo \"<(true)\" '<(x)' \\<\\(y\\)", "<(true) <(x) <(y)\n"],
    'redirected into a loop' => ["while read l; do echo \"[\$l]\"; done < <(printf '1\\n2\\n')", "[1]\n[2]\n"],
    'on another fd' => ['cat 3< <(echo fd3) <&3; exec 4< <(echo four); read x <&4; echo $x', "fd3\nfour\n"],
    'kept open for a whole loop' => ['for f in <(echo a) <(echo b); do cat $f; done', "a\nb\n"],
    'its status is not the command status' => ['cat <(echo a; exit 3); echo $?', "a\n0\n"],
    'it runs in a subshell' => ['x=1; cat <(x=2; echo $x); echo $x', "2\n1\n"],
    'assigned to a variable' => ['x=<(true); echo $x', "/dev/fd/63\n"],
    'sort and grep read it as a file' => ["sort <(printf 'b\\na\\n'); grep -c x <(printf 'x\\ny\\nx\\n')", "a\nb\n2\n"],
    'nested substitutions' => ['cat <(cat <(echo inner)); cat <(echo $(echo cmd))', "inner\ncmd\n"],
    'a writer feeds the reader afterwards' => ['echo hi > >(tr a-z A-Z)', "HI\n"],
    'tee into a reader' => ['echo abc | tee >(rev) >/dev/null', "cba\n"],
    'a reader whose file was removed' => ['rm -f >(cat) 2>/dev/null; echo done', "done\n"],
    'a case word' => ['case <(true) in /dev/fd/*) echo y;; esac', "y\n"],
    'errexit holds inside' => ['set -e; cat <(false; echo x); echo y', "y\n"],
    'a function listing keeps it' => ['f(){ cat <(echo a) > >(cat); }; declare -f f', "f () \n{ \n    cat <(echo a) > >(cat)\n}\n"],
]);
