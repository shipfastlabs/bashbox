<?php

declare(strict_types=1);

use BashBox\Bash;

test('echo', function (string $script, string $stdout): void {
    $bashExecResult = (new Bash)->exec($script);

    expect([$bashExecResult->stdout, $bashExecResult->stderr, $bashExecResult->exitCode])->toBe([$stdout, '', 0]);
})->with([
    'joins arguments' => ['echo a  b', "a b\n"],
    'no arguments' => ['echo', "\n"],
    '-n drops the newline' => ['echo -n a', 'a'],
    'escapes are literal by default' => ["echo 'a\\tb'", "a\\tb\n"],
    '-e expands escapes' => ["echo -e 'a\\tb\\\\c\\n'", "a\tb\\c\n\n"],
    '-e control escapes' => ["echo -e '\\a\\b\\e\\E\\f\\v\\r'", "\x07\x08\e\e\f\v\r\n"],
    '-e unknown escape and trailing backslash stay' => ["echo -e '\\z\\xg\\'", "\\z\\xg\\\n"],
    '-e octal needs a leading zero, takes up to three digits' => ["echo -e '\\101|\\0101|\\01234|\\0'", "\\101|A|S4|\0\n"],
    '-e hex' => ["echo -e '\\x41\\x4a2'", "AJ2\n"],
    '-e \\c stops output and the newline' => ["echo -e 'a\\cb'; echo -e x", "ax\n"],
    'last of -e/-E wins' => ["echo -eE 'a\\tb'; echo -Ee 'a\\tb'", "a\\tb\na\tb\n"],
    'combined flags' => ["echo -ne 'a\\tb'", "a\tb"],
    'options stop at the first non-option' => ['echo -nx; echo -- -n; echo -; echo a -n', "-nx\n-- -n\n-\na -n\n"],
]);
