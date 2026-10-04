<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Filesystem\InMemoryFs;
use BashBox\Limits;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).

test('builtin', function (string $script, string $stdout, string $stderr = ''): void {
    $bashExecResult = (new Bash)->exec($script);

    expect($bashExecResult->stdout)->toBe($stdout)
        ->and($bashExecResult->stderr)->toBe($stderr);
})->with([
    'dirs lists, prints per line, numbers and clears the stack' => [
        'cd /tmp; pushd /home >/dev/null; dirs; dirs -p; dirs -v; dirs -c; dirs',
        "/home /tmp\n/home\n/tmp\n 0  /home\n 1  /tmp\n/home\n",
    ],
    'caller names the calling function, and fails past the stack' => [
        'f(){ set -- $(caller 0); echo "$# $2"; set -- $(caller); echo $#; caller 5; echo $?; }; g(){ f; }; g; caller; echo $?',
        "3 g\n2\n1\n1\n",
    ],
    'help -d gives one-line descriptions' => [
        "help -d cd; help -d 'pu*'; help -d read",
        "cd - Change the shell working directory.\nShell commands matching keyword `pu*'\n\npushd - Add directories to stack.\nread - Read a line from the standard input and split it into fields.\n",
    ],
    'help with no match fails' => [
        'help zzz; echo $?',
        "1\n",
        "bash: help: no help topics match `zzz'.  Try `help help' or `man -k zzz' or `info zzz'.\n",
    ],
    'enable -n hides a builtin until enabled again' => [
        'enable -n pushd; pushd /tmp 2>/dev/null; echo $?; enable pushd; pushd /tmp >/dev/null; echo $?',
        "127\n0\n",
    ],
    'kill -l lays signals out like bash' => [
        'kill -l | head -n 1',
        " 1) SIGHUP\t 2) SIGINT\t 3) SIGQUIT\t 4) SIGILL\t 5) SIGTRAP\n",
    ],
    'kill reports every missing process' => [
        'kill 1234 4321; echo $?',
        "1\n",
        "bash: kill: (1234) - No such process\nbash: kill: (4321) - No such process\n",
    ],
    'ulimit queries report unlimited and setting succeeds quietly' => [
        'ulimit; ulimit -f; ulimit -n 100; echo $?',
        "unlimited\nunlimited\n0\n",
    ],
    'umask is shown zero-padded and rejects non-octal masks' => [
        'umask; umask 077; umask; umask 999; echo $?',
        "0022\n0077\n1\n",
        "bash: umask: 999: octal number out of range\n",
    ],
]);

test('loop', function (string $script, string $stdout): void {
    expect((new Bash)->exec($script)->stdout)->toBe($stdout);
})->with([
    'for without in walks the positional parameters' => ['set -- a b c; for x; do echo $x; done', "a\nb\nc\n"],
    'for with nested break 2 and continue 2' => [
        'for i in 1 2 3; do for j in 1 2 3; do test $j = 2 && continue 2; test $i = 3 && break 2; echo $i$j; done; done; echo end',
        "11\n21\nend\n",
    ],
    'c-style for with continue and break' => ['for ((i=0; i<10; i++)); do test $i = 1 && continue; test $i = 3 && break; echo $i; done', "0\n2\n"],
    'c-style for continue 2 still runs the outer update' => [
        'for ((i=0; i<2; i++)); do for ((j=0; j<3; j++)); do test $j = 1 && continue 2; echo $i$j; done; done',
        "00\n10\n",
    ],
    'c-style for break 2' => [
        'for ((i=0; i<2; i++)); do for ((j=0; j<3; j++)); do test $i = 1 && break 2; echo $i$j; done; done; echo $i',
        "00\n01\n02\n1\n",
    ],
    'c-style for with no clauses loops until break' => ['i=0; for ((;;)); do i=$((i+1)); test $i -gt 2 && break; echo $i; done', "1\n2\n"],
    'loop status is the last body status' => ['for ((i=0; i<2; i++)); do false; done; echo $?', "1\n"],
    'while with continue and break' => ['i=0; while test $i -lt 5; do i=$((i+1)); test $i = 2 && continue; test $i = 4 && break; echo $i; done', "1\n3\n"],
    'while break 2 from a nested while' => ['for a in x y; do while true; do while true; do break 2; done; done; echo $a; done', "x\ny\n"],
    'while continue 3 resumes the outer for' => ['for a in x y; do while true; do while true; do continue 3; done; done; echo no; done; echo done', "done\n"],
    'while that never runs exits 0' => ['while false; do :; done; echo $?', "0\n"],
    'until counts up' => ['i=0; until test $i -ge 3; do echo $i; i=$((i+1)); done', "0\n1\n2\n"],
    'until with continue and break' => ['i=0; until false; do i=$((i+1)); test $i = 2 && continue; test $i = 4 && break; echo $i; done', "1\n3\n"],
    'until break 2 and continue 3' => [
        'for a in x y; do until false; do until false; do break 2; done; done; echo $a; done; for a in x y; do until false; do until false; do continue 3; done; done; echo no; done; echo done',
        "x\ny\ndone\n",
    ],
    'until status is the last body status' => ['i=0; until test $i = 2; do i=$((i+1)); false; done; echo $?', "1\n"],
]);

