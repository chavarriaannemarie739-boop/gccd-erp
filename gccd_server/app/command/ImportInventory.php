<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 导入4月30日期初库存数据
 * php think import-inventory
 */
class ImportInventory extends Command
{
    protected function configure()
    {
        $this->setName('import-inventory')
             ->setDescription('导入4月30日期初库存数据（从CSV文件读取）');
    }

    protected function execute(Input $input, Output $output)
    {
        $importDir = root_path() . 'runtime/import/';
        $output->info('开始导入期初库存...');

        $records = [];

        // ====== 溯源 ======
        $output->info('处理 溯源...');
        $rows = $this->readCsv($importDir . '溯源.csv');
        $processRows = [];
        foreach ($rows as $r) {
            $p = trim($r[0] ?? '');
            if ($p && !in_array($p, ['岗位', '2026年5月11日溯源钢管库存明细 '])) {
                // 多行合并的工序名（如 "断钢\n待穿孔"）
                $p = str_replace("\n", '', $p);
                $p = str_replace(' ', '', $p);
            }
            $processRows[] = $p;
        }
        $currentProcess = '';
        $processMap = [
            '圆钢' => '圆钢',
            '断钢待穿孔' => '断钢',
            '穿孔待球化' => '穿孔',
            '球化待矫直' => '球化',
            '矫直待酸洗' => '矫直',
            '矫直待' => '矫直',
            '酸洗成品' => '酸洗',
            '次成品' => '次成品',
        ];
        foreach ($rows as $idx => $r) {
            if ($idx < 2) {
                continue; // skip header
            }
            $p = $processRows[$idx] ?: $currentProcess;
            if ($p) {
                $currentProcess = $p;
            }

            $mappedProcess = $processMap[$currentProcess] ?? $currentProcess;
            $materialName = trim($r[1] ?? '');
            $cSpec = trim($r[2] ?? '');
            $beginQty = floatval($r[3] ?? 0);

            if (!$materialName || $beginQty <= 0) {
                continue;
            }
            if (in_array($materialName, ['总计：', '合计', '半成品夹灰', '半成品翘皮'])) {
                continue;
            }

            $records[] = [
                'inventory_date' => '2026-04-30',
                'process_name' => $mappedProcess,
                'material_name' => $materialName,
                'c_material_spec' => $cSpec,
                'total_tonnage' => $beginQty,
                'source' => 'import',
                'confirmed' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'create_uid' => 0,
            ];
        }
        $output->info('  溯源: ' . count($records) . ' 条 (累计)');

        // ====== 凯亚 ======
        $before = count($records);
        $output->info('处理 凯亚...');
        $currentProcess = '';
        $kaiyaProcessMap = [
            '圆钢' => '圆钢',
            '断钢' => '断钢',
            '穿孔' => '穿孔',
            '球化' => '球化',
            '矫直' => '矫直',
            '酸洗成品' => '酸洗',
            '二级管' => '次成品',
            '回炉钢' => '次成品',
        ];
        $rows = $this->readCsv($importDir . '凯亚.csv');
        foreach ($rows as $idx => $r) {
            if ($idx < 2) {
                continue;
            }
            $p = trim($r[0] ?? '');
            if ($p) {
                $currentProcess = $p;
            }

            $mappedProcess = $kaiyaProcessMap[$currentProcess] ?? $currentProcess;
            $materialName = trim($r[1] ?? '');
            $length = trim($r[2] ?? '');
            $model = trim($r[3] ?? '');
            $beginQty = floatval($r[4] ?? 0);

            if (!$materialName || $beginQty <= 0) {
                continue;
            }
            if (in_array($materialName, ['总计：'])) {
                continue;
            }

            // 凯亚：规格组合 材质+长度+型号
            $records[] = [
                'inventory_date' => '2026-04-30',
                'process_name' => $mappedProcess,
                'material_name' => $materialName,
                'y_material_spec' => $length,
                'c_material_spec' => $model,
                'total_tonnage' => $beginQty,
                'source' => 'import',
                'confirmed' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'create_uid' => 0,
            ];
        }
        $output->info('  凯亚: ' . (count($records) - $before) . ' 条');

        // ====== 导轨钢 ======
        $before = count($records);
        $output->info('处理 导轨钢...');
        $currentProcess = '';
        $rows = $this->readCsv($importDir . '导轨钢.csv');
        $railProcessMap = [
            '青钢' => '圆钢',
            '临沂钢' => '圆钢',
            '断钢' => '断钢',
        ];
        foreach ($rows as $idx => $r) {
            if ($idx < 2) {
                continue;
            }
            $p = trim($r[0] ?? '');
            if ($p) {
                $currentProcess = $p;
            }

            $mappedProcess = $railProcessMap[$currentProcess] ?? $currentProcess;
            $materialName = trim($r[1] ?? '');
            $length = trim($r[2] ?? '');
            $model = trim($r[3] ?? '');
            $beginQty = floatval($r[4] ?? 0);

            if (!$materialName || $beginQty <= 0) {
                continue;
            }
            if (in_array($materialName, ['合计吨位'])) {
                continue;
            }

            $records[] = [
                'inventory_date' => '2026-04-30',
                'process_name' => $mappedProcess,
                'material_name' => $materialName,
                'y_material_spec' => $length,
                'c_material_spec' => $model,
                'total_tonnage' => $beginQty,
                'source' => 'import',
                'confirmed' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'create_uid' => 0,
            ];
        }
        $output->info('  导轨钢: ' . (count($records) - $before) . ' 条');

        // ====== 易合 ======
        $before = count($records);
        $output->info('处理 易合...');
        $currentProcess = '';
        $simpleProcessMap = [
            '圆钢' => '圆钢',
            '断钢' => '断钢',
            '穿孔' => '穿孔',
            '球化' => '球化',
            '矫直' => '矫直',
            '酸洗成品' => '酸洗',
        ];
        $rows = $this->readCsv($importDir . '易合.csv');
        foreach ($rows as $idx => $r) {
            if ($idx < 2) {
                continue;
            }
            $p = trim($r[0] ?? '');
            if ($p) {
                $currentProcess = $p;
            }

            $mappedProcess = $simpleProcessMap[$currentProcess] ?? $currentProcess;
            $materialName = trim($r[1] ?? '');
            $cSpec = trim($r[2] ?? '');
            $beginQty = floatval($r[4] ?? 0);

            if (!$materialName || $beginQty <= 0) {
                continue;
            }
            if (in_array($materialName, ['总计：'])) {
                continue;
            }

            $records[] = [
                'inventory_date' => '2026-04-30',
                'process_name' => $mappedProcess,
                'material_name' => $materialName,
                'c_material_spec' => $cSpec,
                'total_tonnage' => $beginQty,
                'source' => 'import',
                'confirmed' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'create_uid' => 0,
            ];
        }
        $output->info('  易合: ' . (count($records) - $before) . ' 条');

        // ====== 高齿 ======
        $before = count($records);
        $output->info('处理 高齿...');
        $currentProcess = '';
        $rows = $this->readCsv($importDir . '高齿.csv');
        foreach ($rows as $idx => $r) {
            if ($idx < 2) {
                continue;
            }
            $p = trim($r[0] ?? '');
            if ($p) {
                $currentProcess = $p;
            }

            $mappedProcess = $simpleProcessMap[$currentProcess] ?? $currentProcess;
            $materialName = trim($r[1] ?? '');
            $cSpec = trim($r[2] ?? '');
            $beginQty = floatval($r[4] ?? 0);

            if (!$materialName || $beginQty <= 0) {
                continue;
            }

            $records[] = [
                'inventory_date' => '2026-04-30',
                'process_name' => $mappedProcess,
                'material_name' => $materialName,
                'c_material_spec' => $cSpec,
                'total_tonnage' => $beginQty,
                'source' => 'import',
                'confirmed' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'create_uid' => 0,
            ];
        }
        $output->info('  高齿: ' . (count($records) - $before) . ' 条');

        // ====== 马强济 ======
        $before = count($records);
        $output->info('处理 马强济...');
        $currentProcess = '';
        $rows = $this->readCsv($importDir . '马强济.csv');
        foreach ($rows as $idx => $r) {
            if ($idx < 2) {
                continue;
            }
            $p = trim($r[0] ?? '');
            if ($p) {
                $currentProcess = $p;
            }

            $mappedProcess = $simpleProcessMap[$currentProcess] ?? $currentProcess;
            $materialName = trim($r[1] ?? '');
            $cSpec = trim($r[2] ?? '');
            $beginQty = floatval($r[4] ?? 0);

            if (!$materialName || $beginQty <= 0) {
                continue;
            }
            if (in_array($materialName, ['总计：'])) {
                continue;
            }

            $records[] = [
                'inventory_date' => '2026-04-30',
                'process_name' => $mappedProcess,
                'material_name' => $materialName,
                'c_material_spec' => $cSpec,
                'total_tonnage' => $beginQty,
                'source' => 'import',
                'confirmed' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'create_uid' => 0,
            ];
        }
        $output->info('  马强济: ' . (count($records) - $before) . ' 条');

        // ====== 汇总并插入 ======
        $total = count($records);
        $output->info("共 {$total} 条有效期初记录");

        if ($total === 0) {
            $output->error('无有效数据可导入');
            return;
        }

        // 显示前5条预览
        $output->info('预览前5条:');
        for ($i = 0; $i < min(5, $total); $i++) {
            $r = $records[$i];
            $output->info(sprintf(
                "  %s | %s | %s | %s | %.3f t",
                $r['process_name'],
                $r['material_name'],
                $r['c_material_spec'] ?: '-',
                $r['y_material_spec'] ?: '-',
                $r['total_tonnage']
            ));
        }

        // 插入数据库
        Db::startTrans();
        try {
            // 先删除已存在的2026-04-30 import记录（可重复执行）
            Db::name('material_inventory')
                ->where('inventory_date', '2026-04-30')
                ->where('source', 'import')
                ->delete();

            // 分批插入，每批100条
            $chunks = array_chunk($records, 100);
            foreach ($chunks as $chunk) {
                Db::name('material_inventory')->insertAll($chunk);
            }

            Db::commit();
            $output->info("成功导入 {$total} 条期初库存记录到 2026-04-30");
        } catch (\Exception $e) {
            Db::rollback();
            $output->error('导入失败: ' . $e->getMessage());
        }
    }

    private function readCsv(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        $rows = [];
        $handle = fopen($file, 'r');
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
        return $rows;
    }
}
