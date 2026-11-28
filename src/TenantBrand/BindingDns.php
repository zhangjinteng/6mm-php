<?php
declare(strict_types=1);
namespace SixMm\Shared\TenantBrand;

interface BindingDns
{
    public function zone(string $domain): string;
    public function records(object $binding): array;
    public function verify(object $binding): void;
}
