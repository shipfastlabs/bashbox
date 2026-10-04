<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\ExecOptions;

// Expected values were checked against GNU bash 5.3 (`bash -c`), whose stderr prefix
// "bash: line 1:" BashBox shortens to "bash:".

function runCore(string $script, ?string $stdin = null): array
{
    $bashExecResult = new Bash(new BashOptions(cwd: '/home/user', env: ['HOME' => '/home/user']))
        ->exec($script, $stdin === null ? null : new ExecOptions(stdin: $stdin));

    return [$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode];
}

test('scripts run with bash semantics', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    expect(runCore($script))->toBe([$stdout, $stderr, $exitCode]);
})->with([
    // Nested scripts keep their own output and exit status
    'command substitution captures only its own output' => ['echo a; x=$(echo b); echo "[$x]"', "a\n[b]\n"],
    'exit inside command substitution ends only the subshell' => ['x=$(echo in; exit 3); echo "$? [$x]"', "3 [in]\n"],
    'assignment-only command reports the substitution status' => ['x=$(false); echo $?', "1\n"],
    'set -e aborts on a failed substitution assignment' => ['echo a; set -e; x=$(exit 3); echo no', "a\n", '', 3],

    // EXIT trap runs once, at the very end
    'EXIT trap waits for the whole script' => ['trap "echo bye" EXIT; eval "echo hi"; x=$(echo in); echo "after $x"', "hi\nafter in\nbye\n"],
    'EXIT trap sees the exit status' => ['trap "echo \$?" EXIT; exit 3', "3\n", '', 3],
    'exit inside the EXIT trap replaces the status' => ['trap "exit 7" EXIT; exit 3', '', '', 7],
    'empty EXIT trap is ignored' => ['trap "" EXIT; echo x', "x\n"],

    // And-or lists
    'skipped && still reaches ||' => ['false && echo a || echo b', "b\n"],
    '|| skips on success' => ['true || echo no; echo $?', "0\n"],
    'ERR trap fires for a failing command' => ['trap "echo caught \$?" ERR; false; echo next $?', "caught 1\nnext 1\n"],
    'ERR trap skips non-final members of an and-or list' => ['trap "echo caught" ERR; false && true; false || true; echo done', "done\n"],
    'exit inside the ERR trap ends the script' => ['trap "echo x; exit 5" ERR; false; echo no', "x\n", '', 5],
    'set -e exits on the final command of a list' => ['set -e; true && false; echo no', '', '', 1],
    'set -e ignores a failure before &&' => ['set -e; false && true; echo yes', "yes\n"],
    'set -e ignores a negated pipeline' => ['set -e; ! true; echo yes', "yes\n"],
    'set -o errexit then set +e' => ['set -o errexit; set +e; false; echo yes', "yes\n"],

    // Pipelines
    'pipeline status is the last command' => ['false | true; echo $?', "0\n"],
    'negated pipeline' => ['! false; echo $?; ! true; echo $?', "0\n1\n"],
    'pipefail reports the rightmost failure' => ['set -o pipefail; false | true; echo $?; set +o pipefail; false | true; echo $?', "1\n0\n"],
    '|& pipes stderr too' => ['{ echo out; echo err >&2; } |& cat', "out\nerr\n"],
    'stderr of a piped command is not piped' => ['{ echo out; echo err >&2; } | cat', "out\n", "err\n"],

    // Compound commands, redirections and dispatch
    'compound command output is redirected' => ['{ echo a; echo b >&2; } > out 2>&1; cat out', "a\nb\n"],
    'loop reads from a redirected file' => ['printf "1\n2\n" > in; while read l; do echo $l; done < in', "1\n2\n"],
    'failed compound redirection skips the command' => ['while read l; do echo "<$l>"; done < nofile; echo $?', "1\n", "bash: nofile: No such file or directory\n"],
    'every compound command kind runs' => ['if true; then echo t; fi | cat; case a in a) echo c;; esac; (echo sub); ((1)) && [[ a ]] && echo arith; until true; do :; done; for ((i=0; i<1; i++)); do echo $i; done', "t\nc\nsub\narith\n0\n"],
    'break and continue with levels' => ['for i in 1 2 3; do for j in a b; do test $j = b && continue 2; test $i = 3 && break 2; echo $i$j; done; done; until false; do break; done; echo end', "1a\n2a\nend\n"],
    'break and continue outside a loop' => ['break; continue 2; echo $?', "0\n", "bash: break: only meaningful in a `for', `while', or `until' loop\nbash: continue: only meaningful in a `for', `while', or `until' loop\n"],
    'break inside a function cannot leave the caller loop' => ['f() { break; }; for i in 1; do f; echo no; done', "no\n", "bash: break: only meaningful in a `for', `while', or `until' loop\n"],

    // Simple commands
    'command not found' => ['nope; echo $?', "127\n", "bash: nope: command not found\n"],
    'command name is field split' => ['CMD="echo a b"; $CMD c', "a b c\n"],
    'empty expansion leaves only the assignment' => ['e=; x=1 $e; echo $x', "1\n"],
    'prefix assignment reaches a command env only' => ['FOO=bar printenv FOO; echo "[$FOO]"', "bar\n[]\n"],
    'prefix assignment is temporary for functions' => ['f() { echo "x=$x"; }; x=1 f; echo "[$x]"', "x=1\n[]\n"],
    'prefix assignment is temporary for builtins' => ['y=0; y=5 eval "echo \$y"; echo $y', "5\n0\n"],
    'repeated prefix assignment restores the original' => ['x=0; x=1 x=2 printenv x; echo $x', "2\n0\n"],
    'readonly prefix assignment is reported, the command still runs' => ['readonly R=1; R=2 echo "[$R]"; echo "s=$?"', "[1]\ns=0\n", "bash: R: readonly variable\n"],
    'xtrace prints assignments and commands' => ['set -x; x=1 printenv x; y=2', "1\n", "+ x=1\n+ printenv x\n+ y=2\n"],
    '${x?} ends the script with 127' => ["echo \${x:?unset here}\necho after", '', "bash: x: unset here\n", 127],
    'nounset ends the script with 127' => ["trap 'echo bye' EXIT\nf() { echo \$undef; }\nset -u\nf\necho after", "bye\n", "bash: undef: unbound variable\n", 127],
    'bad substitution fails only its command' => ["echo \${x!}\necho after \$?", "after 1\n", "bash: \${x!}: bad substitution\n"],
    'expansion error abandons the rest of its line' => ["f() { echo \${x!}; echo in; }\nf; echo same\necho next", "next\n", "bash: \${x!}: bad substitution\n"],
    'cannot assign fails only its command' => ["echo \${1:=x}\necho after \$?", "after 1\n", "bash: \$1: cannot assign in this way\n"],

    // exit / return
    'exit with a non-numeric argument' => ['exit abc', '', "bash: exit: abc: numeric argument required\n", 2],
    'exit with too many arguments' => ['exit 1 2; echo no', '', "bash: exit: too many arguments\n", 1],
    'exit status wraps at 256' => ['exit 257', '', '', 1],
    'exit defaults to the last status' => ['false; exit', '', '', 1],
    'return -1 wraps to 255' => ['f() { return -1; }; f; echo $?', "255\n"],
    'return outside a function' => ['return; echo $?', "2\n", "bash: return: can only `return' from a function or sourced script\n"],

    // export / unset / local
    'export tracks later assignments' => ['export X=1; X=2; printenv X; export Y; Y=3; printenv Y; export -n Z=4; echo $Z', "2\n3\n4\n"],
    'export of a readonly variable' => ['readonly R=1; export R=2; echo $?', "1\n", "bash: R: readonly variable\n"],
    'export lists variables' => ['export B=2 A=1; export | grep "[AB]="', "declare -x A=\"1\"\ndeclare -x B=\"2\"\n"],
    'unset -f and -v' => ['f() { echo fn; }; unset -f f; f; a=1; unset -v a; echo "[$a]"', "[]\n", "bash: f: command not found\n"],
    'unset an array element' => ['a=(1 2 3); unset "a[1]"; echo ${a[@]}', "1 3\n"],
    'unset a whole array' => ['a=(1 2); unset a; echo "[${a[@]}]"', "[]\n"],
    'unset a readonly variable' => ['readonly r=1; unset r; echo $?', "1\n", "bash: unset: r: cannot unset: readonly variable\n"],
    'local outside a function' => ['local x=1; echo $?', "1\n", "bash: local: can only be used in a function\n"],
    'local variables vanish after the function' => ['f() { local a=1 b; b=2; echo $a$b; }; f; echo "[$a$b]"', "12\n[]\n"],
    'local of a readonly variable' => ['readonly r=1; f() { local r=2; }; f; echo $?', "1\n", "bash: local: r: readonly variable\n"],

    // set / shopt / cd
    'set lists variables, quoting when needed' => ['a=1; b="x y"; c=""; d="it\'s"; set | grep "^[abcd]="', "a=1\nb='x y'\nc=\nd='it'\\''s'\n"],
    'set positional parameters' => ['set -- x y; echo $# $1; set a b; echo $2', "2 x\nb\n"],
    'set -C and +C' => ['set -C; echo a > f; echo b > f; echo $?; set +C; echo c > f; cat f', "1\nc\n", "bash: f: cannot overwrite existing file\n"],
    'set -f disables globbing' => ['touch a.txt; set -f; echo *.txt; set +f; echo *.txt', "*.txt\na.txt\n"],
    'set -v has no effect on a one-line script' => ['set -v; echo hi', "hi\n"],
    'set -u and +u' => ['set -u; set +u; echo "[$nope]"', "[]\n"],
    'set +x stops tracing' => ['set -x; set +x; echo hi', "hi\n", "+ set +x\n"],
    'shopt succeeds' => ['shopt -s nullglob; echo $?', "0\n"],
    'cd - prints the new directory' => ['cd /tmp; cd -', "/home/user\n"],
    'cd with no argument goes home' => ['mkdir d; cd d; pwd; cd; pwd', "/home/user/d\n/home/user\n"],
    'cd errors name the operand' => ['cd nope; touch f; cd f', '', "bash: cd: nope: No such file or directory\nbash: cd: f: Not a directory\n", 1],
    'cd through a symlink loop' => ['ln -s a b; ln -s b a; cd a', '', "bash: cd: a: Too many levels of symbolic links\n", 1],
    'cd without HOME' => ['unset HOME; cd', '', "bash: cd: HOME not set\n", 1],
    'cd - without OLDPWD' => ['cd -', '', "bash: cd: OLDPWD not set\n", 1],

    // source / eval
    'source without a file' => ['source; echo $?', "2\n", "bash: source: filename argument required\nsource: usage: source [-p path] filename [arguments]\n"],
    'source a missing file' => ['source nope.sh; echo $?', "1\n", "bash: nope.sh: No such file or directory\n"],
    'source arguments become positional parameters' => ['echo "echo sourced \$1 \$#" > s.sh; set -- p q; source s.sh arg; . ./s.sh; echo $1', "sourced arg 1\nsourced p 2\np\n"],
    'source without arguments can set positional parameters' => ['echo "set -- z" > s.sh; source s.sh; echo $1', "z\n"],
    'return ends a sourced file' => ['echo "return 4; echo no" > s.sh; source s.sh; echo $?', "4\n"],
    'return in a file sourced by a function' => ['echo "x=1; return 5; x=2" > s; f() { source ./s; echo "s=$? x=$x"; }; f', "s=5 x=1\n"],
    'exit in a sourced file ends the script' => ['echo "echo in; exit 6" > s; source s; echo no', "in\n", '', 6],
    'eval runs in the current shell' => ['eval "a=1;" "echo \$a"; eval; echo $?', "1\n0\n"],
    'eval output follows its redirection' => ['echo a; eval "echo b" > f; cat f', "a\nb\n"],
    'exit inside eval ends the script' => ['eval "exit 3"; echo no', '', '', 3],

    // declare
    'declare without a value keeps the variable' => ['x=1; declare x; echo $x; declare -p x nope; echo $?', "1\ndeclare -- x=\"1\"\n1\n", "bash: declare: nope: not found\n"],
    'declare -p readonly and arrays' => ['declare -r y=2; declare -p y; a=(1 "b c"); declare -p a', "declare -r y=\"2\"\ndeclare -a a=([0]=\"1\" [1]=\"b c\")\n"],
    'declare in a function is local unless -g' => ['f() { declare x=1; declare -g g=2; }; f; echo "[$x][$g]"', "[][2]\n"],
    'declare -a' => ['declare -a arr; arr[0]=x; echo ${arr[0]}; declare -a b=v; echo ${b[0]}', "x\nv\n"],
    'declare of a readonly variable' => ['readonly RO=1; declare RO=2; echo $?', "1\n", "bash: declare: RO: readonly variable\n"],
    'declare -x' => ['declare -x E=1; printenv E', "1\n"],
    'declare alone lists variables' => ['q=1; declare | grep "^q="', "q=1\n"],

    // let / shift / getopts
    'let without arguments' => ['let; echo $?', "1\n", "bash: let: expression expected\n"],
    'let status follows the last value' => ['let x=0; echo $?; let y=2 z=3; echo $y$z $?', "1\n23 0\n"],
    'shift' => ['set -- a b c; shift; echo $*; shift 2; echo $# $?; shift; echo $?', "b c\n0 0\n1\n"],
    'getopts walks grouped options and arguments' => ['set -- -ac -bval -- -z rest1; while getopts "ab:c" o; do echo "$o ${OPTARG-unset} $OPTIND"; done; shift $((OPTIND-1)); echo "rest: $*"', "a unset 1\nc unset 2\nb val 3\nrest: -z rest1\n"],
    'getopts silent mode' => ['set -- -z -b; while getopts ":ab:" o; do echo "$o ${OPTARG-unset}"; done', "? z\n: b\n"],
    'getopts reports errors' => ['set -- -z -b; while getopts "ab:" o; do echo "$o ${OPTARG-unset}"; done', "? unset\n? unset\n", "bash: illegal option -- z\nbash: option requires an argument -- b\n"],
    'getopts with explicit arguments' => ['getopts a o -a -b; echo "$o $?"; getopts a o -a -b; echo "$o $? ${OPTARG-u}"; getopts a o -a -b; echo "$o $?"', "a 0\n? 0 u\n? 1\n", "bash: illegal option -- b\n"],
    'getopts restarts when OPTIND is reset' => ['getopts ab o -ab; echo $o; OPTIND=1; getopts xy o -x; echo $o', "a\nx\n"],
    'getopts stops at a non-option' => ['set -- a -b; getopts b o; echo "$? $o $OPTIND"', "1 ? 1\n"],
    'getopts option argument in the next word' => ['getopts "a:" o -a val; echo "$o $OPTARG $OPTIND"', "a val 3\n"],
    'getopts usage' => ['getopts; echo $?', "2\n", "getopts: usage: getopts optstring name [arg ...]\n"],

    // type / command / builtin / exec
    'type -t' => ['f(){ :; }; type -t f cd ls echo nope; echo $?', "function\nbuiltin\nfile\nbuiltin\n1\n"],
    'type describes each name' => ['type nope cd ls; echo $?', "cd is a shell builtin\nls is /usr/bin/ls\n1\n", "bash: type: nope: not found\n"],
    'command -v' => ['f(){ :; }; command -v cd ls f nope; echo $?; command -v nope; echo $?', "cd\n/usr/bin/ls\nf\n0\n1\n"],
    'command skips functions' => ['f(){ :; }; command f; echo $?; command; echo $?; echo() { :; }; command echo hi', "127\n0\nhi\n", "bash: f: command not found\n"],
    'command runs builtins' => ['command cd /tmp; pwd', "/tmp\n"],
    'builtin' => ['builtin cd /tmp && pwd; builtin echo hi; builtin cat; echo $?; builtin; echo $?', "/tmp\nhi\n1\n0\n", "bash: builtin: cat: not a shell builtin\n"],
    'builtin respects enable -n' => ['enable -n cd; builtin cd /tmp; pwd', "/home/user\n", "bash: builtin: cd: not a shell builtin\n"],
    'exec runs a command and ends the script' => ['exec echo done; echo no', "done\n"],
    'exec of an unknown command' => ['exec nope; echo after', '', "bash: exec: nope: not found\n", 127],
    'exec never runs functions' => ['f(){ echo fn; }; exec f', '', "bash: exec: f: not found\n", 127],
    'exec without a command' => ['exec; echo still', "still\n"],

    // alias / unalias / readonly / trap
    'alias define, list and query' => ['alias a=b c="it\'s"; alias; alias a; alias zz; echo $?', "alias a='b'\nalias c='it'\\''s'\nalias a='b'\n1\n", "bash: alias: zz: not found\n"],
    'unalias' => ['alias a=b; unalias a zz; echo $?; alias x=y; unalias -a; alias', "1\n", "bash: unalias: zz: not found\n"],
    'readonly listing and reassignment' => ['readonly a=1 b; readonly -p; readonly a=2; echo $?', "declare -r a=\"1\"\ndeclare -r b\n1\n", "bash: a: readonly variable\n"],
    'trap listing order and quoting' => ['trap "echo b" ERR; trap "echo a" EXIT; trap "" INT; trap "it\'s" SIGTERM; trap', "trap -- 'echo a' EXIT\ntrap -- '' SIGINT\ntrap -- 'it'\\''s' SIGTERM\ntrap -- 'echo b' ERR\na\n"],
    'trap with a lone signal resets it' => ['trap "echo x" 0; trap; trap EXIT; trap; echo done', "trap -- 'echo x' EXIT\ndone\n"],
    'ERR trap keeps $?' => ['trap "true" ERR; false; echo $?', "1\n"],

    // pushd / popd
    'pushd and popd' => ['cd /tmp; mkdir -p /tmp/a /tmp/b; pushd /tmp/a; pushd /tmp/b; pushd; popd; popd; popd; echo $?', "/tmp/a /tmp\n/tmp/b /tmp/a /tmp\n/tmp/a /tmp/b /tmp\n/tmp/b /tmp\n/tmp\n1\n", "bash: popd: directory stack empty\n"],
    'pushd without a stack' => ['pushd; echo $?', "1\n", "bash: pushd: no other directory\n"],
    'pushd to a missing directory' => ['pushd /nope; echo $?', "1\n", "bash: pushd: /nope: No such file or directory\n"],
    'pushd abbreviates HOME' => ['cd /tmp; pushd ~', "~ /tmp\n"],

    // job control and completion stubs
    'job control builtins' => ['wait; jobs; complete; echo $?; disown; fg; bg; suspend; compopt; logout', "0\n", "bash: disown: current: no such job\nbash: fg: no job control\nbash: bg: no job control\nbash: suspend: cannot suspend: no job control\nbash: compopt: not currently executing completion function\nbash: logout: not login shell: use `exit'\n", 1],
    'hash' => ['hash; hash -r; : a b; echo $?', "hash: hash table empty\n0\n"],
]);