test('loops stop at the iteration limit', function (string $script): void {
    $bash = new Bash(new BashOptions(limits: new Limits(maxLoopIterations: 3)));

    expect(fn (): \BashBox\BashExecResult => $bash->exec($script))->toThrow(ExecutionLimitException::class, 'Loop iteration limit exceeded');
})->with([
    'for' => ['for i in 1 2 3 4; do :; done'],
    'c-style for' => ['for ((;;)); do :; done'],
    'while' => ['while true; do :; done'],
    'until' => ['until false; do :; done'],
]);

test('recursion stops at the call depth limit', function (): void {
    $bash = new Bash(new BashOptions(limits: new Limits(maxCallDepth: 5)));

    expect(fn (): \BashBox\BashExecResult => $bash->exec('f(){ f; }; f'))->toThrow(ExecutionLimitException::class, 'Call depth limit exceeded');
});

test('output stops at the size limit', function (): void {
    $bash = new Bash(new BashOptions(limits: new Limits(maxOutputSize: 5)));

    expect(fn (): \BashBox\BashExecResult => $bash->exec('echo 123456'))->toThrow(ExecutionLimitException::class, 'Output size limit exceeded');
});

test('case', function (string $script, string $stdout): void {
    expect((new Bash)->exec($script)->stdout)->toBe($stdout);
})->with([
    ';& runs the next body without testing it' => ['case a in a) echo 1;& b) echo 2;; c) echo 3;; esac', "1\n2\n"],
    ';;& goes on testing the next patterns' => ['case ab in a*) echo 1;;& *b) echo 2;;& c) echo 3;; esac', "1\n2\n"],
    'no match exits 0' => ['case x in y) echo no;; esac; echo $?', "0\n"],
    'status of the matched body' => ['case x in x) false;; esac; echo $?', "1\n"],
    'glob patterns' => [
        'case foo in f*) echo star;; esac; case f in ?) echo q;; esac; case b in [abc]) echo cls;; esac; case d in [!abc]) echo neg;; esac; case x in *) echo all;; esac',
        "star\nq\ncls\nneg\nall\n",
    ],
    'quoted backslash' => ["case 'a\\' in 'a\\') echo bs;; esac", "bs\n"],
    'unclosed bracket is literal' => ['case ab in a[b) echo open;; *) echo other;; esac', "other\n"],
]);

test('subshell', function (string $script, string $stdout): void {
    expect((new Bash)->exec($script)->stdout)->toBe($stdout);
})->with([
    'exit ends only the subshell' => ['(exit 3); echo $?', "3\n"],
    'return ends only the subshell' => ['f(){ (return 4); echo $?; }; f', "4\n"],
    'errexit ends only the subshell' => ['(set -e; false; echo no); echo $?', "1\n"],
    'changes stay inside' => [
        'x=1; (x=2; cd /tmp; a=(9); f(){ :; }; set -- p; echo $x); echo $x $PWD ${a[0]-unset} $#; type f >/dev/null 2>&1; echo $?',
        "2\n1 /home/user unset 0\n1\n",
    ],
]);

