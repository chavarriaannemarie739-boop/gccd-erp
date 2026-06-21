<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require __DIR__ . '/vendor/autoload.php';

// 数据库配置
$mysql = [
    'hostname' => '42.192.67.226', 'hostport' => '13306',
    'database' => 'gxgccd', 'username' => 'gxgccd', 'password' => 'gxgccd@123',
];
try {
    $pdo = new PDO('mysql:host=' . $mysql['hostname'] . ';port=' . $mysql['hostport'] . ';dbname=' . $mysql['database'] . ';charset=utf8mb4', $mysql['username'], $mysql['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "数据库连接成功\n\n";
} catch (Exception $e) {
    die("数据库连接失败: " . $e->getMessage() . "\n");
}

$file = '/Users/lizengrui/Documents/高齿传动项目代码/5月13日溯源库存明细.xlsx';
require __DIR__ . '/vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/IOFactory.php';

$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();
$data = $sheet->toArray();

echo "读取完成，共 " . count($data) . " 行\n\n";

// 工序映射
$processMap = [
    '圆钢' => '圆钢',
    '断钢' => '断钢', '断钢待穿孔' => '断钢',
    '穿孔' => '穿孔', '穿孔待球化' => '穿孔',
    '球化' => '球化', '球化待矫直' => '球化',
    '矫直' => '矫直', '矫直待酸洗' => '矫直', '矫直待' => '矫直',
    '酸洗' => '酸洗', '酸洗成品' => '酸洗',
    '次成品' => '次成品',
    '半成品夹灰' => '半成品', '半成品翘皮' => '半成品',
];

// 解析品种：如 "55宣钢" → spec=宣钢, model=55, name="宣钢｜55"
function parseVariety($b) {
    $b = trim($b ?? '');
    if (!$b) return null;
    if ($b === '退回带油管' || $b === '退回成品翘皮' || $b === '成品夹灰' || $b === '半成品夹灰' || $b === '半成品翘皮') {
        return ['name' => $b, 'spec' => '', 'model' => ''];
    }
    // 数字 + 汉字 拆分
    if (preg_match('/^(\d+)(\D+)$/u', $b, $m)) {
        $model = $m[1];    // 55
        $spec = trim($m[2]);  // 宣钢
        return ['name' => $spec . '｜' . $model, 'spec' => $spec, 'model' => $model];
    }
    // 无法拆分则原样保存
    return ['name' => $b, 'spec' => $b, 'model' => ''];
}

$records = [];
$currentProcess = '';
$totalRows = 0;
$skipReasons = [];

foreach ($data as $idx => $row) {
    if ($idx < 2) continue; // 跳过标题行

    $a = trim($row[0] ?? '');
    $b = trim($row[1] ?? '');
    $c = trim($row[2] ?? '');
    $d = trim($row[3] ?? ''); // 上月库存

    // 跳过总结行
    if (in_array($b, ['品种', ''])) {
        if ($a === '总计：' || $a === '合计') continue;
        if (!$a && !$b) continue;
    }

    // 追踪当前工序
    if ($a) {
        $a = str_replace(["\n", "\r", ' '], '', $a);
        $currentProcess = $a;
    }
    $mappedProcess = $processMap[$currentProcess] ?? $currentProcess;

    // 解析品种
    $variety = parseVariety($b);
    if (!$variety) {
        $skipReasons[] = "R" . ($idx+1) . ": 品种为空";
        continue;
    }

    $beginQty = floatval($d);
    if ($beginQty <= 0) {
        // 上月库存为0的跳过
        continue;
    }

    $records[] = [
        'inventory_date' => '2026-04-30',
        'process_name' => $mappedProcess,
        'material_name' => $variety['name'],
        'y_material_spec' => $variety['spec'],
        'y_material_model' => $variety['model'],
        // c_material_spec: 圆钢为空，断钢+为C列（成品规格/型号）
        'c_material_spec' => ($mappedProcess === '圆钢' || $mappedProcess === '次成品' || $mappedProcess === '半成品') ? '' : $c,
        'total_tonnage' => round($beginQty, 3),
        'source' => 'import',
        'confirmed' => 1,
        'create_time' => date('Y-m-d H:i:s'),
        'create_uid' => 0,
    ];
    $totalRows++;
}

echo "解析结果:\n";
$procCount = [];
foreach ($records as $r) {
    $p = $r['process_name'];
    $procCount[$p] = ($procCount[$p] ?? 0) + 1;
}
foreach ($procCount as $p => $n) {
    echo "  {$p}: {$n} 条\n";
}
echo "\n合计: {$totalRows} 条\n\n";

echo "预览前10条:\n";
for ($i = 0; $i < min(10, $totalRows); $i++) {
    $r = $records[$i];
    printf("  %s | %s | y_spec=%s | y_model=%s | c_spec=%s | %.3f t\n",
        $r['process_name'], $r['material_name'],
        $r['y_material_spec'], $r['y_material_model'],
        $r['c_material_spec'] ?: '-',
        $r['total_tonnage']
    );
}

echo "\n插入数据库...\n";
$inventoryDate = '2026-04-30';

// 先删旧的
$stmt = $pdo->prepare("DELETE FROM ya_material_inventory WHERE inventory_date = ? AND source = 'import' AND process_name != '半成品'");
$stmt->execute([$inventoryDate]);
$deleted = $stmt->rowCount();
echo "已删除 {$deleted} 条旧import记录\n";

$now = date('Y-m-d H:i:s');
$inserted = 0;
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "INSERT INTO ya_material_inventory
        (inventory_code, inventory_date, process_name, material_name, y_material_spec, y_material_model, c_material_spec, total_tonnage, source, confirmed, create_time, create_uid)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'import', 1, ?, 0)"
    );

    foreach ($records as $i => $r) {
        $code = 'IMPORT' . date('Ymd') . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);
        $stmt->execute([
            $code, $inventoryDate,
            $r['process_name'], $r['material_name'],
            $r['y_material_spec'], $r['y_material_model'],
            $r['c_material_spec'], $r['total_tonnage'],
            $now,
        ]);
        $inserted++;
    }
    $pdo->commit();
    echo "✅ 成功导入 {$inserted} 条记录到 {$inventoryDate}\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ 导入失败: " . $e->getMessage() . "\n";
    if (isset($records[$inserted])) {
        echo "出错数据: " . json_encode($records[$inserted], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
