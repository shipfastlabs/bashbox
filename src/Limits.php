<?php

declare(strict_types=1);

namespace BashBox;

use BashBox\Filesystem\DiskQuota;

/** Hard limits on one exec() (going over one throws ExecutionLimitException), plus the default filesystem's quota. */
final readonly class Limits
{
    public function __construct(
        /** Nested shell function calls */
        public int $maxCallDepth = 100,
        /** Commands run in the whole script */
        public int $maxCommandCount = 10_000,
        /** Iterations of any one loop */
        public int $maxLoopIterations = 10_000,
        /** Directory entries read for one pathname expansion */
        public int $maxGlobOperations = 100_000,
        /** Bytes of stdout plus stderr, in total and in any one capture ($(...), a pipe stage) */
        public int $maxOutputSize = 10 * 1024 * 1024,
        /** Bytes in a variable's value or a word's expansion */
        public int $maxStringLength = 10 * 1024 * 1024,
        /** Nested $(...), <(...), eval, source and trap texts */
        public int $maxSubstitutionDepth = 50,
        /** Words one brace expansion produces */
        public int $maxBraceExpansionResults = 10_000,
        /** Elements in an array, and words one word expands to */
        public int $maxArrayElements = 100_000,
        /** The highest file descriptor number plus one */
        public int $maxFileDescriptors = 1024,
        /** Branches (b, t, T) one sed run may take */
        public int $maxSedIterations = 100_000,
        /** Bytes of script text */
        public int $maxInputSize = 1024 * 1024,
        /** Tokens in one script text */
        public int $maxTokens = 100_000,
        /** Nesting of the parsed script */
        public int $maxAstDepth = 500,
        /** Bytes in a here-document, before and after expansion */
        public int $maxHereDocSize = 1024 * 1024,
        /** Commands in one pipeline */
        public int $maxPipelineDepth = 100,
        /** Bytes the default filesystem may hold */
        public int $maxFilesystemBytes = DiskQuota::DEFAULT_BYTES,
        /** Files, directories and links the default filesystem may hold */
        public int $maxFilesystemFiles = DiskQuota::DEFAULT_FILES,
    ) {}
}
