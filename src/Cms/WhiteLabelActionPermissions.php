<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\Connection;
use RuntimeException;

/** Defines CMS capabilities, never assigns them to an ordinary tenant role. */
final class WhiteLabelActionPermissions
{
    public static function definitions(): array
    {
        $definitions = [];
        foreach (['news', 'academy', 'help', 'announcement'] as $module) {
            foreach (['_category', ''] as $suffix) {
                $base = 'cms_' . $module . $suffix;
                $actions = ['add' => 'cmsAdd', 'edit' => 'cmsEdit', 'delete' => 'cmsDelete'];
                if ($suffix === '') $actions['publish'] = 'cmsPublish';
                foreach ($actions as $verb => $name) {
                    $definitions[] = ['parent_perms' => $base . ':lists', 'perms' => $base . ':' . $verb,
                        'name' => $name, 'sort' => (array_search($verb, array_keys($actions), true) + 1) * 10];
                }
            }
        }
        return $definitions;
    }

    public function sync(Connection $db, bool $apply = false): array
    {
        return $db->transaction(function () use ($db, $apply): array {
            if ($apply && $db->getDriverName() === 'pgsql') {
                $db->statement("SET LOCAL lock_timeout = '5s'");
                // IDs are shared with non-CMS menu writers. Readers remain unblocked.
                $db->statement('LOCK TABLE control_plane.wl_menu IN SHARE ROW EXCLUSIVE MODE');
            }
            $definitions = self::definitions();
            $parents = [];
            foreach (array_unique(array_column($definitions, 'parent_perms')) as $permission) {
                $rows = $db->table('control_plane.wl_menu')->where('perms', $permission)
                    ->where('type', 'C')->where('is_disable', 0)->whereNull('deleted_at')->get();
                if ($rows->count() !== 1) throw new RuntimeException('Missing or ambiguous CMS page permission: ' . $permission);
                $parents[$permission] = $rows->first()->id;
            }
            $result = ['created' => 0, 'missing' => [], 'existing' => [], 'blocked' => []];
            $nextId = (int) $db->table('control_plane.wl_menu')->max('id');
            foreach ($definitions as $definition) {
                $rows = $db->table('control_plane.wl_menu')->where('perms', $definition['perms'])->get();
                if ($rows->isNotEmpty()) {
                    $valid = $rows->contains(static fn ($row) => $row->type === 'A' && !$row->is_disable
                        && $row->deleted_at === null && (string) $row->pid === (string) $parents[$definition['parent_perms']]);
                    $result[$valid ? 'existing' : 'blocked'][] = $definition['perms'];
                    continue;
                }
                $result['missing'][] = $definition['perms'];
                if (!$apply) continue;
                $db->table('control_plane.wl_menu')->insert([
                    'id' => ++$nextId, 'pid' => $parents[$definition['parent_perms']], 'type' => 'A',
                    'name' => $definition['name'], 'perms' => $definition['perms'],
                    'sort' => $definition['sort'], 'is_show' => 0, 'is_disable' => 0,
                ]);
                $result['created']++;
            }
            if ($apply && $result['created'] && $db->getDriverName() === 'pgsql') {
                $db->select("SELECT setval('control_plane.wl_menu_id_seq'::regclass,
                    GREATEST(?, (SELECT last_value FROM control_plane.wl_menu_id_seq)), true)", [$nextId]);
            }
            return $result;
        });
    }
}
