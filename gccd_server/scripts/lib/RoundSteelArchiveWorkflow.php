<?php

/**
 * [AI-2026-08] 圆钢档案迁移可注入存储工作流。
 */

declare(strict_types=1);

/**
 * @param array<string, callable> $storage
 * @return void
 * @throws RuntimeException
 */
function roundSteelValidateStorage(array $storage): void
{
    foreach (
        [
            'fetch',
            'inventory',
            'isolation',
            'begin',
            'lockAll',
            'lock',
            'update',
            'commit',
            'rollback',
        ]
        as $operation
    ) {
        if (!isset($storage[$operation]) || !is_callable($storage[$operation])) {
            throw new RuntimeException("存储接口缺少 {$operation}");
        }
    }
}

/**
 * @param string $path
 * @param array<string, mixed> $payload
 * @return void
 * @throws RuntimeException
 */
function roundSteelWriteJsonFile(string $path, array $payload): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('无法创建目录: ' . $directory);
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    );
    if (strlen($json) > roundSteelMaxJsonBytes()) {
        throw new RuntimeException('JSON 文件超过 10MB 上限: ' . $path);
    }
    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('无法写入临时 JSON 文件: ' . $temporaryPath);
    }
    if (!chmod($temporaryPath, 0640)) {
        error_log('[round-steel-archive] 无法设置 JSON 文件权限: ' . $temporaryPath);
    }
    if (!rename($temporaryPath, $path)) {
        $cleanupError = '';
        if (is_file($temporaryPath) && !unlink($temporaryPath)) {
            $cleanupError = '；且临时文件清理失败: ' . $temporaryPath;
            error_log('[round-steel-archive] ' . $cleanupError);
        }
        throw new RuntimeException('无法原子写入 JSON 文件: ' . $path . $cleanupError);
    }
}

/**
 * @param string $path
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function roundSteelReadBackupFile(string $path): array
{
    $json = roundSteelReadLimitedJson($path, '回滚备份');
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('回滚备份必须为 JSON 对象');
    }
    roundSteelValidateBackupPayload($payload);

    return $payload;
}

/**
 * @param array<string, mixed> $analysis
 * @param array<int, array<string, mixed>> $inventoryCandidates
 * @param string $mode
 * @return array<string, mixed>
 */
function roundSteelBuildWorkflowOutput(
    array $analysis,
    array $inventoryCandidates,
    string $mode
): array {
    return [
        'mode' => $mode,
        'material_property' => '圆钢',
        'total' => (int) $analysis['total'],
        'empty_manufacturer' => [
            'count' => count($analysis['empty_manufacturer_ids']),
            'material_ids' => $analysis['empty_manufacturer_ids'],
        ],
        'mixed_material_candidates' => [
            'count' => count($analysis['mixed_material_candidate_ids']),
            'material_ids' => $analysis['mixed_material_candidate_ids'],
        ],
        'names_with_separator' => [
            'count' => count($analysis['separator_name_ids']),
            'material_ids' => $analysis['separator_name_ids'],
        ],
        'cleaned_four_field_conflicts' => [
            'count' => count($analysis['conflict_ids']),
            'material_ids' => $analysis['conflict_ids'],
        ],
        'unresolved_records' => [
            'count' => count($analysis['unresolved_ids']),
            'material_ids' => $analysis['unresolved_ids'],
        ],
        'affected_material_ids' => $analysis['affected_material_ids'],
        'invalid_material_inventory' => [
            'count' => count($inventoryCandidates),
            'records' => $inventoryCandidates,
            'action' => 'audit_only_no_automatic_update',
        ],
    ];
}

/**
 * @param array<string, mixed> $request
 * @param array<string, callable> $storage
 * @return array<string, mixed>
 * @throws Throwable
 */
