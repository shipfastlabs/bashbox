<?php

declare(strict_types=1);

use BashBox\Ast\ScriptNode;
use BashBox\Bash;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Exceptions\ParseException;
use BashBox\Limits;
use BashBox\Parser\Lexer;
use BashBox\Parser\Parser;
use BashBox\Parser\Token;
use BashBox\Parser\TokenType;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions);
});

test('scripts are tokenized like bash', function (string $script, string $expected): void {
    expect($this->bash->exec($script)->stdout)->toBe($expected);
})->with([
    'comments' => ["echo a # not printed\n# whole line\necho b", "a\nb\n"],
    'trailing whitespace' => ['echo a   ', "a\n"],
    'line continuation between words' => ["echo a \\\n  b", "a b\n"],
    'line continuation inside a word' => ["echo a\\\nb", "ab\n"],
    'trailing backslash' => ['echo a\\', "a\\\n"],
    'newlines inside quotes' => ["echo 'a\nb' \"c\nd\"", "a\nb c\nd\n"],
    'escapes inside backticks' => ['echo `echo \`echo hi\``', "hi\n"],
    'parens inside quoted command substitution' => ['echo $(echo ")") $(echo "a\"b") $(echo \'x)\')', ") a\"b x)\n"],
    'lone dollar signs' => ['echo $% a$ $', "$% a$ $\n"],
    'nested braces in parameter expansion' => ['echo ${x:-{a}}', "{a}\n"],
    'empty braces are a word' => ['echo {}', "{}\n"],
    'bang followed by text is a word' => ['echo !x', "!x\n"],
    'double brackets followed by text are a word' => ['echo ]]x', "]]x\n"],
    'nested subshell closing with ))' => ['( (echo hi))', "hi\n"],
    'append both streams with &>>' => ['echo a &>> /tmp/f; echo b &>>/tmp/f; cat /tmp/f', "a\nb\n"],
    'nested parens in arithmetic command' => ['(( ((1+2)) == 3 && (1+(2)) == 3 )) && echo yes', "yes\n"],
    '<< is a shift inside (( ))' => ["(( x = 1 << 2 ))\necho \$x", "4\n"],
    ';; inside C-style for header' => ['for ((;;)); do echo x; break; done', "x\n"],
    'parens inside $(...) in double quotes' => ['echo "a $(echo ")") b"', "a ) b\n"],
    'backticks in double quotes' => ['echo "a `echo ")"` b"', "a ) b\n"],
    'quoted brace in ${...}' => ["echo \${x:-'}'}", "}\n"],
    'escaped quote in ANSI-C quoting' => ["echo $'a\\'b'", "a'b\n"],
    'nested parens in $((...))' => ['echo $((1+(2))) foo', "3 foo\n"],
    'extglob group in a word' => ['shopt -s extglob; case ab in @(a|x)b) echo y;; esac', "y\n"],
]);

test('invalid assignment names are ordinary command words', function (string $script): void {
    $result = $this->bash->exec($script);

    expect($result->exitCode)->toBe(127);
    expect($result->stderr)->toContain($script.': command not found');
})->with(['a-b=1', 'a++=1']);

test('heredoc delimiters can be quoted or escaped', function (string $script, string $expected): void {
    expect($this->bash->exec($script)->stdout)->toBe($expected);
})->with([
    'single quoted' => ["x=1; cat << 'EOF'\n\$x\nEOF", "\$x\n"],
    'double quoted' => ["x=1; cat <<\"EOF\"\n\$x\nEOF", "\$x\n"],
    'backslash escaped' => ["x=1; cat <<\\E\\OF\n\$x\nEOF", "\$x\n"],
    'partly quoted' => ["x=1; cat <<E\"OF\"\n\$x\nEOF\necho after", "\$x\nafter\n"],
    'quote then text' => ["cat <<'E'OF\nx\nEOF\necho after", "x\nafter\n"],
    'escaped quote inside double quotes' => ["cat <<\"E\\\"F\"\nx\nE\"F", "x\n"],
    'tab stripping' => ["x=1; cat <<-EOF\n\t\t\$x\n\tEOF", "1\n"],
    'tab stripping with a quoted delimiter' => ["cat <<-\"E\"F\n\t\$x\n\tEF", "\$x\n"],
    'two heredocs on one line' => ["cat <<A; cat <<B\na\nA\nb\nB", "a\nb\n"],
    'heredoc on the last line' => ['cat <<EOF', ''],
    'CRLF line endings keep their carriage returns' => ["cat <<EOF\r\nx\r\nEOF\r\n", "x\r\n"],
]);

test('unterminated quotes and substitutions are syntax errors', function (string $script, string $close): void {
    expect(fn (): ScriptNode => new Parser()->parse($script))
        ->toThrow(ParseException::class, sprintf("unexpected EOF while looking for matching `%s'", $close));
})->with([
    'single quote' => ["echo 'abc", "'"],
    'double quote' => ['echo "abc', '"'],
    'escaped closing double quote' => ['echo "a\\', '"'],
    'ANSI-C quote' => ["echo \$'abc", "'"],
    'backtick' => ['echo `echo', '`'],
    'command substitution' => ['echo $(echo', ')'],
    'arithmetic expansion' => ['echo $((1+', ')'],
    'parameter expansion' => ['echo ${x', '}'],
    'quote inside double-quoted substitution' => ['echo "$(echo "', '"'],
    'process substitution' => ['echo <(echo', ')'],
    'extglob group' => ['echo @(abc', ')'],
]);

test('command substitution keeps quoted parens in a single word', function (): void {
    $tokens = new Lexer('echo $(echo ")" "(" \'(\') z')->tokenize();

    expect(array_map(fn (Token $token): string => $token->value, $tokens))->toBe(['echo', '$(echo ")" "(" \'(\')', 'z', "\n", '']);
});

test('arithmetic expansion ends at the matching closing parens', function (): void {
    $tokens = new Lexer('echo $(( (1+2) * 3 ))x y')->tokenize();

    expect($tokens[1]->value)->toBe('$(( (1+2) * 3 ))x');
    expect($tokens[2]->type)->toBe(TokenType::NAME);
});

test('input size, token count, heredoc size and nesting are limited', function (Limits $limits, string $script, string $message): void {
    expect(fn (): ScriptNode => new Parser($limits)->parse($script))->toThrow(ExecutionLimitException::class, $message);
})->with([
    'input size' => [new Limits(maxInputSize: 10), str_repeat('a', 11), 'Input size limit exceeded (10 bytes)'],
    'token count' => [new Limits(maxTokens: 10), str_repeat('a ', 11), 'Token limit exceeded (10 tokens)'],
    'heredoc size' => [new Limits(maxHereDocSize: 4), "cat <<E\nabcd\nE", 'Here-document size limit exceeded (4 bytes)'],
    'nested substitutions' => [new Limits(maxAstDepth: 5), 'echo "'.str_repeat('$(', 7).str_repeat(')', 7).'"', 'Nesting depth limit exceeded (5)'],
    'nested substitutions that do not parse' => [new Limits(maxAstDepth: 5), 'echo "'.str_repeat('$(fi ', 7).str_repeat(')', 7).'"', 'Nesting depth limit exceeded (5)'],
]);
