<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions);
});

test('compound commands and arithmetic match bash', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect($result->stdout)->toBe($expected)
        ->and($result->exitCode)->toBe(0);
})->with([
    'until loop' => ['i=0; until (( i >= 3 )); do echo $i; i=$((i+1)); done', "0\n1\n2\n"],
    'c-style for' => ['for ((i=0; i<6; i+=2)); do echo $i; done', "0\n2\n4\n"],
    '[[ && ( || ) ]]' => ['[[ -n a && ( -z "" || x == y ) ]] && echo yes', "yes\n"],
    '[[ || ! ]]' => ['[[ a == b || ! -n "" ]]; echo $?; [[ a == b || -z x ]]; echo $?', "0\n1\n"],
    '[[ bare word ]]' => ['[[ word ]] && echo set; [[ "" ]] || echo empty', "set\nempty\n"],
    'arithmetic assign/ternary/unary/group' => [
        'x=5; echo $(( x += 2 )) $(( x > 3 ? 1 : 0 )) $(( -x )) $(( !0 )) $(( (1+2)*3 ))',
        "7 1 -7 1 9\n",
    ],
]);

// Checked against GNU bash 5.3
test('substitutions end where the lexer ends them', function (string $script, string $expected): void {
    $result = $this->bash->exec($script);

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$expected, '', 0]);
})->with([
    'quoted and escaped parens inside $(...)' => ['echo $(echo \'a)\' "b)" \) )', "a) b) )\n"],
    'a brace inside $(...) inside ${...}' => ['echo ${x:-$(echo })}', "}\n"],
    'quoted and escaped braces inside ${...}' => ['y=ab; echo ${x:-"}"} ${x:-\}} ${x:-\'a}\'} ${y/a/\}} "${y/b/\'}\'}"', "} } a} }b a}\n"],
    'single quotes stay literal in a double-quoted default word' => ['y=1; echo "${x:-\'}\'}" "${x:-"a b"}" "${x:-\'a\'"b"}" "${y:+\'a\'}"', "'}' a b 'a'b 'a'\n"],
]);