function roundSteelExecuteWorkflow(array $request, array $storage): array
{
    roundSteelValidateStorage($storage);
    $mode = (string) ($request['mode'] ?? 'audit');
    if (!in_array($mode, ['audit', 'apply', 'rollback'], true)) {
        throw new RuntimeException('不支持的执行模式: ' . $mode);
    }

    if ($mode === 'rollback') {
        return roundSteelExecuteRollback($request, $storage);
    }

    $mappingInput = $request['mapping'] ?? [];
    if (!is_array($mappingInput)) {
        throw new RuntimeException('mapping 必须为数组');
    }
    if ($mode === 'audit') {
        $rows = ($storage['fetch'])();
        $inventoryRows = ($storage['inventory'])();
        if (!is_array($rows) || !is_array($inventoryRows)) {
            throw new RuntimeException('存储接口返回格式无效');
        }
        $analysis = roundSteelAnalyzeRows($rows, $mappingInput);
        return roundSteelBuildWorkflowOutput(
            $analysis,
            roundSteelBuildInventoryCandidates($inventoryRows, $rows),
            $mode
        );
    }

    $inventoryRows = ($storage['inventory'])();
    if (!is_array($inventoryRows)) {
        throw new RuntimeException('存储接口返回格式无效');
    }
    $applyResult = roundSteelExecuteApply($request, $storage);
    $transactionAnalysis = $applyResult['analysis'];
    $transactionRows = $applyResult['rows'];
    unset($applyResult['analysis'], $applyResult['rows']);

    return roundSteelBuildWorkflowOutput(
        $transactionAnalysis,
        roundSteelBuildInventoryCandidates($inventoryRows, $transactionRows),
        $mode
    ) + $applyResult;
}

/**
 * @param array<string, mixed> $request
 * @param array<string, callable> $storage
 * @return array{
 *     backup_path:string,
 *     updated:int,
 *     analysis:array<string,mixed>,
 *     rows:array<int,array<string,mixed>>
 * }
 * @throws Throwable
 */
