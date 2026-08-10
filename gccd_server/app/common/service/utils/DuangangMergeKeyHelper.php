<?php

namespace app\common\service\utils;

/**
 * 断钢合并键：圆钢名称 + 厂商 + 材质(钢号) + 型号 + 成品规格
 */
class DuangangMergeKeyHelper
{
    /* [AI-2026-08] 四字段主数据后，合并键必须区分厂商，避免不同厂家串到同一合并池 */

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
     * 从历史材质文本中剥离已独立保存的厂商。
     *
     * @param string $materialSpec
     * @param string $manufacturer
     * @return string
     */
    public static function cleanMaterialSpec(string $materialSpec, string $manufacturer = ''): string
    {
        $clean = trim(str_replace(['＃'], ['#'], $materialSpec));
        $manufacturer = trim($manufacturer);
        if ($manufacturer !== '' && str_starts_with($clean, $manufacturer)) {
            $clean = mb_substr($clean, mb_strlen($manufacturer));
            $clean = ltrim($clean, " \t\n\r\0\x0B-—_/（）()");
        }

        return trim($clean, " \t\n\r\0\x0B-—_/（）()");
    }

    /**
     * @return array{
     *   mergeKey:string,
     *   steelGrade:string,
     *   yMaterialName:string,
     *   yMaterialManufacturer:string,
     *   yMaterialSpec:string,
     *   yMaterialModel:string,
     *   cMaterialSpec:string
     * }
     */
    public static function buildFromTaskRow(array $taskRow): array
    {
        $yName = trim((string) ($taskRow['y_material_name'] ?? ''));
        $yManufacturer = trim((string) ($taskRow['y_material_manufacturer'] ?? ''));
        $ySpec = self::cleanMaterialSpec(
            (string) ($taskRow['y_material_spec'] ?? ''),
            $yManufacturer
        );
        $yModel = trim((string) ($taskRow['y_material_model'] ?? ''));
        $cSpec = trim((string) ($taskRow['c_material_spec'] ?? ''));
        $grade = self::extractSteelGrade($ySpec);
        $materialPart = $grade !== '' ? $grade : $ySpec;
        // 名称|厂商|材质钢号|型号|成品规格
        $mergeKey = $yName . '|' . $yManufacturer . '|' . $materialPart . '|' . $yModel . '|' . $cSpec;

        return [
            'mergeKey' => $mergeKey,
            'steelGrade' => $grade,
            'yMaterialName' => $yName,
            'yMaterialManufacturer' => $yManufacturer,
            'yMaterialSpec' => $ySpec,
            'yMaterialModel' => $yModel,
            'cMaterialSpec' => $cSpec,
        ];
    }
}
