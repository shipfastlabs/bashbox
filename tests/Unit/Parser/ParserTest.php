<?php

declare(strict_types=1);

use BashBox\Ast\ScriptNode;
use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Exceptions\ParseException;
use BashBox\Limits;
use BashBox\Parser\Parser;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions);
});

test('compound commands and their trailing redirections', function (string $script, string $expected): void {
    expect($this->bash->exec($script)->stdout)->toBe($expected);
})->with([
    'until loop' => ['i=0; until [[ $i -ge 3 ]]; do echo $i; i=$((i+1)); done > /tmp/o; cat /tmp/o', "0\n1\n2\n"],
    'C-style for keeps spacing of each clause' => ['for ((i = 3; i > 0; i = i - -1 - 2)); do echo $i; done 2>/dev/null', "3\n2\n1\n"],
    'C-style for with empty condition' => ['for ((i=0; ; i++)); do [[ $i -eq 2 ]] && break; echo $i; done', "0\n1\n"],
    'group redirection' => ['{ echo a; echo b; } > /tmp/f; cat /tmp/f', "a\nb\n"],
    'fd-numbered trailing redirection' => ['if true; then echo x >&2; fi 2>/dev/null; echo done', "done\n"],
    'function keyword' => ['function f { echo f; }; function g() { echo g; }; f; g', "f\ng\n"],
    'comment before then' => ["if true # c\nthen echo y; fi", "y\n"],
    'newline after pipe' => ["echo a |\n cat", "a\n"],
    'background command' => ['true & echo bg', "bg\n"],
    'time -p is not a command' => ['time -p echo hi', "hi\n"],
]);

test('case items', function (string $script, string $expected): void {
    expect($this->bash->exec($script)->stdout)->toBe($expected);
})->with([
    'parenthesised alternation' => ["case b in (a|b) echo ab;; *) echo other\nesac", "ab\n"],
    'newlines between items' => ["case x in\n  a) echo a;;\n  *) echo star;;\nesac > /tmp/c; cat /tmp/c", "star\n"],
    'fall through with ;&' => ['case a in a) echo 1;& b) echo 2;; c) echo 3;; esac', "1\n2\n"],
    'continue matching with ;;&' => ['case ab in a*) echo 1;;& *b) echo 2;; *) echo 3;; esac', "1\n2\n"],
]);

test('conditional expressions', function (string $script, string $expected): void {
    expect($this->bash->exec($script)->stdout)->toBe($expected);
})->with([
    'or, and, not and grouping' => ['[[ a == b || ( c == c && ! -z x ) ]] && echo y', "y\n"],
    'bare words test non-empty' => ['[[ a ]] && echo y; [[ "" ]] || echo n', "y\nn\n"],
]);

test('redirections and assignments', function (string $script, string $expected): void {
    expect($this->bash->exec($script)->stdout)->toBe($expected);
})->with([
    'redirection before the command name' => ['>/tmp/f echo hi; cat /tmp/f', "hi\n"],
    'fd redirection before the command name' => ['2>/dev/null echo hi', "hi\n"],
    'multi-line array with comment' => ["x=(a\n b # comment\n c); echo \${x[@]}", "a b c\n"],
    'append assignment' => ['x=a; x+=b; echo $x', "ab\n"],
    'braces, brackets and bang are words outside command position' => ['echo } ]] !', "} ]] !\n"],
    'a number apart from > is an argument' => ['echo 1 2 3 > /tmp/f; cat /tmp/f', "1 2 3\n"],
    'a number touching > is its fd' => ['echo a 2>/tmp/f 3 >/tmp/g; cat /tmp/g', "a 3\n"],
    'a number before &> is an argument' => ['echo a 2&>/tmp/f; cat /tmp/f', "a 2\n"],
    '{name} before > names the fd' => ['exec {fd}>/tmp/f; echo hi >&$fd; cat /tmp/f', "hi\n"],
    'here-string reads fd 0' => ['cat <<< hi', "hi\n"],
    'array elements across lines' => ["x=(a\n\n b); echo \${x[1]}", "b\n"],
]);

test('time and ! apply to an empty command', function (): void {
    $result = $this->bash->exec('time; ! ; echo $?');

    expect($result->stdout)->toBe("1\n");
    expect($result->stderr)->toContain('real');
});

test('[[ =~ ]] takes its regex up to a blank outside parentheses', function (string $script, string $expected): void {
    expect($this->bash->exec($script)->stdout)->toBe($expected);
})->with([
    'group and alternation' => ['x=ab; [[ $x =~ ^(a|b)b$ ]] && echo y', "y\n"],
    'blanks inside parentheses' => ['[[ "a b" =~ (a b) ]] && echo ${BASH_REMATCH[0]}', "a b\n"],
    'ends before &&' => ['[[ ab =~ a|c && x ]] && echo y', "y\n"],
]);

test('arithmetic syntax errors are reported when the command runs', function (): void {
    $result = $this->bash->exec('echo before; (( 1+ )); echo $?');

    expect($result->stdout)->toBe("before\n1\n");
    expect($result->stderr)->toContain('operand expected');
});

