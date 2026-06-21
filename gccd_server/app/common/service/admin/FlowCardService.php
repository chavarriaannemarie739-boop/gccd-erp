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
                'y_material_spec' => (string) ($row['material_spec'] ?? ''),
                'y_material_model' => (string) ($row['material_model'] ?? ''),
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

        return [
            'production_plan_name' => (string) ($plan['production_plan_name'] ?? ''),
            'y_material_spec' => (string) ($detailRow['material_spec'] ?? ''),
            'y_material_model' => (string) ($detailRow['material_model'] ?? ''),
            'c_material_spec' => (string) ($plan['material_spec'] ?? ''),
            'c_material_model' => (string) ($plan['material_model'] ?? ''),
            'plan_num' => (float) ($detailRow['plan_num'] ?? 0),
            'device_name' => $deviceName,
            'qr_payload' => $payload,
            'qr_image' => $qrBase64,
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
            'y_material_spec' => (string) ($detailRow['material_spec'] ?? ''),
            'y_material_model' => (string) ($detailRow['material_model'] ?? ''),
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
        $qr = QrCode::create($payload)->setSize(120)->setMargin(4);
        $writer = new PngWriter();
        $result = $writer->write($qr);

        return 'data:image/png;base64,' . base64_encode($result->getString());
    }
}