test('read and mapfile split stdin like bash', function (string $script, string $stdout): void {
    expect(runCore($script, "one two  three\\\n four\nsecond,line\n"))->toBe([$stdout, '', 0]);
})->with([
    'last name takes the rest of the line' => ['read a b; echo "[$a][$b]"', "[one][two  three four]\n"],
    'REPLY keeps the whole line' => ['read; echo "[$REPLY]"', "[one two  three four]\n"],
    '-r keeps backslashes' => ['read -r x; echo "[$x]"', "[one two  three\\]\n"],
    '-d reads up to another delimiter' => ['read -d , x; echo "[$x]"', "[one two  three four\nsecond]\n"],
    'grouped -ra fills an array' => ['read -ra arr; echo ${arr[2]} ${#arr[@]}', "three\\ 3\n"],
    'IFS prefix applies to read only' => ['IFS=: read a b; echo "[$a][$b]"', "[one two  three four][]\n"],
    'extra names stay empty' => ['read a b c d e; echo "[$a][$b][$c][$d][$e]"', "[one][two][three][four][]\n"],
    'read in a loop consumes stdin' => ['while read l; do echo "<$l>"; done', "<one two  three four>\n<second,line>\n"],
    'prompt and timeout options are accepted' => ['read -p "> " -t 5 x; echo "[$x]"', "[one two  three four]\n"],
    'mapfile keeps delimiters without -t' => ['mapfile arr; echo "${#arr[@]} [${arr[1]}]"', "3 [ four\n]\n"],
    'readarray -t -d' => ['readarray -t -d , arr; echo "${#arr[@]} [${arr[1]}]"', "2 [line\n]\n"],
]);

