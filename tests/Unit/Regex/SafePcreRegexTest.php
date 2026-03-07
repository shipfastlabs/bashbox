<?php

declare(strict_types=1);

use BashBox\Exceptions\BashException;
use BashBox\Regex\RegexFactory;
use BashBox\Regex\SafePcreRegex;

// ===== test() =====
test('test returns true for matching pattern', function (): void {
    $regex = new SafePcreRegex;
    expect($regex->test('hello', 'hello world'))->toBeTrue();
});

test('test returns false for non-matching pattern', function (): void {
    $regex = new SafePcreRegex;
    expect($regex->test('xyz', 'hello world'))->toBeFalse();
});

test('test works with already-delimited pattern', function (): void {
    $regex = new SafePcreRegex;
    expect($regex->test('/\d+/', 'abc123'))->toBeTrue();
});

test('test works with pattern flags', function (): void {
    $regex = new SafePcreRegex;
    expect($regex->test('/hello/i', 'HELLO WORLD'))->toBeTrue();
});

// ===== match() =====
test('match returns captures on success', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->match('/(\d+)-(\d+)/', 'date: 2024-01');
    expect($result)->not->toBeNull();
    expect($result[0])->toBe('2024-01');
    expect($result[1])->toBe('2024');
    expect($result[2])->toBe('01');
});

test('match returns null on no match', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->match('/\d+/', 'no numbers here');
    expect($result)->toBeNull();
});

test('match auto-wraps undelimited pattern', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->match('\d+', 'abc42def');
    expect($result)->not->toBeNull();
    expect($result[0])->toBe('42');
});

// ===== replace() =====
test('replace substitutes matches', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->replace('/\d+/', 'NUM', 'a1b2c3');
    expect($result)->toBe('aNUMbNUMcNUM');
});

test('replace returns original when no match', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->replace('/\d+/', 'NUM', 'abc');
    expect($result)->toBe('abc');
});

test('replace supports backreferences', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->replace('/(\w+)@(\w+)/', '$1 at $2', 'user@host');
    expect($result)->toBe('user at host');
});

// ===== split() =====
test('split divides string by pattern', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->split('/[,;]\s*/', 'a, b; c,d');
    expect($result)->toBe(['a', 'b', 'c', 'd']);
});

test('split with limit', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->split('/\s+/', 'one two three four', 3);
    expect($result)->toBe(['one', 'two', 'three four']);
});

test('split returns single element when no match', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->split('/\|/', 'no pipes here');
    expect($result)->toBe(['no pipes here']);
});

// ===== pattern length limit =====
test('throws on pattern exceeding max length', function (): void {
    $regex = new SafePcreRegex(maxPatternLength: 50);
    $longPattern = str_repeat('a', 100);
    $regex->test($longPattern, 'subject');
})->throws(BashException::class, 'exceeds maximum length');

// ===== backtrack limit protection =====
test('throws on catastrophic backtracking', function (): void {
    $regex = new SafePcreRegex(backtrackLimit: 100);
    // Classic catastrophic backtracking pattern: (a+)+ against a string of a's followed by !
    $evilPattern = '/^(a+)+$/';
    $evilSubject = str_repeat('a', 30).'!';
    $regex->test($evilPattern, $evilSubject);
})->throws(BashException::class, 'Backtrack limit');

test('restores ini values after operation', function (): void {
    $originalBacktrack = ini_get('pcre.backtrack_limit');
    $originalRecursion = ini_get('pcre.recursion_limit');

    $regex = new SafePcreRegex(backtrackLimit: 42, recursionLimit: 24);

    try {
        $regex->test('/abc/', 'abc');
    } finally {
        expect(ini_get('pcre.backtrack_limit'))->toBe($originalBacktrack);
        expect(ini_get('pcre.recursion_limit'))->toBe($originalRecursion);
    }
});

// ===== delimiter handling =====
test('handles pattern containing forward slashes', function (): void {
    $regex = new SafePcreRegex;
    $result = $regex->match('http://example', 'visit http://example.com');
    expect($result)->not->toBeNull();
    expect($result[0])->toBe('http://example');
});

test('handles hash-delimited pattern', function (): void {
    $regex = new SafePcreRegex;
    expect($regex->test('#\d+#', 'abc123'))->toBeTrue();
});

// ===== factory =====
test('factory creates SafePcreRegex instance', function (): void {
    $regex = RegexFactory::create();
    expect($regex)->toBeInstanceOf(SafePcreRegex::class);
});

test('factory returns functional instance', function (): void {
    $regex = RegexFactory::create();
    expect($regex->test('/hello/', 'hello world'))->toBeTrue();
    expect($regex->match('/(\d+)/', 'abc42'))->not->toBeNull();
    expect($regex->replace('/x/', 'y', 'xox'))->toBe('yoy');
    expect($regex->split('/,/', 'a,b,c'))->toBe(['a', 'b', 'c']);
});
