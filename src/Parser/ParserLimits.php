<?php

declare(strict_types=1);

namespace BashBox\Parser;

final readonly class ParserLimits
{
    public const int MAX_INPUT_SIZE = 1_000_000;

    public const int MAX_TOKENS = 100_000;

    public const int MAX_PARSE_ITERATIONS = 1_000_000;

    public const int MAX_PARSER_DEPTH = 200;

    public const int MAX_HEREDOC_SIZE = 10_485_760;
}