test('command substitution', function (string $script, string $stdout, string $stderr = ''): void {
    $bashExecResult = (new Bash)->exec($script);

    expect($bashExecResult->stdout)->toBe($stdout)
        ->and($bashExecResult->stderr)->toBe($stderr);
})->with([
    'keeps earlier output in place' => ['echo pre; x=$(echo b); echo "[$x]"', "pre\n[b]\n"],
    'exit status reaches $?' => ['x=$(exit 4); echo $?', "4\n"],
    'state changes stay inside' => ['x=1; y=$(x=2; f(){ :; }; echo $x); echo $x $y; type f >/dev/null 2>&1; echo $?', "1 2\n1\n"],
    'does not fire the EXIT trap' => ["trap 'echo bye' EXIT; x=\$(echo a); echo \$x", "a\nbye\n"],
    'an expansion error ends only the substitution' => ['x=$(echo ${zz:?boom}); echo "after $?"', "after 1\n", "bash: zz: boom\n"],
]);

test('RETURN trap', function (string $script, string $stdout, int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect($bashExecResult->stdout)->toBe($stdout)
        ->and($bashExecResult->exitCode)->toBe($exitCode);
})->with([
    'fires after the body of the function that set it' => ["f(){ trap 'echo ret' RETURN; echo in; }; f; echo after", "in\nret\nafter\n"],
    'is not inherited by functions' => ["trap 'echo ret' RETURN; f(){ echo f; }; f; echo end", "f\nend\n"],
    'stays set but other functions do not see it' => ["f(){ trap 'echo ret' RETURN; }; g(){ echo g; }; f; echo top; g; h(){ g; }; h", "ret\ntop\ng\ng\n"],
    'sees the function locals' => ["f(){ local v=loc; trap 'echo \$v' RETURN; }; f", "loc\n"],
    'exit inside it ends the shell after the body output' => ["f(){ trap 'echo r; exit 5' RETURN; echo in; }; f; echo after", "in\nr\n", 5],
]);

test('RETURN trap output follows the function redirections', function (): void {
    $bashExecResult = (new Bash)->exec("f(){ trap 'echo r >&2' RETURN; echo in; }; f 2>/dev/null; echo ok");

    expect($bashExecResult->stdout)->toBe("in\nok\n")
        ->and($bashExecResult->stderr)->toBe('');
});

test('if with no true branch and no else exits 0', function (): void {
    expect((new Bash)->exec('if false; then echo a; elif false; then echo b; fi; echo $?')->stdout)->toBe("0\n");
});

test('return value of a function', function (): void {
    expect((new Bash)->exec('f(){ echo in; return 3; echo no; }; f; echo $?')->stdout)->toBe("in\n3\n");
});

test('redirection', function (string $script, string $stdout, string $stderr = ''): void {
    $bashExecResult = (new Bash)->exec($script);

    expect($bashExecResult->stdout)->toBe($stdout)
        ->and($bashExecResult->stderr)->toBe($stderr);
})->with([
    'input from a file, an fd other than 0, and /dev/null' => ['echo hi > out.txt; cat < out.txt; cat 3< out.txt; cat < /dev/null; echo $?', "hi\n0\n"],
    'input from a missing file' => ['cat < missing.txt; echo $?', "1\n", "bash: missing.txt: No such file or directory\n"],
    '<> creates the file' => ['cat <> rw.txt; echo $?; test -f rw.txt && echo created', "0\ncreated\n"],
    '2>&1 before >/dev/null keeps stderr on the pipe' => ['{ echo out; echo err >&2; } 2>&1 >/dev/null | cat', "err\n"],
    'closing stderr' => ['ls /nope 2>&-; echo y', "y\n"],
    '>&file sends both streams to the file' => ['{ echo z; echo e >&2; } >& both.txt; cat both.txt', "z\ne\n"],
    '&> and &>> write and append both streams' => ['{ echo a; echo b >&2; } &> all.txt; { echo c; } &>> all.txt; cat all.txt', "a\nb\nc\n"],
    '/dev/stderr is fd 2 as of that redirection' => ['echo x > /dev/stderr 2>/dev/null; echo y', "y\n", "x\n"],
    'noclobber refuses > but not >|' => [
        'set -o noclobber; echo a > f.txt; echo b > f.txt; echo $?; echo c >| f.txt; cat f.txt',
        "1\nc\n",
        "bash: f.txt: cannot overwrite existing file\n",
    ],
    'a missing directory fails >' => ['echo a > /no/such/dir/f; echo $?', "1\n", "bash: /no/such/dir/f: No such file or directory\n"],
    'a missing directory fails >&' => ['echo z >& /no/such/dir/f; echo $?', "1\n", "bash: /no/such/dir/f: No such file or directory\n"],
    'quoted heredoc stays literal' => ["cat <<'EOF'\n\$HOME\tx\nEOF", "\$HOME\tx\n"],
    '<<- strips leading tabs and expands' => ["cat <<-EOF\n\ttab \$((1+1))\n\tEOF", "tab 2\n"],
    'here-string' => ['cat <<< "here $((2+3))"', "here 5\n"],
]);

