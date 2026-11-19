<?php
declare(strict_types=1);

namespace SixMm\Shared\CurrentOrders;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;

/** Filters unsupported global RPC categories without preloading the user directory or skipping rows. */
final class OwnerCurrentOrderPager
{
    public function __construct(private ConnectionInterface $db, private $fetch) {}

    public function search(array $params, TradeFillOwnerScope $owner, ?array $userIds = null, ?int $userType = null): array
    {
        $empty = ['lists' => [], 'has_more' => false, 'next_cursor' => null];
        if ($owner->isEmpty() || $userIds === []) return $empty;
        $cursor = $params['cursor'] ?? null;
        unset($params['cursor']);
        $size = max(1, min(100, (int) ($params['page_size'] ?? 20)));
        $params['page_size'] = $size;
        $pairs = $owner->owners();
        $native = count($pairs) === 1 && $pairs[0][0] > 0;
        if ($native) {
            $params['home_site_id'] = (string) $pairs[0][0];
            $params['agent_id'] = $pairs[0][1];
        }
        $fingerprint = hash('sha256', json_encode([$params, $owner->cacheKey(), $userIds, $userType], JSON_THROW_ON_ERROR));
        $rpcCursor = null; $offset = 0;
        if ($cursor !== null && $cursor !== '') {
            $payload = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
            if (!is_array($payload) || ($payload['owner_cursor'] ?? null) !== 1
                || ($payload['filters'] ?? null) !== $fingerprint || !is_int($payload['offset'] ?? null)
                || $payload['offset'] < 0 || $payload['offset'] > $size
                || (!is_null($payload['rpc'] ?? null) && !is_string($payload['rpc']))) {
                throw new \InvalidArgumentException('Invalid current-order cursor; restart the query');
            }
            $rpcCursor = $payload['rpc'] ?? null; $offset = $payload['offset'];
        }
        $result = [];
        $seen = [];
        do {
            $request = $params;
            if ($rpcCursor !== null) $request['cursor'] = $rpcCursor;
            $batch = ($this->fetch)($request, $userIds);
            if ($native && ($batch['scope_applied'] ?? false) !== true) {
                throw new \RuntimeException('Trading service did not apply the requested owner scope');
            }
            $rows = $batch['lists'] ?? [];
            $allowed = null;
            if ($userType !== null && $rows !== []) {
                $allowed = array_fill_keys($this->db->table('users')->whereIn('user_id', array_column($rows, 'user_id'))
                    ->where('user_type', $userType)->pluck('user_id')->map('strval')->all(), true);
            }
            foreach ($rows as $index => $row) {
                if ($index < $offset || !$owner->matches($row['home_site_id'] ?? null, $row['agent_id'] ?? null)) continue;
                if ($allowed !== null && !isset($allowed[(string) $row['user_id']])) continue;
                if (count($result) === $size) {
                    return ['lists' => $result, 'has_more' => true, 'next_cursor' => $this->cursor($fingerprint, $rpcCursor, $index)];
                }
                $result[] = $row;
            }
            if (!($batch['has_more'] ?? false)) break;
            $next = $batch['next_cursor'] ?? null;
            if (!is_string($next) || $next === '' || $next === $rpcCursor || isset($seen[$next])) {
                throw new \RuntimeException('Current-order RPC cursor cannot advance');
            }
            $seen[$next] = true;
            $rpcCursor = $next; $offset = 0;
        } while (true);
        return ['lists' => $result, 'has_more' => false, 'next_cursor' => null];
    }

    private function cursor(string $fingerprint, ?string $rpc, int $offset): string
    {
        return rtrim(strtr(base64_encode(json_encode(['owner_cursor' => 1, 'filters' => $fingerprint, 'rpc' => $rpc, 'offset' => $offset], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
