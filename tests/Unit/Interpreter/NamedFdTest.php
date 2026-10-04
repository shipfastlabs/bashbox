<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected value is bash 5.3's (`bash -c`, LC_ALL=en_US.UTF-8), with "bash: line N:" shortened to "bash:".
test('named fd redirections behave like bash', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'exec opens the lowest free fd from 10' => ['exec {fd}>f; echo $fd; echo hi >&$fd; exec {fd}>&-; cat f', "10\nhi\n"],
    'fds in use are skipped' => ['exec 10>x 11>y; exec {a}>f {b}>g; echo $a $b', "12 13\n"],
    'a regular command keeps it open' => ['echo hi {fd}>f; echo $fd; echo there >&$fd; cat f', "hi\n10\nthere\n"],
    'as a prefix' => ['{fd}>f echo x; echo $fd', "x\n10\n"],
    'read from a named fd' => ["printf 'a\\nb\\n' > f; exec {fd}<f; read x <&\$fd; read y <&\$fd; echo \$x \$y", "a b\n"],
    'here-string and here-document' => ["exec {h}<<<'line'; read x <&\$h; echo \"\$x \$h\"; exec {d}<<E\ndoc\nE\nread y <&\$d; echo \"\$y \$d\"", "line 10\ndoc 11\n"],
    'read-write and append' => ['exec {fd}<>f; echo hi >&$fd; exec {a}>>f; echo more >&$a; cat f', "hi\nmore\n"],
    'duplicating fds' => ['exec {fd}>&1; echo $fd; echo to-stdout >&$fd; exec {in}<&0; echo $in', "10\nto-stdout\n11\n"],
    'a closed fd is reused' => ['exec {fd}>f; echo $fd; exec {fd}>&-; exec {fd}>g; echo $fd', "10\n10\n"],
    'writing to a closed named fd' => ['exec {fd}>f; exec {fd}>&-; echo hi >&$fd; echo $?', "1\n", "bash: \$fd: Bad file descriptor\n"],
    'closing needs a number' => ['exec {fd}>&-; echo $?; fd=foo; exec {fd}<&-; echo $?', "1\n1\n", "bash: fd: ambiguous redirect\nbash: fd: ambiguous redirect\n"],
    'a dup to a word is ambiguous' => ['exec {fd}>&f; echo "$? [$fd]"', "1 []\n", "bash: fd: ambiguous redirect\n"],
    'a readonly variable' => ['readonly fd=3; exec {fd}>f; echo $?', "1\n", "bash: fd: readonly variable\nbash: fd: cannot assign fd to variable\n"],
    'a failed open leaves the variable alone' => ['exec {fd}>/nonexistent/x; echo "$? [$fd]"', "1 []\n", "bash: /nonexistent/x: No such file or directory\n"],
    'into a local' => ['f(){ local fd; exec {fd}>f; echo $fd; }; f; echo "[$fd]"', "10\n[]\n"],
    'into an array element' => ['x=(1 2); exec {x[1]}>f; echo ${x[@]}; exec {x[1]}>&-; echo $?', "1 10\n0\n"],
    'not a name, so just a word' => ['echo {1x}>f; cat f', "{1x}\n"],
    'on a compound command' => ['{ echo in; } {fd}>f; echo $fd; cat f', "in\n10\n"],
    'a function listing keeps the names' => ["f(){ echo a {fd}>f; cat {x}<f 2>&1; read {y}<<<w; exec {z}>&-; echo {q}>&2; echo {r}>&\$x; cat {h}<<E\nx\nE\n}; declare -f f", "f () \n{ \n    echo a {fd}> f;\n    cat {x}< f 2>&1;\n    read {y}<<< w;\n    exec {z}>&-;\n    echo {q}>&2;\n    echo {r}>&\$x;\n    cat {h}<<E\nx\nE\n\n}\n"],
    'a numbered here-string and here-document' => ["exec 3<<<hi; read x <&3; echo \$x; cat 4<<E <&4\ndoc\nE", "hi\ndoc\n"],
    'a bad fd names the word as written' => ['x=10; echo hi >&$x; cat <&$x; cat 3<&"$x"', '', "bash: \$x: Bad file descriptor\nbash: \$x: Bad file descriptor\nbash: 3: Bad file descriptor\n", 1],
]);
