<?php

declare(strict_types=1);

use BashBox\Bash;

// Every expected output below was checked against GNU bash 5.3 (`bash -c '<script>'`).

test('$\'...\' quoting', function (string $script, string $stdout, string $stderr = '', int $exitCode = 0): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, $stderr, $exitCode]);
})->with([
    'named escapes' => ['printf \'%s|\' $\'\\a\\b\\e\\E\\f\\n\\r\\t\\v\\\\\\\'\\"\\?\'', "\x07\x08\x1b\x1b\x0c\n\x0d\t\x0b\\'\"?|"],
    'octal, hex and unicode escapes' => ['printf \'%s|\' $\'\\101\\0101\\1234\\7x\' $\'\\x41\\x4\\xg\\x414\' $\'\\u00e9\\u20AC\\U0001F600\\u\\U41\'', "A\x081S4\x07x|A\x04\\xgA4|é€😀\\uA|"],
    'control characters, unknown escapes and NUL' => ['printf \'%s|\' $\'\\cA\\ca\\c?\\c[\\cz\' $\'\\z\\q\' $\'a\\0b\' $\'x\\c@y\' $\'\\08\'', "\x01\x01\x7f\x1b\x1a|\\z\\q|a|x||"],
    'the result is quoted text' => ['x=$\'a\\nb\'; echo "$x"; echo "$\'q\'" \'$\'"\'q\'"; echo $"dq $x"; echo $\'it\\\'s\' $\'a\'\'b\' x$\'\\t\'y; touch zz; echo $\'z\'* $\'*\'', "a\nb\n\$'q' \$'q'\ndq a\nb\nit's ab x\ty\nzz *\n"],
    'type shows it single-quoted' => ["f() { echo \$'a\\tb' \$'it\\'s' \"\$'x'\" \$\"y\"; }\ntype f", "f is a function\nf () \n{ \n    echo 'a\tb' 'it'\\''s' \"\$'x'\" \"y\"\n}\n"],
    'here-documents expand as in double quotes, quotes stay' => ["x=1; cat <<EOF\ndon't \"q\" \$x \\\$x \$'a' `echo bt` \"\\\"\" \\\\\n a\\\nb\nEOF\necho \"`echo in dq` \\` \\a\"", "don't \"q\" 1 \$x \$'a' bt \"\\\"\" \\\n ab\nin dq ` \\a\n"],
]);
