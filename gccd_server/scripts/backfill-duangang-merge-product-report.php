<?php

/**
 * 断钢合并报工 → product_report 镜像历史回填
 *
 * 用法：
 *   php gccd_server/scripts/backfill-duangang-merge-product-report.php --dry-run
 *   php gccd_server/scripts/backfill-duangang-merge-product-report.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new think\App(dirname(__DIR__));
$app->initialize();

$dryRun = in_array('--dry-run', $argv ?? [], true);

$result = \app\common\service\admin\DuangangMergeReportMirror::backfillMissing($dryRun);

echo json_encode($result + ['dry_run' => $dryRun], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (!$dryRun && (int) ($result['failed'] ?? 0) > 0) {
    exit(1);
}
