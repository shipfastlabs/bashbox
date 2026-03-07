<?php

declare(strict_types=1);

use BashBox\Filesystem\UnixFileMode;
use BashBox\Filesystem\UnixFileType;

test('extracts the unix file type from a stat mode', function (): void {
    expect(UnixFileMode::type(0o040755))->toBe(UnixFileType::Directory)
        ->and(UnixFileMode::type(0o100644))->toBe(UnixFileType::RegularFile)
        ->and(UnixFileMode::type(0o120777))->toBe(UnixFileType::SymbolicLink);
});

test('matches file type helpers against a stat mode', function (): void {
    expect(UnixFileMode::isDirectory(0o040755))->toBeTrue()
        ->and(UnixFileMode::isRegularFile(0o040755))->toBeFalse()
        ->and(UnixFileMode::isRegularFile(0o100644))->toBeTrue()
        ->and(UnixFileMode::isSymbolicLink(0o120777))->toBeTrue();
});

test('extracts permissions from a stat mode', function (): void {
    expect(UnixFileMode::permissions(0o100755))->toBe(0o755)
        ->and(UnixFileMode::permissions(0o040700))->toBe(0o700);
});
