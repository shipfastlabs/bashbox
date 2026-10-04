<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

// Expected output was recorded from GNU bash 5.3 and coreutils in an empty directory, except three sed cases from GNU sed's docs.

test('matches bash', function (string $script, string $expected): void {
    $bash = new Bash(new BashOptions(cwd: '/home/user', env: ['TMPDIR' => '/tmp']));

    expect($bash->exec($script)->stdout)->toBe($expected);
})->with([
    'read consumes stdin line by line' => ['printf "a\\nb\\n" | while read l; do echo "<$l>"; done', "<a>\n<b>\n"],
    'loop reads redirected file' => ['printf "1\\n2\\n" > in; while read l; do echo $l | cat; done < in', "1\n2\n"],
    'nested reads share stdin' => ['printf "1\\n2\\n" | { { read a; }; read b; echo $a$b; }', "12\n"],
    'read returns 1 on unterminated last line' => ['printf "x" | { read v; echo "$?:$v"; }', "1:x\n"],
    'pipe into if' => ['echo hi | if true; then cat; fi', "hi\n"],
    'loop output goes through the pipe' => ['for i in b a; do echo $i; done | sort', "a\nb\n"],
    'loop redirected to /dev/null' => ['while true; do echo x; break; done >/dev/null; echo ok', "ok\n"],
    'group redirected to file with 2>&1' => ['{ echo o; echo e >&2; } > f 2>&1; cat f', "o\ne\n"],
    'stderr to /dev/null' => ['cat nope 2>/dev/null; echo rc=$?', "rc=1\n"],
    'stderr into the pipe' => ['ls nope 2>&1 | grep -c nope', "1\n"],
    'stdout to stderr' => ['echo e >&2 2>/dev/null; echo done', "done\n"],
    'missing input file skips the command' => ['cat < nofile 2>/dev/null; echo rc=$?', "rc=1\n"],
    'break exits loop with status 0' => ['while :; do false; break; done; echo $?', "0\n"],
    'bare assignment status' => ['false; x=1; echo $?', "0\n"],
    'assignment takes substitution status' => ['x=$(false); echo $?', "1\n"],
    'quoted $@ keeps words' => ['set -- "a b" c; printf "[%s]" "$@"; echo', "[a b][c]\n"],
    'empty quoted $@ vanishes' => ['set --; printf "[%s]" "$@" x; echo', "[x]\n"],
    '$@ joins with surrounding text' => ['set -- a b; printf "[%s]" "x$@y"; echo', "[xa][by]\n"],
    'quoted array expansion' => ['a=("x y" z); printf "[%s]" "${a[@]}" "${a[*]}"; echo', "[x y][z][x y z]\n"],
    '${1+"$@"} idiom' => ['set -- "a b" c; for w in "${1+"$@"}"; do echo "[$w]"; done', "[a b]\n[c]\n"],
    'positional slice' => ['set -- a b c; echo "${@:2}"', "b c\n"],
    'positional beyond 9' => ['set -- 1 2 3 4 5 6 7 8 9 10 11; echo ${10} ${11}', "10 11\n"],
    'unquoted variable is split' => ['x="a  b"; printf "[%s]" $x "$x"; echo', "[a][b][a  b]\n"],
    'unquoted empty variable vanishes' => ['e=; printf "[%s]" $e "$e" x; echo', "[][x]\n"],
    'custom IFS splits expansions only' => ['IFS=:; x=a:b; printf "[%s]" $x; echo', "[a][b]\n"],
    'command word is split' => ['C="echo hi"; $C there', "hi there\n"],
    'default and alternative operators' => ['unset u; e=; echo "[${u-d}][${u+a}][${e-d}][${e+a}][${e:-d}]"', "[d][][][a][d]\n"],
    'cannot assign to positional' => [': ${1:=x}; echo after', ''],
    'error-if-unset stops the script' => ['echo a; echo ${zz:?boom}; echo after', "a\n"],
    'glob matches directories only with */' => ['mkdir d1 d2; touch f1; for d in */; do echo $d; done', "d1/\nd2/\n"],
    'quoted glob stays literal' => ['touch a.txt b.txt; echo *.txt "*.txt" \\*.txt', "a.txt b.txt *.txt *.txt\n"],
    'glob skips dotfiles' => ['touch .h v; echo *', "v\n"],
    'glob bracket expression' => ['touch a1 a2 b1; echo a[12] [!a]1', "a1 a2 b1\n"],
    'glob in a directory component' => ['mkdir -p p/x p/y; touch p/x/f p/y/f; echo p/*/f', "p/x/f p/y/f\n"],
    'sed append keeps leading blanks' => ['echo x | sed "a\\   indented"', "x\n   indented\n"],
    'sed addresses and commands' => ['printf "1\\n2\\n3\\n" | sed -n 2p; printf "1\\n2\\n3\\n" | sed 2d; printf "a\\nb\\n" | sed "1c X"', "2\n1\n3\nX\nb\n"],
    'sed range and negation' => ['printf "x\\nstart\\ny\\nend\\nz\\n" | sed -n "/start/,/end/p"; printf "1\\n2\\n3\\n" | sed "\\$!d"', "start\ny\nend\n3\n"],
    'sed multiple commands' => ['echo abc | sed "s/a/A/;s/c/C/"', "AbC\n"],
    'sed BRE groups and escaped backslash' => ['echo ab | sed "s/\\(a\\)\\(b\\)/\\2\\1/"; echo ab | sed "s/\\(a\\)/\\\\\\\\\\1/"', "ba\n\\ab\n"],
    'sed nth occurrence' => ['echo aaa | sed s/a/X/2; echo aaa | sed s/a/X/2g', "aXa\naXX\n"],
    'sed N and hold space' => ['printf "1\\n2\\n3\\n4\\n" | sed "N;s/\\n/,/"; printf "1\\n2\\n" | sed -n "h;n;G;p"', "1,2\n3,4\n2\n1\n"],
    'head and tail -N shorthand' => ['printf "1\\n2\\n3\\n" | head -2; printf "1\\n2\\n3\\n" | tail -2', "1\n2\n2\n3\n"],
    'tr octal escapes' => ['echo a | tr a "\\101"', "A\n"],
    'cp -f and -n' => ['echo a > f; echo b > g; cp -f f h && cat h; cp -n g f; cat f', "a\na\n"],
    'yes is finite in a pipe' => ['yes | head -2; yes ab | head -1', "y\ny\nab\n"],
    'realpath' => ['mkdir -p r/s; cd r; realpath s . | sed "s|.*/||"', "s\nr\n"],
    'mktemp creates a private file' => ['f=$(mktemp); test -f "$f" && echo file; d=$(mktemp -d); test -d "$d" && echo dir', "file\ndir\n"],
    'declare -A compound assignment keeps quoted values' => ['declare -A m=([k]="hello world" [j]=x); echo "${m[k]}|${m[j]}|${#m[@]}"', "hello world|x|2\n"],
    'local -A compound assignment' => ['f(){ local -A m=([a]=1 [b]="2 3"); echo "${m[a]}${m[b]}"; }; f', "12 3\n"],
    'keyed elements in an indexed array' => ['a=(x [5]=y z); echo ${!a[@]} ${a[6]}', "0 5 6 z\n"],
    'array keys are expanded' => ['k=key; declare -A m=([$k]="v w"); echo "${m[key]}"', "v w\n"],
    'readonly array declaration' => ['readonly -a r=(1 2); echo ${r[1]}', "2\n"],
]);

test('an expansion error inside $(...) ends only the substitution', function (): void {
    $bashExecResult = (new Bash)->exec('x=$(echo ${zz:?boom}); echo after');

    expect($bashExecResult->stdout)->toBe("after\n")
        ->and($bashExecResult->stderr)->toContain('zz: boom');
});
