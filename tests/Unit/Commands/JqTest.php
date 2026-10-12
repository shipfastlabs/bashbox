<?php

declare(strict_types=1);

use BashBox\Bash;
use BashBox\BashExecResult;
use BashBox\BashOptions;
use BashBox\Commands\CommandContext;
use BashBox\Commands\Jq_;
use BashBox\Exceptions\ExecutionLimitException;
use BashBox\ExecOptions;
use BashBox\ExecResult;
use BashBox\Filesystem\FileSystemInterface;
use BashBox\Filesystem\InMemoryFs;
use BashBox\Limits;

beforeEach(function (): void {
    $this->bash = new Bash(new BashOptions(cwd: '/home/user'));
    $this->bash->writeFile('/home/user/one', '{"n":7} {"n":2}');
    $this->bash->writeFile('/home/user/two', '{"n":11}');
});

test('jq bounded query subset', function (string $script, string $input, string $output, int $status = 0): void {
    $result = $this->bash->exec($script, new ExecOptions(stdin: $input));

    expect([$result->stdout, $result->stderr, $result->exitCode])->toBe([$output, '', $status]);
})->with([
    'identity preserves empty object and array' => ['jq -c .', '{} [] {"0":{}}', "{}\n[]\n{\"0\":{}}\n"],
    'pretty nested values' => ['jq .', '{"z":[3,{},[]],"a":true}', "{\n  \"z\": [\n    3,\n    {},\n    []\n  ],\n  \"a\": true\n}\n"],
    'nested and quoted access' => ['jq -c \'.a["odd.key"][1]."x y"\'', '{"a":{"odd.key":[9,{"x y":23}]}}', "23\n"],
    'spacing before a field or quoted key' => ['jq \'.a .b, . "a" . "b"\'', '{"a":{"b":7}}', "7\n7\n"],
    'negative and missing index' => ["jq -c '.[-1], .[9]'", '[5,2,8]', "8\nnull\n"],
    'missing field propagates null' => ["jq '.missing.nested[0]'", '{}', "null\n"],
    'stream pipe and comma precedence' => ["jq -c '.a,.b | .[]'", '{"a":[7,2],"b":[11]}', "7\n2\n11\n"],
    'object iteration retains insertion order' => ["jq -c '.[]'", '{"z":7,"a":2}', "7\n2\n"],
    'array collects stream' => ["jq -c '[.[] | .n]'", '[{"n":7},{"n":2},{"n":11}]', "[7,2,11]\n"],
    'map select comparison' => ["jq -c 'map(select(.n >= 7) | .n)'", '[{"n":7},{"n":2},{"n":11}]', "[7,11]\n"],
    'map flattens multiple results' => ["jq -c 'map(.,type)'", '[7,"a",{}]', "[7,\"number\",\"a\",\"string\",{},\"object\"]\n"],
    'object construction and shorthand' => ['jq -c \'{n, size: .a | length, "empty": {}}\'', '{"n":5,"a":[7,2,11]}', "{\"n\":5,\"size\":3,\"empty\":{}}\n"],
    'object construction Cartesian product' => ["jq -nc '{a:(7,2),b:(true,false)}'", '', "{\"a\":7,\"b\":true}\n{\"a\":7,\"b\":false}\n{\"a\":2,\"b\":true}\n{\"a\":2,\"b\":false}\n"],
    'fallback removes only false and null' => ['jq -c \'.[] // "fallback"\'', '[null,false,0,"",[]]', "0\n\"\"\n[]\n"],
    'fallback when no truthy results' => ["jq -c '.[] // 19'", '[null,false]', "19\n"],
    'empty fallback' => ["jq -nc 'empty // 19'", '', "19\n"],
    'keys sorted as strings' => ['jq -c keys', '{"z":5,"10":1,"2":2,"a":3}', "[\"10\",\"2\",\"a\",\"z\"]\n"],
    'array keys' => ['jq -c keys', '[7,2,11]', "[0,1,2]\n"],
    'length unicode number null' => ['jq -c length', '"é🙂" -12 null', "2\n12\n0\n"],
    'has null is still present' => ['jq -c \'has("a"),has("b")\'', '{"a":null}', "true\nfalse\n"],
    'has on null' => ['jq \'has("a")\'', 'null', "false\n"],
    'has array index' => ["jq -c 'has(0),has(-1),has(2)'", '[null,7]', "true\nfalse\nfalse\n"],
    'has truncates numeric index' => ["jq -c 'has(1.0),has(1.9),has(-0.1),has(-1.1)'", '[7,2,11]', "true\ntrue\ntrue\nfalse\n"],
    'raw strings but JSON nonstrings' => ["jq -r '.[]'", '["é/x\nq",{},false]', "é/x\nq\n{}\nfalse\n"],
    'null input arguments' => ['jq -nc --arg name \'a b\' --argjson nums \'[7,2]\' \'{name:$name,nums:$nums}\'', 'not JSON', "{\"name\":\"a b\",\"nums\":[7,2]}\n"],
    'duplicate argument first wins including null' => ['jq -nc --argjson x null --arg x later \'$x\'', '', "null\n"],
    'slurp JSON value streams' => ["jq -sc 'map(type)'", '{}[7] "x" true null', "[\"object\",\"array\",\"string\",\"boolean\",\"null\"]\n"],
    'slurp empty input' => ['jq -sc .', '', "[]\n"],
    'files and explicit stdin' => ['jq -c .n one - two', '{"n":19}', "7\n2\n19\n11\n"],
    'slurp multiple files' => ["jq -sc 'map(.n)' one two", '', "[7,2,11]\n"],
    'null input ignores files' => ['jq -nc . missing', '', "null\n"],
    'long option aliases' => ["jq --null-input --compact-output --exit-status '{}'", '', "{}\n"],
    'exit last false' => ["jq -ec '.[]'", '[7,false]', "7\nfalse\n", 1],
    'exit zero is truthy' => ['jq -e .', '0', "0\n"],
    'exit no results' => ['jq -e empty', '{}', '', 4],
    'exit no inputs' => ['jq -e .', '', '', 4],
    'empty without exit status' => ['jq empty', '{}', ''],
    'JSON equality respects types' => ['jq -nc \'[1 == "1", {} == [], {a:1,b:2} == {b:2,a:1}, [1,2] < [1,3]]\'', '', "[false,false,true,true]\n"],
    'comparison asymmetric streams' => ["jq -nc '(7,2) > (5,9)'", '', "true\nfalse\nfalse\nfalse\n"],
    'type ordering and equality boundaries' => ["jq -nc '[null==null,true==true,false==false,[1,2]>[1],1!=2,2<=2,null<false]'", '', "[true,true,true,true,true,true,true]\n"],
    'select zero and empty string are truthy' => ["jq -c 'select(.)'", '0 "" false null [] {}', "0\n\"\"\n[]\n{}\n"],
    'negative literal filter' => ['jq -n -12', '', "-12\n"],
]);

