<?php

/**
 * [AI-2026-08] 圆钢档案迁移纯函数回归测试。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/scripts/lib/RoundSteelArchiveMigration.php';

$passed = 0;

/**
 * @param bool $condition
 * @param string $message
 * @return void
 * @throws RuntimeException
 */
function assertRoundSteel(bool $condition, string $message): void
{
    global $passed;

    if (!$condition) {
        throw new RuntimeException($message);
    }

    $passed++;
}

/**
 * @param callable $callback
 * @param string $expectedMessage
 * @return void
 * @throws RuntimeException
 */
function assertRoundSteelThrows(callable $callback, string $expectedMessage): void
{
    global $passed;

    try {
        $callback();
    } catch (Throwable $exception) {
        if (!str_contains($exception->getMessage(), $expectedMessage)) {
            throw new RuntimeException(
                '异常内容不符合预期: ' . $exception->getMessage()
            );
        }

        $passed++;
        return;
    }

    throw new RuntimeException('预期抛出异常，但实际未抛出');
}

$rows = [
    [
        'id' => 11,
        'material_name' => '45#/40Cr',
        'manufacturer' => '',
        'material_spec' => 'Φ50',
        'material_model' => 'A',
    ],
    [
        'id' => 12,
        'material_name' => '45#',
        'manufacturer' => '宝钢',
        'material_spec' => 'Φ50',
        'material_model' => 'A',
    ],
];

$mapping = [
    11 => [
        'material_id' => 11,
        'expected' => [
            'material_name' => '45#/40Cr',
            'manufacturer' => '',
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ],
        'target' => [
            'material_name' => '40Cr',
            'manufacturer' => '宝钢',
            'material_spec' => 'Φ50',
            'material_model' => 'B',
        ],
    ],
];

$analysis = roundSteelAnalyzeRows($rows, $mapping);
assertRoundSteel($analysis['total'] === 2, '总数统计错误');
assertRoundSteel($analysis['empty_manufacturer_ids'] === [], '映射后仍错误报告厂商空值');
assertRoundSteel($analysis['separator_name_ids'] === [11], '名称分隔符审计错误');
assertRoundSteel($analysis['mixed_material_candidate_ids'] === [11], '混合材质候选审计错误');
assertRoundSteel($analysis['unresolved_ids'] === [], '已映射异常记录不应 unresolved');
assertRoundSteel($analysis['affected_material_ids'] === [11], '受影响 ID 统计错误');

$conflictMapping = $mapping;
$conflictMapping[11]['target'] = [
    'material_name' => '45#',
    'manufacturer' => '宝钢',
    'material_spec' => 'Φ50',
    'material_model' => 'A',
];
$conflictAnalysis = roundSteelAnalyzeRows($rows, $conflictMapping);
assertRoundSteel($conflictAnalysis['conflict_ids'] === [11, 12], '四字段冲突识别错误');

assertRoundSteelThrows(
    static fn () => roundSteelAssertApplyAllowed($conflictAnalysis),
    'conflict'
);
assertRoundSteelThrows(
    static fn () => roundSteelAssertApplyAllowed([
        'unresolved_ids' => [11],
        'empty_manufacturer_ids' => [],
        'conflict_ids' => [],
    ]),
    'unresolved'
);
assertRoundSteelThrows(
    static fn () => roundSteelAssertApplyAllowed([
        'unresolved_ids' => [],
        'empty_manufacturer_ids' => [11],
        'conflict_ids' => [],
    ]),
    'empty manufacturer'
);

$badMapping = $mapping;
$badMapping[11]['expected']['material_name'] = '错误旧值';
assertRoundSteelThrows(
    static fn () => roundSteelAnalyzeRows($rows, $badMapping),
    '旧值不匹配'
);

$inventoryRows = [
    [
        'inventory_id' => 91,
        'material_id' => 999,
        'material_name' => '45#',
        'y_material_spec' => 'Φ50',
        'y_material_model' => 'A',
    ],
];
$candidates = roundSteelBuildInventoryCandidates($inventoryRows, $rows);
assertRoundSteel($candidates[0]['candidate_material_ids'] === [12], '孤儿库存候选识别错误');

$backup = roundSteelBuildBackupPayload(
    [$rows[0]],
    [$mapping[11]['target'] + ['id' => 11]],
    'mapping-sha256'
);
assertRoundSteel(roundSteelValidateBackupPayload($backup) === true, '备份校验失败');

$tamperedBackup = $backup;
$tamperedBackup['records'][0]['before']['material_name'] = '被篡改';
assertRoundSteelThrows(
    static fn () => roundSteelValidateBackupPayload($tamperedBackup),
    '校验和'
);

$mappingPath = sys_get_temp_dir() . '/round-steel-map-' . bin2hex(random_bytes(6)) . '.json';
$mappingPayload = [
    'format' => 'round-steel-archive-map/v1',
    'records' => [
        [
            'material_id' => 11,
            'expected' => [
                'material_name' => ' 45#/40Cr ',
                'manufacturer' => '',
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
            'target' => $mapping[11]['target'],
        ],
    ],
];
file_put_contents(
    $mappingPath,
    json_encode($mappingPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
);
$loadedMapping = roundSteelLoadMappingFile($mappingPath);
unlink($mappingPath);
assertRoundSteel(
    $loadedMapping[11]['expected']['material_name'] === ' 45#/40Cr ',
    '映射 expected 必须保留原始值'
);

echo "PASS {$passed} assertions\n";
