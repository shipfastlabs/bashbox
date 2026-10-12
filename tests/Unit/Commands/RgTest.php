<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\Limits;

function rgTestBash(): Bash
{
    $bash = new Bash(new BashOptions(cwd: '/home/user'));
    $bash->writeFile('/home/user/a.txt', "apple\nBanana\ncherry pie\n");
    $bash->writeFile('/home/user/b.txt', "banana split\n");
    $bash->writeFile('/home/user/dir/c.txt', "apple tart\n");
    $bash->writeFile('/home/user/dir/sub/d.log', "no fruit\n");
    $bash->writeFile('/home/user/.secret', "apple hidden\n");
    $bash->writeFile('/home/user/.hidden/e.txt', "apple buried\n");
    $bash->writeFile('/home/user/binary', "apple\0data\n");
    $bash->getFilesystem()->symlink('/home/user/a.txt', '/home/user/dir/link');
    $bash->getFilesystem()->symlink('/home/user/dir', '/home/user/dir/sub/cycle');

    return $bash;
}

// Non-terminal output expectations checked against ripgrep 14.1.1, except the
// stricter binary policy, empty-pipe limit and PCRE-specific escaped punctuation.
test('rg searches through the public shell', function (string $script, string $expected, int $status = 0): void {
    $bashExecResult = rgTestBash()->exec($script);

    expect($bashExecResult->stdout)->toBe($expected)
        ->and($bashExecResult->stderr)->toBe('')
        ->and($bashExecResult->exitCode)->toBe($status);
})->with([
    'piped stdin' => ["printf 'apple\\nbanana\\napricot' | rg ap", "apple\napricot\n"],
    'explicit stdin' => ["printf 'apple\\n' | rg apple -", "apple\n"],
    'empty explicit stdin' => ["printf '' | rg apple -", '', 1],
    'empty pipe defaults to directory' => ["printf '' | rg apple", "a.txt:apple\ndir/c.txt:apple tart\n"],
    'file has no default prefix or number' => ['rg an a.txt', "Banana\n"],
    'no match' => ['rg zzz a.txt', '', 1],
    'multiple files' => ['rg -i banana a.txt b.txt', "a.txt:Banana\nb.txt:banana split\n"],
    'default recursion' => ['rg apple', "a.txt:apple\ndir/c.txt:apple tart\n"],
    'directory operand' => ['rg apple dir', "dir/c.txt:apple tart\n"],
    'dot prefix' => ['rg apple .', "./a.txt:apple\n./dir/c.txt:apple tart\n"],
    'trailing slash' => ['rg fruit dir/', "dir/sub/d.log:no fruit\n"],
    'explicit hidden file' => ['rg apple .secret', "apple hidden\n"],
    'explicit hidden directory' => ['rg apple .hidden', ".hidden/e.txt:apple buried\n"],
    'hidden recursion' => ['rg --hidden -g e.txt apple', ".hidden/e.txt:apple buried\n"],
    'binary skipped' => ['rg apple binary', '', 1],
    'text override' => ['rg -a apple binary', "apple\0data\n"],
    'fixed strings' => ["printf 'a.c\\nabc\\n' | rg -F a.c", "a.c\n"],
    'regex is extended by default' => ["printf 'cat\\ndog\\ncow\\n' | rg '^(cat|dog)$'", "cat\ndog\n"],
    'punctuation delimiter escapes' => ["printf 'a~b\\na/b\\n' | rg 'a\\~b|a/b'", "a~b\na/b\n"],
    'multiple patterns' => ['rg -e apple -e cherry a.txt', "apple\ncherry pie\n"],
    'dash pattern' => ['echo x-v | rg -e -v', "x-v\n"],
    'double dash' => ['echo x-v | rg -- -v', "x-v\n"],
    'empty pattern' => ['rg -e "" a.txt', "apple\nBanana\ncherry pie\n"],
    'inverted' => ['rg -v an a.txt', "apple\ncherry pie\n"],
    'whole word' => ["printf 'pie\\npies\\n_pie\\n' | rg -w pie", "pie\n"],
    'whole line' => ['rg -ix APPLE a.txt', "apple\n"],
    'line number' => ['rg -n e a.txt', "1:apple\n3:cherry pie\n"],
    'disable numbers' => ['rg -nN e a.txt', "apple\ncherry pie\n"],
    'reenable numbers' => ['rg -Nn apple a.txt', "1:apple\n"],
    'force names' => ['rg -H apple a.txt', "a.txt:apple\n"],
    'stdin label' => ['echo apple | rg -H apple', "<stdin>:apple\n"],
    'disable names' => ['rg --no-filename apple', "apple\napple tart\n"],
    'last name option wins' => ['rg --no-filename -H apple a.txt', "a.txt:apple\n"],
    'matching files' => ['rg -l apple', "a.txt\ndir/c.txt\n"],
    'counts lines' => ['rg -c a a.txt b.txt', "a.txt:2\nb.txt:1\n"],
    'count suppresses zero' => ['rg -c zzz a.txt', '', 1],
    'quiet' => ['rg -q apple a.txt', ''],
    'quiet no match' => ['rg -q zzz a.txt', '', 1],
    'only matching' => ['rg -on an a.txt', "2:an\n2:an\n"],
    'only matching counts occurrences' => ['rg -oc an a.txt', "2\n"],
    'only matching inversion' => ['rg -ov an a.txt', "apple\ncherry pie\n"],
    'empty matches' => ["echo aaa | rg -o 'b*'", "\n\n\n\n"],
    'file listing does not read binary' => ['rg --files -g binary', "binary\n"],
    'file listing recursively filters' => ['rg --files -g "*.txt"', "a.txt\nb.txt\ndir/c.txt\n"],
    'file listing explicit' => ['rg --files .secret', ".secret\n"],
    'file listing none' => ['rg --files -g "*.missing"', '', 1],
    'glob includes union' => ['rg --files -g a.txt -g "*.log"', "a.txt\ndir/sub/d.log\n"],
    'glob exclusion' => ['rg -g "*.txt" -g "!a.txt" a', "b.txt:banana split\ndir/c.txt:apple tart\n"],
    'directory exclusion' => ['rg -g "!dir" apple', "a.txt:apple\n"],
    'last glob wins' => ['rg -g "!*.txt" -g a.txt apple', "a.txt:apple\n"],
    'double star matches root too' => ['rg --files -g "**/*.txt"', "a.txt\nb.txt\ndir/c.txt\n"],
    'double star component spans directories' => ['rg --files -g "dir/**"', "dir/c.txt\ndir/sub/d.log\n"],
    'double star inside a name is not a directory component' => ['rg --files -g "dir**log"', '', 1],
    'star does not span directories' => ['rg --files -g "dir/*.log"', '', 1],
    'question glob' => ['rg --files -g "?.txt"', "a.txt\nb.txt\ndir/c.txt\n"],
    'explicit file bypasses glob' => ['rg -g "*.php" apple a.txt', "apple\n"],
    'after context' => ['rg -n -A1 apple a.txt', "1:apple\n2-Banana\n"],
    'before context' => ['rg -n -B1 cherry a.txt', "2-Banana\n3:cherry pie\n"],
    'overlapping context merges' => ['rg -n -C1 -e apple -e cherry a.txt', "1:apple\n2-Banana\n3:cherry pie\n"],
    'named context' => ['rg -Hn -C1 Banana a.txt', "a.txt-1-apple\na.txt:2:Banana\na.txt-3-cherry pie\n"],
    'only matching keeps context' => ['rg -on -C1 an a.txt', "1-apple\n2:an\n2:an\n3-cherry pie\n"],
    'separate groups' => ["printf 'a\\nhit\\nb\\nc\\nd\\ne\\nhit\\nf\\n' | rg -n -C1 hit", "1-a\n2:hit\n3-b\n--\n6-e\n7:hit\n8-f\n"],
]);

