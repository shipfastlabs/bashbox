<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).
// bash's own $0 shows here as bashbox.

test('line numbers, caller and the call stack', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'LINENO counts lines, continuations included' => ["echo \$LINENO\n\necho \$LINENO \\\n\$LINENO; echo \$LINENO\necho \"a\nb\" \$LINENO", "1\n3 3\n4\na\nb 5\n"],
    'compound commands use their own line' => ["for i in \$LINENO; do\n  echo \$LINENO \$i\ndone\ncase \$LINENO in *) echo c\$LINENO;; esac\n[[ \$LINENO == 6 ]] && echo yes\nif (( LINENO == 7 ))\nthen echo \$((LINENO)); fi", "2 1\nc4\n"],
    'command substitution and eval count on from their line' => ["echo \$(echo \$LINENO\necho \$LINENO)\neval \"echo \\\$LINENO\necho \\\$LINENO\"\necho \$LINENO", "1 2\n3\n4\n5\n"],
    'a function body keeps its definition lines' => ["f() {\n  echo \"in \$LINENO\"\n}\n\nf\necho \$LINENO", "in 2\n6\n"],
    'LINENO assignments do not stick, a local does, unset makes it plain' => ["LINENO=50\necho \$LINENO\nh() { local LINENO=7; echo \$LINENO; }\nh\necho \$LINENO\nunset LINENO; echo \"[\$LINENO]\"\necho \"[\$LINENO]\"", "2\n7\n5\n[]\n[]\n"],
    'caller and caller N walk the call stack' => ["f() {\n  caller\n  caller 0\n  g\n}\ng() { caller 0; caller 1; caller 2; echo \$?; }\nf\ncaller; echo \$?", "7 NULL\n4 f bashbox\n1\n1\n"],
    'FUNCNAME, BASH_LINENO and BASH_SOURCE' => ["f() { echo \"\${FUNCNAME[*]}|\${BASH_LINENO[*]}|\${BASH_SOURCE[*]}\"; }\ng() {\n  f\n}\ng\necho \"[\${FUNCNAME[*]}|\${BASH_LINENO[*]}]\"; declare -p BASH_LINENO", "f g|3 5|bashbox bashbox\n[|]\ndeclare -a BASH_LINENO=()\n"],
    'caller rejects a bad frame number' => ["f() { caller x; echo \$?; caller -1; echo \$?; caller 0 1; echo \$?; }\ng() { f; }\ng", "2\n2\n2 g bashbox\n0\n", "bash: caller: x: invalid number\ncaller: usage: caller [expr]\nbash: caller: -1: invalid option\ncaller: usage: caller [expr]\n"],
    'a sourced file is a frame of its own' => ["printf 'echo \"in \$LINENO\"\\nf() { caller; caller 0; caller 1; echo \"\${FUNCNAME[*]}|\${BASH_LINENO[*]}|\${BASH_SOURCE[*]}\"; }\\nf\\ncaller; caller 0; echo \"s=\$?\"\\n' > t.sh\necho \$LINENO\n\n. ./t.sh\nf\ng() { f; }\ng", "2\nin 1\n3 ./t.sh\n3 source ./t.sh\nf source|3 4|./t.sh ./t.sh\n4 NULL\ns=1\n5 NULL\nf|5|./t.sh\n6 bashbox\n6 g bashbox\nf g|6 7|./t.sh bashbox\n"],
    'the ERR trap and eval report the failing line' => ["trap 'echo err \$LINENO' ERR\necho x; false\neval \"echo ev \\\$LINENO\nfalse\"", "x\nerr 2\nev 3\nerr 4\nerr 3\n", '', 1],
    'an expansion error drops the rest of the line it ends on' => ["for i in 1; do\n  echo \$((1/0))\ndone; echo same\necho next\necho a; for i in 1; do echo \${x/}; done; echo same\necho next\n{ echo a; echo \${y!}; echo b; }; echo same\nif true; then\necho \${x!}; fi; echo same\necho last", "next\na\n\nsame\nnext\na\nlast\n", "bash: 1/0: division by 0 (error token is \"0\")\nbash: \${y!}: bad substitution\nbash: \${x!}: bad substitution\n"],
]);
