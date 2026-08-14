<?php
/**
 * [AI-2026-08] 穿孔到酸洗历史盘点：按生产计划成品档案回填型号+长度，一对多按报工占比拆行
 *
 * 用法：
 *   php gccd_server/scripts/backfill-stocktake-finished-length.php
 *   php gccd_server/scripts/backfill-stocktake-finished-length.php --write
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use app\common\service\admin\MaterialInventoryService;
use app\common\service\admin\PublicService;
use think\facade\Db;

$doWrite = in_array('--write', $argv ?? [], true);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

$processes = ['穿孔', '球化', '矫直', '酸洗'];
$codeByProcess = [
    '穿孔' => 'gx_0011',
    '球化' => 'gx_0012',
    '矫直' => 'gx_0013',
    '酸洗' => 'gx_0014',
];

$rows = Db::name('material_inventory')
    ->where('is_delete', 0)
    ->whereIn('process_name', $processes)
    ->where(function ($q) {
        $q->whereNull('c_material_model')->whereOr('c_material_model', '');
    })
    ->select()
    ->toArray();

echo ($doWrite ? "模式: WRITE\n" : "模式: DRY-RUN\n");
echo '待处理行: ' . count($rows) . "\n";

$minDate = '2000-01-01';
$maxDate = date('Y-m-d');
$finishedIndex = MaterialInventoryService::buildFinishedReportQtyIndex($minDate, $maxDate);

$updated = 0;
$split = 0;
$skipped = 0;
$now = date('Y-m-d H:i:s');

foreach ($rows as $row) {
    $id = intval($row['id']);
    $mid = intval($row['material_id'] ?? 0);
    $processName = (string) $row['process_name'];
    $oldSpec = MaterialInventoryService::normalizeSpec((string) ($row['c_material_spec'] ?? ''));
    $code = $codeByProcess[$processName] ?? '';
    if ($mid <= 0 || $oldSpec === '' || $code === '') {
        $skipped++;
        echo "SKIP id={$id} 缺圆钢或规格\n";
        continue;
    }

    $matches = [];
    foreach (($finishedIndex[$mid][$code] ?? []) as $cSpec => $models) {
        foreach ($models as $cModel => $dates) {
            $cSpec = MaterialInventoryService::normalizeSpec((string) $cSpec);
            $cModel = MaterialInventoryService::normalizeSpec((string) $cModel);
            if ($cModel === $oldSpec || $cSpec === $oldSpec) {
                $qty = 0.0;
                foreach ($dates as $dQty) {
                    $qty += floatval($dQty);
                }
                $matches[$cSpec . "\x00" . $cModel] = [
                    'c_material_spec' => $cSpec,
                    'c_material_model' => $cModel,
                    'qty' => $qty,
                ];
            }
        }
    }

    if ($matches === []) {
        $skipped++;
        echo "SKIP id={$id} {$processName} mid={$mid} spec={$oldSpec} 对不上计划\n";
        continue;
    }

    $matchList = array_values($matches);
    $totalTonnage = floatval($row['total_tonnage'] ?? 0);
    $reportSum = array_sum(array_column($matchList, 'qty'));

    if (count($matchList) === 1) {
        $one = $matchList[0];
        echo "UPDATE id={$id} {$oldSpec} -> 型号{$one['c_material_model']} 规格{$one['c_material_spec']}\n";
        if ($doWrite) {
            Db::name('material_inventory')->where('id', $id)->update([
                'c_material_spec' => $one['c_material_spec'],
                'c_material_model' => $one['c_material_model'],
                'update_time' => $now,
            ]);
        }
        $updated++;
        continue;
    }

    echo "SPLIT id={$id} {$oldSpec} 吨={$totalTonnage} 对上 " . count($matchList) . " 个长度\n";
    $remain = $totalTonnage;
    foreach ($matchList as $i => $one) {
        $share = $reportSum > 0.000001
            ? round($totalTonnage * ($one['qty'] / $reportSum), 3)
            : round($totalTonnage / count($matchList), 3);
        if ($i === count($matchList) - 1) {
            $share = round($remain, 3);
        } else {
            $remain -= $share;
        }
        echo "  -> 型号{$one['c_material_model']} 规格{$one['c_material_spec']} 吨={$share} 报工={$one['qty']}\n";
        if (!$doWrite) {
            continue;
        }
        if ($i === 0) {
            Db::name('material_inventory')->where('id', $id)->update([
                'c_material_spec' => $one['c_material_spec'],
                'c_material_model' => $one['c_material_model'],
                'total_tonnage' => $share,
                'day_shift_tonnage' => $share,
                'night_shift_tonnage' => 0,
                'update_time' => $now,
            ]);
        } else {
            $copy = $row;
            unset($copy['id']);
            $copy['inventory_code'] = PublicService::getCode(10)['code'] ?? ('BF' . $id . $i . time());
            $copy['c_material_spec'] = $one['c_material_spec'];
            $copy['c_material_model'] = $one['c_material_model'];
            $copy['total_tonnage'] = $share;
            $copy['day_shift_tonnage'] = $share;
            $copy['night_shift_tonnage'] = 0;
            $copy['update_time'] = $now;
            $copy['create_time'] = $now;
            Db::name('material_inventory')->insert($copy);
        }
    }
    $split++;
}

echo "完成 update={$updated} split={$split} skip={$skipped}\n";
