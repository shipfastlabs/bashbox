<?php

declare(strict_types=1);

namespace BashBox\Network;

final readonly class NetworkConfig
{
    /**
     * @param  list<string>  $allowedUrlPrefixes  URL prefixes that are allowed (e.g., "https://api.example.com/")
     * @param  list<string>  $allowedMethods  HTTP methods allowed (e.g., ["GET", "POST"])
     * @param  int  $maxResponseSize  Maximum response body size in bytes
     * @param  int  $maxRedirects  Maximum number of HTTP redirects to follow
     * @param  int  $timeout  Request timeout in seconds
     */
    public function __construct(
        public array $allowedUrlPrefixes = [],
        public array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
        public bool $denyPrivateRanges = true,
        public int $maxResponseSize = 10 * 1024 * 1024,
        public int $maxRedirects = 20,
        public int $timeout = 30,
    ) {}
}
