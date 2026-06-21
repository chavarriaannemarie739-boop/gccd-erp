<?php

/**
 * 第一步部署：执行断钢合并 + 穿孔设备建表 SQL，并重建合并池
 * 用法: php gccd_server/scripts/run-duangang-migration.php
 */
declare(strict_types=1);

$serverRoot = dirname(__DIR__);
require $serverRoot . '/vendor/autoload.php';

$app = new think\App($serverRoot);
$app->initialize();

use app\common\service\admin\DuangangMergeService;
use think\facade\Db;

$sqlFile = $serverRoot . '/scripts/add-duangang-merge-and-punch-device.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "SQL file missing: {$sqlFile}\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
// 去掉整行 -- 注释，避免首条 CREATE 因前置注释被误判跳过
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$statements = array_values(array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))));

echo "Database: " . env('database.database', '') . '@' . env('database.hostname', '') . "\n";
echo "Running " . count($statements) . " SQL statement(s)...\n";

foreach ($statements as $stmt) {
    if ($stmt === '') {
        continue;
    }
    try {
        Db::execute($stmt);
        if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $stmt, $m)) {
            echo "OK  table {$m[1]}\n";
        } else {
            echo "OK  statement\n";
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "Rebuilding duangang merge pools...\n";
try {
    DuangangMergeService::rebuildAll();
    $active = (int) Db::name('duangang_merge_group')->where('is_delete', 0)->where('status', 0)->count();
    echo "Done. Active merge groups: {$active}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Rebuild warning: {$e->getMessage()}\n");
    exit(1);
}

exit(0);
