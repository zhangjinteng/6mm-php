<?php

declare(strict_types=1);

namespace SixMm\Shared\MarketData;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

final class MarketCatalog
{
    /** The host must supply an authorized query; this service never initializes tenant rows. */
    public function preview(Builder|EloquentBuilder $catalog, array $params, array $funding, int $seconds): array
    {
        $page = max(1, min(2147483647, (int) ($params['page_no'] ?? 1)));
        $size = max(1, min(40, (int) ($params['page_size'] ?? 20)));
        $query = clone $catalog;
        $query->select('symbol', 'id');
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') $query->whereRaw('LOWER(symbol) LIKE ?', ['%' . strtolower($keyword) . '%']);
        $symbol = (string) ($params['sym'] ?? 'All');
        if ($symbol !== '' && $symbol !== 'All') $query->where('symbol', $symbol);
        $query->orderByRaw('sort ASC NULLS LAST')->orderByDesc('id');
        $decorate = static function ($row) use ($funding, $seconds): array {
            $hours = (int) ($funding[$row->symbol]['funding_interval_hours'] ?? 8);
            $hours = $hours > 0 && $hours <= 168 ? $hours : 8;
            $remain = $hours * 3600 - ($seconds % ($hours * 3600));
            $display = $remain % 86400;
            return ['id' => $row->id, 'symbol' => $row->symbol, 'remain_seconds' => $remain,
                'remain' => sprintf('%d:%d:%d/%d(小时)', intdiv($display, 3600), intdiv($display % 3600, 60), $display % 60, $hours)];
        };
        $order = $params['remain_order'] ?? '';
        if (in_array($order, ['asc', 'desc'], true)) {
            $rows = $query->get()->map($decorate)->sortBy('remain_seconds', SORT_REGULAR, $order === 'desc')->values();
            $count = $rows->count();
            $lists = $rows->slice(($page - 1) * $size, $size)->values()->all();
        } else {
            $count = (clone $query)->count();
            $lists = $query->forPage($page, $size)->get()->map($decorate)->all();
        }
        return ['lists' => $lists, 'count' => $count, 'page_no' => $page, 'page_size' => $size, 'extend' => []];
    }
}
