<?php

namespace app\common\service\admin;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use think\facade\Db;

/**
 * 生产流转卡：二维码生成、标签数据、扫码解析
 */
class FlowCardService
{
    public const PAYLOAD_PREFIX = 'GCCD:FLOW:';

    /** @var string[] 可扫码直达报工的工序 */
    private static $scanReportProcessNames = ['穿孔', '球化', '矫直', '酸洗'];

    // [AI-2026-08] 流转卡圆钢四字段统一输出开始
    /**
     * 按名称、厂商、材质、型号构建圆钢打印文本。
     *
     * @param array<string,mixed> $row
     * @return string
     */
    public static function formatRoundSteelText(array $row): string
    {
        $values = [
            self::firstRoundSteelValue($row, 'name'),
            self::firstRoundSteelValue($row, 'manufacturer'),
            self::firstRoundSteelValue($row, 'spec'),
            self::firstRoundSteelValue($row, 'model'),
        ];
        $values = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));

        return $values === [] ? '' : '圆:' . implode(' / ', $values);
    }

    /**
     * 构建流转码明文
     */
    public static function buildPayload(string $planCode, int $materialId = 0, string $deviceCode = ''): string
    {
        $planCode = trim($planCode);
        $deviceCode = trim($deviceCode);

        return self::PAYLOAD_PREFIX
            . $planCode . ':'
            . ($materialId > 0 ? (string) $materialId : '0') . ':'
            . ($deviceCode !== '' ? $deviceCode : '-');
    }

    /**
     * 是否为流转卡码
     */
    public static function isFlowPayload(string $raw): bool
    {
        return str_starts_with(trim($raw), self::PAYLOAD_PREFIX);
    }

    /**
     * 解析流转码
     *
     * @return array{plan_code:string,material_id:int,device_code:string}|null
     */
    public static function parsePayload(string $raw): ?array
    {
        $raw = trim($raw);
        if (!self::isFlowPayload($raw)) {
            return null;
        }
        $body = substr($raw, strlen(self::PAYLOAD_PREFIX));
        $parts = explode(':', $body, 3);
        if (count($parts) < 3) {
            return null;
        }
        $planCode = trim($parts[0]);
        if ($planCode === '') {
            return null;
        }
        $materialId = (int) $parts[1];
        $deviceCode = trim($parts[2]);
        if ($deviceCode === '-') {
            $deviceCode = '';
        }

        return [
            'plan_code' => $planCode,
            'material_id' => $materialId,
            'device_code' => $deviceCode,
        ];
    }

    /**
     * 打印前：可选圆钢明细与穿孔设备
     *
     * @return array<string,mixed>
     */
    public static function printOptions(int $planId): array
    {
        $plan = ProductionService::info($planId);
        $materials = [];
        foreach ($plan['content'] ?? [] as $row) {
            $mid = (int) ($row['material_id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $materials[] = [
                'material_id' => $mid,
                'y_material_name' => self::firstRoundSteelValue($row, 'name'),
                'y_material_manufacturer' => self::firstRoundSteelValue($row, 'manufacturer'),
                'y_material_spec' => self::firstRoundSteelValue($row, 'spec'),
                'y_material_model' => self::firstRoundSteelValue($row, 'model'),
                'plan_num' => (float) ($row['plan_num'] ?? 0),
            ];
        }
        $devices = [];
        foreach ($plan['punch_devices'] ?? [] as $dev) {
            $code = trim((string) ($dev['device_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $devices[] = [
                'device_code' => $code,
                'device_name' => (string) ($dev['device_name'] ?? $code),
            ];
        }

        return [
            'production_plan_code' => (string) ($plan['production_plan_code'] ?? ''),
            'production_plan_name' => (string) ($plan['production_plan_name'] ?? ''),
            'materials' => $materials,
            'devices' => $devices,
            'need_pick_material' => count($materials) > 1,
            'need_pick_device' => count($devices) > 1,
        ];
    }

    /**
     * 标签打印数据（含二维码 PNG Base64）
     *
     * @return array<string,mixed>
     */
    public static function labelData(int $planId, int $materialId = 0, string $deviceCode = ''): array
    {
        $plan = ProductionService::info($planId);
        $planCode = (string) ($plan['production_plan_code'] ?? '');
        $content = $plan['content'] ?? [];
        if ($content === []) {
            exception('计划无圆钢明细');
        }
        if ($materialId <= 0 && count($content) === 1) {
            $materialId = (int) ($content[0]['material_id'] ?? 0);
        }
        if ($materialId <= 0 && count($content) > 1) {
            exception('请选择圆钢明细');
        }

        $detailRow = null;
        foreach ($content as $row) {
            if ((int) ($row['material_id'] ?? 0) === $materialId) {
                $detailRow = $row;
                break;
            }
        }
        if ($detailRow === null && $materialId > 0) {
            exception('圆钢明细不存在');
        }

        $devices = $plan['punch_devices'] ?? [];
        $deviceCode = trim($deviceCode);
        if ($deviceCode === '' && count($devices) === 1) {
            $deviceCode = trim((string) ($devices[0]['device_code'] ?? ''));
        }
        if (count($devices) > 1 && $deviceCode === '') {
            exception('请选择穿孔设备');
        }
        if ($deviceCode !== '') {
            $allowed = array_map(static fn ($d) => trim((string) ($d['device_code'] ?? '')), $devices);
            if (!in_array($deviceCode, $allowed, true)) {
                exception('穿孔设备不在计划绑定范围内');
            }
        }

        $deviceName = '';
        foreach ($devices as $dev) {
            if (trim((string) ($dev['device_code'] ?? '')) === $deviceCode) {
                $deviceName = trim((string) ($dev['device_name'] ?? ''));
                if ($deviceName === '') {
                    $deviceName = $deviceCode;
                }
                break;
            }
        }

        $payload = self::buildPayload($planCode, $materialId, $deviceCode);
        $qrBase64 = self::qrPngBase64($payload);

        // [AI-2026-08] TSPL：圆钢四字段拆为两行，保留 30mm 标签、成品行与二维码位置
        $planName = trim((string) ($plan['production_plan_name'] ?? ''));
        $roundSteelText = self::formatRoundSteelText($detailRow ?? []);
        $cSpec = trim((string) ($plan['material_spec'] ?? ''));
        $cModel = trim((string) ($plan['material_model'] ?? ''));
        $finished = ($cSpec !== '' && $cModel !== '') ? ($cSpec . '×' . $cModel) : ($cSpec . $cModel);
        $num = $detailRow['plan_num'] ?? 0;
        $roundSteelTsplLines = self::buildRoundSteelTsplLines($detailRow ?? [], $num);

        $line1 = $planName;
        if ($deviceName !== '') {
            $line1 = trim($line1 . ' ' . $deviceName);
        }
        $lines = [];
        $lines[] = 'SIZE 30 mm,30 mm';
        $lines[] = 'GAP 2 mm,0 mm';
        $lines[] = 'DIRECTION 1';
        $lines[] = 'CODEPAGE 936';
        $lines[] = 'CLS';

        $leftX = 4;
        if ($line1 !== '') {
            $lines[] = 'TEXT ' . $leftX . ',4,"TSS16.BF2",0,1,1,"' . self::escTspl(self::truncate($line1, 14)) . '"';
        }
        $lines[] = 'TEXT ' . $leftX . ',26,"TSS12.BF2",0,1,1,"'
            . self::escTspl($roundSteelTsplLines[0]) . '"';
        $lines[] = 'TEXT ' . $leftX . ',44,"TSS12.BF2",0,1,1,"'
            . self::escTspl($roundSteelTsplLines[1]) . '"';
        if ($finished !== '') {
            self::appendProductLineTspl($lines, $finished);
        }

        // 二维码（保持原来大小位置不变）
        if ($payload) {
            $lines[] = 'QRCODE 60,85,H,4,A,0,"' . self::escTspl($payload) . '"';
        }
        $lines[] = 'PRINT 1';
        $tsplStr = implode("\r\n", $lines) . "\r\n";
        $tsplGbk = mb_convert_encoding($tsplStr, 'GBK', 'UTF-8');
        $tsplBase64 = base64_encode($tsplGbk);

        return [
            'production_plan_name' => (string) ($plan['production_plan_name'] ?? ''),
            'y_material_name' => self::firstRoundSteelValue($detailRow ?? [], 'name'),
            'y_material_manufacturer' => self::firstRoundSteelValue($detailRow ?? [], 'manufacturer'),
            'y_material_spec' => self::firstRoundSteelValue($detailRow ?? [], 'spec'),
            'y_material_model' => self::firstRoundSteelValue($detailRow ?? [], 'model'),
            'y_material_text' => $roundSteelText,
            'c_material_spec' => (string) ($plan['material_spec'] ?? ''),
            'c_material_model' => (string) ($plan['material_model'] ?? ''),
            'plan_num' => (float) ($detailRow['plan_num'] ?? 0),
            'device_name' => $deviceName,
            'qr_payload' => $payload,
            'qr_image' => $qrBase64,
            'tspl_cmd' => $tsplBase64,
        ];
    }

    /**
     * 小程序扫码解析
     *
     * @return array<string,mixed>
     */
    public static function resolveScan(string $raw, int $userId): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            exception('扫码内容为空');
        }

        if (!self::isFlowPayload($raw)) {
            return [
                'scan_type' => 'device',
                'device_code' => $raw,
            ];
        }

        $parsed = self::parsePayload($raw);
        if ($parsed === null) {
            exception('流转卡格式无效');
        }

        $ctx = MiniProgramWorkService::resolveWorkContext($userId);
        $processName = (string) ($ctx['processes_name'] ?? '');
        $processCode = (string) ($ctx['processes_code'] ?? '');
        $canReport = in_array($processName, self::$scanReportProcessNames, true);

        $plan = Db::name('production')
            ->where('production_plan_code', $parsed['plan_code'])
            ->where('is_delete', 0)
            ->find();
        if (empty($plan)) {
            exception('生产计划不存在');
        }

        $planInfo = self::buildPlanInfoDisplay($plan, $parsed['material_id']);

        $deviceCode = $parsed['device_code'];
        $punchDevices = ProductionPunchDeviceService::listByPlanCode($parsed['plan_code']);
        if ($deviceCode !== '') {
            $allowed = array_map(static fn ($d) => trim((string) ($d['device_code'] ?? '')), $punchDevices);
            if (!in_array($deviceCode, $allowed, true)) {
                exception('穿孔设备与计划不匹配');
            }
        } elseif (count($punchDevices) === 1) {
            $deviceCode = trim((string) ($punchDevices[0]['device_code'] ?? ''));
        }

        $deviceName = '';
        foreach ($punchDevices as $dev) {
            if (trim((string) ($dev['device_code'] ?? '')) === $deviceCode) {
                $deviceName = trim((string) ($dev['device_name'] ?? '')) ?: $deviceCode;
                break;
            }
        }

        $task = self::findTaskForFlow($parsed['plan_code'], $parsed['material_id']);
        $taskId = (int) ($task['id'] ?? 0);

        $finished = false;
        if ($canReport && $processCode !== '' && $taskId > 0) {
            $taskRow = ProductTaskService::info($taskId, false, $processCode);
            if (!empty($taskRow)) {
                $finished = ProductTaskService::isTaskFinishedForMini($taskRow, $processCode);
            }
        }

        $result = [
            'scan_type' => 'flow',
            'can_report' => $canReport && $taskId > 0,
            'finished' => $finished,
            'task_id' => $taskId,
            'process_code' => $processCode,
            'processes_name' => $processName,
            'device_code' => $deviceCode,
            'device_name' => $deviceName,
            'plan_info' => $planInfo,
        ];

        if ($canReport && $taskId <= 0) {
            exception('未找到对应生产工单，请确认计划已下推');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function buildPlanInfoDisplay(array $plan, int $materialId): array
    {
        $planCode = (string) ($plan['production_plan_code'] ?? '');
        $detail = Db::name('production_detail')
            ->alias('a')
            ->leftJoin('material b', 'b.id=a.material_id')
            ->where('a.production_plan_code', $planCode);
        if ($materialId > 0) {
            $detail->where('a.material_id', $materialId);
        }
        $detailRow = $detail->field('a.*,b.material_spec,b.material_model')->find();

        $finished = Db::name('material')->where('id', (int) ($plan['english_material'] ?? 0))->find();

        return [
            'production_plan_name' => (string) ($plan['production_plan_name'] ?? ''),
            'y_material_name' => self::firstRoundSteelValue($detailRow ?? [], 'name'),
            'y_material_manufacturer' => self::firstRoundSteelValue($detailRow ?? [], 'manufacturer'),
            'y_material_spec' => self::firstRoundSteelValue($detailRow ?? [], 'spec'),
            'y_material_model' => self::firstRoundSteelValue($detailRow ?? [], 'model'),
            'c_material_spec' => (string) ($finished['material_spec'] ?? ''),
            'c_material_model' => (string) ($finished['material_model'] ?? ''),
            'plan_num' => (float) ($detailRow['plan_num'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function findTaskForFlow(string $planCode, int $materialId): array
    {
        $query = Db::name('product_task')
            ->where('relation_code', $planCode)
            ->where('is_delete', 0);
        if ($materialId > 0) {
            $query->where('material_id', $materialId);
        }
        $rows = $query->order('create_time', 'desc')->select()->toArray();
        if ($rows === []) {
            return [];
        }

        return $rows[0];
    }

    private static function qrPngBase64(string $payload): string
    {
        $qr = QrCode::create($payload)->setSize(120)->setMargin(1);
        $writer = new PngWriter();
        $result = $writer->write($qr);

        return 'data:image/png;base64,' . base64_encode($result->getString());
    }

    /**
     * 第三行成品规格：单行 Y=56，品: + 规格一次打完（font"1" 8×12，× 转 x 避免乱码叠字）
     *
     * @param array<int,string> $lines
     */
    private static function appendProductLineTspl(array &$lines, string $finished): void
    {
        $lineY = 64;
        $bodyX = 28;
        $lines[] = 'TEXT 4,' . $lineY . ',"TSS12.BF2",0,1,1,"' . self::escTspl('品:') . '"';
        $body = str_replace('×', 'x', $finished);
        $lines[] = 'TEXT ' . $bodyX . ',' . $lineY . ',"1",0,1,1,"' . self::escTspl($body) . '"';
    }

    /**
     * @param array<string,mixed> $row
     * @param int|float|string $num
     * @return array{0:string,1:string}
     */
    public static function buildRoundSteelTsplLines(array $row, $num): array
    {
        $name = self::firstRoundSteelValue($row, 'name');
        $manufacturer = self::firstRoundSteelValue($row, 'manufacturer');
        $spec = self::firstRoundSteelValue($row, 'spec');
        $model = self::firstRoundSteelValue($row, 'model');
        $first = self::fitTsplFieldPair($name, $manufacturer, '圆:', '', 232, 12);
        $second = self::fitTsplFieldPair($spec, $model, '', ' 数:' . $num, 232, 12);

        return [$first, $second];
    }

    /**
     * 按 TSPL 点阵宽度计算文本占用。
     *
     * @param string $text
     * @param int $fontSize
     * @return int
     */
    public static function measureTsplTextWidth(string $text, int $fontSize = 12): int
    {
        $width = 0;
        $asciiWidth = (int) ceil($fontSize / 2);
        $length = mb_strlen($text, 'UTF-8');
        for ($index = 0; $index < $length; $index++) {
            $char = mb_substr($text, $index, 1, 'UTF-8');
            $width += strlen($char) === 1 && ord($char) < 128 ? $asciiWidth : $fontSize;
        }

        return $width;
    }

    /**
     * 为同一行两个字段动态分配宽度，短字段不浪费剩余空间。
     */
    private static function fitTsplFieldPair(
        string $left,
        string $right,
        string $prefix,
        string $suffix,
        int $maxWidth,
        int $fontSize
    ): string {
        $separator = $left !== '' && $right !== '' ? ' / ' : '';
        $fixedWidth = self::measureTsplTextWidth($prefix . $separator . $suffix, $fontSize);
        $available = max(0, $maxWidth - $fixedWidth);
        $leftWidth = self::measureTsplTextWidth($left, $fontSize);
        $rightWidth = self::measureTsplTextWidth($right, $fontSize);
        $leftBudget = $leftWidth;
        $rightBudget = $rightWidth;

        if ($leftWidth + $rightWidth > $available) {
            if ($left === '') {
                $rightBudget = $available;
            } elseif ($right === '') {
                $leftBudget = $available;
            } else {
                $half = (int) floor($available / 2);
                if ($leftWidth <= $half) {
                    $leftBudget = $leftWidth;
                    $rightBudget = $available - $leftBudget;
                } elseif ($rightWidth <= $half) {
                    $rightBudget = $rightWidth;
                    $leftBudget = $available - $rightBudget;
                } else {
                    $leftBudget = $half;
                    $rightBudget = $available - $half;
                }
            }
        }

        return $prefix
            . self::truncateTsplText($left, $leftBudget, $fontSize)
            . $separator
            . self::truncateTsplText($right, $rightBudget, $fontSize)
            . $suffix;
    }

    /**
     * 按点阵宽度截断，省略号计入预算。
     */
    private static function truncateTsplText(string $text, int $maxWidth, int $fontSize): string
    {
        if (self::measureTsplTextWidth($text, $fontSize) <= $maxWidth) {
            return $text;
        }
        $ellipsis = '…';
        $ellipsisWidth = self::measureTsplTextWidth($ellipsis, $fontSize);
        if ($maxWidth < $ellipsisWidth) {
            return '';
        }

        $result = '';
        $width = 0;
        $targetWidth = $maxWidth - $ellipsisWidth;
        $length = mb_strlen($text, 'UTF-8');
        for ($index = 0; $index < $length; $index++) {
            $char = mb_substr($text, $index, 1, 'UTF-8');
            $charWidth = self::measureTsplTextWidth($char, $fontSize);
            if ($width + $charWidth > $targetWidth) {
                break;
            }
            $result .= $char;
            $width += $charWidth;
        }

        return $result . $ellipsis;
    }

    /**
     * 标准字段优先，历史错误拼写仅用于读取兼容。
     *
     * @param array<string,mixed> $row
     * @param string $field
     * @return string
     */
    private static function firstRoundSteelValue(array $row, string $field): string
    {
        foreach (
            [
                'y_material_' . $field,
                'material_' . $field,
                'y_mateial_' . $field,
                $field === 'manufacturer' ? 'manufacturer' : '',
            ] as $key
        ) {
            if ($key === '') {
                continue;
            }
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
    // [AI-2026-08] 流转卡圆钢四字段统一输出结束

    private static function truncate(string $str, int $maxLen): string
    {
        if (mb_strlen($str) <= $maxLen) {
            return $str;
        }
        return mb_substr($str, 0, $maxLen - 1) . '…';
    }

    private static function escTspl(string $str): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $str);
    }

    private static function getTsplCenterX(string $str, int $fontSize = 24): int
    {
        $len = mb_strlen($str, 'UTF-8');
        $width = 0;
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            if (ord($char) > 127) {
                $width += $fontSize;
            } else {
                $width += $fontSize / 2;
            }
        }
        $x = (int) ((240 - $width) / 2);
        return max(4, $x);
    }
}
