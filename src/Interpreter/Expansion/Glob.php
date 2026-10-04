<?php

declare(strict_types=1);

namespace BashBox\Interpreter\Expansion;

/**
 * @phpstan-type GlobNode array{string, string, list<string>}
 *
 * Shell patterns: `*`, `?`, `[...]` (ranges, `!`/`^` negation, `[:class:]`), `\` escapes and, with extglob,
 * `?(..)` `*(..)` `+(..)` `@(..)` `!(..)`. They compile to PCRE, except that `!(..)` has no exact regex
 * form, so a pattern holding one goes through a small backtracking matcher instead.
 *
 * A GlobNode is its kind ('lit', '?', '*', '[' or '('), then the literal character, PCRE class or extglob
 * operator, then an extglob group's alternatives as pattern text.
 */
final readonly class Glob
{
    private function __construct(
        private string $subject,
        private bool $nocase,
    ) {}

    /** The PCRE body (no delimiters or anchors) for $pattern, or null when it holds `!(..)`. */
    public static function toRegex(string $pattern, bool $extglob, bool $lazy = false): ?string
    {
        return self::regex(self::parse($pattern, $extglob), $lazy);
    }

    public static function matches(string $pattern, string $subject, bool $extglob, bool $nocase = false): bool
    {
        $nodes = self::parse($pattern, $extglob);
        $regex = self::regex($nodes, false);

        return $regex === null
            ? new self($subject, $nocase)->sequence($nodes, 0, 0, strlen($subject))
            : preg_match('/^'.$regex.'\z/'.self::flags($pattern.$subject).($nocase ? 'i' : ''), $subject) === 1;
    }

    /** Regex flags for that text: the locale is UTF-8, so valid UTF-8 matches by character (`?`, `[é]`), anything else by byte. */
    public static function flags(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? 'su' : 's';
    }

    /** Whether $pattern has anything to expand: an unescaped `*`, `?`, `[`, or an extglob group. */
    public static function isPattern(string $pattern, bool $extglob): bool
    {
        return array_any(self::parse($pattern, $extglob), fn (array $node): bool => $node[0] !== 'lit');
    }

    /** @return list<GlobNode> */
    private static function parse(string $pattern, bool $extglob, int &$i = 0, bool $inGroup = false): array
    {
        $nodes = [];
        $len = strlen($pattern);

        while ($i < $len) {
            $ch = $pattern[$i];

            if ($inGroup && ($ch === '|' || $ch === ')')) {
                break;
            }

            if ($extglob && str_contains('?*+@!', $ch) && ($pattern[$i + 1] ?? '') === '(' && ($group = self::parseGroup($pattern, $i)) !== null) {
                $nodes[] = $group;

                continue;
            }

            // A bracket expression; a `[` without its closing `]` is literal. PCRE shares the class syntax.
            if ($ch === '[' && preg_match('/\G\[([!^]?)(\]?(?:\[:\w+:\]|[^]])*)\]/', $pattern, $class, 0, $i) === 1 && $class[2] !== '') {
                $nodes[] = ['[', self::validClass('['.($class[1] === '' ? '' : '^').str_replace('/', '\/', $class[2]).']'), []];
                $i += strlen($class[0]);

                continue;
            }

            $i++;
            $nodes[] = match ($ch) {
                '*', '?' => [$ch, '', []],
                '\\' => ['lit', $pattern[$i++] ?? '\\', []],
                default => ['lit', $ch, []],
            };
        }

        return $nodes;
    }

    /** A class PCRE rejects, such as [z-a], matches nothing, as in bash */
    private static function validClass(string $class): string
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match('/'.$class.'/', '') === false ? '(*FAIL)' : $class;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * An extglob group starting at $i (its operator), or null when its `)` is missing and it's literal text.
     *
     * @return GlobNode|null
     */
    private static function parseGroup(string $pattern, int &$i): ?array
    {
        $j = $i + 2;
        $alternatives = [];

        do {
            $from = $j;
            self::parse($pattern, true, $j, true);
            $alternatives[] = substr($pattern, $from, $j - $from);
        } while (($pattern[$j++] ?? '') === '|');

        if (($pattern[$j - 1] ?? '') !== ')') {
            return null;
        }

        $op = $pattern[$i];
        $i = $j;

        return ['(', $op, $alternatives];
    }

    /** @param list<GlobNode> $nodes */
    private static function regex(array $nodes, bool $lazy): ?string
    {
        $regex = '';

        foreach ($nodes as $node) {
            if ($node[0] !== '(') {
                $regex .= match ($node[0]) {
                    'lit' => preg_quote($node[1], '/'),
                    '?' => '.',
                    '*' => $lazy ? '.*?' : '.*',
                    default => $node[1], // '['
                };

                continue;
            }

            if ($node[1] === '!') {
                return null;
            }

            $alternatives = [];

            foreach ($node[2] as $alternative) {
                $alternatives[] = self::regex(self::parse($alternative, true), $lazy);
            }

            if (in_array(null, $alternatives, true)) {
                return null;
            }

            $quantifier = $node[1] === '@' ? '' : $node[1];
            $regex .= '(?:'.implode('|', $alternatives).')'.$quantifier.($lazy && $quantifier !== '' ? '?' : '');
        }

        return $regex;
    }

    /**
     * Whether $nodes from index $n match the subject between $from and $to exactly.
     *
     * @param  list<GlobNode>  $nodes
     */
    private function sequence(array $nodes, int $n, int $from, int $to): bool
    {
        if ($n === count($nodes)) {
            return $from === $to;
        }

        $node = $nodes[$n];

        if ($node[0] === '*' || $node[0] === '(') {
            for ($k = $from; $k <= $to; $k++) {
                if (($node[0] === '*' || $this->group($node, $from, $k)) && $this->sequence($nodes, $n + 1, $k, $to)) {
                    return true;
                }
            }

            return false;
        }

        return $from < $to && $this->character($node, $this->subject[$from]) && $this->sequence($nodes, $n + 1, $from + 1, $to);
    }

    /** @param GlobNode $node */
    private function character(array $node, string $ch): bool
    {
        return match ($node[0]) {
            'lit' => $this->nocase ? strcasecmp($node[1], $ch) === 0 : $node[1] === $ch,
            '?' => true,
            default => preg_match('/'.$node[1].'/'.($this->nocase ? 'i' : ''), $ch) === 1, // '['
        };
    }

    /** @param GlobNode $node an extglob group */
    private function group(array $node, int $from, int $to): bool
    {
        return match ($node[1]) {
            '@' => $this->alternative($node, $from, $to),
            '!' => ! $this->alternative($node, $from, $to),
            '?' => $from === $to || $this->alternative($node, $from, $to),
            '*' => $from === $to || $this->repeat($node, $from, $to),
            default => $this->repeat($node, $from, $to), // '+'
        };
    }

    /** @param GlobNode $node */
    private function alternative(array $node, int $from, int $to): bool
    {
        return array_any($node[2], fn (string $alternative): bool => $this->sequence(self::parse($alternative, true), 0, $from, $to));
    }

    /**
     * One or more alternatives back to back.
     *
     * @param  GlobNode  $node
     */
    private function repeat(array $node, int $from, int $to): bool
    {
        if ($this->alternative($node, $from, $to)) {
            return true;
        }

        for ($k = $from + 1; $k < $to; $k++) {
            if ($this->alternative($node, $from, $k) && $this->repeat($node, $k, $to)) {
                return true;
            }
        }

        return false;
    }
}
