<?php

declare(strict_types=1);

use BashBox\Bash;

// Every row was produced by running "readonly r=1" plus the script through bash 5.3 (-c).
test('assigning to a readonly variable behaves like bash', function (string $script, string $stdout, string $stderr, int $exitCode): void {
    $bashExecResult = (new Bash)->exec("readonly r=1\n".$script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'plain assignment abandons its line' => ['r=2; echo same
echo next $?', 'next 1
', 'bash: r: readonly variable
', 0],
    'append' => ['r+=x
echo "$r"', '1
', 'bash: r: readonly variable
', 0],
    'inside a function' => ['f(){ r=2; echo inf; }
f; echo same
echo next', 'next
', 'bash: r: readonly variable
', 0],
    'prefix assignment is reported, the command still runs' => ['r=2 echo "hi [$r]"; echo "same $?"', 'hi [1]
same 0
', 'bash: r: readonly variable
', 0],
    'prefix error ignores the command redirection' => ['r=2 true 2>/dev/null; echo "same $?"', 'same 0
', 'bash: r: readonly variable
', 0],
    'or-list does not rescue it' => ['r=2 || echo no
echo next', 'next
', 'bash: r: readonly variable
', 0],
    'declare' => ['declare r=2; echo "same $?"', 'same 1
', 'bash: declare: r: readonly variable
', 0],
    'export' => ['export r=2; echo "same $?"', 'same 1
', 'bash: r: readonly variable
', 0],
    'readonly again' => ['readonly r=3; echo "same $?"', 'same 1
', 'bash: r: readonly variable
', 0],
    'read' => ['read r <<< x; echo "same $?"', 'same 1
', 'bash: r: readonly variable
', 0],
    'read -a' => ['readonly -a a=(1); read -a a <<< \'x y\'; echo "same $? ${a[*]}"', 'same 1 1
', 'bash: a: readonly variable
', 0],
    'mapfile' => ['mapfile r <<< x; echo "same $?"', 'same 1
', 'bash: r: readonly variable
', 0],
    'getopts' => ['getopts a r; echo "same $?"', 'same 1
', 'bash: r: readonly variable
', 0],
    'let' => ['let r=3; echo "same $?"', 'same 1
', 'bash: r: readonly variable
', 0],
    'arithmetic command' => ['(( r=5 )); echo "same $?"', 'same 1
', 'bash: r: readonly variable
', 0],
    'arithmetic expansion abandons its line' => ['echo $((r=5)); echo same
echo next', 'next
', 'bash: r: readonly variable
', 0],
    'for loop variable' => ['for r in a; do echo body; done; echo "same $?"', 'same 1
', 'bash: r: readonly variable
', 0],
    'default assignment' => [': ${r:=z}; echo "same $?"', 'same 0
', '', 0],
    'array element' => ['a=(1); readonly a
a[1]=2; echo same
echo "next ${a[*]}"', 'next 1
', 'bash: a: readonly variable
', 0],
    'declare of a readonly array' => ['readonly -a arr=(1 2)
declare arr=(3 4); echo same
echo "next ${arr[*]}"', 'next 1 2
', 'bash: arr: readonly variable
', 0],
    'in a subshell' => ['(r=2; echo in); echo "sub $?"', 'sub 1
', 'bash: r: readonly variable
', 0],
    'in a command substitution' => ['x=$(r=2; echo in); echo "after $? [$x]"', 'after 1 []
', 'bash: r: readonly variable
', 0],
    'EXIT trap still runs' => ['trap \'echo trap\' EXIT
r=2
echo next', 'next
trap
', 'bash: r: readonly variable
', 0],
]);
