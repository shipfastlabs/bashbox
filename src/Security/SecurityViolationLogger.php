<?php

declare(strict_types=1);

namespace BashBox\Security;

final class SecurityViolationLogger
{
    /** @var list<array{type: SecurityViolationType, message: string, context: array<string, mixed>, timestamp: float}> */
    private array $violations = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(SecurityViolationType $securityViolationType, string $message, array $context = []): void
    {
        $this->violations[] = [
            'type' => $securityViolationType,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * @return list<array{type: SecurityViolationType, message: string, context: array<string, mixed>, timestamp: float}>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    public function clear(): void
    {
        $this->violations = [];
    }

    public function count(): int
    {
        return count($this->violations);
    }
}