test('read handles separators, escapes and EOF', function (string $script, string $stdout): void {
    expect(runCore($script))->toBe([$stdout, '', 0]);
})->with([
    'REPLY is not trimmed' => ['echo "  a  b  " | { read; echo "[$REPLY]"; }', "[  a  b  ]\n"],
    'non-whitespace IFS keeps the rest intact' => ['echo "a:b:c:d" | { IFS=: read x y; echo "[$x][$y]"; }', "[a][b:c:d]\n"],
    'adjacent separators make empty fields' => ['echo "a::b" | { IFS=: read -a x; echo "${#x[@]} [${x[1]}]"; }', "3 []\n"],
    'unterminated line assigns but fails' => ['printf "a b" | { read x; echo "$? [$x]"; }', "1 [a b]\n"],
    'escaped separator stays in the field' => ['echo "a\\\\ b c" | { read x y; echo "[$x][$y]"; }', "[a b][c]\n"],
    'EOF empties the variable' => ['x=old; read x < /dev/null; echo "$? [$x]"', "1 []\n"],
    'empty IFS disables splitting' => ['printf "x y" | { IFS= read -r v; echo "[$v]"; }', "[x y]\n"],
    'NUL delimited records' => ['printf "a\0b\0" | while read -r -d "" v; do echo "[$v]"; done', "[a]\n[b]\n"],
    'mapfile on NUL delimiters' => ['printf "a\0b\0" | { mapfile -d "" arr; echo ${#arr[@]}; }', "2\n"],
    'readarray -t into MAPFILE' => ['printf "a\nb" | { readarray -t; echo "${MAPFILE[1]}"; }', "b\n"],
    'read from a pipe into eval' => ['echo hi | eval "read x; echo \$x"', "hi\n"],
]);

