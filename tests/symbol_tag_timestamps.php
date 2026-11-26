<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/scoped_symbol_configs.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\SymbolTags\SymbolTagService;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
scopedSymbolFixture($db);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$originalTimezone = date_default_timezone_get();
try {
    foreach (['Asia/Shanghai', 'UTC', 'America/New_York'] as $timezone) {
        date_default_timezone_set($timezone);
        foreach ([[0, 0], [10, 0], [10, 20]] as [$site, $agent]) {
            $service = new SymbolTagService($db, ownerId: $agent, scope: ['home_site_id' => $site]);
            $before = time();
            $code = 'time_'.str_replace('/', '_', strtolower($timezone));
            $parent = $service->create(['tag_name' => 'Parent', 'tag_code' => $code,
                'parent_id' => 0, 'sort' => 1, 'is_enable' => 1]);
            $child = $service->create(['tag_name' => 'Child', 'tag_code' => $code.'_child',
                'parent_id' => $parent['id'], 'sort' => 1, 'is_enable' => 1]);
            foreach ([0, 1] as $enabled) {
                $updated = $service->update($parent['id'], array_merge($parent, ['is_enable' => $enabled]));
                $stored = $db->table('symbol_tag')->where('id', $parent['id'])->value('updated_at');
                $check((bool) preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $stored), 'Stored update must include timezone');
                // Interpret as a UTC database session would. Explicit offsets must preserve the instant.
                $instant = new DateTimeImmutable($stored, new DateTimeZone('UTC'));
                $check($instant->getTimestamp() >= $before && $instant->getTimestamp() <= time(), 'Database must store the actual operation instant');
                $check($updated['updated_at'] === $stored, 'API must preserve the stored timezone');
                $check($updated['is_enable'] === $enabled, 'Enable and disable behavior must remain intact');
                if ($enabled === 0) {
                    $updatedChild = $service->detail($child['id']);
                    $check($updatedChild['updated_at'] === $stored && $updatedChild['is_enable'] === 0, 'Cascaded disable uses the same timestamp');
                }
            }
            $check((bool) preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $parent['created_at']), 'Creation must also preserve timezone');
            $service->delete($child['id']);
            $deleted = $db->table('symbol_tag')->where('id', $child['id'])->first();
            $check($deleted->updated_at === $deleted->deleted_at, 'Deletion timestamps must agree');
            $check((bool) preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $deleted->deleted_at), 'Deletion must preserve timezone');
        }
    }
} finally {
    date_default_timezone_set($originalTimezone);
}
echo "Symbol tag timestamps: {$checks} checks passed\n";