test('assignment', function (string $script, string $stdout, string $stderr = ''): void {
    $bashExecResult = (new Bash)->exec($script);

    expect($bashExecResult->stdout)->toBe($stdout)
        ->and($bashExecResult->stderr)->toBe($stderr);
})->with([
    'appending to arrays, scalars and elements' => [
        'a=(x); a+=(y z); b+=(w); echo ${a[@]} ${b[@]}; s=ab; s+=cd; echo $s; a[0]+=Q; a[5]+=n; echo ${a[0]} ${a[5]}',
        "x y z w\nabcd\nxQ n\n",
    ],
    'an empty array' => ['a=(); echo ${#a[@]}; a=(1 2 3); a=(); echo ${#a[@]}', "0\n0\n"],
    'to a readonly variable fails' => ["readonly r=1\nr=2\necho \$?", "1\n", "bash: r: readonly variable\n"],
    'xtrace puts each assignment on its own line' => [
        'set -x; a=1 b+=2; arr=(x y); arr+=(z); arr[1]=q; arr[1]+=r; echo ${arr[@]}',
        "x qr z\n",
        "+ a=1\n+ b+=2\n+ arr=(x y)\n+ arr+=(z)\n+ arr[1]=q\n+ arr[1]+=r\n+ echo x qr z\n",
    ],
    'xtrace of a prefix assignment' => ['set -x; X=1 echo hi', "hi\n", "+ X=1\n+ echo hi\n"],
]);

test('arithmetic', function (string $script, string $stdout): void {
    expect((new Bash)->exec($script)->stdout)->toBe($stdout);
})->with([
    'binary operators' => [
        'echo $((7-2)) $((2**10)) $((1<<4)) $((256>>2)) $((1<2)) $((2<=2)) $((3>=4)) $((1!=2)) $((6&3)) $((6|3)) $((6^3)) $((1,2)) $((3==3)) $((5>2))',
        "5 1024 16 64 1 1 0 1 2 7 5 2 1 1\n",
    ],
    '&& and || short-circuit' => ['x=0; echo $((0 && (x=5))) $x $((1 || (x=6))) $x $((1 && 2)) $((0 || 0)) $((0 || 3))', "0 0 1 0 1 0 1\n"],
    'unary operators' => ['echo $((-(2+3))) $((+4)) $((!0)) $((!5)) $((~0)) $((++5)) $((--5))', "-5 4 1 0 -1 5 5\n"],
    'increment and decrement' => ['x=5; echo $((x++)) $x $((x--)) $x $((++x)) $((--x)); echo $((y++)) $y', "5 6 6 5 6 5\n0 1\n"],
    'ternary' => ['echo $((1?2:3)) $((0?2:3))', "2 3\n"],
    'compound assignment' => [
        'x=10; ((x+=5)); ((x-=3)); ((x*=2)); ((x/=4)); ((x%=4)); echo $x; y=1; ((y<<=3)); ((y>>=1)); ((y&=6)); ((y|=1)); ((y^=2)); echo $y; ((z=4)); echo $z',
        "2\n7\n4\n",
    ],
    'variables holding expressions' => ['x=1+2; echo $((x*3)) $(($x*3))', "9 7\n"],
    'positional parameters' => ['set -- 5; echo $(($1+1)); (( $1 == 5 )) && echo five', "6\nfive\n"],
    'expansions and quotes inside' => ['a=(1 2 3); echo $(("1"+1)) $(( $(echo 2) * 3 )) $(( ${#a[@]} + 1 ))', "2 6 4\n"],
    'array elements' => [
        'a=(3 4); echo $((a[1]+1)) $((a)); (( a[0]=7 )); echo ${a[0]}; i=1; (( a[i]+=10 )); echo ${a[1]}; (( a[2]++ )); echo ${a[2]} $((a[a[2]-1]))',
        "5 3\n7\n14\n1 7\n",
    ],
    'associative array elements' => ['declare -A m; m[foo]=5; echo $((m[foo]*2)); (( m[bar]=3 )); echo ${m[bar]}', "10\n3\n"],
    'command status' => ['((0)); echo $?; ((2)); echo $?', "1\n0\n"],
    'c-style for header with a substitution' => ['for ((i=$(echo 1); i<3; i++)); do echo $i; done', "1\n2\n"],
]);

