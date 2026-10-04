<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

// Expected values come from GNU bash 5.3 (`bash -c`), with its "bash: line 1:" stderr prefix shortened to "bash:".

function runDeclared(string $script): array
{
    $bashExecResult = new Bash(new BashOptions(env: ['HOME' => '/home/user']))->exec($script);

    return [$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode];
}

test('the -i, -l, -u and -n attributes and the arrays declare makes', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    expect(runDeclared($script))->toBe([$stdout, $stderr, $exitCode]);
})->with([
    '-i evaluates each assignment, and += adds' => ["declare -i x=1+2; x+=3; echo \$x; x='2*3'; echo \$x; x=abc; echo \$x; x=; echo \$x; declare -p x", "6\n6\n0\n0\ndeclare -i x=\"0\"\n"],
    '-i on an array evaluates each element as it is assigned' => ['declare -i a=(1+1 2*3); a+=(5+5); a[7]=3+3; a[7]+=1; declare -p a', "declare -ai a=([0]=\"2\" [1]=\"6\" [2]=\"10\" [7]=\"7\")\n"],
    '-i reads and let/(( )) agree' => ['declare -i x; let x=4; ((x=x+1)); echo $x; read x <<< 2+2; echo $x', "5\n4\n"],
    'an -i value is evaluated without being expanded again' => ["y=4; declare -i x; x='\$y'\necho after", '', "bash: \$y: arithmetic syntax error: operand expected (error token is \"\$y\")\n", 1],
    'a bad -i value ends the shell' => ["declare -i x; x=1/0\necho after", '', "bash: 1/0: division by 0 (error token is \"0\")\n", 1],
    'in a declaration the error names the builtin' => ["declare -i x=08\necho after", '', "bash: declare: 08: value too great for base (error token is \"08\")\n", 1],
    'read and printf name themselves too' => ['declare -i x; (read x <<< 1/0); (printf -v x 1/0); echo $?', "1\n", "bash: read: 1/0: division by 0 (error token is \"0\")\nbash: printf: 1/0: division by 0 (error token is \"0\")\n"],
    'a subshell ends with status 1' => ['(declare -i x; x=1/0; echo in); echo out $?', "out 1\n", "bash: 1/0: division by 0 (error token is \"0\")\n"],
    '+i removes the attribute, and a local does not inherit it' => ['declare -i x=1; f() { local x; x=1+1; echo $x; }; f; declare +i x; x=1+1; echo $x', "1+1\n1+1\n"],
    'a prefix assignment ignores -i, -l and -u' => ['declare -u x; declare -i n; f() { echo "$x $n"; }; x=abc n=1/0 f', "abc 1/0\n"],
    '-l and -u convert, += included' => ['declare -l x=ABC; x+=DEF; echo $x; declare -u y=abc; echo $y; read y <<< mixed; echo $y; declare -p x y', "abcdef\nABC\nMIXED\ndeclare -l x=\"abcdef\"\ndeclare -u y=\"MIXED\"\n"],
    '-l and -u replace each other; together neither changes' => ['declare -l x=AB; declare -u x; echo $x; x=Cd; echo $x; declare -lu x; x=eF; echo $x; declare +u x; x=Gh; declare -p x', "ab\nCD\neF\ndeclare -- x=\"Gh\"\n"],
    '-u applies to array elements' => ['declare -u a=(xa yb); a[3]=zz; a[3]+=q; declare -p a', "declare -au a=([0]=\"XA\" [1]=\"YB\" [3]=\"ZZQ\")\n"],
    "declare -p prints the attributes in bash's order" => ['declare -ilrx x=5; declare -iAx m; declare -nr q=z; declare -p x m q', "declare -irxl x=\"5\"\ndeclare -Aix m\ndeclare -nr q=\"z\"\n"],
    'declared but unset arrays and scalars print bare' => ['declare -a e; declare -A f; declare -i g; declare -p e f g; e+=(q); declare -p e; [[ -v f ]]; echo $?', "declare -a e\ndeclare -A f\ndeclare -i g\ndeclare -a e=([0]=\"q\")\n1\n"],
    'a scalar declared as an array becomes element 0' => ['x=1; declare -A x; declare -p x; y=2; declare -a y; declare -p y; declare -a z=v; declare -p z', "declare -A x=([0]=\"1\" )\ndeclare -a y=([0]=\"2\")\ndeclare -a z=([0]=\"v\")\n"],
    'converting between indexed and associative fails' => ['a=(1 2); declare -A a; echo $?; declare -A m; declare -a m; echo $?; declare -p a m', "1\n1\ndeclare -a a=([0]=\"1\" [1]=\"2\")\ndeclare -A m\n", "bash: declare: a: cannot convert indexed to associative array\nbash: declare: m: cannot convert associative to indexed array\n"],
    'with an array value the conversion error abandons the line' => ["a=(1); declare -A a=([x]=2); echo same\necho next \$?; declare -p a", "next 1\ndeclare -a a=([0]=\"1\")\n", "bash: a: cannot convert indexed to associative array\n"],
    'declare with only attributes lists the variables that have them all' => ["declare -i I=2; declare -ia J=(1); declare -A M; declare -ai; declare -A | grep ' M'; declare -i | grep -E '^declare -a?i [IJ]'", "declare -ai J=([0]=\"1\")\ndeclare -A M\ndeclare -i I=\"2\"\ndeclare -ai J=([0]=\"1\")\n"],
    'a nameref reads, writes and appends through to its target' => ['declare -n r=a; a=5; echo $r; r=7; echo $a; r+=x; echo $a; echo ${!r}; declare -p r a', "5\n7\n7x\na\ndeclare -n r=\"a\"\ndeclare -- a=\"7x\"\n"],
    'unset goes to the target, unset -n removes the reference' => ['declare -n r=a; a=1; unset r; echo "${a-unset}"; declare -p r; unset -n r; declare -p r', "unset\ndeclare -n r=\"a\"\n", "bash: declare: r: not found\n", 1],
    'a chain of namerefs' => ['declare -n r=x; declare -n s=r; s=5; echo $x ${!s}', "5 x\n"],
    'a nameref to an element' => ['declare -n r=a[1]; r=x; echo $r; declare -p a', "x\ndeclare -a a=([1]=\"x\")\n"],
    'a nameref to an array' => ['declare -n r=arr; r=(1 2 3); echo ${r[1]} ${#r[@]} "${!r[@]}"; r[1]=b; declare -p arr', "2 3 0 1 2\ndeclare -a arr=([0]=\"1\" [1]=\"b\" [2]=\"3\")\n"],
    'declare acts on the target unless -n is given' => ['declare -n r=x; declare r=5; declare -i r; r=1+1; declare -p r x', "declare -n r=\"x\"\ndeclare -i x=\"2\"\n"],
    '+n turns a nameref back into a plain variable' => ['declare -n r=x; declare +n r; declare -p r', "declare -- r=\"x\"\n"],
    'a nameref declared without a value takes the first value assigned' => ['declare -n r; r=x; declare -p r; echo "[${!r}]"', "declare -n r=\"x\"\n[x]\n"],
    '${!ref} of a nameref with no target is an invalid indirect expansion' => ['declare -n r; echo "[${!r}]"', '', "bash: r: invalid indirect expansion\n", 1],
    'a for loop points a nameref at each word' => ['declare -n r; for r in a b; do r=v; done; declare -p a b r', "declare -- a=\"v\"\ndeclare -- b=\"v\"\ndeclare -n r=\"b\"\n"],
    "local -n refers to the caller's variable" => ['f() { local -n r=$1; r=hi; }; f v; echo $v', "hi\n"],
    'invalid nameref targets are refused' => ["declare -n r='1x'; echo \$?; declare -n r=@; echo \$?; f() { local -n r='a b'; }; f; echo \$?", "1\n1\n1\n", "bash: declare: `1x': invalid variable name for name reference\nbash: declare: `@': invalid variable name for name reference\nbash: local: `a b': invalid variable name for name reference\n"],
    "a value that can't be a name stays and declare -n still succeeds" => ['x=1; declare -n x; echo $?; declare -p x; y=foo; declare -n y; declare -p y', "1\ndeclare -- x=\"1\"\ndeclare -n y=\"foo\"\n", "bash: declare: `1': invalid variable name for name reference\n"],
    "a nameref can't refer to itself" => ['declare -n a=a; echo $?; declare -p a', "1\n", "bash: declare: a: nameref variable self references not allowed\nbash: declare: a: not found\n", 1],
    'in a function a self reference only warns' => ['f() { local -n r=r; echo $?; }; f', "0\n", "bash: local: warning: r: circular name reference\nbash: warning: r: circular name reference\n"],
    'a circular reference reads as unset with a warning' => ['declare -n a=b; declare -n b=a; echo "${a-u}"; declare -p a b', "u\ndeclare -n a=\"b\"\ndeclare -n b=\"a\"\n", "bash: warning: a: circular name reference\n"],
    'assigning through a circular reference abandons the line' => ["declare -n a=b; declare -n b=a; a=1; echo same\necho next \$?", "next 1\n", "bash: warning: a: circular name reference\n"],
    'read through a circular reference fails' => ['declare -n a=b; declare -n b=a; read a <<< x; echo $?', "1\n", "bash: warning: a: circular name reference\n"],
    '${!ref} of a circular reference is an invalid indirect expansion' => ['declare -n a=b; declare -n b=a; echo ${!a}', '', "bash: a: invalid indirect expansion\n", 1],
    'a nameref local to a function is restored' => ['r=global; f() { local -n r=x; r=1; }; f; echo $r $x', "global 1\n"],
    'readonly accepts only -a, -A and -f' => ['readonly -i r=1+1; echo $?; readonly -A m=([k]=v); declare -p m; readonly -a m2; declare -p m2', "2\ndeclare -Ar m=([k]=\"v\" )\ndeclare -r m2\n", "bash: readonly: -i: invalid option\nreadonly: usage: readonly [-aAf] [name[=value] ...] or readonly -p\n"],
    'readonly -A converts nothing' => ['a=(1); readonly -A a; echo $?; declare -p a', "0\ndeclare -ar a=([0]=\"1\")\n"],
    'export and readonly assign array operands' => ['export a=(1 2); readonly b=(3); declare -p a b', "declare -ax a=([0]=\"1\" [1]=\"2\")\ndeclare -ar b=([0]=\"3\")\n"],
    "declare -p quotes control characters and bytes that aren't UTF-8 like bash" => ["x=\$'a\\x01b\\e\\'\"\\\\c\$`d\\a\\b\\f\\v\\r\\t\\n\\x7f'; declare -p x; y=\$'\\xc3\\xa9\\n'; z=\$'\\xff'; declare -p y z; a=(\$'x\\ny' z); declare -p a", "declare -- x=\$'a\\001b\\E\\'\"\\\\c\$`d\\a\\b\\f\\v\\r\\t\\n\\177'\ndeclare -- y=\$'é\\n'\ndeclare -- z=\$'\\377'\ndeclare -a a=([0]=\$'x\\ny' [1]=\"z\")\n"],
    'associative keys with shell metacharacters are quoted' => ["declare -A m=([\"a b\"]=1) n=(['\$x']=2) o=([c]=3); declare -p m n o", "declare -A m=([\"a b\"]=\"1\" )\ndeclare -A n=([\"\\\$x\"]=\"2\" )\ndeclare -A o=([c]=\"3\" )\n"],
    'declare -p with no names lists every variable' => ["unset PIPESTATUS; x=1; declare -p | grep -E '^declare -- x='", "declare -- x=\"1\"\n"],
    'plain declare lists like set' => ["x=1; declare | grep '^x='", "x=1\n"],
]);

