<?php

/**
 * 参考示例：能耗报工在设备为「酸洗车间」时，增加「磷化 / 皂化 / 碱」吨数字段，统一保留 3 位小数。
 *
 * - 本文件仅供对照，未接入路由、未改 ProductReportService::reportpower。
 * - 落库前请在表 ya_product_power（或你们的 product_power 表）增加字段，或改用 JSON 扩展存储；
 *   下方示例使用 snake_case 列名：phosphating_ton、saponification_ton、alkali_ton。
 *
 * 前端 list 单项可传（二选一，示例函数会归一）：
 *   A) 中文键：磷化、皂化、碱
 *   B) 英文键：phosphating_ton、saponification_ton、alkali_ton
 *
 * 请求示例（节选）：
 * {
 *   "list": [
 *     {
 *       "device_code": "XXX",
 *       "power_num": "100",
 *       "gas_num": "50",
 *       "direction": "南",
 *       "磷化": "1.23456",
 *       "皂化": "2",
 *       "碱": "0.5"
 *     }
 *   ]
 * }
 */

declare(strict_types=1);

use app\common\model\admin\DeviceModel;

/**
 * 判断设备是否属于「酸洗车间」（按设备名称匹配，可按库内实际名称增删）。
 */
function prp_pickling_device_names(): array
{
    return ['酸洗车间'];
}

/**
 * @param array<string, mixed>|null $deviceRow DeviceModel::find() 转数组
 */
function prp_is_pickling_workshop(?array $deviceRow): bool
{
    if ($deviceRow === null || !isset($deviceRow['device_name'])) {
        return false;
    }
    $name = (string)$deviceRow['device_name'];
    foreach (prp_pickling_device_names() as $n) {
        if ($name === $n || mb_strpos($name, $n) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 吨数保留 3 位小数（存储用 float；展示可再 number_format）。
 */
function prp_round_ton_three($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    return round((float)$value, 3);
}

/**
 * 从单行参数取出磷化/皂化/碱，归一为带标准键的数组（未传则不含该键）。
 *
 * @return array{phosphating_ton?:float,saponification_ton?:float,alkali_ton?:float}
 */
function prp_normalize_pickling_tons_from_item(array $item): array
{
    $map = [
        'phosphating_ton' => ['磷化', 'phosphating_ton'],
        'saponification_ton' => ['皂化', 'saponification_ton'],
        'alkali_ton' => ['碱', 'alkali_ton'],
    ];
    $out = [];
    foreach ($map as $canonical => $keys) {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $item)) {
                continue;
            }
            $out[$canonical] = prp_round_ton_three($item[$k]);
            break;
        }
    }
    return $out;
}

/**
 * 酸洗车间：要求三项吨数均已填写且 >= 0（可按业务改为「允许缺省」）。
 *
 * @throws \InvalidArgumentException
 */
function prp_assert_pickling_tons_when_required(array $tons): void
{
    $required = ['phosphating_ton', 'saponification_ton', 'alkali_ton'];
    foreach ($required as $k) {
        if (!array_key_exists($k, $tons)) {
            throw new \InvalidArgumentException('酸洗车间需填写磷化、皂化、碱吨数');
        }
        if ($tons[$k] < 0) {
            throw new \InvalidArgumentException($k . ' 不能为负数');
        }
    }
}

/**
 * 根据 device_code 查设备，若为酸洗车间则把三个吨数字段 merge 进待保存行（仍保留原有电/气逻辑由你方实现）。
 *
 * @param array<string, mixed> $item list 中一行（含 device_code）
 * @return array<string, mixed> 供 saveAll 的一行
 */
function prp_enrich_power_item_with_pickling_tons(array $item): array
{
    $code = isset($item['device_code']) ? (string)$item['device_code'] : '';
    if ($code === '') {
        return $item;
    }

    $deviceRow = (new DeviceModel())->where('device_code', $code)->find();
    $deviceArr = $deviceRow ? $deviceRow->toArray() : null;

    if (!prp_is_pickling_workshop($deviceArr)) {
        return $item;
    }

    $tons = prp_normalize_pickling_tons_from_item($item);
    prp_assert_pickling_tons_when_required($tons);

    return array_merge($item, $tons);
}

/**
 * 批量处理 list（示例：在计算 power_consume / gas_consume 之前或之后均可，只要入库行含三字段即可）。
 *
 * @param list<array<string, mixed>> $list
 * @return list<array<string, mixed>>
 */
function prp_map_list_pickling_tons(array $list): array
{
    return array_map(
        static function (array $row): array {
            return prp_enrich_power_item_with_pickling_tons($row);
        },
        $list
    );
}

/*
 * ========== 与现有 reportpower 拼接思路（仅说明） ==========
 *
 * 1) 在 foreach 内对 $v 先做 prp_enrich_power_item_with_pickling_tons($v)，再算差分消耗；
 * 2) 或 foreach 前：$param['list'] = prp_map_list_pickling_tons($param['list']);
 * 3) 数据库需能存 phosphating_ton / saponification_ton / alkali_ton，或在模型里用 JSON 字段打包；
 * 4) 若仅酸洗车间展示这三个字段，列表查询侧可按 device 关联过滤展示，不必全表强校验。
 */
