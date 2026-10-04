<?php

declare(strict_types=1);

namespace BashBox\Network;

use BashBox\Network\Exceptions\NetworkAccessDeniedException;
use Error;
use RuntimeException;

/** Validates each redirect hop before it is requested. */
final class ValidatedRedirects
{
    private int $count = 0;

    public function __construct(
        private readonly AllowList $allowList,
        private readonly int $maxRedirects,
    ) {
        if ($maxRedirects < 1) {
            throw new Error('Invalid redirection limit: '.$maxRedirects);
        }
    }

    public function validate(string $method, string $fromUrl, string $toUrl): void
    {
        if (++$this->count > $this->maxRedirects) {
            // Same code and message as curl's CURLE_TOO_MANY_REDIRECTS.
            throw new RuntimeException(sprintf('Maximum (%d) redirects followed', $this->maxRedirects), 47);
        }

        try {
            $this->allowList->validateRequest($method, $toUrl);
        } catch (NetworkAccessDeniedException $networkAccessDeniedException) {
            throw new NetworkAccessDeniedException(sprintf(
                'Redirect to denied URL: %s (original: %s). %s',
                $toUrl,
                $fromUrl,
                $networkAccessDeniedException->getMessage(),
            ), $networkAccessDeniedException->getCode(), previous: $networkAccessDeniedException);
        }
    }
}
