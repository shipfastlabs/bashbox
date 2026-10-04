<?php

declare(strict_types=1);

use BashBox\Bash;

// The ERR trap and set -e: tested commands are exempt, and functions and subshells inherit the trap only under set -E.
// Every expected value is bash 5.3's (`bash -c`, LC_ALL=en_US.UTF-8), with "bash: line N:" shortened to "bash:".
test('the ERR trap and errexit behave like bash', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'functions do not inherit the ERR trap' => ["trap 'echo err' ERR; f(){ false; echo in; }; f; echo out", "in\nout\n"],
    'the failing call still fires it' => ["trap 'echo err' ERR; f(){ g; }; g(){ false; }; f; echo end", "err\nend\n"],
    'set -E passes it to functions' => ["set -E; trap 'echo err \$LINENO' ERR; f(){ false; }; f; echo \$-; set +E; f; echo end", "err 1\nerr 1\nhBEc\nerr 1\nend\n"],
    'set -o errtrace' => ["set -o errtrace; trap 'echo err' ERR; f(){ false; return 0; }; f; shopt -o errtrace", "err\nerrtrace            \ton\n"],
    'a trap set in a function stays' => ["f(){ trap 'echo ferr \$LINENO' ERR; false; }\nf\nf\nfalse\necho end", "ferr 1\nferr 1\nferr 3\nferr 4\nend\n"],
    'the call fires only a trap set before it' => ["f(){ trap 'echo t' ERR; return 1; }; f; false; echo end", "t\nend\n"],
    'trap - ERR in a function leaves the outer trap' => ["trap 'echo err \$LINENO' ERR\nf(){ trap - ERR; false; }\nf\nfalse", "err 3\nerr 4\n", '', 1],
    'eval fires the trap it found' => ["trap 'echo a' ERR; eval false; eval \"trap 'echo b' ERR; false\"; echo end", "a\na\nb\nb\nend\n"],
    'conditions do not fire it' => ["trap 'echo err' ERR; if false; then :; elif false; then :; fi; while false; do :; done; until true; do :; done; echo end", "end\n"],
    'tested function calls do not fire it' => ["trap 'echo err' ERR; f(){ false; echo in; }; f || true; f && true; ! f; if f; then :; fi; echo end", "in\nin\nin\nin\nend\n"],
    'and-or lists fire only for the last' => ["trap 'echo err' ERR; false && true; false || false; echo end", "err\nend\n"],
    'compound commands fire only through their commands' => ["trap 'echo err' ERR; { false; }; if true; then false; fi; for i in 1; do false; done; case x in x) false;; esac; echo end", "err\nerr\nerr\nerr\nend\n"],
    'a failing subshell fires it once' => ["trap 'echo err' ERR; (false); (false; true); echo end", "err\nend\n"],
    'set -E reaches subshells' => ["set -E; trap 'echo err' ERR; (false; true); echo end", "err\nend\n"],
    'command substitution drops the ERR trap' => ["trap 'echo err' ERR; x=\$(false; echo in); echo \"[\$x]\"; f(){ false; }; y=\$(f); echo \"[\$y]\"", "[in]\nerr\n[]\n"],
    'set -E keeps it in command substitution' => ["set -E; trap 'echo err' ERR; x=\$(false; echo in); echo \"[\$x]\"", "[err\nin]\n"],
    'arithmetic and [[ ]] fire it' => ["trap 'echo err' ERR; [[ 1 = 2 ]]; (( 0 )); echo end", "err\nerr\nend\n"],
    'errexit ignores conditions' => ['set -e; if false; then :; fi; while false; do :; done; f(){ false; echo in; }; f || true; f && true; ! f; echo end', "in\nin\nin\nend\n"],
    'errexit is off in command substitution' => ['set -e; x=$(false; echo in); echo "[$x]"; false; echo no', "[in]\n", '', 1],
    'inherit_errexit keeps it' => ['set -e; shopt -s inherit_errexit; x=$(false; echo in); echo "[$x]"', '', '', 1],
    'errexit on a failing subshell' => ['set -e; (exit 3); echo no', '', '', 3],
    'set -T passes the RETURN trap to functions' => ["set -T; echo \$-; trap 'echo r' RETURN; g(){ :; }; f(){ g; }; f; echo end", "hBTc\nr\nr\nend\n"],
    'without -T it stays out of nested calls' => ["trap 'echo r' RETURN; g(){ :; }; f(){ g; }; f; echo end", "end\n"],
    'set -o lists the options' => ['set -o | grep -E "errtrace|functrace"; set -E; set +o | grep errtrace', "errtrace       \toff\nfunctrace      \toff\nset -o errtrace\n"],
]);
