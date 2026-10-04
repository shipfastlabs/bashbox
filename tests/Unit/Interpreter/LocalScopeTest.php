<?php

declare(strict_types=1);

use BashBox\Bash;

// Locals, scalar and array alike, are dynamically scoped: a callee sees them, and returning restores what they hid.
// Every expected value is bash 5.3's (`bash -c`, LC_ALL=en_US.UTF-8), with "bash: line N:" shortened to "bash:".
test('function-local variables and arrays behave like bash', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'local -a is scoped to the function' => ['a=(g); f(){ local -a a=(1 2); echo "${a[@]} ${#a[@]}"; }; f; echo "${a[@]}"', "1 2 2\ng\n"],
    'local -A with keys' => ['f(){ local -A m=([k]=v [j]=w); echo "${!m[@]} ${m[k]}"; }; f; declare -p m; echo $?', "k j v\n1\n", "bash: declare: m: not found\n"],
    'local a=(...) without a flag' => ['f(){ local a=(x y); echo "${a[1]}"; }; f; echo "[${a[@]}]"', "y\n[]\n"],
    'declare in a function is local' => ['f(){ declare -a a=(1 2); declare s=v; }; f; echo "[${a[@]}] [$s]"', "[] []\n"],
    'declare -g in a function is global' => ['f(){ declare -g -a a=(1 2); declare -g s=v; }; f; echo "${a[@]} $s"', "1 2 v\n"],
    'declare -g reaches past a local' => ['f(){ local x=1; g; echo $x; }; g(){ declare -g x=2; }; f; echo $x', "1\n2\n"],
    'declare -g of a readonly global hidden by a local' => ['readonly x=1; f(){ local x=2 2>/dev/null; declare -g x=3; echo $?; }; f; echo $x', "1\n1\n", "bash: declare: x: readonly variable\n"],
    'a callee sees the caller local array' => ['f(){ local a=(1 2); g; echo "${a[@]}"; }; g(){ echo "${a[@]}"; a+=(3); }; a=(top); f; echo "${a[@]}"', "1 2\n1 2 3\ntop\n"],
    'recursion keeps each frame array' => ['f(){ local -a a=($1); (($1 > 0)) && f $(($1 - 1)); echo "${a[@]}"; }; f 2', "0\n1\n2\n"],
    'unset of a local array in its own function' => ['f(){ local a=(1 2); unset a; echo "[${a[@]}]"; a=(3); }; a=(g); f; echo "${a[@]}"', "[]\ng\n"],
    'unset from a callee uncovers the global' => ['f(){ local a=(1 2); g; echo "[${a[@]}]"; }; g(){ unset a; }; a=(gg); f; echo "${a[@]}"', "[gg]\ngg\n"],
    'unset from a callee uncovers a global scalar' => ['f(){ local x=1; g; echo "[${x-unset}]"; }; g(){ unset x; }; x=g; f; echo $x', "[g]\ng\n"],
    'a local scalar hides a global array' => ['a=(1 2 3); f(){ local a=x; echo "${a[@]} ${#a[@]}"; declare -p a; }; f; echo "${a[@]}"', "x 1\ndeclare -- a=\"x\"\n1 2 3\n"],
    'a local array hides a global scalar' => ['a=s; f(){ local -a a=(1 2); echo "${a[@]}"; }; f; echo "${a[@]}"; declare -p a', "1 2\ns\ndeclare -- a=\"s\"\n"],
    'local without a value is unset' => ['x=g; f(){ local x; echo "[${x-unset}]"; }; f; echo $x', "[unset]\ng\n"],
    'local again keeps the value' => ['f(){ local x=1; local x; echo $x; }; f', "1\n"],
    'local -r goes away on return' => ['f(){ local -r x=1; x=2; }; f; x=3; echo $x', '', "bash: x: readonly variable\n", 1],
    'local readonly array' => ['f(){ local -ra a=(1 2); a[0]=3; echo no; }; f; echo next', '', "bash: a: readonly variable\n", 1],
    'local of a readonly variable' => ['readonly x=1; f(){ local x; echo "[$x]"; }; f; echo $?', "[1]\n0\n", "bash: local: x: readonly variable\n"],
    'local -g is global' => ['f(){ local -g x=1; }; f; echo $x', "1\n"],
    'local with no names lists nothing' => ['f(){ local x=1; local; }; f; echo $?', "declare -- x=\"1\"\n0\n"],
    'local outside a function' => ['local x=1; echo $?', "1\n", "bash: local: can only be used in a function\n"],
    'locals are not exported' => ['x=g; f(){ local x=1; printenv x; }; f; echo $?', "1\n"],
    'local LINENO is an ordinary variable' => ['f(){ local LINENO=5; echo $LINENO; }; f; echo $LINENO', "5\n1\n"],
    'a local array readonly declaration' => ['f(){ declare -ra a=(1); declare -p a; }; f; a=(2); echo "${a[@]}"', "declare -ar a=([0]=\"1\")\n2\n"],
    'a scalar is element 0 of an array' => ['x=5; echo "${x[@]} ${#x[@]} ${!x[@]} ${x[0]}"; x[1]=6; echo "${x[@]} $x"; declare -p x', "5 1 0 5\n5 6 5\ndeclare -a x=([0]=\"5\" [1]=\"6\")\n"],
    'assigning an array name sets element 0' => ['a=(1 2); a=x; echo "${a[@]}"; a+=y; echo "${a[@]}"', "x 2\nxy 2\n"],
    'arithmetic on a scalar element' => ['x=4; echo $((x[0] + 1)); ((x[1] = 7)); echo "${x[@]}"', "5\n4 7\n"],
    'declare -a converts a scalar' => ['x=v; declare -a x; declare -p x', "declare -a x=([0]=\"v\")\n"],
    'read assigns into a local array' => ['f(){ local -a r; read -a r <<< "p q"; echo "${r[1]}"; }; f; echo "[${r[@]}]"', "q\n[]\n"],
    'local lists the function locals' => ['f(){ local x; local -a a=(1); local y=2 z; local -r r=1; local; }; f', "declare -a a=([0]=\"1\")\ndeclare -r r=\"1\"\ndeclare -- x\ndeclare -- y=\"2\"\ndeclare -- z\n"],
    'declare -p of a local without a value' => ['f(){ local x2; declare -p x2; }; f', "declare -- x2\n"],
]);
