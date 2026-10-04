<?php

declare(strict_types=1);

use BashBox\Bash;

// stdout and status match GNU bash 5.3 (`bash -c`); stderr is bash's minus its "line N:" and the quoted source line.

test('syntax errors are reported like bash -c', function (string $script, string $stdout, string $stderr, int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'a script that does not parse runs nothing' => ['cd /tmp; echo ok; fi', '', "bash: syntax error near unexpected token `fi'\n", 2],
    'eval reports and returns 2' => ['eval fi; echo $?', "2\n", "bash: eval: syntax error near unexpected token `fi'\n"],
    'a sourced file reports under its name and returns 2' => ["echo 'if then' > bad.sh; source ./bad.sh; echo \$?", "2\n", "./bad.sh: syntax error near unexpected token `then'\n"],
    'a syntax error inside $(...) ends the script with 127' => ["echo a\nx=\$(fi)\necho b", "a\n", "bash: syntax error near unexpected token `fi' while looking for matching `)'\n", 127],
    'so does one inside <(...)' => ["echo a\ncat <(fi)", "a\n", "bash: syntax error near unexpected token `fi' while looking for matching `)'\n", 127],
    'a list cut short by the closing paren names it' => ["echo a\nx=\$(if true)", "a\n", "bash: syntax error near unexpected token `)'\n", 127],
    'a word followed by ( starts a function definition' => ['echo (', '', "bash: syntax error near unexpected token `newline'\n", 2],
    'whose ( must be followed by )' => ['echo ( a )', '', "bash: syntax error near unexpected token `a'\n", 2],
    'a backquoted substitution is parsed when it runs and fails with 2' => ['x=`fi`; echo "[$x] $?"', "[] 2\n", "bash: command substitution: syntax error near unexpected token `fi'\n"],
    'the EXIT trap reports its own syntax error' => ['trap fi EXIT; echo a', "a\n", "bash: exit trap: syntax error near unexpected token `fi'\n"],
    'the ERR trap reports its own syntax error' => ['trap fi ERR; false; echo $?', "1\n", "bash: error trap: syntax error near unexpected token `fi'\n"],
    'the RETURN trap reports its own syntax error' => ['f(){ trap fi RETURN; }; f; echo $?', "0\n", "bash: return trap: syntax error near unexpected token `fi'\n"],
]);

test('a command substitution ends where its command list does', function (string $script, string $stdout): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, '', 0]);
})->with([
    'a case pattern' => ['x=$(case a in a) echo A;; esac); echo "[$x]"', "[A]\n"],
    'a case pattern in double quotes' => ['echo "$(case b in a) echo A;; b) echo B;; esac)"', "B\n"],
    'a comment' => ["x=\$( # comment with )\necho hi); echo \"[\$x]\"", "[hi]\n"],
    'a comment after a command' => ["x=\$(echo a #)\n); echo \$x", "a\n"],
    'a here-document' => ["x=\$(cat <<EOF\n)\nEOF\n); echo \"[\$x]\"", "[)]\n"],
    'a process substitution' => ['cat <(case a in a) echo P;; esac)', "P\n"],
    'nested substitutions' => ['echo $(echo $(case a in a) echo in;; esac) out)', "in out\n"],
    'arithmetic still counts parentheses' => ['echo $(( (1 + 2) * 3 ))', "9\n"],
]);
