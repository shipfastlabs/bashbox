<?php

declare(strict_types=1);

namespace BashBox\Filesystem;

use LogicException;

final class UnixFileMode
{
    /** The nine `ls -l` permission characters, with set-id and sticky bits: rwxr-sr-T */
    public static function symbolic(int $mode): string
    {
        $text = '';

        foreach ([[6, 04000, 's'], [3, 02000, 's'], [0, 01000, 't']] as [$shift, $special, $letter]) {
            $bits = ($mode >> $shift) & 7;
            $execute = ($bits & 1) !== 0;
            $text .= (($bits & 4) !== 0 ? 'r' : '-').(($bits & 2) !== 0 ? 'w' : '-')
                .(($mode & $special) !== 0 ? ($execute ? $letter : strtoupper($letter)) : ($execute ? 'x' : '-'));
        }

        return $text;
    }

    /** Applies a chmod-style MODE (octal, or symbolic like `u+x,go=r`) as gnulib's mode_compile() and mode_adjust() do; null if MODE is invalid. */
    public static function adjust(string $spec, int $mode, bool $directory = false, int $umask = 0): ?int
    {
        if (preg_match('/^[0-7]+$/', $spec) === 1) {
            $octal = (int) octdec($spec);

            // Like GNU chmod, a short octal mode keeps a directory's set-id bits.
            return $octal > 07777 ? null : ($directory && strlen(ltrim($spec, '0')) < 4 ? $octal | ($mode & 06000) : $octal);
        }

        if (preg_match('/^(?:[ugoa]*(?:[-+=](?:[ugo]|[rwxXst]*))+)(?:,[ugoa]*(?:[-+=](?:[ugo]|[rwxXst]*))+)*$/', $spec) !== 1) {
            return null;
        }

        $new = $mode & 07777;

        foreach (explode(',', $spec) as $clause) {
            preg_match('/^([ugoa]*)(.*)$/', $clause, $m) ?: throw new LogicException('pattern matches any clause');
            $affected = 0;

            foreach (str_split($m[1]) as $who) {
                $affected |= ['u' => 04700, 'g' => 02070, 'o' => 01007, 'a' => 07777][$who];
            }

            preg_match_all('/([-+=])([ugo]|[rwxXst]*)/', $m[2], $operations, PREG_SET_ORDER);

            foreach ($operations as [, $operator, $permissions]) {
                $value = 0;

                if (in_array($permissions, ['u', 'g', 'o'], true)) {
                    // Copy that class's current bits to every class.
                    $value = (($new >> ['u' => 6, 'g' => 3, 'o' => 0][$permissions]) & 7) * 0111;
                } else {
                    foreach (str_split($permissions) as $permission) {
                        $value |= match ($permission) {
                            'r' => 0444,
                            'w' => 0222,
                            'x' => 0111,
                            's' => 06000,
                            't' => 01000,
                            default => ($new & 0111) !== 0 || $directory ? 0111 : 0, // X
                        };
                    }
                }

                // On directories, set-id bits change only when named.
                $omit = $directory ? 06000 & ~($affected !== 0 ? $affected & $value : $value) : 0;
                $value &= ($affected !== 0 ? $affected : ~$umask & 07777) & ~$omit;
                $new = match ($operator) {
                    '=' => ($new & (($affected !== 0 ? ~$affected : 0) | $omit)) | $value,
                    '+' => $new | $value,
                    default => $new & ~$value,
                };
            }
        }

        return $new & 07777;
    }
}
