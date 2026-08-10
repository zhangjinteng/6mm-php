<?php

declare(strict_types=1);

namespace SixMm\Shared\UserDetails;

final class UserDetail
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        private int $platformUserId,
        private array $attributes
    ) {
    }

    public function platformUserId(): int
    {
        return $this->platformUserId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
