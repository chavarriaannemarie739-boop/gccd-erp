<?php

/**
 * [AI-2026-08] 圆钢档案导航树聚合纯函数测试。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/common/service/admin/MaterialService.php';

use app\common\service\admin\MaterialService;

$passed = 0;
$failed = [];

/**
 * @param string $name
 * @param callable $callback
 * @return void
 */
function runRoundSteelTreeCase(string $name, callable $callback): void
{
    global $passed, $failed;
    try {
        $callback();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failed[] = $name . ': ' . $e->getMessage();
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function expectTree(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

runRoundSteelTreeCase('empty rows yield empty tree', static function (): void {
    $tree = MaterialService::buildRoundSteelTree([]);
    expectTree($tree === [], 'expected empty tree');
});

runRoundSteelTreeCase('groups name > manufacturer > spec > model', static function (): void {
    $tree = MaterialService::buildRoundSteelTree([
        [
            'material_name' => '溯源',
            'manufacturer' => '巨能',
            'material_spec' => '轴承钢',
            'material_model' => '50',
        ],
        [
            'material_name' => '溯源',
            'manufacturer' => '巨能',
            'material_spec' => '轴承钢',
            'material_model' => '55',
        ],
        [
            'material_name' => '凯亚',
            'manufacturer' => '',
            'material_spec' => '20#',
            'material_model' => '60',
        ],
    ]);

    expectTree(count($tree) === 2, 'expected 2 top-level names');
    expectTree($tree[0]['label'] === '凯亚' || $tree[1]['label'] === '凯亚', 'missing 凯亚');
    expectTree($tree[0]['tag'] === '大类', 'top tag should be 大类');

    $suyuan = null;
    foreach ($tree as $node) {
        if ($node['value'] === '溯源') {
            $suyuan = $node;
            break;
        }
    }
    expectTree($suyuan !== null, 'missing 溯源 node');
    expectTree(($suyuan['count'] ?? 0) === 2, '溯源 count should be 2');
    expectTree(count($suyuan['children']) === 1, '溯源 should have 1 manufacturer');
    expectTree($suyuan['children'][0]['label'] === '巨能', 'manufacturer label');
    expectTree($suyuan['children'][0]['tag'] === '厂商', 'manufacturer tag');
    expectTree($suyuan['children'][0]['children'][0]['label'] === '轴承钢', 'spec label');
    expectTree($suyuan['children'][0]['children'][0]['tag'] === '材质', 'spec tag');
    expectTree(count($suyuan['children'][0]['children'][0]['children']) === 2, 'two models');
    expectTree($suyuan['children'][0]['children'][0]['children'][0]['tag'] === '型号', 'model tag');
});

runRoundSteelTreeCase('empty manufacturer becomes 未填写 with __EMPTY__ value', static function (): void {
    $tree = MaterialService::buildRoundSteelTree([
        [
            'material_name' => '凯亚',
            'manufacturer' => '  ',
            'material_spec' => '20#',
            'material_model' => '60',
        ],
    ]);
    expectTree($tree[0]['children'][0]['label'] === '未填写', 'empty manufacturer label');
    expectTree($tree[0]['children'][0]['value'] === '__EMPTY__', 'empty manufacturer sentinel');
    expectTree($tree[0]['children'][0]['children'][0]['label'] === '20#', 'spec under empty manufacturer');
});

runRoundSteelTreeCase('empty material_spec becomes 未填写', static function (): void {
    $tree = MaterialService::buildRoundSteelTree([
        [
            'material_name' => '溯源',
            'manufacturer' => '天钢',
            'material_spec' => '',
            'material_model' => '70',
        ],
    ]);
    $specNode = $tree[0]['children'][0]['children'][0];
    expectTree($specNode['label'] === '未填写', 'empty spec label');
    expectTree($specNode['value'] === '__EMPTY__', 'empty spec sentinel');
});

if ($failed !== []) {
    fwrite(STDERR, "FAILED " . count($failed) . "\n" . implode("\n", $failed) . "\n");
    exit(1);
}

echo "OK {$passed}\n";