test('jq reports unsupported syntax and input errors', function (string $script, string $input, int $status, string $message): void {
    $result = $this->bash->exec($script, new ExecOptions(stdin: $input));

    expect($result->exitCode)->toBe($status)
        ->and($result->stderr)->toContain($message);
})->with([
    'unknown option' => ['jq --sort-keys .', '{}', 2, 'unrecognized option'],
    'raw input unsupported' => ['jq -R .', '{}', 2, 'invalid option'],
    'arithmetic unsupported' => ["jq '. + 1'", '1', 3, 'unsupported filter'],
    'recursive descent unsupported' => ["jq '..'", '{}', 3, 'expected field'],
    'optional access unsupported' => ["jq '.a?'", '{}', 3, 'unsupported filter'],
    'slicing unsupported' => ["jq '.[1:2]'", '[1,2]', 3, 'expected ]'],
    'unknown function' => ['jq sort', '[]', 3, 'unsupported filter'],
    'undefined variable even without inputs' => ['jq \'$missing\'', '', 3, 'undefined variable'],
    'incomplete filter' => ["jq 'map('", '[]', 3, 'unsupported filter'],
    'trailing filter token' => ["jq '. length'", '', 3, 'unsupported filter token'],
    'spacing within an unquoted field' => ["jq '.a. b'", '{}', 3, 'expected field name adjacent to dot'],
    'dynamic indexes unsupported' => ["jq '.[.n]'", '{}', 3, 'only literal string keys'],
    'unquoted numeric object key' => ["jq -n '{7:2}'", '', 3, 'expected field name'],
    'arg missing value' => ['jq --arg x', '', 2, 'requires'],
    'argjson invalid' => ['jq --argjson x nope .', '', 2, 'invalid JSON'],
    'argjson empty' => ["jq --argjson x '' .", '', 2, 'invalid JSON'],
    'argjson multiple values' => ["jq --argjson x '1 2' .", '', 2, 'invalid JSON'],
    'bad JSON' => ['jq .', '{"a":}', 5, 'parse error'],
    'unclosed JSON' => ['jq .', '[1,2', 5, 'parse error'],
    'invalid adjacent scalar' => ['jq .', '1true', 5, 'parse error'],
    'nonfinite input number unsupported' => ['jq .', '1e999', 5, 'numeric range'],
    'nonfinite filter number unsupported' => ["jq -n '1e999 | type'", '', 3, 'numeric range'],
    'overflow index unsupported' => ["jq '.[99999999999999999999]'", '[]', 3, 'integer range'],
    'NUL key unsupported' => ['jq -nc \'{"\u0000":7}\'', '', 3, 'NUL are unsupported'],
    'cannot index array by string' => ['jq .a', '[]', 5, 'cannot index array'],
    'cannot iterate null' => ["jq '.[]'", 'null', 5, 'cannot iterate'],
    'has mismatched key type' => ['jq \'has("a")\'', '[]', 5, 'has requires'],
    'length boolean' => ['jq length', 'true', 5, 'length is not defined'],
    'keys number' => ['jq keys', '3', 5, 'keys requires'],
    'missing file' => ['jq . missing', '', 2, 'No such file'],
]);

