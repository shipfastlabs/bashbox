<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(env: ['HOME' => '/home/user']));
});

// Every expected value below was checked against GNU bash 5.3.
test('parameter expansion matches bash', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->stderr)->toBe('')
        ->and($result->exitCode)->toBe(0);
})->with([
    'braced positional and counts' => ['set -- a b; echo ${1} ${#} ${#@} ${#*}', "a 2 2 2\n"],
    'positional slices' => ['set -- a b c; echo ${@:2} ${*: -1}', "b c c\n"],
    '$name[i] is not an array reference' => ['a=(x y); echo $a[1]', "x[1]\n"],
    'bare array name is element 0' => ['a=(x y); echo $a ${a}', "x x\n"],
    'substring with negative offset/length' => ['s=hello; echo ${s: -2} ${s:1:-1} ${s: -3:2} "[${s: -10}]" ${s:1+1:2}', "lo ell ll [] ll\n"],
    'array slices' => ['a=(1 2 3 4); echo ${a[@]: -2} ${a[@]:1:2} "[${a[@]: -9}]"', "3 4 2 3 []\n"],
    'subscripts are arithmetic' => ['a=(x y z); i=1; echo ${a[$i]} ${a[i]} ${a[i+1]} ${a[-1]}', "y y z z\n"],
    'associative subscript is expanded' => ['declare -A m; m[k]=v; key=k; echo ${m[$key]} ${m[k]}', "v v\n"],
    'element length' => ['a=(foo bar); echo ${#a[1]}', "3\n"],
    'keys and counts' => ['a=(b a); echo ${!a[@]} ${#a[@]} ${a[*]}', "0 1 2 b a\n"],
    'indirection' => ['r=x; x=val; echo "[${!r}]"', "[val]\n"],
    'indirection to an element' => ['a=(p q); r="a[1]"; echo ${!r}', "q\n"],
    'nested default' => ['x=; echo ${x:-${y:-z}}', "z\n"],
    'element default' => ['a=(p q); echo ${a[0]:-d} ${a[5]:-d}', "p d\n"],
    'set vs null tests' => ['unset x; echo ${x-unset} ${x+set}; x=; echo ${x-unset} ${x+set} ${x:+nonempty}', "unset\nset\n"],
    'empty $@ uses default' => ['set --; echo "[${@-x}] [${@:-y}]"', "[x] [y]\n"],
    'assign default' => ['echo ${z=assigned} $z', "assigned assigned\n"],
    'assign default to element' => ['echo ${a[1]:=x} ${a[1]}', "x x\n"],
    'error-if-unset passes a set value' => ['x=v; echo ${x?never} ${x:?never}', "v v\n"],
    'pattern operand is expanded' => ['p=b; x=abc; echo ${x#*$p}', "c\n"],
    'quoted pattern characters are literal' => ['x=\'a*b\'; echo ${x#*\*} ${x%"*"b}', "b a\n"],
    'prefix/suffix removal' => ['x=a.b.c; echo ${x#*.} ${x##*.} ${x%.*} ${x%%.*} ${x#?}', "b.c c a.b a .b.c\n"],
    'anchored replacement' => ['x=abc; echo ${x/#a/X} ${x/%c/Y} ${x/b}', "Xbc abY ac\n"],
    'replacement is not a regex template' => ['x=abc; r=\'\0$1\'; echo ${x/b/$r}', "a\\0\$1c\n"],
    'replace first/all' => ['x=abab; echo ${x/b/X} ${x//b/X}', "aXab aXaX\n"],
    'case modification' => ['x=hello; echo ${x^} ${x^^} ${x,} ${x,,}', "Hello HELLO hello hello\n"],
]);

test('literal and quoting expansion matches bash', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->exitCode)->toBe(0);
})->with([
    'nested arithmetic parens' => ['echo $(( ((1+2)) * 3 )) $(( (1+(2)) ))', "9 3\n"],
    'quoted parens in $(...)' => ['echo $(echo ")" "(" \'(\') $(echo "a)b\\")")', ") ( ( a)b\")\n"],
    '"$*" joins with first IFS char' => ['set -- a b; IFS=:; echo "$*"', "a:b\n"],
    'trailing backslash pattern' => ['x="a\\\\"; p="\\\\"; echo ${x%$p}', "a\n"],
    'lone or unknown $' => ['echo a$%b $', "a\$%b \$\n"],
    'unset variable is empty' => ['echo $nothing.', ".\n"],
    'double-quote escapes' => ['echo "a\"b\$c\\\\d\q"', "a\"b\$c\\d\\q\n"],
    'backtick keeps other backslashes' => ['x=`printf "%s" "a\qb"`; echo $x', "a\\qb\n"],
    'nested backticks' => ['x=`echo \`echo hi\``; echo $x', "hi\n"],
    'tilde for home only' => ['echo ~ ~/docs ~alice/docs ~bob', "/home/user /home/user/docs ~alice/docs ~bob\n"],
    'IFS splitting with non-whitespace' => ['IFS=:; x=":a::b:"; printf "[%s]" $x; echo', "[][a][][b]\n"],
    'empty unquoted word vanishes' => ['x=; printf "[%s]" $x a; echo', "[a]\n"],
    'empty IFS does not split' => ['IFS=; x="a b"; printf "[%s]" $x; echo', "[a b]\n"],
    'whitespace IFS collapses' => ['x="  a   b  "; printf "[%s]" $x; echo', "[a][b]\n"],
    '"$@" keeps words' => ['f(){ for x in "$@"; do echo "[$x]"; done; }; f a "b c"', "[a]\n[b c]\n"],
]);