test('printf -v assigns what printf prints', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    expect(runDeclared($script))->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'printf -v assigns the output, newlines and all' => ["printf -v x '%s-%s\\n' a b; echo \"[\$x]\"; printf -vy %s a; echo \$y; printf -v 'a[1+1]' %s z; declare -p a", "[a-b\n]\na\ndeclare -a a=([2]=\"z\")\n"],
    'printf -v takes -- and a local' => ['printf -v x -- %s a; echo $x; f() { local y; printf -v y %s in; echo $y; }; f; echo ${y-unset}', "a\nin\nunset\n"],
    'printf -v errors' => ["printf -v; echo \$?; printf -v x; echo \$? \${x-unset}; printf -v 1x %s a; echo \$?; printf -v 'a[' %s x; echo \$?", "2\n2 unset\n2\n2\n", "bash: printf: -v: option requires an argument\nprintf: usage: printf [-v var] format [arguments]\nprintf: usage: printf [-v var] format [arguments]\nbash: printf: `1x': not a valid identifier\nbash: printf: `a[': not a valid identifier\n"],
    'printf -v into a readonly variable fails' => ['readonly r; printf -v r %s a; echo $?', "1\n", "bash: r: readonly variable\n"],
    'printf without -v is the printf command' => ['printf -- -v; echo', "-v\n"],
]);
