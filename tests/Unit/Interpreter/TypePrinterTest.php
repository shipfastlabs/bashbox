<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).
// A function's definition prints as bash lays it out (print_cmd.c).

test('type and declare -f print a function like bash', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'a one-line body' => ["f() { echo hi; }\ntype f", "f is a function\nf () \n{ \n    echo hi\n}\n"],
    'assignments, words and redirections' => ["g() { a=1 b=2 cmd x 'y z' \"q \$v\" >out 2>&1 <in; x=1 y= z+=2; }\ntype g", "g is a function\ng () \n{ \n    a=1 b=2 cmd x 'y z' \"q \$v\" > out 2>&1 < in;\n    x=1 y= z+=2\n}\n"],
    'pipelines, |&, !, and-or lists and background jobs' => ["g() { echo a | grep b |& cat; ! true && false || echo x; sleep 1 & echo bg; }\ntype g", "g is a function\ng () \n{ \n    echo a | grep b 2>&1 | cat;\n    ! true && false || echo x;\n    sleep 1 & echo bg\n}\n"],
    'a trailing & and a list of background jobs' => ["d() { a & }\ne() { a &\nb & }\ntype d e", "d is a function\nd () \n{ \n    a &\n}\ne is a function\ne () \n{ \n    a & b &\n}\n"],
    'if, elif and else nest like bash stores them' => ["h() { if a; then b; elif c; then d; else e; fi; }\ntype h", "h is a function\nh () \n{ \n    if a; then\n        b;\n    else\n        if c; then\n            d;\n        else\n            e;\n        fi;\n    fi\n}\n"],
    'for, a bare for, while and until' => ["h() { for i in 1 2; do echo \$i; done; for j; do :; done; while x; do y; done; until x; do y; done; }\ntype h", "h is a function\nh () \n{ \n    for i in 1 2;\n    do\n        echo \$i;\n    done;\n    for j in \"\$@\";\n    do\n        :;\n    done;\n    while x; do\n        y;\n    done;\n    until x; do\n        y;\n    done\n}\n"],
    'case with alternatives, fallthrough and an empty body' => ["k() { case \$1 in a|b) echo ab;; c) echo c;& d) ;;& *) echo def; esac; case x in (a) ;; esac; case w in esac; }\ntype k", "k is a function\nk () \n{ \n    case \$1 in \n        a | b)\n            echo ab\n        ;;\n        c)\n            echo c\n        ;&\n        d)\n\n        ;;&\n        *)\n            echo def\n        ;;\n    esac;\n    case x in \n        a)\n\n        ;;\n    esac;\n    case w in \n    esac\n}\n"],
    'subshells, groups, arithmetic and conditionals' => ["k() { ( sub; shell ); { grp; }; (( x++ )); ((y=1)); [[ -n \$a && ( \$b == c* || ! -f x ) ]]; [[ x ]]; [[ ! ( a == b ) ]]; [[ (a) ]]; }\ntype k", "k is a function\nk () \n{ \n    ( sub;\n    shell );\n    { \n        grp\n    };\n    (( x++ ));\n    ((y=1));\n    [[ -n \$a && ( \$b == c* || ! -f x ) ]];\n    [[ -n x ]];\n    [[ ! ( a == b ) ]];\n    [[ ( -n a ) ]]\n}\n"],
    'C-style for keeps each part as written, empty parts read 1' => ["a() { for ((i=0;i<3;i++)); do echo; done; for (( i = 0 ; i < 3 ; i++ )); do :; done; for ((;;)); do break; done; }\ntype a", "a is a function\na () \n{ \n    for ((i=0; i<3; i++))\n    do\n        echo;\n    done;\n    for ((i = 0 ; i < 3 ; i++ ))\n    do\n        :;\n    done;\n    for ((1; 1; 1))\n    do\n        break;\n    done\n}\n"],
    'a subshell body' => ["m() ( echo subshell-body )\ntype m", "m is a function\nm () \n{ \n    ( echo subshell-body )\n}\n"],
    'a compound body that is not a group' => ["f() if true; then :; fi\ng() for i in a; do :; done 2>/dev/null\ntype f g", "f is a function\nf () \n{ \n    if true; then\n        :;\n    fi\n}\ng is a function\ng () \n{ \n    for i in a;\n    do\n        :;\n    done 2> /dev/null\n}\n"],
    'here-documents follow the line that opens them' => ["n() { cat <<EOF\nhello \$x\nEOF\ncat <<-'Q' > f\n\tlit\n\tQ\necho after; }\ntype n", "n is a function\nn () \n{ \n    cat <<EOF\nhello \$x\nEOF\n\n    cat <<-'Q' > f\nlit\nQ\n\n    echo after\n}\n"],
    'a here-document ending the body' => ["c() { cat <<EOF\nx\nEOF\n}\ntype c", "c is a function\nc () \n{ \n    cat <<EOF\nx\nEOF\n\n}\n"],
    'a here-document in a pipeline' => ["b() { cat <<EOF | grep x\nbody\nEOF\necho z; }\ntype b", "b is a function\nb () \n{ \n    cat <<EOF |\nbody\nEOF\n  grep x\n    echo z\n}\n"],
    'here-documents in an and-or list' => ["b() { cat <<\"E1\" && echo x || cat <<\\E2\nq\nE1\nr\nE2\n}\ntype b", "b is a function\nb () \n{ \n    cat <<'E1' && \nq\nE1\n echo x || cat <<'E2'\nr\nE2\n\n}\n"],
    'two here-documents on one command, and the missing ; that follows' => ["z() { cat <<A <<'B' >f; echo hi\none\nA\ntwo\nB\n}\nw() { cat <<EOF; echo a; echo b\nx\nEOF\n}\ntype z w", "z is a function\nz () \n{ \n    cat <<A <<'B' > f\none\nA\ntwo\nB\n\n    echo hi\n}\nw is a function\nw () \n{ \n    cat <<EOF\nx\nEOF\n\n    echo a\n    echo b\n}\n"],
    'arrays, declarations and appends' => ["function o { local x=(1 2 3) y; declare -A z=([a]=1); x+=(4); echo \"\${x[@]}\"; x=( 1   2\n 3 ); }\ntype o", "o is a function\no () \n{ \n    local x=(1 2 3) y;\n    declare -A z=([a]=1);\n    x+=(4);\n    echo \"\${x[@]}\";\n    x=(1 2 3)\n}\n"],
    'time, substitutions and a nested function' => ["p() { time ls; time -p ls; echo \$(date) `date` \$((1+2)) \${a:-b}; i() { inner; }; }\ntype p", "p is a function\np () \n{ \n    time ls;\n    time -p ls;\n    echo \$(date) `date` \$((1+2)) \${a:-b};\n    function i () \n    { \n        inner\n    }\n}\n"],
    'redirections of the function itself' => ["q() { echo a; } > /dev/null 2>&1\ntype q", "q is a function\nq () \n{ \n    echo a\n} > /dev/null 2>&1\n"],
    'every kind of redirection' => ["r() { echo 1 2>&- 3<&0 4<>file 5>|x &>all &>>app >>x <>f >&2 <&- 2<&- >&file 2>&file <&w 3<&w; cat <<< \"here \$x\"; >out; 2>err cat; }\ntype r", "r is a function\nr () \n{ \n    echo 1 2>&- 3<&0 4<> file 5>| x &> all &>> app >> x <> f 1>&2 0>&- 2>&- >&file 2>&file <&w 3<&w;\n    cat <<< \"here \$x\";\n    > out;\n    cat 2> err\n}\n"],
    'comments and continuations disappear' => ["t() { echo # comment\n}\nv() {\n  # leading comment\n  echo one   # trailing\n  echo \"multi\nline\" \\\n  cont\n}\ntype t v", "t is a function\nt () \n{ \n    echo\n}\nv is a function\nv () \n{ \n    echo one;\n    echo \"multi\nline\" cont\n}\n"],
    'quoting is kept as written' => ["j() { echo a\\ b \"c\\\"d\" 'e' \\\$x x=1; }\ntype j", "j is a function\nj () \n{ \n    echo a\\ b \"c\\\"d\" 'e' \\\$x x=1\n}\n"],
    'declare -f prints every function, sorted' => ["b() { :; }\na() { echo; }\ndeclare -f", "a () \n{ \n    echo\n}\nb () \n{ \n    :\n}\n"],
    'declare -f with names, failing quietly for a missing one' => ["b() { :; }\ndeclare -f b nope; echo \"s=\$?\"", "b () \n{ \n    :\n}\ns=1\n"],
    'declare -F lists names' => ["b() { :; }\na() { :; }\ndeclare -F; declare -F b nope a; echo \"s=\$?\"", "declare -f a\ndeclare -f b\nb\na\ns=1\n"],
    'type reports each name' => ["b() { :; }\ntype b nope; echo \"s=\$?\"; type -t b", "b is a function\nb () \n{ \n    :\n}\ns=1\nfunction\n", "bash: type: nope: not found\n"],
]);