test('brace expansion matches bash', function (string $script, string $expected): void {
    expect($this->bash->exec($script)->stdout)->toBe($expected);
})->with([
    'escaped comma and non-expanding braces' => ['echo {a\,b,c} {a}b {abc} {1..c}', "a,b c {a}b {abc} {1..c}\n"],
    'descending ranges' => ['echo {5..1} {c..a}', "5 4 3 2 1 c b a\n"],
    'stepped ranges' => ['echo {1..9..3} {a..e..2} {1..5..-2}', "1 4 7 a c e 1 3 5\n"],
]);

test('globbing matches bash', function (): void {
    $result = $this->bash->exec(<<<'SH'
        mkdir -p /tmp/g && cd /tmp/g && touch a1 b1 c1 "]1" .h "x?1"
        echo [ab]1 [!a]1 []]1 [^ab]1
        echo *
        echo .h*
        echo "x?"* x?1
        echo */ /nonexistent/* *.nomatch [ [[ a[[ x[ab
        mkdir -p d/e && echo d/*
        SH);

    expect($result->stdout)->toBe(<<<'OUT'
        a1 b1 ]1 b1 c1 ]1 ]1 c1
        ]1 a1 b1 c1 x?1
        .h
        x?1 x?1
        */ /nonexistent/* *.nomatch [ [[ a[[ x[ab
        d/e

        OUT);
});

// `${x?}` and a bad `${x@op}` end the script with 127; the other errors fail only their line.
test('expansion errors report like bash', function (string $script, string $stderr, string $stdout, int $exitCode): void {
    $result = $this->bash->exec($script."\necho after");

    expect($result->stdout)->toBe($stdout)
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe($exitCode);
})->with([
    'unset with message' => ['echo ${x?oops}', "bash: x: oops\n", '', 127],
    'null without message' => ['x=; echo ${x:?}', "bash: x: parameter null or not set\n", '', 127],
    'element' => ['echo ${a[2]:?}', "bash: a[2]: parameter null or not set\n", '', 127],
    'bad transformation' => ['x=1; echo ${x@Z}', "bash: \${x@Z}: bad substitution\n", '', 127],
    'bad substitution' => ['echo ${}', "bash: \${}: bad substitution\n", "after\n", 0],
    'length with operator' => ['echo ${#x:-1}', "bash: \${#x:-1}: bad substitution\n", "after\n", 0],
    'assign to positional' => ['echo ${1:=x}', "bash: \$1: cannot assign in this way\n", "after\n", 0],
    'invalid indirection' => ['echo ${!r}', "bash: r: invalid indirect expansion\n", "after\n", 0],
]);

test('a non-fatal expansion error sets status 1 for its command', function (): void {
    expect($this->bash->exec('echo ${}')->exitCode)->toBe(1);
});

test('errors inside command substitution end only the substitution', function (string $inner, string $stderr): void {
    $result = $this->bash->exec('x=$('.$inner.'); echo "after[$x] $?"');

    expect($result->stdout)->toBe("after[] 1\n")
        ->and($result->stderr)->toBe($stderr)
        ->and($result->exitCode)->toBe(0);
})->with([
    'nounset' => ['set -u; echo $u', "bash: u: unbound variable\n"],
    'fatal expansion' => ['echo ${y?boom}', "bash: y: boom\n"],
]);

test('nounset reports unbound names', function (string $script, string $name): void {
    $result = $this->bash->exec('set -u; '.$script);

    expect($result->stderr)->toBe("bash: {$name}: unbound variable\n")
        ->and($result->exitCode)->toBe(127);
})->with([
    'scalar' => ['echo $nope', 'nope'],
    'element' => ['a=(x); echo ${a[3]}', 'a[3]'],
]);
