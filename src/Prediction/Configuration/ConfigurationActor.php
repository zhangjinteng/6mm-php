<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction\Configuration;

/** Implemented by the host's authenticated context, never populated from request fields. */
interface ConfigurationActor
{
    public function operatorId(): string;
    public function agentId(): string;
    public function traceId(): string;
}
