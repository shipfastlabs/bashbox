<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).

test('arithmetic', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'elements read and write by index, nested and negative' => ['a=(5 6 7); i=1; echo $((a[i+1] + a[0])) $((a[a[0]-4])) $(( a[-1] )) $(( a[ 1 ] )); ((a[1]+=10, a[5]=3)); echo ${a[@]}; ((a[2]++)); ((--a[0])); echo ${a[0]} ${a[2]} $((a[1]--)) ${a[1]}', "12 6 7 6\n5 16 7 3\n4 8 16 15\n"],
    'associative keys stay text, and a scalar is its own element 0' => ['declare -A m; m[x]=4; echo $((m[x]*2)); ((m[y]=7)); echo ${m[y]}; k=x; echo $((m[$k]+1)); b=3; echo $((b[0])) $((b[1]))', "8\n7\n5\n3 0\n"],
    'an unclosed subscript is an error' => ["echo \$((a[1 ))\necho next", "next\n", "bash: a[1 : bad array subscript (error token is \"a[1 \")\n"],
    'an empty subscript is reported twice and reads 0' => ['a=(1 2); echo $((a[])) $((a[]+1))', "0 1\n", "bash: a[]: bad array subscript\nbash: a[]: bad array subscript\nbash: a[]: bad array subscript\nbash: a[]: bad array subscript\n"],
    'division by 0 names the divisor' => ["echo \$(( 1/0 ))\necho \$(( 10 / (2-2) ))\necho \$(( 5 % 0 + 1 ))\na=1; (( a /= 0 )); echo \$?\necho \$(( a %= 0 ))\nx=\"1/0\"; echo \$(( x + 1 ))\necho \$(( 0 && 1/0 ))", "1\n0\n", "bash: 1/0 : division by 0 (error token is \"0 \")\nbash: 10 / (2-2) : division by 0 (error token is \"(2-2) \")\nbash: 5 % 0 + 1 : division by 0 (error token is \"0 + 1 \")\nbash: ((: a /= 0 : division by 0 (error token is \"0 \")\nbash: a %= 0 : division by 0 (error token is \"0 \")\nbash: 1/0: division by 0 (error token is \"0\")\n"],
    'a negative exponent is an error' => ["echo \$(( 2 ** -1 ))\necho \$(( 2 ** -1 + 3 ))", '', "bash: 2 ** -1 : exponent less than 0 (error token is \"1 \")\nbash: 2 ** -1 + 3 : exponent less than 0 (error token is \"+ 3 \")\n", 1],
    'syntax errors quote the token bash stopped at' => ["echo \$(( 1 + ))\necho \$((1 +))\n((1 + )); echo \$?\necho \$(( 1 2 ))\necho \$(( 1 ? 2 ))\necho \$(( 1 +* 2 ))\necho \$(( 08 ))\nlet \"x = 1 +\"; echo \$?", "1\n1\n", "bash: 1 + : arithmetic syntax error: operand expected (error token is \"+ \")\nbash: 1 +: arithmetic syntax error: operand expected (error token is \"+\")\nbash: ((: 1 + : arithmetic syntax error: operand expected (error token is \"+ \")\nbash: 1 2 : arithmetic syntax error in expression (error token is \"2 \")\nbash: 1 ? 2 : `:' expected for conditional expression (error token is \"2 \")\nbash: 1 +* 2 : arithmetic syntax error: operand expected (error token is \"* 2 \")\nbash: 08: value too great for base (error token is \"08\")\nbash: let: x = 1 +: arithmetic syntax error: operand expected (error token is \"+\")\n"],
    'integers wrap around at 64 bits' => ['echo $(( 9223372036854775807 + 1 )) $(( 9223372036854775807 * 3 )) $(( -9223372036854775807 - 2 )) $(( 99999999999999999999 )); x=-9223372036854775807; echo $(( (x-1) / -1 )) $(( (x-1) % -1 )) $(( -(x-1) )); y=9223372036854775807; ((y++)); echo $y; ((y*=2)); echo $y', "-9223372036854775808 9223372036854775805 9223372036854775807 7766279631452241919\n-9223372036854775808 0 -9223372036854775808\n-9223372036854775808\n0\n"],
    'powers and shifts wrap like C' => ['echo $(( 2 ** 64 )) $(( 3 ** 41 )) $(( 2 ** 63 )) $(( 7 ** 0 )) $(( 1 << 64 )) $(( 1 << 63 )) $(( -1 >> 70 )) $(( 1 << -1 )) $(( 8 >> -62 )); z=1; ((z <<= 65)); echo $z', "0 -420491770248316829 -9223372036854775808 1 1 -9223372036854775808 -1 -9223372036854775808 2\n2\n"],
    'number errors quote the expression up to the number' => ["echo \$(( 1 + 08 ))\necho \$(( 2# ))\necho \$(( 65#1 ))\necho \$(( 1#1 ))\necho \$(( 0x ))\necho \$(( 2#1#1 ))", "0\n", "bash: 1 + 08: value too great for base (error token is \"08\")\nbash: 2#: invalid integer constant (error token is \"2#\")\nbash: 65#1: invalid arithmetic base (error token is \"65#1\")\nbash: 1#1: invalid arithmetic base (error token is \"1#1\")\nbash: 2#1#1: invalid number (error token is \"2#1#1\")\n", 1],
    'let stops at a failing expression' => ['let "1/0" "y=2"; echo $? $y; let; echo $?; let x=1 "y=x+1"; echo $? $y; let 0; echo $?', "1\n1\n0 2\n1\n", "bash: let: 1/0: division by 0 (error token is \"0\")\nbash: let: expression expected\n"],
    'a dollar sign left in arithmetic' => ['x=2; echo $(( \\$x + 1 ))', '', "bash: \$x + 1 : arithmetic syntax error: operand expected (error token is \"\$x + 1 \")\n", 1],
]);
