<?php
declare(strict_types=1);

namespace SixMm\Shared\CurrentOrders;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;

/** Merge TK's descending (created_at_ms, order_id) streams without losing unread rows. */
final class ClassifiedCurrentOrderPager
{
    public function __construct(private ConnectionInterface $db, private $fetch) {}

    public function search(array $params, TradeFillOwnerScope $owner, ?array $ids = null, ?int $userType = null): array
    {
        $token = $params['cursor'] ?? '';
        unset($params['cursor'], $params['ownership_type'], $params['home_site_id'], $params['agent_id']);
        $limit = max(1, min(100, (int)($params['page_size'] ?? 20)));
        $params['page_size'] = min(40, $limit);
        $scopes = $owner->rpcScopes();
        $signature = hash('sha256', serialize([$scopes, $params, $limit, $ids, $userType]));
        $state = array_fill(0, count($scopes), ['', 0]);
        if ($token !== '' && $token !== null) {
            $decoded = json_decode((string)base64_decode((string)$token, true), true);
            if (($decoded['key'] ?? null) !== $signature || !is_array($decoded['streams'] ?? null)
                || array_keys($decoded['streams']) !== array_keys($state)) throw new \InvalidArgumentException('Restart the current order query');
            $state = $decoded['streams'];
        }
        $streams = $rows = [];
        foreach ($scopes as $i => $scope) {
            if ($ids === [] || $state[$i] === null) continue;
            $pos = $state[$i];
            if (!is_array($pos) || !is_string($pos[0] ?? null) || !is_int($pos[1] ?? null)
                || $pos[1] < 0 || $pos[1] >= $params['page_size']) throw new \InvalidArgumentException('Invalid current order checkpoint');
            $streams[$i] = $this->stream($scope + $params, $ids, $userType, $owner, $pos);
        }
        while ($streams) {
            $winner = null;
            foreach ($streams as $i => $stream) {
                if (!$stream->valid()) { unset($streams[$i]); $state[$i] = null; continue; }
                [$row, $state[$i]] = $stream->current();
                if ($winner === null || self::key($row) > self::key($streams[$winner]->current()[0])) $winner = $i;
            }
            if ($winner === null) break;
            if (count($rows) === $limit) {
                return ['lists'=>$rows, 'has_more'=>true, 'next_cursor'=>base64_encode(json_encode(['key'=>$signature,'streams'=>$state], JSON_THROW_ON_ERROR))];
            }
            $rows[] = $streams[$winner]->current()[0];
            $streams[$winner]->next();
        }
        return ['lists'=>$rows, 'has_more'=>false, 'next_cursor'=>null];
    }

    private static function key(array $row): array
    {
        return [(int)$row['created_at_ms'], (int)$row['order_id']];
    }

    private function stream(array $request, ?array $ids, ?int $userType, TradeFillOwnerScope $owner, array $pos): \Generator
    {
        [$cursor, $offset] = $pos;
        $seen = [];
        do {
            if ($cursor !== '') $request['cursor'] = $cursor;
            $batch = ($this->fetch)($request, $ids);
            if (($batch['scope_applied'] ?? null) !== true || ($batch['applied_ownership_type'] ?? null) !== $request['ownership_type'])
                throw new \RuntimeException('Unconfirmed TK open order ownership');
            $rows = $batch['lists'];
            $allowed = $userType === null || !$rows ? null : array_flip($this->db->table('users')
                ->whereIn('user_id', array_column($rows, 'user_id'))->where('user_type', $userType)->pluck('user_id')->all());
            foreach ($rows as $i => $row) {
                if ($i >= $offset && $owner->matches($row['home_site_id'] ?? null, $row['agent_id'] ?? null)
                    && ($allowed === null || isset($allowed[$row['user_id']]))) yield [$row, [$cursor, $i]];
            }
            if (!$batch['has_more']) return;
            $seen[$cursor] = true;
            $cursor = $batch['next_cursor'] ?? null;
            if (!is_string($cursor) || $cursor === '' || isset($seen[$cursor])) throw new \RuntimeException('Invalid TK order cursor');
            $offset = 0;
        } while (true);
    }
}
