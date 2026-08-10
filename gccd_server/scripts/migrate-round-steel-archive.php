<?php

/**
 * [AI-2026-08] 圆钢档案四字段审计、显式映射、备份、应用与回滚 CLI。
 *
 * 默认仅审计：
 *   php gccd_server/scripts/migrate-round-steel-archive.php
 * 预演映射：
 *   php gccd_server/scripts/migrate-round-steel-archive.php --mapping=/path/map.json
 * 正式应用：
 *   php gccd_server/scripts/migrate-round-steel-archive.php --apply --mapping=/path/map.json
 * 显式回滚：
 *   php gccd_server/scripts/migrate-round-steel-archive.php --rollback=/path/backup.json
 */

declare(strict_types=1);

use think\facade\Db;

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/lib/RoundSteelArchiveMigration.php';
require __DIR__ . '/lib/RoundSteelArchiveWorkflow.php';

$app = new think\App(dirname(__DIR__));
$app->initialize();

/**
 * @return array<int, array<string, mixed>>
 */
function fetchRoundSteelArchiveRows(): array
{
    return Db::name('material')
        ->where('material_property', '圆钢')
        ->where('is_delete', 0)
        ->field('id,material_property,material_name,manufacturer,material_spec,material_model')
        ->order('id', 'asc')
        ->select()
        ->toArray();
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchInvalidRoundSteelInventoryRows(): array
{
    return Db::name('material_inventory')
        ->alias('mi')
        ->leftJoin('material m', 'mi.material_id = m.id')
        ->where('mi.is_delete', 0)
        ->where('mi.process_name', '圆钢')
        ->where(static function ($query): void {
            $query->whereNull('m.id')
                ->whereOr('m.is_delete', '<>', 0)
                ->whereOr('m.material_property', '<>', '圆钢');
        })
        ->field(
            'mi.id AS inventory_id,mi.material_id,mi.material_name,'
            . 'mi.y_material_spec,mi.y_material_model'
        )
        ->order('mi.id', 'asc')
        ->select()
        ->toArray();
}

/**
 * @return array<string, callable>
 */
function createRoundSteelDbStorage(): array
{
    return [
        'fetch' => static fn (): array => fetchRoundSteelArchiveRows(),
        'inventory' => static fn (): array => fetchInvalidRoundSteelInventoryRows(),
        'isolation' => static function (): void {
            // [AI-2026-08] MySQL 5.5 兼容语法；显式 RR 让 FOR UPDATE 的 next-key lock
            // 锁住圆钢查询范围，防止门禁分析后并发插入形成幻读。
            Db::execute('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        },
        'begin' => static function (): void {
            Db::startTrans();
        },
        'lockAll' => static function (): array {
            return Db::name('material')
                ->where('material_property', '圆钢')
                ->where('is_delete', 0)
                ->field(
                    'id,material_property,material_name,manufacturer,material_spec,material_model'
                )
                ->order('id', 'asc')
                ->lock(true)
                ->select()
                ->toArray();
        },
        'lock' => static function (int $materialId): ?array {
            $row = Db::name('material')
                ->where('id', $materialId)
                ->where('material_property', '圆钢')
                ->where('is_delete', 0)
                ->lock(true)
                ->field(
                    'id,material_property,material_name,manufacturer,material_spec,material_model'
                )
                ->find();

            return is_array($row) ? $row : null;
        },
        'update' => static function (int $materialId, array $fields): int {
            $safeFields = roundSteelValidateStrictFieldSet(
                $fields,
                "数据库更新 material_id={$materialId}"
            );
            $databaseFields = [];
            foreach ($safeFields as $fieldName => $value) {
                // [AI-2026-08] 显式 SET NULL，确保回滚不会受 ORM 空值过滤配置影响。
                $databaseFields[$fieldName] = $value === null ? Db::raw('NULL') : $value;
            }

            return Db::name('material')
                ->where('id', $materialId)
                ->where('material_property', '圆钢')
                ->where('is_delete', 0)
                ->update($databaseFields);
        },
        'commit' => static function (): void {
            Db::commit();
        },
        'rollback' => static function (): void {
            Db::rollback();
        },
    ];
}

/**
 * @param array<string, mixed> $normalizedMapping
 * @param string $mappingPath
 * @param string $backupDirectory
 * @return array<string, mixed>
 * @throws Throwable
 */
function applyRoundSteelMapping(
    array $normalizedMapping,
    string $mappingPath,
    string $backupDirectory
): array {
    $mappingSha256 = hash_file('sha256', $mappingPath);
    if ($mappingSha256 === false) {
        throw new RuntimeException('无法计算映射文件校验和');
    }

    $result = roundSteelExecuteWorkflow([
        'mode' => 'apply',
        'mapping' => $normalizedMapping,
        'mapping_sha256' => $mappingSha256,
        'backup_directory' => $backupDirectory,
    ], createRoundSteelDbStorage());

    return $result;
}

/**
 * @param string $backupPath
 * @return array{restored:int,backup_path:string}
 * @throws Throwable
 */
function rollbackRoundSteelBackup(string $backupPath): array
{
    $result = roundSteelExecuteWorkflow([
        'mode' => 'rollback',
        'backup_path' => $backupPath,
    ], createRoundSteelDbStorage());

    return [
        'restored' => (int) $result['restored'],
        'backup_path' => (string) $result['backup_path'],
    ];
}

try {
    $options = getopt('', ['apply', 'mapping:', 'rollback:', 'backup-dir:', 'report:']);
    if ($options === false) {
        throw new RuntimeException('CLI 参数解析失败');
    }

    $apply = array_key_exists('apply', $options);
    $rollbackPath = isset($options['rollback']) ? trim((string) $options['rollback']) : '';
    if ($apply && $rollbackPath !== '') {
        throw new RuntimeException('--apply 与 --rollback 不能同时使用');
    }

    if ($rollbackPath !== '') {
        $result = rollbackRoundSteelBackup($rollbackPath);
        echo json_encode(
            ['mode' => 'rollback', 'material_property' => '圆钢'] + $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit(0);
    }

    $mappingPath = isset($options['mapping']) ? trim((string) $options['mapping']) : '';
    if ($apply && $mappingPath === '') {
        throw new RuntimeException('--apply 必须同时提供 --mapping=<显式映射文件>');
    }
    $normalizedMapping = $mappingPath === '' ? [] : roundSteelLoadMappingFile($mappingPath);

    if ($apply) {
        $backupDirectory = isset($options['backup-dir'])
            ? trim((string) $options['backup-dir'])
            : runtime_path() . 'round-steel-archive-backups';
        if ($backupDirectory === '') {
            throw new RuntimeException('--backup-dir 不能为空');
        }
        $output = applyRoundSteelMapping($normalizedMapping, $mappingPath, $backupDirectory);
    } else {
        $output = roundSteelExecuteWorkflow([
            'mode' => 'audit',
            'mapping' => $normalizedMapping,
        ], createRoundSteelDbStorage());
    }

    $reportPath = isset($options['report']) ? trim((string) $options['report']) : '';
    if ($reportPath !== '') {
        roundSteelWriteJsonFile($reportPath, $output);
        $output['report_path'] = $reportPath;
    }

    echo json_encode(
        $output,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $exception) {
    error_log('[round-steel-archive] ' . $exception->getMessage());
    fwrite(STDERR, '执行失败: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
