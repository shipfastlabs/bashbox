<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).

test('pattern matching', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    '[[ ]] always understands extglob' => ['x=abc; [[ $x == @(a|b)* ]] && echo y1; [[ $x == +([a-c]) ]] && echo y2; [[ $x == !(a*) ]] || echo y3; [[ "" == !(x) ]] && echo y4; [[ "a|b" == @(a\\|b) ]] && echo y5; [[ abab == +(ab|a) ]] && echo y6; [[ b == *(a)b ]] && echo y7; [[ ab == ?(a)b ]] && echo y8', "y1\ny2\ny3\ny4\ny5\ny6\ny7\ny8\n"],
    'case needs shopt -s extglob' => ["p='@(abc)'; case abc in \$p) echo no;; *) echo plain;; esac\nshopt -s extglob\ncase abc in \$p) echo yes;; esac; case abc in @(abc)) echo yes;; esac; case a in !(a)*) echo m1;; esac", "plain\nyes\nyes\nm1\n"],
    'pattern removal and substitution with extglob' => ["shopt -s extglob\nx=abc; echo \"\${x//*(z)/-}|\${x/*(z)/-}|\${x//!(b)/-}|\${x/!(b)/-}|\${x#!(b)}|\${x##!(b)}|\${x%!(b)}|\${x%%!(b)}|\${x/#!(a)/-}|\${x/%!(c)/-}\"\necho \${x/@(b|c)/X} \${x//[ac]/Y}; y=aXbXc; echo \${y//!(X)/-} \${x//?(b)/-}\nx=aab; echo \${x##+(a)} \${x#+(a)} \${x%%+(b)} \${x/+(a)/-}; z=a; echo \"\${z//!(a)/-}\" \"\${z/#!(b)/-}\" \"\${x/%!(z)/-}\" \"\${x/!(a*)/-}\" \"\${x/!(*)/-}\"", "-a-b-c|-abc|-|-|abc||abc||-|-\naXc YbY\n- -a--c\nb ab aa -b\n-a - - -aab aab\n"],
    'quoted parts of a pattern match literally' => ["[[ abc == \"a\"* ]] && echo q1; [[ abc == \"a*\" ]] || echo q2; case \"a*\" in \"a*\") echo q3;; esac; case ab in \"a\"?) echo q4;; esac\np='a*'; [[ abc == \$p ]] && echo q5; [[ abc == \"\$p\" ]] || echo q6; [[ x != \"x\" ]] || echo q7", "q1\nq2\nq3\nq4\nq5\nq6\nq7\n"],
    'nocasematch covers case, [[ ]] and substitution, not removal' => ["x=ABC; shopt -s nocasematch\ncase ABC in a*) echo m1;; esac; [[ ABC == ab? ]] && echo m2; [[ ABC =~ ^ab ]] && echo m3; [[ ABC = abc ]] && echo m4; echo \${x#a} \${x/b/z} \${x%c} \${x//b/z} \${x/#a/q}; [[ \$x != abc ]]; echo \$?\nshopt -u nocasematch; [[ ABC == abc ]] || echo m5", "m1\nm2\nm3\nm4\nABC AzC ABC AzC qBC\n1\nm5\n"],
    'bracket expressions in removal patterns' => ['x=ABC; echo ${x#[AB]} ${x##[[:upper:]]*} ${x%[!C]C} ${x/[z-a]/q} ${x#[z-a]}', "BC A ABC ABC\n"],
    'array and positional operations apply per element' => ['set -- ab ac; echo "${@#a}" "${@/c/x}"; a=(xa ya); echo "${a[@]%a}" "${a[*]^^}"; x=aab; echo ${x/q/z} ${x/#/-} ${x/%/-} "${x//b/\\$}"; declare -A m=([k1]=a [k2]=b); for k in "${!m[@]}"; do echo "<$k>"; done', "b c ab ax\nx y XA YA\naab -aab aab- aa\$\n<k1>\n<k2>\n"],
    'quotes inside a group' => ['[[ "b)" == @(a|\'b)\') ]] && echo q1; [[ \'a"\' == @("a\\"") ]] && echo q2; [[ \'a|\' == @(a\\|) ]] && echo q3', "q1\nq2\nq3\n"],
    'an unbalanced group from a variable is literal' => ['p=\'@(a\'; [[ \'@(a\' == $p ]] && echo lit', "lit\n"],
    'a negation nested in another group' => ['[[ c == @(!(a)|b) ]] && echo y1; [[ a == @(!(a)) ]] || echo y2; [[ ab == !(x)? ]] && echo y3', "y1\ny2\ny3\n"],
    'groups next to a negation' => ['[[ abab == !(x)+(ab) ]] && echo y1; [[ ab == @(a)!(x) ]] && echo y2; [[ b == ?(a)!(z) ]] && echo y3; [[ aab == *(a)!(z) ]] && echo y4; [[ ab == +(a|ab)!(z) ]] && echo y5; [[ aaa == !(z)*(a|aa) ]] && echo y6; [[ x == !(z)+(a) ]] || echo y7; [[ ab == !(x)+(a|b) ]] && echo y8', "y1\ny2\ny3\ny4\ny5\ny6\ny7\ny8\n"],
    'no match leaves the value alone' => ["shopt -s extglob\nx=abc; echo \${x#!(*)} \${x/#!(*)/-} \${x//!(*)/-} \${x%%!(*)}", "abc abc abc abc\n"],
]);

test('an extglob group left open is a syntax error', function (): void {
    $bashExecResult = (new Bash)->exec('echo @(abc');

    expect([$bashExecResult->stdout, $bashExecResult->exitCode])->toBe(['', 2])
        ->and($bashExecResult->stderr)->toStartWith('bash: ');
});
