<?php

/**
 * [AI-2026-08] 圆钢档案四字段业务规则纯函数测试。
 */

declare(strict_types=1);

/**
 * @param string $message
 * @return never
 * @throws RuntimeException
 */
function exception(string $message = ''): never
{
    throw new RuntimeException($message);
}

require dirname(__DIR__, 2) . '/app/common/service/admin/MaterialService.php';

use app\common\service\admin\MaterialService;

$passed = 0;
$failed = [];

/**
 * @param string $name
 * @param callable $callback
 * @return void
 */
function runMaterialArchiveCase(string $name, callable $callback): void
{
    global $passed, $failed;

    try {
        $callback();
        $passed++;
    } catch (Throwable $exception) {
        $failed[] = $name . ': ' . $exception->getMessage();
    }
}

/**
 * @param bool $condition
 * @param string $message
 * @return void
 * @throws RuntimeException
 */
function expectMaterialArchive(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string, mixed> $param
 * @param array<string, mixed>|null $existing
 * @param string $materialProperty
 * @return array<string, string>
 */
function normalizeMaterialArchiveIdentity(
    array $param,
    ?array $existing,
    string $materialProperty
): array {
    $method = new ReflectionMethod(MaterialService::class, 'normalizeMaterialTriple');

    return $method->invoke(null, $param, $existing, $materialProperty);
}

/**
 * @param int $recordCount
 * @param array<string, mixed> $param
 * @return void
 */
function assertMaterialArchiveBatchEditAllowed(
    int $recordCount,
    array $param
): void {
    $method = new ReflectionMethod(MaterialService::class, 'assertBatchIdentityEditAllowed');
    $method->invoke(null, $recordCount, $param);
}

/**
 * @param array<string, mixed> $param
 * @return bool
 */
function hasMaterialArchiveIdentityField(array $param): bool
{
    $method = new ReflectionMethod(MaterialService::class, 'hasMaterialIdentityField');

    return $method->invoke(null, $param);
}

runMaterialArchiveCase('round_steel_uses_four_field_identity', static function (): void {
    $identity = normalizeMaterialArchiveIdentity([
        'material_name' => ' 45# ',
        'manufacturer' => ' 宝钢 ',
        'material_spec' => ' 40Cr ',
        'material_model' => ' Φ50 ',
    ], null, '圆钢');

    expectMaterialArchive($identity === [
        'name' => '45#',
        'manufacturer' => '宝钢',
        'spec' => '40Cr',
        'model' => 'Φ50',
    ], '圆钢查重标识必须包含名称、厂商、材质、型号');
});

runMaterialArchiveCase('round_steel_edit_backfills_omitted_fields', static function (): void {
    $identity = normalizeMaterialArchiveIdentity([
        'material_model' => 'Φ60',
    ], [
        'material_name' => '45#',
        'manufacturer' => '宝钢',
        'material_spec' => '40Cr',
        'material_model' => 'Φ50',
    ], '圆钢');

    expectMaterialArchive($identity === [
        'name' => '45#',
        'manufacturer' => '宝钢',
        'spec' => '40Cr',
        'model' => 'Φ60',
    ], '编辑圆钢时未传字段必须从原档案补全');
});

runMaterialArchiveCase('round_steel_rejects_each_empty_required_field', static function (): void {
    $fields = [
        'material_name' => '圆钢名称',
        'manufacturer' => '生产厂商',
        'material_spec' => '材质',
        'material_model' => '型号',
    ];

    foreach ($fields as $field => $label) {
        $param = [
            'material_name' => '45#',
            'manufacturer' => '宝钢',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ];
        $param[$field] = '   ';

        try {
            normalizeMaterialArchiveIdentity($param, null, '圆钢');
        } catch (Throwable $exception) {
            expectMaterialArchive(
                str_contains($exception->getMessage(), $label),
                "缺少{$label}时错误提示不明确"
            );
            continue;
        }

        throw new RuntimeException("圆钢{$label}为空时未被拒绝");
    }
});

runMaterialArchiveCase('non_round_steel_keeps_original_three_field_identity', static function (): void {
    foreach (['成品', '配件'] as $materialProperty) {
        $identity = normalizeMaterialArchiveIdentity([
            'material_name' => '齿轮',
            'manufacturer' => '甲厂',
            'material_spec' => '20CrMnTi',
            'material_model' => 'M20',
        ], null, $materialProperty);

        expectMaterialArchive($identity === [
            'name' => '齿轮',
            'spec' => '20CrMnTi',
            'model' => 'M20',
        ], "{$materialProperty}不应改为四字段查重");
    }
});

runMaterialArchiveCase('all_material_types_reject_batch_identity_edit', static function (): void {
    $identityFields = [
        'material_name' => '齿轮',
        'manufacturer' => '宝钢',
        'material_spec' => '20CrMnTi',
        'material_model' => 'M20',
        'material_property' => '成品',
    ];

    foreach ($identityFields as $field => $value) {
        try {
            assertMaterialArchiveBatchEditAllowed(2, [$field => $value]);
        } catch (Throwable $exception) {
            expectMaterialArchive(
                str_contains($exception->getMessage(), '逐条修改'),
                "批量编辑{$field}时错误提示不明确"
            );
            continue;
        }

        throw new RuntimeException("批量编辑{$field}未被拒绝");
    }
});

runMaterialArchiveCase('batch_non_identity_edit_remains_allowed', static function (): void {
    $param = [
        'min_stock_qty' => 10,
    ];
    assertMaterialArchiveBatchEditAllowed(2, $param);
    expectMaterialArchive(
        hasMaterialArchiveIdentityField($param) === false,
        '非身份字段更新不应触发身份补全、非空校验或查重'
    );
});

runMaterialArchiveCase('single_identity_edit_remains_allowed', static function (): void {
    $param = [
        'material_model' => 'M20',
    ];
    assertMaterialArchiveBatchEditAllowed(1, $param);
    expectMaterialArchive(
        hasMaterialArchiveIdentityField($param) === true,
        '单条身份字段更新仍应触发补全、校验和查重'
    );
});

if ($failed !== []) {
    fwrite(STDERR, "FAIL " . count($failed) . " cases\n- " . implode("\n- ", $failed) . PHP_EOL);
    exit(1);
}

echo "PASS {$passed} cases\n";
