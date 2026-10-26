<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction;
interface GridScope {
    public function isCurrent(): bool;
    public function isGlobal(): bool;
    public function homeSiteIds(): array;
    public function allowsSite(string $site): bool;
    /** null: no merchant restriction; []: deny every order. Server-authorized only. */
    public function orderAgentIds(): ?array;
}
