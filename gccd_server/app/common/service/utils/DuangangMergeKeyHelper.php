<?php

namespace app\common\service\utils;

/**
 * 断钢合并键：圆钢规格(钢号) + 圆钢型号 + 成品规格
 */
class DuangangMergeKeyHelper
{
    /**
     * 从圆钢规格提取钢号（如 天钢45# → 45#）
     */
    public static function extractSteelGrade(string $yMaterialSpec): string
    {
        if (preg_match('/(\d+#)/u', $yMaterialSpec, $m)) {
            return (string) $m[1];
        }

        return '';
    }

    /**
     * @return array{mergeKey:string,steelGrade:string,yMaterialSpec:string,yMaterialModel:string,cMaterialSpec:string}
     */
    public static function buildFromTaskRow(array $taskRow): array
    {
        $ySpec = trim((string) ($taskRow['y_material_spec'] ?? ''));
        $yModel = trim((string) ($taskRow['y_material_model'] ?? ''));
        $cSpec = trim((string) ($taskRow['c_material_spec'] ?? ''));
        $grade = self::extractSteelGrade($ySpec);
        // 有钢号(45#)：按归一钢号+型号+成品规格；无钢号：按圆钢规格(邯钢/潍坊)+型号+成品规格
        $mergeKey = $grade !== ''
            ? $grade . '|' . $yModel . '|' . $cSpec
            : $ySpec . '|' . $yModel . '|' . $cSpec;

        return [
            'mergeKey' => $mergeKey,
            'steelGrade' => $grade,
            'yMaterialSpec' => $ySpec,
            'yMaterialModel' => $yModel,
            'cMaterialSpec' => $cSpec,
        ];
    }
}