function roundSteelExecuteApply(
    array $request,
    array $storage
): array {
    $backupDirectory = trim((string) ($request['backup_directory'] ?? ''));
    $mappingSha256 = trim((string) ($request['mapping_sha256'] ?? ''));
    if ($backupDirectory === '' || $mappingSha256 === '') {
        throw new RuntimeException('apply 缺少 backup_directory 或 mapping_sha256');
    }

    $mappingInput = $request['mapping'] ?? [];
    if (!is_array($mappingInput)) {
        throw new RuntimeException('mapping 必须为数组');
    }
    $backupWriter = $request['backup_writer'] ?? static function (
        string $path,
        array $payload
    ): void {
        roundSteelWriteJsonFile($path, $payload);
    };
    if (!is_callable($backupWriter)) {
        throw new RuntimeException('backup_writer 必须可调用');
    }
    $pendingBackupPath = '';
    $finalBackupPath = '';
    $commitAttempted = false;
    ($storage['isolation'])();
    ($storage['begin'])();
    try {
        $lockedRows = ($storage['lockAll'])();
        if (!is_array($lockedRows)) {
            throw new RuntimeException('事务内圆钢档案读取格式无效');
        }
        $analysis = roundSteelAnalyzeRows($lockedRows, $mappingInput);
        roundSteelAssertApplyAllowed($analysis);
        $normalizedMapping = $analysis['normalized_mapping'];
        $affectedIds = $analysis['affected_material_ids'];
        if ($affectedIds === []) {
            $commitAttempted = true;
            ($storage['commit'])();
            return [
                'backup_path' => '',
                'updated' => 0,
                'analysis' => $analysis,
                'rows' => $lockedRows,
            ];
        }

        $rowsById = [];
        foreach ($lockedRows as $row) {
            $rowsById[(int) ($row['id'] ?? 0)] = $row;
        }
        $beforeRows = [];
        foreach ($affectedIds as $materialId) {
            $current = $rowsById[$materialId] ?? null;
            if (!is_array($current) || ($current['material_property'] ?? null) !== '圆钢') {
                throw new RuntimeException("material_id={$materialId} 不再是有效圆钢档案");
            }
            if (roundSteelRawFourFields($current) !== $normalizedMapping[$materialId]['expected']) {
                throw new RuntimeException("material_id={$materialId} 原始值已变化，拒绝应用旧映射");
            }

            $beforeRows[] = $current;
        }

        $finalBackupPath = rtrim($backupDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'round-steel-archive-'
            . date('Ymd-His')
            . '-'
            . bin2hex(random_bytes(4))
            . '.json';
        $pendingBackupPath = $finalBackupPath . '.pending';
        $updated = 0;
        $afterRows = [];
        foreach ($affectedIds as $materialId) {
            $target = roundSteelValidateStrictFieldSet(
                $normalizedMapping[$materialId]['target'],
                "更新 material_id={$materialId}"
            );
            $affected = ($storage['update'])((int) $materialId, $target);
            if ($affected !== 1) {
                throw new RuntimeException("material_id={$materialId} 更新行数异常: {$affected}");
            }

            $actual = ($storage['lock'])((int) $materialId);
            if (!is_array($actual) || ($actual['material_property'] ?? null) !== '圆钢') {
                throw new RuntimeException("material_id={$materialId} 更新后无法回读");
            }
            $afterRows[] = $actual;
            $rowsById[$materialId] = $actual;
            $updated++;
        }

        $backupPayload = roundSteelBuildBackupPayload(
            $beforeRows,
            $afterRows,
            $mappingSha256
        );
        $backupWriter($pendingBackupPath, $backupPayload);
        $commitAttempted = true;
        ($storage['commit'])();
    } catch (Throwable $exception) {
        try {
            ($storage['rollback'])();
        } catch (Throwable $rollbackException) {
            error_log('[round-steel-archive] rollback 失败: ' . $rollbackException->getMessage());
        }
        if ($commitAttempted && $pendingBackupPath !== '' && is_file($pendingBackupPath)) {
            error_log(
                '[round-steel-archive] commit 结果不确定，保留 pending 备份: '
                . $pendingBackupPath
            );
        } elseif ($pendingBackupPath !== ''
            && is_file($pendingBackupPath)
            && !unlink($pendingBackupPath)
        ) {
            error_log('[round-steel-archive] 无法删除未应用备份: ' . $pendingBackupPath);
        }
        throw $exception;
    }

    $backupPath = $pendingBackupPath;
    if (rename($pendingBackupPath, $finalBackupPath)) {
        $backupPath = $finalBackupPath;
    } else {
        error_log('[round-steel-archive] 备份已生成但无法移除 pending 后缀: ' . $pendingBackupPath);
    }

    return [
        'backup_path' => $backupPath,
        'updated' => $updated,
        'analysis' => $analysis,
        'rows' => array_values($rowsById),
    ];
}

/**
 * @param array<string, mixed> $request
 * @param array<string, callable> $storage
 * @return array{mode:string,material_property:string,restored:int,backup_path:string}
 * @throws Throwable
 */
function roundSteelExecuteRollback(array $request, array $storage): array
{
    $backupPath = trim((string) ($request['backup_path'] ?? ''));
    if ($backupPath === '') {
        throw new RuntimeException('rollback 缺少 backup_path');
    }

    $payload = roundSteelReadBackupFile($backupPath);
    ($storage['isolation'])();
    ($storage['begin'])();
    try {
        $restored = 0;
        foreach ($payload['records'] as $record) {
            $materialId = (int) $record['material_id'];
            $current = ($storage['lock'])($materialId);
            if (!is_array($current) || ($current['material_property'] ?? null) !== '圆钢') {
                throw new RuntimeException("回滚 material_id={$materialId} 不再是圆钢档案");
            }
            if (roundSteelRawFourFields($current) !== $record['after']) {
                throw new RuntimeException("回滚 material_id={$materialId} 当前原始值与备份不一致");
            }

            $before = roundSteelValidateStrictFieldSet(
                $record['before'],
                "回滚 material_id={$materialId}"
            );
            $affected = ($storage['update'])($materialId, $before);
            if ($affected !== 1) {
                throw new RuntimeException("回滚 material_id={$materialId} 更新行数异常: {$affected}");
            }
            $actual = ($storage['lock'])($materialId);
            if (!is_array($actual)
                || ($actual['material_property'] ?? null) !== '圆钢'
                || roundSteelRawFourFields($actual) !== $before
            ) {
                throw new RuntimeException("回滚 material_id={$materialId} 数据库回读校验失败");
            }
            $restored++;
        }
        ($storage['commit'])();
    } catch (Throwable $exception) {
        try {
            ($storage['rollback'])();
        } catch (Throwable $rollbackException) {
            error_log('[round-steel-archive] rollback 失败: ' . $rollbackException->getMessage());
        }
        throw $exception;
    }

    return [
        'mode' => 'rollback',
        'material_property' => '圆钢',
        'restored' => $restored,
        'backup_path' => $backupPath,
    ];
}