test('jq continues past unreadable files and preserves prior output on errors', function (): void {
    $result = $this->bash->exec('jq -c .n missing one');
    expect($result->stdout)->toBe("7\n")->and($result->exitCode)->toBe(2);

    $result = $this->bash->exec('jq -sc . missing one two');
    expect($result->stdout)->toBe("[{\"n\":7},{\"n\":2},{\"n\":11}]\n")->and($result->exitCode)->toBe(2);

    $result = $this->bash->exec('jq -c .', new ExecOptions(stdin: '7 {bad}'));
    expect($result->stdout)->toBe("7\n")->and($result->exitCode)->toBe(5);
});

test('jq enforces resource limits through Bash', function (string $script, string $input, Limits $limits): void {
    expect(fn () => $this->bash->exec($script, new ExecOptions(limits: $limits, stdin: $input)))
        ->toThrow(ExecutionLimitException::class, 'limit exceeded');
})->with([
    'fanout before decoding' => ['jq .', '[7,2,11]', new Limits(maxArrayElements: 2)],
    'constructed fanout' => ["jq -nc '[7,2,11]'", '', new Limits(maxArrayElements: 2)],
    'object construction fanout' => ["jq -nc '{a:7,b:2,c:11}'", '', new Limits(maxArrayElements: 2)],
    'filter tokens' => ["jq -n '".implode(',', array_fill(0, 30, '.'))."'", '', new Limits(maxTokens: 20)],
    'filter recursion' => ["jq -n '".str_repeat('[', 12).'.'.str_repeat(']', 12)."'", '', new Limits(maxAstDepth: 10)],
    'linear access tree depth' => ["jq -n '".str_repeat('.a', 12)."'", '', new Limits(maxAstDepth: 10)],
    'JSON recursion' => ['jq .', str_repeat('[', 12).'7'.str_repeat(']', 12), new Limits(maxAstDepth: 10)],
    'evaluation work' => ["jq -c 'map(.,.,.)'", '[7,2,11]', new Limits(maxLoopIterations: 10)],
    'output' => ['jq -nc \'["abcdefghijklmnop","abcdefghijklmnop"]\'', '', new Limits(maxOutputSize: 30)],
    'escaped output' => ['jq .', '"\\u0001\\u0001\\u0001\\u0001\\u0001"', new Limits(maxOutputSize: 30)],
    'input bytes' => ['jq .', '"'.str_repeat('a', 100).'"', new Limits(maxStringLength: 50)],
    'exponential construction size' => ["jq -nc '[7,2] | [.,.] | [.,.] | [.,.]'", '', new Limits(maxOutputSize: 100)],
]);

test('jq limits also hold through the command interface without shell expansion', function (array $args, string $stdin, Limits $limits): void {
    $commandContext = new CommandContext(
        fs: new InMemoryFs,
        cwd: '/home/user',
        env: [],
        stdin: $stdin,
        limits: $limits,
        exec: fn (string $script): ExecResult => new ExecResult,
    );

    expect(fn (): ExecResult => (new Jq_)->execute($args, $commandContext))
        ->toThrow(ExecutionLimitException::class, 'limit exceeded');
})->with([
    'argument length' => [['-n', '--arg', 'x', 'four', '$x'], '', new Limits(maxStringLength: 3)],
    'JSON argument length' => [['-n', '--argjson', 'x', '[1,2]', '$x'], '', new Limits(maxStringLength: 3)],
    'literal string length' => [['-n', '"four"'], '', new Limits(maxStringLength: 3)],
    'filter bytes' => [['-n', str_repeat(' ', 21).'.'], '', new Limits(maxInputSize: 20)],
    'JSON collection tokens' => [['.'], '[1,2,3,4]', new Limits(maxTokens: 3)],
    'JSON scalar stream tokens' => [['.'], '1 2 3 4', new Limits(maxTokens: 3)],
    'single element with zero capacity' => [['.'], '{"n":7}', new Limits(maxArrayElements: 0)],
    'value size before rendering' => [['.'], '[11,2,3]', new Limits(maxOutputSize: 20)],
]);

test('agent commands propagate execution limits from a custom filesystem', function (string $script): void {
    $fileSystem = $this->createStub(FileSystemInterface::class);
    $fileSystem->method('readFile')->willThrowException(new ExecutionLimitException('custom filesystem limit exceeded'));
    $fileSystem->method('stat')->willThrowException(new ExecutionLimitException('custom filesystem limit exceeded'));
    $bash = new Bash(new BashOptions(fs: $fileSystem));

    expect(fn (): BashExecResult => $bash->exec($script))->toThrow(ExecutionLimitException::class, 'custom filesystem limit exceeded');
})->with(['jq . input', 'bash input', 'diff input other', 'rg pattern input']);