test('eval reports syntax errors without stopping the script', function (): void {
    expect(runCore('eval fi; echo $?'))->toBe(["2\n", "bash: eval: syntax error near unexpected token `fi'\n", 0]);
});

test('times prints shell and child CPU times', function (): void {
    expect(runCore('times'))->toBe(["0m0.000s 0m0.000s\n0m0.000s 0m0.000s\n", '', 0]);
});

test('type names a function', function (): void {
    // bash then prints the function body, which BashBox can't reproduce without an AST printer
    [$stdout, $stderr, $exitCode] = runCore('f() { :; }; type f');

    expect($stdout)->toStartWith("f is a function\n")
        ->and([$stderr, $exitCode])->toBe(['', 0]);
});

test('arithmetic errors fail only their command', function (): void {
    [$stdout, $stderr, $exitCode] = runCore("echo \$((1/0))\necho after \$?");

    expect([$stdout, $exitCode])->toBe(["after 1\n", 0])
        ->and($stderr)->toContain('division by 0');
});

test('time reports to stderr in the default and POSIX formats', function (string $script, string $stdout, string $stderr): void {
    [$out, $err, $exitCode] = runCore($script);

    expect([$out, $exitCode])->toBe([$stdout, 0])
        ->and($err)->toMatch($stderr);
})->with([
    'default' => ['time echo hi', "hi\n", "/^\nreal\t0m\\d\\.\\d{3}s\nuser\t0m\\d\\.\\d{3}s\nsys\t0m\\d\\.\\d{3}s\n$/"],
    'posix' => ['time -p true', '', "/^real \\d+\\.\\d{2}\nuser \\d+\\.\\d{2}\nsys \\d+\\.\\d{2}\n$/"],
]);
