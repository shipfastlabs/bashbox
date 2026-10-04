<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashOptions;

test('tr transforms stdin', function (string $script, string $expected): void {
    $bashExecResult = new Bash(new BashOptions)->exec($script);

    expect($bashExecResult->stdout)->toBe($expected)
        ->and($bashExecResult->stderr)->toBe('')
        ->and($bashExecResult->exitCode)->toBe(0);
})->with([
    'ranges' => ['echo hello | tr a-z A-Z', "HELLO\n"],
    'short set2 repeats its last char' => ['echo hello | tr a-z A-C', "CCCCC\n"],
    'escapes' => ["printf 'a\\tb\\n' | tr '\\t\\n' ' ;'", 'a b;'],
    'octal escape' => ["echo ABC | tr '\\101' x", "xBC\n"],
    'octal escape stops after three digits' => ["echo A8 | tr '\\1018' xy", "xy\n"],
    'escaped backslash' => ["printf 'a\\\\b' | tr '\\\\' /", 'a/b'],
    'control escapes' => ["printf 'a\\rb\\fc\\vd\\ae\\bf' | tr '\\r\\f\\v\\a\\b' 12345", 'a1b2c3d4e5f'],
    'other escaped char is literal' => ["echo a-b | tr 'a\\-' xy", "xyb\n"],
    'trailing dash is literal' => ["echo a-b | tr 'a-' xy", "xyb\n"],
    'class keeps a following ]' => ["echo 'a]b' | tr '[:lower:]]' X", "XXX\n"],
    'space class in byte order' => ["printf '\\t\\n ' | tr '[:space:]' abc", 'abc'],
    'delete' => ['echo hello world | tr -d lo', "he wrd\n"],
    'delete alnum' => ["printf 'Hi 1x\\t!' | tr -d '[:alnum:]'", " \t!"],
    'delete alpha' => ["printf 'a1B2' | tr -d '[:alpha:]'", '12'],
    'delete digits and punct' => ["printf 'f!1G?' | tr -d '[:digit:][:punct:]'", 'fG'],
    'delete upper' => ["printf 'aBcD' | tr -d '[:upper:]'", 'ac'],
    'delete xdigit' => ["printf 'abcdefgh' | tr -d '[:xdigit:]'", 'gh'],
    'delete cntrl' => ["printf 'x\\001y' | tr -d '[:cntrl:]'", 'xy'],
    'delete graph keeps space' => ["printf 'a b' | tr -d '[:graph:]'", ' '],
    'delete print keeps tab' => ["printf 'a\\tb' | tr -d '[:print:]'", "\t"],
    'squeeze' => ["echo 'aa  bb' | tr -s '[:blank:]'", "aa bb\n"],
    'squeeze range' => ['echo aabbccdd | tr -s a-c', "abcdd\n"],
    'translate then squeeze set2' => ["echo 'a..b,,c' | tr -s ., __", "a_b_c\n"],
    'delete then squeeze' => ["echo 'xaxxbx  c' | tr -ds x ' '", "ab c\n"],
]);

test('tr rejects bad operands', function (string $script, string $stderr): void {
    $bashExecResult = new Bash(new BashOptions)->exec($script);

    expect($bashExecResult->stdout)->toBe('')
        ->and($bashExecResult->stderr)->toBe($stderr)
        ->and($bashExecResult->exitCode)->toBe(1);
})->with([
    'no operands' => ['echo a | tr', "tr: missing operand\nTry 'tr --help' for more information.\n"],
    'delete without set' => ['echo a | tr -d', "tr: missing operand\nTry 'tr --help' for more information.\n"],
    'translate with one set' => ['echo a | tr a', "tr: missing operand after 'a'\nTwo strings must be given when translating.\nTry 'tr --help' for more information.\n"],
    'reversed range' => ['echo a | tr z-a x', "tr: range-endpoints of 'z-a' are in reverse collating sequence order\n"],
    'unknown class' => ["echo a | tr '[:foo:]' x", "tr: invalid character class 'foo'\n"],
    'empty set2' => ["echo a | tr a ''", "tr: when not truncating set1, string2 must be non-empty\n"],
]);
