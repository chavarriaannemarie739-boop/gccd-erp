<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$pdo = new PDO('mysql:host=42.192.67.226;port=13306;dbname=gxgccd;charset=utf8mb4', 'gxgccd', 'gxgccd@123', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// 查导入的期初库存
$imports = $pdo->query("SELECT id, material_name, y_material_spec, y_material_model, process_name, material_id FROM ya_material_inventory WHERE inventory_date='2026-04-30' AND source='import' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

echo "共 " . count($imports) . " 条导入记录\n\n";

$updated = 0;
$notFound = 0;

foreach ($imports as $row) {
    $id = $row['id'];
    $spec = $row['y_material_spec'];
    $model = $row['y_material_model'];
    $matName = $row['material_name'];

    if (!$spec && !$model) {
        echo "  跳过 ID={$id}: 无spec/model\n";
        continue;
    }

    // 在material表按 material_spec + material_model 匹配
    // 先尝试精确匹配
    $stmt = $pdo->prepare("SELECT id, material_name FROM ya_material WHERE material_spec = ? AND material_model = ? AND material_property = '圆钢' LIMIT 1");
    $stmt->execute([$spec, $model]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($match) {
        $pdo->prepare("UPDATE ya_material_inventory SET material_id = ? WHERE id = ?")->execute([$match['id'], $id]);
        printf("  ✅ ID=%d | %s → material_id=%d (%s)\n", $id, $matName, $match['id'], $match['material_name']);
        $updated++;
    } else {
        printf("  ❌ ID=%d | %s (spec=%s, model=%s) 未匹配\n", $id, $matName, $spec, $model);
        $notFound++;
    }
}

echo "\n结果: {$updated} 条已更新, {$notFound} 条未匹配\n";
