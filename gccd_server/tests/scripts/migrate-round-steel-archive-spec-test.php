<?php

/**
 * [AI-2026-08] 圆钢档案迁移规格缺陷与集成控制流测试。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/scripts/lib/RoundSteelArchiveMigration.php';
require dirname(__DIR__, 2) . '/scripts/lib/RoundSteelArchiveWorkflow.php';

$passed = 0;
$failed = [];

/**
 * @param string $name
 * @param callable $callback
 * @return void
 */
function runRoundSteelCase(string $name, callable $callback): void
{
    global $passed, $failed;

    try {
        $callback();
        $passed++;
    } catch (Throwable $exception) {
        $failed[] = $name . ': ' . $exception->getMessage();
    }
}

/**
 * @param bool $condition
 * @param string $message
 * @return void
 * @throws RuntimeException
 */
function expectRoundSteel(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param string $path
 * @return void
 */
function removeRoundSteelTestFile(string $path): void
{
    if (is_file($path) && !unlink($path)) {
        error_log('测试文件清理失败: ' . $path);
    }
}

/**
 * @param string $path
 * @return void
 */
function removeRoundSteelTestDirectory(string $path): void
{
    if (is_dir($path) && !rmdir($path)) {
        error_log('测试目录清理失败: ' . $path);
    }
}

/**
 * @param array<string, mixed> $state
 * @return array<string, callable>
 */
function roundSteelCreateArrayStorage(array &$state): array
{
    $transactionRows = null;

    return [
        'fetch' => static function () use (&$state): array {
            $state['events'][] = 'fetch';
            return array_values($state['rows']);
        },
        'inventory' => static function () use (&$state): array {
            $state['events'][] = 'inventory';
            return $state['inventory'];
        },
        'isolation' => static function () use (&$state): void {
            $state['events'][] = 'isolation';
        },
        'begin' => static function () use (&$state, &$transactionRows): void {
            $state['events'][] = 'begin';
            $transactionRows = $state['rows'];
        },
        'lockAll' => static function () use (&$state): array {
            $state['events'][] = 'lockAll';
            return array_values($state['rows']);
        },
        'lock' => static function (int $materialId) use (&$state): ?array {
            $state['events'][] = "lock:{$materialId}";
            return $state['rows'][$materialId] ?? null;
        },
        'update' => static function (int $materialId, array $fields) use (&$state): int {
            $state['events'][] = "update:{$materialId}";
            if (($state['rows'][$materialId]['material_property'] ?? null) !== '圆钢') {
                return 0;
            }
            $safeFields = roundSteelValidateStrictFieldSet($fields, '测试存储更新');
            if (isset($state['updateTransform']) && is_callable($state['updateTransform'])) {
                $safeFields = ($state['updateTransform'])($safeFields);
            }
            foreach ($safeFields as $fieldName => $value) {
                $state['rows'][$materialId][$fieldName] = $value;
            }
            return 1;
        },
        'commit' => static function () use (&$state, &$transactionRows): void {
            $state['events'][] = 'commit';
            $transactionRows = null;
        },
        'rollback' => static function () use (&$state, &$transactionRows): void {
            $state['events'][] = 'rollback';
            if (is_array($transactionRows)) {
                $state['rows'] = $transactionRows;
            }
            $transactionRows = null;
        },
    ];
}

runRoundSteelCase('backup_preserves_database_null', static function (): void {
    $backup = roundSteelBuildBackupPayload(
        [[
            'id' => 11,
            'material_name' => '45#',
            'manufacturer' => null,
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ]],
        [[
            'id' => 11,
            'material_name' => '45#',
            'manufacturer' => '宝钢',
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ]],
        'mapping-sha256'
    );

    expectRoundSteel(
        $backup['records'][0]['before']['manufacturer'] === null,
        '数据库 NULL 被转换，无法完全回滚'
    );
});

runRoundSteelCase('backup_rejects_non_whitelisted_columns', static function (): void {
    $backup = roundSteelBuildBackupPayload(
        [[
            'id' => 11,
            'material_name' => '45#',
            'manufacturer' => null,
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ]],
        [[
            'id' => 11,
            'material_name' => '45#',
            'manufacturer' => '宝钢',
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ]],
        'mapping-sha256'
    );
    $backup['records'][0]['before']['material_property'] = '成品';
    $backup['payload_sha256'] = roundSteelPayloadChecksum($backup);

    try {
        roundSteelValidateBackupPayload($backup);
    } catch (RuntimeException $exception) {
        expectRoundSteel(
            str_contains($exception->getMessage(), '白名单'),
            '应明确报告白名单错误'
        );
        return;
    }

    throw new RuntimeException('备份 before 接受了非白名单列');
});

runRoundSteelCase('mapping_compares_raw_values_exactly', static function (): void {
    $mapping = [[
        'material_id' => 11,
        'expected' => [
            'material_name' => ' 45# ',
            'manufacturer' => null,
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ],
        'target' => [
            'material_name' => '45#',
            'manufacturer' => '宝钢',
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ],
    ]];
    $rows = [[
        'id' => 11,
        'material_name' => '45#',
        'manufacturer' => null,
        'material_spec' => 'Φ50',
        'material_model' => 'A',
    ]];

    try {
        roundSteelAnalyzeRows($rows, $mapping);
    } catch (RuntimeException $exception) {
        expectRoundSteel(
            str_contains($exception->getMessage(), '旧值不匹配'),
            '原始值不一致应阻断映射'
        );
        return;
    }

    throw new RuntimeException('首尾空格差异被 trim 后错误接受');
});

runRoundSteelCase('integration_control_flow', static function (): void {
    $state = [
        'rows' => [
            11 => [
                'id' => 11,
                'material_property' => '圆钢',
                'material_name' => '45#',
                'manufacturer' => null,
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
        ],
        'inventory' => [[
            'inventory_id' => 91,
            'material_id' => 0,
            'material_name' => '45#',
            'y_material_spec' => 'Φ50',
            'y_material_model' => 'A',
        ]],
        'events' => [],
    ];

    $storage = roundSteelCreateArrayStorage($state);
    $audit = roundSteelExecuteWorkflow([], $storage);
    expectRoundSteel($audit['mode'] === 'audit', '默认模式不是 audit');
    expectRoundSteel($state['events'] === ['fetch', 'inventory'], 'audit 发生了写操作');
    expectRoundSteel($state['rows'][11]['manufacturer'] === null, 'audit 修改了档案');
    expectRoundSteel($state['inventory'][0]['material_id'] === 0, 'audit 修改了库存快照');

    $state['events'] = [];
    $mapping = [
        11 => [
            'material_id' => 11,
            'expected' => [
                'material_name' => '45#',
                'manufacturer' => null,
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
            'target' => [
                'material_name' => '45#',
                'manufacturer' => '宝钢',
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
        ],
    ];
    $preview = roundSteelExecuteWorkflow(['mapping' => $mapping], $storage);
    expectRoundSteel($preview['mode'] === 'audit', '仅提供 mapping 不应隐式 apply');
    expectRoundSteel($state['rows'][11]['manufacturer'] === null, '未显式 apply 却更新档案');
    expectRoundSteel(
        $state['events'] === ['fetch', 'inventory'],
        '未显式 apply 却进入事务'
    );

    $state['events'] = [];
    $backupDirectory = sys_get_temp_dir() . '/round-steel-workflow-' . bin2hex(random_bytes(6));
    $applied = null;
    try {
        $applied = roundSteelExecuteWorkflow([
        'mode' => 'apply',
        'mapping' => $mapping,
        'mapping_sha256' => 'mapping-sha256',
        'backup_directory' => $backupDirectory,
    ], $storage);
    expectRoundSteel(is_file($applied['backup_path']), 'apply 未落盘备份');
    expectRoundSteel($state['rows'][11]['manufacturer'] === '宝钢', '事务未更新档案');
    expectRoundSteel(
        $state['events'] === [
            'inventory',
            'isolation',
            'begin',
            'lockAll',
            'update:11',
            'lock:11',
            'commit',
        ],
        '事务或锁定控制流顺序错误: ' . implode(',', $state['events'])
    );
    expectRoundSteel($state['inventory'][0]['material_id'] === 0, 'apply 盲改了库存候选');

    $state['events'] = [];
    $state['rows'][11]['material_property'] = '成品';
    try {
        roundSteelExecuteWorkflow([
            'mode' => 'rollback',
            'backup_path' => $applied['backup_path'],
        ], $storage);
        throw new RuntimeException('rollback 错误更新了非圆钢记录');
    } catch (RuntimeException $exception) {
        expectRoundSteel(
            str_contains($exception->getMessage(), '圆钢'),
            '非圆钢回滚阻断错误不明确'
        );
    }
    expectRoundSteel($state['rows'][11]['manufacturer'] === '宝钢', '阻断回滚仍修改了档案');

    $state['events'] = [];
    $state['rows'][11]['material_property'] = '圆钢';
    roundSteelExecuteWorkflow([
        'mode' => 'rollback',
        'backup_path' => $applied['backup_path'],
    ], $storage);
    expectRoundSteel($state['rows'][11]['manufacturer'] === null, 'rollback 未恢复数据库 NULL');
    expectRoundSteel(
        $state['events'] === [
            'isolation',
            'begin',
            'lock:11',
            'update:11',
            'lock:11',
            'commit',
        ],
        'rollback 未在事务内锁定更新'
    );
    } finally {
        $backupPath = is_array($applied) ? (string) ($applied['backup_path'] ?? '') : '';
        if ($backupPath !== '') {
            removeRoundSteelTestFile($backupPath);
        }
        removeRoundSteelTestDirectory($backupDirectory);
    }
});

runRoundSteelCase('apply_rechecks_all_rows_inside_transaction', static function (): void {
    $state = [
        'rows' => [
            11 => [
                'id' => 11,
                'material_property' => '圆钢',
                'material_name' => '45#',
                'manufacturer' => null,
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
        ],
        'inventory' => [],
        'events' => [],
    ];
    $storage = roundSteelCreateArrayStorage($state);
    $originalBegin = $storage['begin'];
    $storage['begin'] = static function () use (&$state, $originalBegin): void {
        $state['rows'][12] = [
            'id' => 12,
            'material_property' => '圆钢',
            'material_name' => '40Cr',
            'manufacturer' => null,
            'material_spec' => 'Φ60',
            'material_model' => 'B',
        ];
        $originalBegin();
    };

    $mapping = [
        11 => [
            'material_id' => 11,
            'expected' => [
                'material_name' => '45#',
                'manufacturer' => null,
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
            'target' => [
                'material_name' => '45#',
                'manufacturer' => '宝钢',
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
        ],
    ];
    $backupDirectory = sys_get_temp_dir() . '/round-steel-gate-' . bin2hex(random_bytes(6));
    $backupPath = '';

    try {
        $result = roundSteelExecuteWorkflow([
            'mode' => 'apply',
            'mapping' => $mapping,
            'mapping_sha256' => 'mapping-sha256',
            'backup_directory' => $backupDirectory,
        ], $storage);
        $backupPath = (string) ($result['backup_path'] ?? '');
        throw new RuntimeException('事务内新增的空厂商圆钢未阻断 apply');
    } catch (RuntimeException $exception) {
        expectRoundSteel(
            str_contains($exception->getMessage(), 'empty manufacturer'),
            $exception->getMessage()
        );
    } finally {
        if ($backupPath !== '' && is_file($backupPath)) {
            removeRoundSteelTestFile($backupPath);
        }
        removeRoundSteelTestDirectory($backupDirectory);
    }

    expectRoundSteel($state['rows'][11]['manufacturer'] === null, '门禁失败仍更新映射记录');
});

runRoundSteelCase('apply_backup_uses_database_reread', static function (): void {
    $state = [
        'rows' => [
            11 => [
                'id' => 11,
                'material_property' => '圆钢',
                'material_name' => '45#',
                'manufacturer' => null,
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
        ],
        'inventory' => [[
            'inventory_id' => 91,
            'material_id' => 0,
            'material_name' => '45#',
            'y_material_spec' => 'Φ50',
            'y_material_model' => 'A',
        ]],
        'events' => [],
        'updateTransform' => static function (array $fields): array {
            $fields['material_model'] = 'A-DB';
            return $fields;
        },
    ];
    $storage = roundSteelCreateArrayStorage($state);
    $backupDirectory = sys_get_temp_dir() . '/round-steel-reread-' . bin2hex(random_bytes(6));
    $result = null;
    try {
        $result = roundSteelExecuteWorkflow([
            'mode' => 'apply',
            'mapping' => [
                11 => [
                    'material_id' => 11,
                    'expected' => [
                        'material_name' => '45#',
                        'manufacturer' => null,
                        'material_spec' => 'Φ50',
                        'material_model' => 'A',
                    ],
                    'target' => [
                        'material_name' => '45#',
                        'manufacturer' => '宝钢',
                        'material_spec' => 'Φ50',
                        'material_model' => 'A',
                    ],
                ],
            ],
            'mapping_sha256' => 'mapping-sha256',
            'backup_directory' => $backupDirectory,
        ], $storage);

        $backup = roundSteelReadBackupFile($result['backup_path']);
        expectRoundSteel(
            $backup['records'][0]['after']['material_model'] === 'A-DB',
            '备份 after 未使用数据库真实回读值'
        );
        expectRoundSteel(
            $result['invalid_material_inventory']['records'][0]['candidate_material_ids'] === [],
            'apply 后库存候选未使用数据库真实新档案值'
        );
    } finally {
        $backupPath = is_array($result) ? (string) ($result['backup_path'] ?? '') : '';
        if ($backupPath !== '') {
            removeRoundSteelTestFile($backupPath);
        }
        removeRoundSteelTestDirectory($backupDirectory);
    }
});

runRoundSteelCase('apply_backup_failure_rolls_back_before_commit', static function (): void {
    $state = [
        'rows' => [
            11 => [
                'id' => 11,
                'material_property' => '圆钢',
                'material_name' => '45#',
                'manufacturer' => null,
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
        ],
        'inventory' => [],
        'events' => [],
    ];
    $storage = roundSteelCreateArrayStorage($state);
    try {
        roundSteelExecuteWorkflow([
            'mode' => 'apply',
            'mapping' => [
                11 => [
                    'material_id' => 11,
                    'expected' => [
                        'material_name' => '45#',
                        'manufacturer' => null,
                        'material_spec' => 'Φ50',
                        'material_model' => 'A',
                    ],
                    'target' => [
                        'material_name' => '45#',
                        'manufacturer' => '宝钢',
                        'material_spec' => 'Φ50',
                        'material_model' => 'A',
                    ],
                ],
            ],
            'mapping_sha256' => 'mapping-sha256',
            'backup_directory' => sys_get_temp_dir(),
            'backup_writer' => static function (): void {
                throw new RuntimeException('模拟备份落盘失败');
            },
        ], $storage);
        throw new RuntimeException('备份失败未阻断 commit');
    } catch (RuntimeException $exception) {
        expectRoundSteel(
            str_contains($exception->getMessage(), '模拟备份落盘失败'),
            $exception->getMessage()
        );
    }

    expectRoundSteel($state['rows'][11]['manufacturer'] === null, '备份失败未回滚数据库更新');
    expectRoundSteel(!in_array('commit', $state['events'], true), '备份失败后仍提交事务');
});

runRoundSteelCase('rollback_validates_backup_before_begin', static function (): void {
    $state = ['rows' => [], 'inventory' => [], 'events' => []];
    $storage = roundSteelCreateArrayStorage($state);
    $invalidPath = sys_get_temp_dir() . '/round-steel-invalid-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($invalidPath, '{"format":"invalid"}');
    try {
        roundSteelExecuteWorkflow([
            'mode' => 'rollback',
            'backup_path' => $invalidPath,
        ], $storage);
        throw new RuntimeException('无效备份未被拒绝');
    } catch (Throwable $exception) {
        expectRoundSteel($state['events'] === [], '备份验签前已开启事务');
    } finally {
        removeRoundSteelTestFile($invalidPath);
    }
});

runRoundSteelCase('rollback_verifies_database_reread', static function (): void {
    $backupDirectory = sys_get_temp_dir() . '/round-steel-rollback-' . bin2hex(random_bytes(6));
    mkdir($backupDirectory, 0750, true);
    $backupPath = $backupDirectory . '/backup.json';
    $payload = roundSteelBuildBackupPayload(
        [[
            'id' => 11,
            'material_name' => '45#',
            'manufacturer' => null,
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ]],
        [[
            'id' => 11,
            'material_name' => '45#',
            'manufacturer' => '宝钢',
            'material_spec' => 'Φ50',
            'material_model' => 'A',
        ]],
        'mapping-sha256'
    );
    roundSteelWriteJsonFile($backupPath, $payload);

    $state = [
        'rows' => [
            11 => [
                'id' => 11,
                'material_property' => '圆钢',
                'material_name' => '45#',
                'manufacturer' => '宝钢',
                'material_spec' => 'Φ50',
                'material_model' => 'A',
            ],
        ],
        'inventory' => [],
        'events' => [],
        'updateTransform' => static function (array $fields): array {
            $fields['manufacturer'] = '数据库异常值';
            return $fields;
        },
    ];
    $storage = roundSteelCreateArrayStorage($state);
    try {
        roundSteelExecuteWorkflow([
            'mode' => 'rollback',
            'backup_path' => $backupPath,
        ], $storage);
        throw new RuntimeException('rollback 未校验数据库真实回读值');
    } catch (RuntimeException $exception) {
        expectRoundSteel(
            str_contains($exception->getMessage(), '回读'),
            $exception->getMessage()
        );
    } finally {
        removeRoundSteelTestFile($backupPath);
        removeRoundSteelTestDirectory($backupDirectory);
    }

    expectRoundSteel($state['rows'][11]['manufacturer'] === '宝钢', '回读异常后未回滚');
});

runRoundSteelCase('json_files_reject_over_10mb', static function (): void {
    $largePath = sys_get_temp_dir() . '/round-steel-large-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($largePath, str_repeat(' ', 10 * 1024 * 1024 + 1));
    try {
        roundSteelLoadMappingFile($largePath);
        throw new RuntimeException('超限映射文件未被拒绝');
    } catch (RuntimeException $exception) {
        expectRoundSteel(str_contains($exception->getMessage(), '10MB'), '映射超限错误不明确');
    }
    try {
        roundSteelReadBackupFile($largePath);
        throw new RuntimeException('超限备份文件未被拒绝');
    } catch (RuntimeException $exception) {
        expectRoundSteel(str_contains($exception->getMessage(), '10MB'), '备份超限错误不明确');
    } finally {
        removeRoundSteelTestFile($largePath);
    }
});

if ($failed !== []) {
    fwrite(STDERR, "FAIL " . count($failed) . " cases\n- " . implode("\n- ", $failed) . PHP_EOL);
    exit(1);
}

echo "PASS {$passed} cases\n";
