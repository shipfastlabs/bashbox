<?php

declare(strict_types=1);

namespace BashBox;

final readonly class Limits
{
    public function __construct(
        public int $maxCallDepth = 100,
        public int $maxCommandCount = 10_000,
        public int $maxLoopIterations = 10_000,
        public int $maxGlobOperations = 100_000,
        public int $maxOutputSize = 10 * 1024 * 1024,
        public int $maxStringLength = 10 * 1024 * 1024,
        public int $maxSubstitutionDepth = 50,
        public int $maxBraceExpansionResults = 10_000,
        public int $maxArrayElements = 100_000,
        public int $maxFileDescriptors = 1024,
        public int $maxSedIterations = 100_000,
        public int $maxAwkIterations = 100_000,
        public int $maxJqIterations = 100_000,
        public int $maxInputSize = 1024 * 1024,
        public int $maxTokens = 100_000,
        public int $maxAstDepth = 500,
        public int $maxHereDocSize = 1024 * 1024,
        public int $maxPipelineDepth = 100,
        public int $maxBackgroundJobs = 0,
    ) {}
}
