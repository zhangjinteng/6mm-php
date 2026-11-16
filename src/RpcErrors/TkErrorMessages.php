<?php

declare(strict_types=1);

namespace SixMm\Shared\RpcErrors;

/** TK reject_code_reference.md, 2026-11-16. Transport status is a separate namespace. */
final class TkErrorMessages
{
    public const BUSINESS = [
        0 => '当前请求阶段无业务拒绝',
        1000 => '内部／兜底错误',
        1001 => '服务不可用',
        1002 => '服务繁忙',
        2001 => '命令 ID 缺失或非法',
        2002 => '用户 ID 缺失或非法',
        2003 => '缺少 symbol',
        2004 => '缺少 order_id',
        2005 => '缺少 transaction_id',
        2007 => '字段超长',
        2008 => '命令类型非法',
        2009 => '命令重复或请求身份冲突',
        3001 => '价格非法',
        3002 => '数量非法',
        3003 => '方向非法',
        3004 => '订单类型非法',
        3005 => 'time_in_force 非法',
        3006 => 'maker_only 与市价单冲突',
        3007 => '数量低于最小下单量',
        3008 => '数量不满足步长',
        3009 => '名义价值低于最小值',
        3010 => '限价数量超过上限',
        3011 => '市价数量超过上限',
        3012 => '滑点不在允许区间',
        3013 => '对手深度不足',
        3014 => '订单不存在或已终态',
        3015 => 'client_order_id 冲突',
        3016 => '挂单数超过上限',
        3017 => '不支持改单',
        3018 => '改单会立即成交',
        3019 => '标记价缺失或过期',
        3020 => '价格超过标记价保护范围',
        3021 => 'price_type 非法',
        3022 => 'place_type 非法',
        3023 => '改单 payload 非法',
        3024 => '滑点格式非法',
        3025 => '下单 payload 非法',
        4001 => '账户不存在',
        4002 => '可用余额或转换权益不足',
        4003 => '金额非法',
        4004 => '账户变动类型非法',
        4005 => '用户身份资料不存在',
        4006 => 'user_type 非法',
        4007 => 'agent_id 非法',
        4008 => '身份版本非法',
        4009 => 'vip_level 非法',
        4010 => '划转身份非法或不存在',
        4012 => '资产不支持',
        5001 => '杠杆非法',
        5002 => '杠杆超出档位范围',
        5003 => '保证金模式非法／不适用',
        5004 => '持仓阻挡当前操作',
        5005 => '挂单或资金占用阻挡操作',
        5006 => '只减仓位不足',
        5007 => '仓位处于强平接管',
        5008 => '指定持仓不存在',
        5009 => '逐仓保证金调整方向非法',
        5010 => '权益低于标记价初始保证金',
        5011 => '触及逐仓风险线',
        5012 => '存在 OPEN 全仓欠损',
        6001 => '交易对不可交易',
        6002 => '配置、范围或状态迁移非法',
        6003 => '版本过旧或期望版本不匹配',
        6004 => '配置版本非法',
        6005 => '杠杆保证金档位不可用',
        6006 => '手续费等级不可用',
        6007 => '交易对配置非法',
        6008 => '杠杆保证金配置非法',
        6009 => '手续费等级配置非法',
        6010 => '手续费率非法',
        7001 => '缺少资金费轮次 ID',
        7002 => '资金费率非法',
        7003 => '结算时间非法',
        7004 => '结算价格或价时依据无效',
    ];

    public static function business(int $code, string $method = '', array $request = []): string
    {
        $message = self::BUSINESS[$code] ?? '未知业务错误，请联系管理员核对';
        if ($code === -1) $message = '该项系统错误，执行结果待确认，请按原命令核对';
        if ($code === 7004 && in_array($method, ['DelistSymbolTrading', 'DelistAndSettleSymbol'], true)) {
            $automatic = ($request['mark_price'] ?? '') === '' && (string) ($request['mark_price_time'] ?? '0') === '0';
            $message = $automatic
                ? '当前交易对暂无有效标记价，暂不能下架结算，请检查行情后重试'
                : '下架价格或价格时间无效，请检查';
        }
        return "TK：RPC错误码：{$code}，{$message}";
    }

    public static function status(string|int $status): string
    {
        if (is_int($status)) return [0=>'OK', 1=>'Cancelled', 2=>'Unknown', 3=>'InvalidArgument',
            4=>'DeadlineExceeded', 5=>'NotFound', 6=>'AlreadyExists', 7=>'PermissionDenied', 8=>'ResourceExhausted',
            9=>'FailedPrecondition', 10=>'Aborted', 11=>'OutOfRange', 12=>'Unimplemented', 13=>'Internal',
            14=>'Unavailable', 15=>'DataLoss', 16=>'Unauthenticated'][$status] ?? (string) $status;
        return $status === 'Canceled' ? 'Cancelled' : ($status === '' ? 'Unknown' : $status);
    }

    public static function transport(string|int $status): string
    {
        $status = self::status($status);
        $message = match ($status) {
            'InvalidArgument', 'OutOfRange' => '请求参数非法，请检查',
            'Unauthenticated' => '服务身份缺失或认证失败，请核对调用身份',
            'PermissionDenied' => '无权调用该接口或操作该范围，请核对权限',
            'NotFound' => '查询的配置或资源不存在，请按接口语义核对',
            'AlreadyExists' => '请求身份冲突或记录已存在，请核对原请求及结果',
            'FailedPrecondition' => '当前状态或接口前置条件不满足，请核对',
            'Aborted' => '操作执行中或发生并发冲突，请按原命令确认结果',
            'Unimplemented' => '目标服务不支持该接口，请核对服务版本与路由',
            'ResourceExhausted' => '服务容量不足或请求过多，写操作请先确认原结果',
            'Unavailable', 'NetworkError' => '服务暂不可用或连接异常，写操作结果需按原命令确认',
            'DeadlineExceeded', 'Cancelled', 'Unknown' => '调用超时、取消或结果不明确，请按原命令确认，勿重复提交',
            'Internal', 'DataLoss' => '服务内部或持久化数据异常，请保留原命令并联系管理员核对',
            default => '传输状态异常，写操作请先确认原结果',
        };
        return "TK：gRPC状态码：{$status}，{$message}";
    }
}
