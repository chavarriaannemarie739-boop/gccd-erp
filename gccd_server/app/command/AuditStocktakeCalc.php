<?php

namespace app\command;

use app\common\service\admin\MaterialInventoryService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 批量审计智能推算结果与源数据
 * php think audit-stocktake-calc
 * php think audit-stocktake-calc 2026-05-20
 */
class AuditStocktakeCalc extends Command
{
    protected function configure()
    {
        $this->setName('audit-stocktake-calc')
            ->addArgument('calc_date', null, '推算日 Y-m-d，默认今天')
            ->setDescription('批量检测智能推算：公式、期初、区间、物料ID、重复项等');
    }

    protected function execute(Input $input, Output $output)
    {
        $calcDate = $input->getArgument('calc_date') ?: date('Y-m-d');
        $calcDate = date('Y-m-d', strtotime($calcDate));
        $globalEnd = date('Y-m-d', strtotime($calcDate . ' -1 day'));

        $output->writeln("推算日 calc_date = {$calcDate}，流水统计终点 = {$globalEnd}");
        $output->writeln(str_repeat('=', 72));

        $result = MaterialInventoryService::autoCalculateInventory($calcDate);
        if (isset($result['error'])) {
            $output->error($result['error']);
            return 1;
        }

        $issues = [];
        $stats = [
            'total' => count($result),
            'yuan_gang' => 0,
            'invalid_period' => 0,
            'stale_baseline' => 0,
            'wrong_material_id' => 0,
            'formula_mismatch' => 0,
            'is_new_inbound' => 0,
            'negative' => 0,
            'duplicate_key' => 0,
            'consume_name_bleed' => 0,
            'zero_period_has_flow' => 0,
        ];

        $seenKeys = [];

        foreach ($result as $row) {
            $pn = $row['process_name'] ?? '';
            if ($pn === '圆钢') {
                $stats['yuan_gang']++;
            }

            $label = $this->rowLabel($row);
            $key = ($pn === '圆钢')
                ? ($row['material_name'] ?? '') . '|' . ($row['y_material_spec'] ?? '') . '|' . ($row['y_material_model'] ?? '')
                : $label;
            $dedupe = $pn . "\0" . $key;
            if (isset($seenKeys[$dedupe])) {
                $stats['duplicate_key']++;
                $issues[] = $this->issue('duplicate_key', $row, '与另一条推算结果重复：' . $seenKeys[$dedupe]);
            } else {
                $seenKeys[$dedupe] = $label;
            }

            $predicted = floatval($row['predicted_qty'] ?? 0);
            if ($predicted < -0.001) {
                $stats['negative']++;
                $issues[] = $this->issue('negative', $row, '预测为负数');
            }

            if (!empty($row['is_new'])) {
                $stats['is_new_inbound']++;
                $issues[] = $this->issue('is_new', $row, '无期初，由 Step6 新入库检出');
            }

            $baselineDate = $row['baseline_date'] ?? '';
            $periodStart = $row['calc_start_date'] ?? '';
            $periodEnd = $row['calc_end_date'] ?? '';
            if ($periodStart && $periodEnd && strtotime($periodStart) > strtotime($periodEnd)) {
                $stats['invalid_period']++;
                $issues[] = $this->issue('invalid_period', $row, "统计区间无效 {$periodStart}~{$periodEnd}（仅显示期初，未扣流水）");
            }

            $detail = json_decode($row['calc_detail'] ?? '{}', true) ?: [];
            if (($detail['note'] ?? '') === '今日已有手动盘点，直接作为预测值，不走历史推算') {
                continue;
            }

            $baseline = floatval($row['baseline_qty'] ?? $detail['baseline'] ?? 0);
            $expected = $this->expectedFromDetail($pn, $detail, $baseline);
            if ($expected !== null && abs($predicted - $expected) > 0.05) {
                $stats['formula_mismatch']++;
                $issues[] = $this->issue('formula_mismatch', $row, sprintf(
                    '明细公式不符：预测 %.3f，按 calc_detail 应为 %.3f',
                    $predicted,
                    $expected
                ));
            }

            if ($pn === '圆钢' && $baselineDate) {
                $this->auditRoundSteelRow($row, $calcDate, $globalEnd, $issues, $stats);
            }
        }

        $this->auditManualMaterialIds($calcDate, $issues, $stats);

        $output->writeln("\n【汇总】");
        foreach ($stats as $k => $v) {
            $output->writeln(sprintf('  %-22s %d', $k . ':', $v));
        }

        $byType = [];
        foreach ($issues as $it) {
            $byType[$it['type']][] = $it;
        }

        $output->writeln("\n【问题明细】共 " . count($issues) . " 条\n");
        $priority = ['invalid_period', 'stale_baseline', 'wrong_material_id', 'consume_name_bleed', 'zero_period_has_flow', 'formula_mismatch', 'is_new', 'duplicate_key', 'negative'];
        foreach ($priority as $type) {
            if (empty($byType[$type])) {
                continue;
            }
            $output->writeln("--- {$type} (" . count($byType[$type]) . ") ---");
            foreach (array_slice($byType[$type], 0, 30) as $it) {
                $output->writeln("  [{$it['process']}] {$it['label']}");
                $output->writeln("       {$it['message']}");
                if (!empty($it['extra'])) {
                    $output->writeln("       {$it['extra']}");
                }
            }
            if (count($byType[$type]) > 30) {
                $output->writeln('  ... 还有 ' . (count($byType[$type]) - 30) . ' 条');
            }
            $output->writeln('');
        }

        $reportPath = runtime_path() . 'audit_stocktake_' . $calcDate . '.json';
        file_put_contents($reportPath, json_encode([
            'calc_date' => $calcDate,
            'global_end' => $globalEnd,
            'stats' => $stats,
            'issues' => $issues,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $output->writeln("完整报告已写入: {$reportPath}");

        return count($issues) > 0 ? 0 : 0;
    }

    private function auditRoundSteelRow(array $row, string $calcDate, string $globalEnd, array &$issues, array &$stats): void
    {
        $name = trim($row['material_name'] ?? '');
        $spec = trim($row['y_material_spec'] ?? '');
        $model = trim($row['y_material_model'] ?? '');
        $mid = intval($row['material_id'] ?? 0);
        $baselineDate = $row['baseline_date'] ?? '';

        if ($spec === '' || $model === '') {
            return;
        }

        $latest = Db::name('material_inventory')
            ->alias('mi')
            ->join('material m', 'mi.material_id = m.id', 'LEFT')
            ->where('mi.is_delete', 0)
            ->where('mi.confirmed', 1)
            ->where('mi.process_name', '圆钢')
            ->where(function ($q) use ($name, $spec, $model) {
                $q->where(function ($q2) use ($name, $spec, $model) {
                    $q2->where('mi.material_name', $name)
                        ->where('mi.y_material_spec', $spec)
                        ->where('mi.y_material_model', $model);
                });
                $q->whereOr(function ($q2) use ($name, $spec, $model) {
                    $q2->where('m.material_name', $name)
                        ->where('m.material_spec', $spec)
                        ->where('m.material_model', $model);
                });
            })
            ->where(function ($q) {
                $q->where('mi.source', 'manual')->whereOr('mi.source', '')->whereOr('mi.source', null);
            })
            ->where('mi.inventory_date', '<=', $calcDate)
            ->order(['mi.inventory_date' => 'desc', 'mi.create_time' => 'desc', 'mi.id' => 'desc'])
            ->field('mi.inventory_date,mi.material_id,mi.day_shift_tonnage,mi.night_shift_tonnage,m.material_spec,m.material_model')
            ->find();

        if ($latest && ($latest['inventory_date'] ?? '') > $baselineDate) {
            $stats['stale_baseline']++;
            $qty = round(floatval($latest['day_shift_tonnage']) + floatval($latest['night_shift_tonnage']), 3);
            $issues[] = $this->issue('stale_baseline', $row, sprintf(
                '期初日 %s，但库里有更新手动盘点 %s（%.3f 吨），可能未提交该规格或 material_id 错误',
                $baselineDate,
                $latest['inventory_date'],
                $qty
            ), "库中 material_id={$latest['material_id']}");
        }

        $periodStart = $row['calc_start_date'] ?? date('Y-m-d', strtotime($baselineDate . ' +1 day'));
        $periodEnd = $row['calc_end_date'] ?? $globalEnd;
        if (!$periodStart || !$periodEnd || strtotime($periodStart) > strtotime($periodEnd)) {
            if ($mid > 0 && $baselineDate) {
                $flow = $this->sumDgByMid($mid, date('Y-m-d', strtotime($baselineDate . ' +1 day')), $globalEnd);
                if ($flow > 0.01) {
                    $stats['zero_period_has_flow']++;
                    $issues[] = $this->issue('zero_period_has_flow', $row, sprintf(
                        '区间未统计但 %s~%s 有断钢报工 %.3f 吨（检查 calc_date 是否应为今天）',
                        date('Y-m-d', strtotime($baselineDate . ' +1 day')),
                        $globalEnd,
                        $flow
                    ));
                }
            }
            return;
        }

        if ($mid <= 0) {
            return;
        }

        $byMid = $this->sumDgByMid($mid, $periodStart, $periodEnd);
        $byNameSpec = $this->sumDgByNameSpec($name, $spec, $periodStart, $periodEnd);
        $detailConsume = floatval(json_decode($row['calc_detail'] ?? '{}', true)['consume'] ?? 0);

        if (abs($byMid - $detailConsume) < 0.05 && abs($byNameSpec - $byMid) > 0.5) {
            $stats['consume_name_bleed']++;
            $issues[] = $this->issue('consume_name_bleed', $row, sprintf(
                '按 material_id 消耗 %.3f，若按名称+规格(不分型号)为 %.3f，其他型号报工可能被误扣',
                $byMid,
                $byNameSpec
            ));
        }
    }

    private function auditManualMaterialIds(string $calcDate, array &$issues, array &$stats): void
    {
        $rows = Db::name('material_inventory')
            ->alias('mi')
            ->join('material m', 'mi.material_id = m.id', 'LEFT')
            ->where('mi.is_delete', 0)
            ->where('mi.confirmed', 1)
            ->where('mi.process_name', '圆钢')
            ->where('mi.inventory_date', '>=', date('Y-m-d', strtotime($calcDate . ' -7 day')))
            ->where(function ($q) {
                $q->where('mi.source', 'manual')->whereOr('mi.source', '')->whereOr('mi.source', null);
            })
            ->where('mi.material_id', '>', 0)
            ->field('mi.id,mi.inventory_date,mi.material_id,mi.material_name,mi.y_material_spec,mi.y_material_model,m.material_spec as arch_spec,m.material_model as arch_model')
            ->select()
            ->toArray();

        foreach ($rows as $r) {
            $ySpec = trim($r['y_material_spec'] ?? '');
            $yModel = trim($r['y_material_model'] ?? '');
            $aSpec = trim($r['arch_spec'] ?? '');
            $aModel = trim($r['arch_model'] ?? '');
            if ($ySpec === '' || $yModel === '') {
                continue;
            }
            if ($aSpec !== $ySpec || $aModel !== $yModel) {
                $stats['wrong_material_id']++;
                $issues[] = [
                    'type' => 'wrong_material_id',
                    'process' => '圆钢',
                    'label' => ($r['material_name'] ?? '') . ' / ' . $ySpec . ' / ' . $yModel,
                    'message' => sprintf(
                        '盘点记录 id=%d 日期=%s：填写 %s/%s，但 material_id=%d 档案为 %s/%s',
                        $r['id'],
                        $r['inventory_date'],
                        $ySpec,
                        $yModel,
                        $r['material_id'],
                        $aSpec,
                        $aModel
                    ),
                    'extra' => '',
                ];
            }
        }
    }

    private function sumDgByMid(int $mid, string $start, string $end): float
    {
        if (strtotime($start) > strtotime($end)) {
            return 0;
        }
        return floatval(Db::name('product_report')
            ->where('material_id', $mid)
            ->where('processes_code', 'gx_0010')
            ->where('is_delete', 0)
            ->where('report_date', '>=', $start)
            ->where('report_date', '<=', $end)
            ->sum('report_num'));
    }

    private function sumDgByNameSpec(string $name, string $spec, string $start, string $end): float
    {
        if (strtotime($start) > strtotime($end) || $name === '' || $spec === '') {
            return 0;
        }
        return floatval(Db::name('product_report')
            ->alias('pr')
            ->join('material m', 'pr.material_id = m.id')
            ->where('m.material_name', $name)
            ->where('m.material_spec', $spec)
            ->where('pr.processes_code', 'gx_0010')
            ->where('pr.is_delete', 0)
            ->where('report_date', '>=', $start)
            ->where('report_date', '<=', $end)
            ->sum('report_num'));
    }

    private function expectedFromDetail(string $processName, array $detail, float $baseline): ?float
    {
        if ($processName === '圆钢') {
            return $baseline + floatval($detail['inbound'] ?? 0) - floatval($detail['consume'] ?? 0);
        }
        if (isset($detail['current_output'], $detail['next_consume'])) {
            $v = $baseline + floatval($detail['current_output']) - floatval($detail['next_consume']);
            if (isset($detail['ship_out'])) {
                $v -= floatval($detail['ship_out']);
            }
            return $v;
        }
        return null;
    }

    private function rowLabel(array $row): string
    {
        $parts = array_filter([
            $row['material_name'] ?? '',
            $row['y_material_spec'] ?? '',
            $row['y_material_model'] ?? '',
            $row['c_material_spec'] ?? '',
        ]);
        return implode(' / ', $parts);
    }

    private function issue(string $type, array $row, string $message, string $extra = ''): array
    {
        return [
            'type' => $type,
            'process' => $row['process_name'] ?? '',
            'label' => $this->rowLabel($row),
            'predicted_qty' => $row['predicted_qty'] ?? null,
            'baseline_date' => $row['baseline_date'] ?? '',
            'message' => $message,
            'extra' => $extra,
        ];
    }
}
