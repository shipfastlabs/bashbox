<?php

declare(strict_types=1);

namespace BashBox\Regex;

/**
 * Translates POSIX basic/extended regular expressions (GNU flavour, as used by grep and sed) into PCRE.
 *
 * In brackets, single-character collating elements [.c.] and equivalence classes [=c=] are that character, as in
 * the C locale; glibc rejects longer names.
 * Limitation: PCRE returns its first match, not POSIX's leftmost-longest one: `a|ab` and `a?(ab)?` match only "a" of "ab".
 * Matching exactly like glibc needs a POSIX (DFA/NFA) engine in place of PCRE.
 */
final class PosixRegex
{
    /** PCRE compile error fragments and the glibc regcomp() message GNU tools print for them. */
    private const array ERRORS = [
        'missing closing parenthesis' => 'Unmatched ( or \\(',
        'unmatched closing parenthesis' => 'Unmatched ) or \\)',
        'missing terminating ]' => 'Unmatched [, [^, [:, [., or [=',
        'quantifier does not follow' => 'Invalid preceding regular expression',
        '(*VERB)' => 'Invalid preceding regular expression',
        'out of order in {}' => 'Invalid content of \\{\\}',
        'non-existent subpattern' => 'Invalid back reference',
        'range out of order' => 'Invalid range end',
        'invalid range' => 'Invalid range end',
        'POSIX class' => 'Invalid character class name',
        'collating elements' => 'Invalid collation character',
    ];

    /**
     * Returns a '/'-delimited PCRE pattern body (without delimiters or modifiers). A pattern glibc would reject
     * yields a body that fails to compile and that error() reports with glibc's message.
     *
     * @param  bool  $ignoreLeadingOps  GNU grep's leniency: drop ERE `*`, `+` and `?` at the start of an expression and take
     *                                  malformed intervals literally, where sed (glibc regcomp()) rejects both
     * @param  string|null  $multiline  glibc's REG_NEWLINE (sed's M flag), given the record separator (a newline, or NUL for
     *                                  sed -z): anchors also match at separators, which `.` and `[^...]` do not match, nor a newline
     */
    public static function toPcre(string $pattern, bool $extended, bool $ignoreLeadingOps = true, ?string $multiline = null): string
    {
        $separator = $multiline === null ? null : sprintf('\x%02x', ord($multiline));
        $out = '';
        $len = strlen($pattern);
        // At the start of an expression or group a quantifier is literal, and a BRE '^' is an anchor.
        $atStart = true;
        // Where the last repeatable item starts in $out, and whether it already has a quantifier
        $atom = null;
        $repeated = false;
        $groups = [];

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            $wasStart = $atStart;
            $atStart = false;
            $escaped = $ch === '\\';

            if ($escaped) {
                if ($i + 1 === $len) {
                    return self::invalid('Trailing backslash');
                }

                $ch = $pattern[++$i];
            }

            // One token: ( ) | a quantifier, or an anchor or atom
            $special = $extended ? ! $escaped && str_contains('()|*+?{', $ch) : ($escaped ? str_contains('()|{+?', $ch) : $ch === '*');
            $kind = $special ? (str_contains('()|', $ch) ? $ch : 'repeat') : 'atom';

            if ($kind === '(') {
                $groups[] = strlen($out);
                $out .= '(';
                [$atStart, $atom] = [true, null];
            } elseif ($kind === ')') {
                $out .= ')';
                [$atom, $repeated] = [array_pop($groups), false];
            } elseif ($kind === '|') {
                $out .= '|';
                [$atStart, $atom] = [true, null];
            } elseif ($kind === 'repeat') {
                $interval = $ch === '{' ? self::interval($pattern, $i, $extended) : [$ch, $i];

                if ($wasStart && ($extended || $ch === '{') && ! $ignoreLeadingOps) {
                    return self::invalid('Invalid preceding regular expression');
                }

                if ($wasStart && $extended && $ch !== '{') {
                    // GNU grep ignores a leading quantifier
                    $atStart = true;
                } elseif ($wasStart || $atom === null || (is_string($interval) && $ignoreLeadingOps)) {
                    // Literal: a BRE quantifier with nothing to repeat, or one of grep's malformed intervals
                    [$atom, $repeated] = [strlen($out), false];
                    $out .= '\\'.$ch;
                } elseif (is_string($interval)) {
                    return self::invalid($interval);
                } elseif ($repeated && ! $extended && ! $ignoreLeadingOps) {
                    return self::invalid('Invalid preceding regular expression');
                } else {
                    // POSIX applies stacked quantifiers in turn; in PCRE a second one would make the first lazy or possessive
                    $out = $repeated ? substr($out, 0, $atom).'(?:'.substr($out, $atom).')' : $out;
                    [$out, $i, $repeated] = [$out.$interval[0], $interval[1], true];
                }
            } elseif (! $escaped && $ch === '[') {
                [$class, $i] = self::bracket($pattern, $i, $separator);
                [$atom, $repeated] = [strlen($out), false];
                $out .= $class;
            } elseif (! $escaped && ($ch === '^' || $ch === '$')) {
                // In a BRE, ^ anchors only at the start and $ only at the end of an expression
                $rest = substr($pattern, $i + 1);
                $anchor = $extended || ($ch === '^' ? $wasStart : $rest === '' || str_starts_with($rest, '\)') || str_starts_with($rest, '\|'));
                [$atom, $repeated] = $anchor ? [null, false] : [strlen($out), false];
                $out .= match (true) {
                    ! $anchor => '\\'.$ch,
                    // PCRE's multiline ^ would not match after a final newline, nor at a NUL
                    $separator !== null => $ch === '^' ? sprintf('(?<![^%s])', $separator) : sprintf('(?![^%s])', $separator),
                    default => $ch,
                };
                $atStart = $ch === '^' && $wasStart;
            } else {
                [$atom, $repeated] = [strlen($out), false];
                $out .= match (true) {
                    ! $escaped && $ch === '.' => $separator === null ? '.' : sprintf('[^\n%s]', $separator),
                    ! $escaped => preg_quote($ch, '/'),
                    $ch === '<' => '\b(?=\w)',
                    $ch === '>' => '\b(?<=\w)',
                    // sed -z with M searches each NUL-separated record on its own
                    $ch === '`' => $multiline === "\0" ? '(?<![^\x00])' : '\A',
                    $ch === "'" => $multiline === "\0" ? '(?![^\x00])' : '\z',
                    // GNU treats other letters after a backslash as "stray" and matches them literally
                    ctype_alpha($ch) && ! str_contains('wWsSbB', $ch) => $ch,
                    default => '\\'.$ch,
                };
            }
        }

