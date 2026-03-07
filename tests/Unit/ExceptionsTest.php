<?php

declare(strict_types=1);

use BashBox\Exceptions\BreakException;
use BashBox\Exceptions\ContinueException;
use BashBox\Exceptions\ReturnException;

test('BreakException stores levels correctly', function (): void {
    $e = new BreakException(1);
    expect($e->levels)->toBe(1);
    
    $e = new BreakException(3);
    expect($e->levels)->toBe(3);
});

test('BreakException has correct message', function (): void {
    $e = new BreakException();
    expect($e->getMessage())->toBe('break');
});

test('ContinueException stores levels correctly', function (): void {
    $e = new ContinueException(1);
    expect($e->levels)->toBe(1);
    
    $e = new ContinueException(2);
    expect($e->levels)->toBe(2);
});

test('ContinueException has correct message', function (): void {
    $e = new ContinueException();
    expect($e->getMessage())->toBe('continue');
});

test('ReturnException stores exit code correctly', function (): void {
    $e = new ReturnException(0);
    expect($e->exitCode)->toBe(0);
    
    $e = new ReturnException(42);
    expect($e->exitCode)->toBe(42);
    
    $e = new ReturnException(1);
    expect($e->exitCode)->toBe(1);
});

test('ReturnException has correct message', function (): void {
    $e = new ReturnException();
    expect($e->getMessage())->toBe('return');
});
