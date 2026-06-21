<?php
namespace app;
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/vendor/autoload.php';
$http = (new \think\App())->http;
$response = $http->run();

use think\facade\Db;

$sql = "
    SELECT 
        p.production_plan_code as plan_code,
        COALESCE(SUM(pr.report_num), 0) as in_num,
        (SELECT COALESCE(SUM(material_num), 0) FROM ya_ship WHERE relation_code = p.production_plan_code AND is_delete = 0) as shipped_num
    FROM ya_production p
    LEFT JOIN ya_product_task pt ON pt.relation_code = p.production_plan_code AND pt.is_delete = 0
    LEFT JOIN ya_product_report pr ON pr.relation_code = pt.task_code 
        AND pr.processes_code = 'gx_0014' 
        AND pr.is_delete = 0
    WHERE p.is_delete = 0
    GROUP BY p.production_plan_code, p.english_material
    HAVING (in_num - shipped_num) > 0
    ORDER BY p.create_time DESC
    LIMIT 5
";

try {
    $rows = Db::query($sql);
    echo "Query Succeeded! Rows:\n";
    print_r($rows);
} catch (\Exception $e) {
    echo "Query Failed! Error: " . $e->getMessage() . "\n";
}