test('division by zero in (( )) fails only that command', function (string $script, string $stdout): void {
    $bashExecResult = (new Bash)->exec($script);

    expect($bashExecResult->stdout)->toBe($stdout)
        ->and($bashExecResult->stderr)->toContain('division by 0');
})->with([
    '/=' => ['x=5; ((x/=0)); echo $? $x', "1 5\n"],
    '%=' => ['x=5; ((x%=0)); echo $? $x', "1 5\n"],
    '/' => ['((5/0)); echo $?', "1\n"],
    '%' => ['((5%0)); echo $?', "1\n"],
]);

test('[[ ]]', function (string $script, string $stdout): void {
    expect((new Bash)->exec($script)->stdout)->toBe($stdout);
})->with([
    'string comparisons' => ['[[ abc != a* ]]; echo $?; [[ abc != x* ]]; echo $?; [[ a < b ]]; echo $?; [[ b > a ]]; echo $?; [[ b < a ]]; echo $?', "1\n0\n0\n0\n1\n"],
    'integer comparisons' => ['[[ 3 -eq 3 && 3 -ne 4 && 2 -lt 3 && 3 -le 3 && 4 -gt 3 && 4 -ge 4 ]]; echo $?; [[ 3 -eq 4 ]]; echo $?', "0\n1\n"],
    'regex match' => ['[[ abc123 =~ [0-9]+ ]]; echo $?; [[ abc =~ ^b ]]; echo $?', "0\n1\n"],
    'regex fills BASH_REMATCH' => ['re="(b)(c)"; [[ abc =~ $re ]]; echo $? ${BASH_REMATCH[0]} ${BASH_REMATCH[2]}; [[ a/b =~ a/b ]]; echo $?', "0 bc c\n0\n"],
    'empty and non-empty strings' => ['[[ -z "" && -n x ]]; echo $?; [[ -z x ]]; echo $?; [[ x ]]; echo $?; [[ "" ]]; echo $?', "0\n1\n0\n1\n"],
    'file tests' => [
        'mkdir d; echo hi > f; : > empty; [[ -e f ]]; echo $?; [[ -a f ]]; echo $?; [[ -f f ]]; echo $?; [[ -f d ]]; echo $?; [[ -d d ]]; echo $?; [[ -d nope ]]; echo $?; [[ -s f ]]; echo $?; [[ -s empty ]]; echo $?; [[ -s nope ]]; echo $?; [[ -e nope ]]; echo $?; [[ -r f ]]; echo $?; [[ -w nope ]]; echo $?; [[ -x f ]]; echo $?; [[ -x d ]]; echo $?; [[ -p f ]]; echo $?',
        "0\n0\n0\n1\n0\n1\n0\n1\n1\n1\n0\n1\n1\n0\n1\n",
    ],
    'file age and identity' => [
        'echo x > f; [[ nope -nt f ]]; echo $?; [[ f -nt nope ]]; echo $?; [[ nope -ot f ]]; echo $?; [[ f -ot nope ]]; echo $?; [[ f -ef ./f ]]; echo $?; [[ f -ef nope ]]; echo $?; [[ nope -ef nope ]]; echo $?',
        "1\n0\n0\n1\n0\n1\n1\n",
    ],
    'variable is set' => ['v=1; a=(1 2); [[ -v v ]]; echo $?; [[ -v a ]]; echo $?; [[ -v nope ]]; echo $?', "0\n0\n1\n"],
    'negation, grouping, && and ||' => [
        '[[ ! -z x ]]; echo $?; [[ a == b || b == b ]]; echo $?; [[ a == a || x == y ]]; echo $?; [[ a == b || c == d ]]; echo $?; [[ ( a == a ) && ( b == c ) ]]; echo $?',
        "0\n0\n0\n1\n1\n",
    ],
    'glob patterns' => [
        '[[ abc == a?c ]]; echo $?; [[ abc == a[bx]c ]]; echo $?; [[ abc == a[!b]c ]]; echo $?; [[ a.c == a.c ]]; echo $?; [[ abc == a.c ]]; echo $?',
        "0\n0\n1\n0\n1\n",
    ],
    'bracket expressions' => [
        "[[ b == [a-c] ]]; echo \$?; [[ 5 == [[:digit:]] ]]; echo \$?; [[ x == [^x] ]]; echo \$?; [[ ']' == []] ]]; echo \$?; [[ '[]' == [] ]]; echo \$?",
        "0\n0\n1\n0\n0\n",
    ],
    '* and ? match newlines' => ['x=$(printf "a\nb"); [[ $x == a* ]]; echo $?; case $x in a?b) echo nl;; esac', "0\nnl\n"],
]);

