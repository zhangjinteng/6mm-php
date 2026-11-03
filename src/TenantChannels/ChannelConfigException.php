<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantChannels;

final class ChannelConfigException extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
