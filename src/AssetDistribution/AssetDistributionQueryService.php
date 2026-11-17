<?php

declare(strict_types=1);

namespace SixMm\Shared\AssetDistribution;

use SixMm\Shared\AssetReports\AssetReportQueryService;
use Illuminate\Database\ConnectionInterface;

final class AssetDistributionQueryService
{
    public const SORTABLE = [
        'source_name', 'source_type', 'account_type', 'currency', 'funded_user_count',
        'total_balance', 'available_balance', 'restricted_balance', 'total_valuation', 'asset_share', 'updated_at',
    ];

    public static function rules(bool $sourceFilters = true): array
    {
        $rules = [
            'account_type' => 'nullable|in:funding,contract',
            'order_by' => 'sometimes|required|in:' . implode(',', self::SORTABLE),
            'order_dir' => 'sometimes|required|in:asc,desc',
            'page_no' => 'sometimes|required|integer|min:1|max:2147483647',
            'page_size' => 'sometimes|required|integer|between:1,100',
        ];
        return $sourceFilters ? $rules + [
            'source_type' => 'sometimes|required|in:platform,tenant,merchant',
            'source_keyword' => 'nullable|string|max:128',
        ] : $rules;
    }

    /** Only authenticated tenant IDs may be passed here, never HTTP query fields. */
    public function searchTenant(array $params, string $tenantId): array
    {
        if (!\SixMm\Shared\FuturesReports\ReportUserScope::positiveId($tenantId)) {
            throw new \InvalidArgumentException('资产分布需要有效的白标身份');
        }
        unset($params['source_keyword']);
        $params['source_type'] = 'tenant';
        $params['source_id'] = $tenantId;
        return $this->search($params);
    }

    private const BALANCES = [
        'total_balance' => 'closing_assets',
        'available_balance' => 'closing_available',
        'restricted_balance' => 'closing_occupied',
    ];

    public function __construct(private ConnectionInterface $database) {}

    public function search(array $params): array
    {
        if (!in_array($params['order_by'] ?? 'source_name', self::SORTABLE, true)
            || !in_array($params['order_dir'] ?? 'asc', ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('不支持的资产分布排序');
        }
        $data = (new AssetReportQueryService($this->database))->currentBalances($params);
        $date = $data['stat_date'];
        $account = (string) ($params['account_type'] ?? '');
        $accounts = ['funding', 'contract'];
        $sources = [];
        $facts = [];
        foreach ($data['rows'] as $fact) {
            $key = $fact['source_type'] . ':' . $fact['source_id'];
            $sources[$key] = $fact;
            $facts[$key][$fact['account_type']] = $fact;
        }
        $rows = [];
        // Each source is published independently from users. Never add merchant and
        // tenant amounts into the platform, or infer a tenant from a merchant's site.
        foreach ($sources as $key => $source) {
            $sourceRows = [];
            foreach ($accounts as $accountType) {
                $sourceRows[$accountType] = $this->row($source, $facts[$key][$accountType] ?? null, $accountType, $date);
            }
            $funding = $sourceRows['funding']['total_valuation'];
            $contract = $sourceRows['contract']['total_valuation'];
            $total = $funding === null || $contract === null ? null : bcadd($funding, $contract, 16);
            // Account filters and pagination must not shrink the source's allocation denominator.
            foreach ($sourceRows as $accountType => $row) {
                $row['asset_share'] = null;
                if ($total !== null) {
                    if (bccomp($total, '0', 16) !== 0) {
                        $row['asset_share'] = bcdiv(bcmul($row['total_valuation'], '100', 16), $total, 16);
                    } elseif (bccomp($row['total_valuation'], '0', 16) === 0) {
                        $row['asset_share'] = '0.0000000000000000';
                    }
                }
                if ($account === '' || $accountType === $account) $rows[] = $row;
            }
        }

        $sort = (string) ($params['order_by'] ?? 'source_name');
        $descending = ($params['order_dir'] ?? 'asc') === 'desc';
        $numeric = in_array($sort, ['funded_user_count', 'total_valuation', 'asset_share'], true)
            || array_key_exists($sort, self::BALANCES);
        usort($rows, static function (array $left, array $right) use ($sort, $descending, $numeric): int {
            $a = $left[$sort];
            $b = $right[$sort];
            if ($a === null || $b === null) {
                return $a === $b ? strcmp($left['id'], $right['id']) : ($a === null ? 1 : -1);
            }
            $comparison = $numeric
                ? bccomp((string) $a, (string) $b, 16) : strcmp((string) $a, (string) $b);
            return ($descending ? -$comparison : $comparison) ?: strcmp($left['id'], $right['id']);
        });
        $page = max(1, min(2147483647, (int) ($params['page_no'] ?? 1)));
        $size = max(1, min(100, (int) ($params['page_size'] ?? 20)));
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size),
            'count' => count($rows), 'page' => $page, 'limit' => $size, 'stat_date' => $date];
    }

    private function row(array $source, ?array $fact, string $account, string $date): array
    {
        $complete = $fact !== null && $fact['stat_status'] !== 'incomplete'
            && $fact['funded_user_count'] !== null && $fact['updated_at'] !== null;
        foreach (self::BALANCES as $column) {
            $complete = $complete && ($fact[$column] ?? null) !== null;
        }
        $row = [
            'id' => implode(':', [$date, $source['source_type'], $source['source_id'], $account, 'USDT']),
            'stat_date' => $date, 'source_type' => $source['source_type'],
            'source_id' => (string) $source['source_id'], 'source_name' => $source['source_name'],
            'account_type' => $account, 'currency' => 'USDT',
            'funded_user_count' => $complete ? $fact['funded_user_count'] : null,
            'current_price' => '1', 'updated_at' => $fact['updated_at'] ?? null,
            'snapshot_status' => $complete ? 'complete' : 'incomplete',
        ];
        foreach (self::BALANCES as $field => $column) {
            $row[$field] = $complete ? $fact[$column] : null;
        }
        $row['total_valuation'] = $row['total_balance'] === null ? null
            : bcmul($row['total_balance'], $row['current_price'], 16);
        return $row;
    }
}