        return $out;
    }

    /**
     * Parses the interval starting at $i (`{` or BRE `\{`) as glibc does.
     *
     * @return array{string, int}|string the PCRE quantifier and the index of the interval's last character, or glibc's error
     */
    private static function interval(string $pattern, int $i, bool $extended): array|string
    {
        $j = $i + 1;
        $token = '';
        $start = self::intervalNumber($pattern, $j, $extended, $token);

        if ($start === -1) {
            // {,n} is {0,n}; {} is invalid
            $start = $token === ',' ? 0 : -2;
        }

        $end = $start === -2 ? -2 : ($token === '}' ? $start : ($token === ',' ? self::intervalNumber($pattern, $j, $extended, $token) : -2));

        if ($start === -2 || $end === -2 || ($end !== -1 && $start > $end) || $token !== '}') {
            return $token === '' ? 'Unmatched \\{' : 'Invalid content of \\{\\}';
        }

        if (max($start, $end) > 0x7FFF) {
            return 'Regular expression too big';
        }

        return [$end === -1 ? sprintf('{%s,}', $start) : ($start === $end ? sprintf('{%s}', $start) : sprintf('{%s,%s}', $start, $end)), $j - 1];
    }

    /**
     * Reads digits up to `,` or the closing brace, setting $token to ',', '}' or '' (the end of the pattern).
     *
     * @return int the number, -1 if there were no digits, -2 if something else was found
     */
    private static function intervalNumber(string $pattern, int &$j, bool $extended, string &$token): int
    {
        $close = $extended ? '}' : '\}';
        $len = strlen($pattern);
        $n = -1;

        for ($token = ''; $j < $len; $j++) {
            if (substr_compare($pattern, $close, $j, strlen($close)) === 0 || $pattern[$j] === ',') {
                $token = $pattern[$j] === ',' ? ',' : '}';
                $j += $token === ',' ? 1 : strlen($close);

                return $n;
            }

            $n = ! ctype_digit($pattern[$j]) || $n === -2 ? -2 : min(0x8000, ($n === -1 ? 0 : $n * 10) + (int) $pattern[$j]);
        }

        return -2;
    }

    /** A body that does not compile, carrying the glibc message for error(). */
    private static function invalid(string $message): string
    {
        return '(?#posix-error:'.$message.')(';
    }

    /**
     * Null when PCRE compiles the pattern, else the matching glibc regcomp() message (as GNU grep and sed print it).
     */
    public static function error(string $regex): ?string
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            preg_match($regex, '');
        } finally {
            restore_error_handler();
        }

        if ($warning === null) {
            return null;
        }

        if (preg_match('/\(\?#posix-error:([^)]*)\)\(/', $regex, $m) === 1) {
            return $m[1];
        }

        $fragment = array_find_key(self::ERRORS, fn (string $posix, string $pcre): bool => str_contains($warning, $pcre));

        return $fragment === null ? 'Invalid regular expression' : self::ERRORS[$fragment];
    }

    /**
     * Copies a bracket expression starting at $start; backslashes are literal inside POSIX brackets.
     *
     * @return array{string, int} the PCRE class and the index of its closing ']'
     */
    private static function bracket(string $pattern, int $start, ?string $separator): array
    {
        $len = strlen($pattern);
        $i = $start + 1;
        $out = '[';

        if ($i < $len && $pattern[$i] === '^') {
            $out .= '^'.($separator === null ? '' : '\n'.$separator);
            $i++;
        }

        // A ']' right after the opening bracket is a literal member.
        if ($i < $len && $pattern[$i] === ']') {
            $out .= '\]';
            $i++;
        }

        for (; $i < $len; $i++) {
            $ch = $pattern[$i];

            if ($ch === ']') {
                return [$out.']', $i];
            }

            if ($ch === '[' && $i + 1 < $len && str_contains(':.=', $pattern[$i + 1])) {
                $close = strpos($pattern, $pattern[$i + 1].']', $i + 2);

                if ($close !== false) {
                    $name = substr($pattern, $i + 2, $close - $i - 2);
                    // PCRE rejects longer collating names, which error() reports as glibc does
                    $out .= $pattern[$i + 1] !== ':' && strlen($name) === 1 ? preg_quote($name, '/') : substr($pattern, $i, $close + 2 - $i);
                    $i = $close + 1;

                    continue;
                }
            }

            $out .= match ($ch) {
                '\\' => '\\\\',
                '/' => '\/',
                '[' => '\[',
                default => $ch,
            };
        }

        // Unterminated bracket: leave it for PCRE to reject.
        return [$out, $len - 1];
    }
}