test('[[ -L ]] tells symlinks from files', function (): void {
    $fs = new InMemoryFs(['/home/user/f' => 'x']);
    $fs->symlink('f', '/home/user/l');

    expect(new Bash(new BashOptions(fs: $fs))->exec('[[ -L l ]]; echo $?; [[ -h f ]]; echo $?; [[ -L nope ]]; echo $?')->stdout)->toBe("0\n1\n1\n");
});

test('shell variable', function (string $script, string $stdout): void {
    expect((new Bash)->exec($script)->stdout)->toBe($stdout);
})->with([
    'specials without a variable behind them' => [
        '[[ $$ == $BASHPID ]] && echo same; echo "[$!]" $SECONDS $BASH_VERSINFO; [[ $BASH_VERSION == 5.* ]] && echo v5; [[ -n $HOSTNAME ]] && echo host; (( RANDOM >= 0 && RANDOM <= 32767 )) && echo rand; echo "[$IFS]"',
        "same\n[] 0 5\nv5\nhost\nrand\n[ \t\n]\n",
    ],
    // bash run with HOME=/home/user USER=user, the sandbox's defaults.
    'default environment' => ['echo $HOME $USER; [[ $PATH == */usr/bin* ]] && echo path', "/home/user user\npath\n"],
    'unset in its own function keeps a local hiding the global' => ['f(){ local x=1; unset x; echo "[${x-unset}]"; x=2; echo $x; }; x=g; f; echo $x', "[unset]\n2\ng\n"],
    'unset from a called function uncovers the global' => [
        'x=g; f(){ local x=l; g; echo "f:${x-unset}"; }; g(){ unset x; echo "g:${x-unset}"; }; f; echo "top:$x"',
        "g:g\nf:g\ntop:g\n",
    ],
]);

test('$- lists the set options in bash order', function (): void {
    $bashExecResult = (new Bash)->exec('echo $-; set -o errexit -o noglob -o nounset -o xtrace -o noclobber; echo $- 2>/dev/null');

    expect($bashExecResult->stdout)->toBe("hBc\nefhuxBCc\n");
});
