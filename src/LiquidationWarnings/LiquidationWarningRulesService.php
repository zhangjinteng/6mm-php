<?php

declare (strict_types=1);
namespace SixMm\Shared\LiquidationWarnings;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
final class LiquidationWarningRulesService
{
    public const CONFIG_KEY = 'liquidation_warning_rules';
    public const ENTRY = 'min_large_position_value';
    public const HIGH = 'liquidation_high_risk_threshold';
    public const NOTIFY = 'liquidation_warning_notify_enabled';
    public function __construct(private ConnectionInterface $connection)
    {
    }
    /** Exact merchant scope first; absent record falls back as a whole to platform rules. */
    public function get(int $homeSiteId = 0, int $agentId = 0): array
    {
        $this->assertScope($homeSiteId, $agentId);
        $schema = $this->connection->getSchemaBuilder();
        if ($schema->hasTable('source_config')) {
            $record = $this->query($homeSiteId, $agentId)->first();
            if ($record === null && ($homeSiteId !== 0 || $agentId !== 0)) {
                $record = $this->query(0, 0)->first();
            }
            if ($record !== null) {
                return self::normalize($record->config_value, true);
            }
        }
        $legacy = $schema->hasTable('system_config') ? $this->connection->table('system_config')->where('config_key', 'risk_control_config')->value('config_value') : null;
        return self::normalize($legacy);
    }
    /** Callback must synchronously acknowledge the same scoped TradeKernel configuration. */
    public function save(array $values, int $homeSiteId, int $agentId, callable $publish): array
    {
        $this->assertScope($homeSiteId, $agentId);
        $entry = $values[self::ENTRY] ?? null;
        $high = $values[self::HIGH] ?? null;
        if (!is_numeric($entry) || !is_numeric($high) || !is_finite((float) $entry) || !is_finite((float) $high) || $entry <= 0 || $entry >= 100 || $high <= $entry || $high >= 100 || !is_bool($values[self::NOTIFY] ?? null)) {
            throw new InvalidArgumentException('Invalid liquidation warning thresholds.');
        }
        $entryRatio = rtrim(rtrim(bcdiv(self::decimal($entry), '100', 16), '0'), '.');
        $highRatio = rtrim(rtrim(bcdiv(self::decimal($high), '100', 16), '0'), '.');
        if (bccomp($entryRatio, '0', 16) <= 0 || bccomp($highRatio, $entryRatio, 16) <= 0) {
            throw new InvalidArgumentException('Liquidation warning thresholds are too precise.');
        }
        $stored = [self::ENTRY => (float) $entryRatio, self::HIGH => (float) $highRatio, self::NOTIFY => $values[self::NOTIFY]];
        return $this->connection->transaction(function () use ($stored, $entryRatio, $highRatio, $homeSiteId, $agentId, $publish) {
            $this->connection->table('source_config')->insertOrIgnore(['home_site_id' => $homeSiteId, 'agent_id' => $agentId, 'config_key' => self::CONFIG_KEY, 'config_value' => json_encode($stored, JSON_THROW_ON_ERROR), 'description' => '强平预警规则', 'updated_at' => date('Y-m-d H:i:s')]);
            $record = $this->query($homeSiteId, $agentId)->lockForUpdate()->first();
            $existing = self::decode($record->config_value);
            $this->query($homeSiteId, $agentId)->update(['config_value' => json_encode(array_merge($existing, $stored), JSON_THROW_ON_ERROR), 'updated_at' => date('Y-m-d H:i:s')]);
            $publish($entryRatio, $highRatio, $stored[self::NOTIFY], $homeSiteId, $agentId);
            return self::normalize($stored, true);
        });
    }
    public static function normalize($value, bool $storedRatios = false): array
    {
        $value = self::decode($value);
        // Older source_config rows stored 20/90 percentages instead of .2/.9.
        if ($storedRatios && is_numeric($value[self::HIGH] ?? null) && (float) $value[self::HIGH] < 1) {
            foreach ([self::ENTRY, self::HIGH] as $key) {
                if (is_numeric($value[$key] ?? null)) {
                    $value[$key] = (float) bcmul(self::decimal($value[$key]), '100', 16);
                }
            }
        }
        $positive = static fn($v, $default): float => is_numeric($v) && is_finite((float) $v) && (float) $v > 0 ? (float) $v : $default;
        return [self::ENTRY => $positive($value[self::ENTRY] ?? null, 80.0), self::HIGH => $positive($value[self::HIGH] ?? null, 90.0), self::NOTIFY => in_array($value[self::NOTIFY] ?? false, [true, 1, '1', 'true'], true)];
    }
    private static function decode($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }
    private static function decimal($value): string
    {
        $text = trim((string) $value);
        return stripos($text, 'e') === false ? $text : sprintf('%.32F', (float) $value);
    }
    private function query(int $site, int $agent)
    {
        return $this->connection->table('source_config')->where('home_site_id', $site)->where('agent_id', $agent)->where('config_key', self::CONFIG_KEY);
    }
    private function assertScope(int $site, int $agent): void
    {
        if ($site < 0 || $agent < 0) {
            throw new InvalidArgumentException('Invalid warning rule scope.');
        }
    }
}