test('syntax errors', function (string $script, string $message): void {
    expect(fn (): ScriptNode => new Parser()->parse($script))->toThrow(ParseException::class, $message);
})->with([
    'reserved word out of place' => ['echo a; done; fi; then echo b', "syntax error near unexpected token `done'"],
    'stray paren' => [')', "syntax error near unexpected token `)'"],
    'missing redirection target' => ['echo >', "syntax error near unexpected token `newline'"],
    'missing heredoc delimiter' => ['cat <<', "syntax error near unexpected token `newline'"],
    'comment as heredoc delimiter' => ['cat << #x', "syntax error near unexpected token `newline'"],
    'operator at start of list' => ['{ | ; }', "syntax error near unexpected token `|'"],
    'separator at start of list' => ['{ ; echo; }', "syntax error near unexpected token `;'"],
    'double separator' => ['echo a; ;', "syntax error near unexpected token `;'"],
    'separator after background' => ['echo a & ;', "syntax error near unexpected token `;'"],
    'separator after &&' => ['echo a && ;', "syntax error near unexpected token `;'"],
    'paren after arguments' => ['echo a (echo b)', "syntax error near unexpected token `('"],
    'operator in case body' => ['case a in a) | ;; esac', "syntax error near unexpected token `|'"],
    'case pattern without )' => ['case a in a', "syntax error near unexpected token `newline'"],
    'case without word' => ['case', "syntax error near unexpected token `newline'"],
    'simple function body' => ['f() echo hi', "syntax error near unexpected token `echo'"],
    'function without name' => ['function', "syntax error near unexpected token `newline'"],
    'empty then' => ['if true; then fi', "syntax error near unexpected token `fi'"],
    'empty group' => ['f() { }', "syntax error near unexpected token `}'"],
    'for without name' => ['for ;', "syntax error near unexpected token `;'"],
    'for without do' => ['for x in a b; echo', "syntax error near unexpected token `echo'"],
    'separator in array' => ['x=(a ;)', "syntax error near unexpected token `;'"],
    'unterminated array' => ["x=(a\nb", "unexpected EOF while looking for matching `)'"],
    'unterminated arithmetic command' => ['(( 1 +', "unexpected EOF while looking for matching `)'"],
    'unterminated C-style for' => ['for ((i=0;', "unexpected EOF while looking for matching `)'"],
    'list ends after &&' => ['echo a &&', 'syntax error: unexpected end of file'],
    'list ends after |' => ['echo a |', 'syntax error: unexpected end of file'],
    'function without body' => ['function f', 'syntax error: unexpected end of file'],
    'missing fi' => ['if true; then echo a', "syntax error: unexpected end of file from `if' command on line 1"],
    'missing then' => ["\nif true", "syntax error: unexpected end of file from `if' command on line 2"],
    'missing done' => ['while true; do echo a', "syntax error: unexpected end of file from `while' command on line 1"],
    'innermost command is reported' => ['while true; do if true; then', "syntax error: unexpected end of file from `if' command on line 1"],
    'missing until done' => ['until false', "syntax error: unexpected end of file from `until' command on line 1"],
    'missing for done' => ['for x in a b', "syntax error: unexpected end of file from `for' command on line 1"],
    'missing esac' => ['case a in a) echo a;;', "syntax error: unexpected end of file from `case' command on line 1"],
    'missing in' => ['case a', "syntax error: unexpected end of file from `case' command on line 1"],
    'missing }' => ['{ echo a &&', "syntax error: unexpected end of file from `{' command on line 1"],
    'missing )' => ['echo a; (', "syntax error: unexpected end of file from `(' command on line 1"],
    '[[ ]] without operands' => ['[[ ]]', "syntax error near `]]'"],
    '[[ ]] operand after &&' => ['[[ a && ]]', "syntax error near `]]'"],
    '[[ ]] two words' => ['[[ a b ]]', "unexpected token `b', conditional binary operator expected"],
    '[[ ]] unterminated' => ['[[ a', "unexpected token `newline', conditional binary operator expected"],
    '[[ ]] unary operator without argument' => ['[[ -f ]]', "unexpected argument `]]' to conditional unary operator"],
    '[[ ]] binary operator without argument' => ['[[ a == ]]', "unexpected argument `]]' to conditional binary operator"],
    '[[ ]] regex operator without argument' => ['[[ a =~ ]]', "unexpected argument `]]' to conditional binary operator"],
    '[[ ]] unclosed group' => ['[[ ( a ]]', "unexpected token `]]', expected `)'"],
]);

test('nesting depth is limited', function (string $script): void {
    expect(fn (): ScriptNode => new Parser(new Limits(maxAstDepth: 20))->parse($script))
        ->toThrow(ExecutionLimitException::class, 'Nesting depth limit exceeded (20)');
})->with([
    'groups' => [str_repeat('{ ', 21).'true; '.str_repeat('} ', 21)],
    '[[ ]] negations' => ['[[ '.str_repeat('! ', 21).'a ]]'],
    'command substitutions' => ['echo '.str_repeat('$(', 22).str_repeat(')', 22)],
]);

test('deep nesting stops at the default limit rather than the PHP stack', function (): void {
    expect(fn (): ScriptNode => new Parser()->parse('[[ '.str_repeat('! ', 90000).'a ]]'))
        ->toThrow(ExecutionLimitException::class, 'Nesting depth limit exceeded (500)');
});