test('rg reports errors rather than silently accepting unsupported options', function (string $script, string $message): void {
    $bashExecResult = rgTestBash()->exec($script);

    expect($bashExecResult->exitCode)->toBe(2)
        ->and($bashExecResult->stderr)->toContain('rg: ', $message);
})->with([
    ['rg', 'a pattern is required'],
    ['rg -e', 'requires an argument'],
    ['rg --follow apple', 'unrecognized option'],
    ['rg --pcre2 apple', 'unrecognized option'],
    ['rg --ignore-case=yes apple', "doesn't allow an argument"],
    ['rg -z apple', 'invalid option'],
    ['rg -A -1 apple', 'non-negative integer'],
    ['rg -C xyz apple', 'non-negative integer'],
    ["rg -e \"\$(printf 'a\\nc')\"", 'multiline patterns are unsupported'],
    ["rg \$'\\001' a.txt", 'patterns containing byte 0x01 are unsupported'],
    ['rg --files -g "*.{txt,log}"', 'unsupported or empty glob'],
    ['rg "[" a.txt', 'regex match failed'],
    ['rg apple nope', 'No such file or directory'],
    ['rg --files nope', 'No such file or directory'],
]);

test('rg preserves matches while reporting missing operands', function (): void {
    $bashExecResult = rgTestBash()->exec('rg apple nope a.txt');

    expect($bashExecResult->stdout)->toBe("a.txt:apple\n")
        ->and($bashExecResult->stderr)->toBe("rg: nope: No such file or directory\n")
        ->and($bashExecResult->exitCode)->toBe(2);
});

test('rg does not follow recursive links but accepts explicit file links', function (): void {
    $bashExecResult = rgTestBash()->exec('rg apple dir/link');

    expect($bashExecResult->stdout)->toBe("apple\n")->and($bashExecResult->exitCode)->toBe(0);
});

test('rg applies bounded PCRE execution', function (): void {
    $bash = rgTestBash();
    $bash->writeFile('/home/user/evil', str_repeat('a', 10000).'!');

    $bashExecResult = $bash->exec("rg '(a+)+$' evil");

    expect($bashExecResult->stdout)->toBe('')
        ->and($bashExecResult->exitCode)->toBe(2)
        ->and($bashExecResult->stderr)->toContain('regex match failed');
});

test('rg obeys execution limits', function (Limits $limits, string $script, string $message): void {
    $bash = new Bash(new BashOptions(limits: $limits, initialFiles: ['/home/user/a' => "hit\nhit\n"]));

    expect(fn (): BashExecResult => $bash->exec($script))->toThrow(ExecutionLimitException::class, $message);
})->with([
    [new Limits(maxGlobOperations: 1), 'rg hit', 'rg: traversal limit exceeded'],
    [new Limits(maxGlobOperations: 1), 'rg hit a a', 'rg: traversal limit exceeded'],
    [new Limits(maxOutputSize: 5), 'rg hit a', 'rg: output size limit exceeded'],
    [new Limits(maxOutputSize: 1), 'rg --files a', 'rg: output size limit exceeded'],
    [new Limits(maxStringLength: 5), 'rg hit a', 'rg: input size limit exceeded'],
    [new Limits(maxArrayElements: 1), 'rg hit a', 'rg: line count limit exceeded'],
    [new Limits(maxArrayElements: 2), 'rg -o . a', 'Regex match count limit exceeded'],
    [new Limits(maxArrayElements: 2), 'rg -co . a', 'Regex match count limit exceeded'],
]);
