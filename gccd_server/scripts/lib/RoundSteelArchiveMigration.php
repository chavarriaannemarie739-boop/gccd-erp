<?php

/**
 * [AI-2026-08] 圆钢档案审计、映射、备份及回滚纯函数边界。
 */

declare(strict_types=1);

/**
 * @return array<int, string>
 */
function roundSteelAllowedFieldNames(): array
{
    return ['material_name', 'manufacturer', 'material_spec', 'material_model'];
}

/**
 * @param array<string, mixed> $fields
 * @param string $context
 * @return array{material_name:?string,manufacturer:?string,material_spec:?string,material_model:?string}
 * @throws RuntimeException
 */
function roundSteelValidateStrictFieldSet(array $fields, string $context): array
{
    $allowed = roundSteelAllowedFieldNames();
    $actual = array_keys($fields);
    sort($allowed);
    sort($actual);
    if ($actual !== $allowed) {
        throw new RuntimeException($context . ' 仅允许四字段白名单');
    }

    $validated = [];
    foreach (roundSteelAllowedFieldNames() as $fieldName) {
        $value = $fields[$fieldName];
        if (!is_string($value) && $value !== null) {
            throw new RuntimeException($context . ".{$fieldName} 仅允许字符串或 NULL");
        }
        $validated[$fieldName] = $value;
    }

    return $validated;
}

/**
 * @param array<string, mixed> $row
 * @return array{material_name:?string,manufacturer:?string,material_spec:?string,material_model:?string}
 */
function roundSteelRawFourFields(array $row): array
{
    return [
        'material_name' => array_key_exists('material_name', $row)
            ? ($row['material_name'] === null ? null : (string) $row['material_name'])
            : null,
        'manufacturer' => array_key_exists('manufacturer', $row)
            ? ($row['manufacturer'] === null ? null : (string) $row['manufacturer'])
            : null,
        'material_spec' => array_key_exists('material_spec', $row)
            ? ($row['material_spec'] === null ? null : (string) $row['material_spec'])
            : null,
        'material_model' => array_key_exists('material_model', $row)
            ? ($row['material_model'] === null ? null : (string) $row['material_model'])
            : null,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array{material_name:string,manufacturer:string,material_spec:string,material_model:string}
 */
function roundSteelFourFields(array $row): array
{
    return [
        'material_name' => trim((string) ($row['material_name'] ?? '')),
        'manufacturer' => trim((string) ($row['manufacturer'] ?? '')),
        'material_spec' => trim((string) ($row['material_spec'] ?? '')),
        'material_model' => trim((string) ($row['material_model'] ?? '')),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return string
 */
function roundSteelFourFieldKey(array $row): string
{
    return implode("\0", array_values(roundSteelFourFields($row)));
}

/**
 * @param string $name
 * @return bool
 */
function roundSteelNameHasSeparator(string $name): bool
{
    return preg_match('/[\/\\\\|、,，;；+＋&＆]/u', $name) === 1;
}

/**
 * @param string $name
 * @return bool
 */
function roundSteelIsMixedMaterialCandidate(string $name): bool
{
    if (!roundSteelNameHasSeparator($name)) {
        return false;
    }

    $parts = preg_split('/[\/\\\\|、,，;；+＋&＆]+/u', $name) ?: [];
    $parts = array_values(array_filter(
        array_map(static fn (string $part): string => trim($part), $parts),
        static fn (string $part): bool => $part !== ''
    ));

    return count(array_unique($parts)) > 1;
}

/**
 * @param array<string, mixed> $entry
 * @return array{
 *     material_id:int,
 *     expected:array{material_name:?string,manufacturer:?string,material_spec:?string,material_model:?string},
 *     target:array{material_name:?string,manufacturer:?string,material_spec:?string,material_model:?string}
 * }
 * @throws RuntimeException
 */
function roundSteelNormalizeMappingEntry(array $entry): array
{
    $materialId = filter_var($entry['material_id'] ?? null, FILTER_VALIDATE_INT);
    if ($materialId === false || $materialId <= 0) {
        throw new RuntimeException('映射记录 material_id 必须为正整数');
    }

    if (!is_array($entry['expected'] ?? null) || !is_array($entry['target'] ?? null)) {
        throw new RuntimeException("映射 material_id={$materialId} 缺少 expected/target");
    }

    $expected = roundSteelValidateStrictFieldSet(
        $entry['expected'],
        "映射 material_id={$materialId} expected"
    );
    $targetRaw = roundSteelValidateStrictFieldSet(
        $entry['target'],
        "映射 material_id={$materialId} target"
    );
    $target = [];
    foreach ($targetRaw as $fieldName => $value) {
        $target[$fieldName] = $value === null ? null : trim($value);
    }

    return [
        'material_id' => (int) $materialId,
        'expected' => $expected,
        'target' => $target,
    ];
}

/**
 * @return int
 */
function roundSteelMaxJsonBytes(): int
{
    return 10 * 1024 * 1024;
}

/**
 * @param string $path
 * @param string $label
 * @return string
 * @throws RuntimeException
 */
function roundSteelReadLimitedJson(string $path, string $label): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException($label . '不存在或不可读: ' . $path);
    }

    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('无法读取' . $label . '大小: ' . $path);
    }
    if ($size > roundSteelMaxJsonBytes()) {
        throw new RuntimeException($label . '超过 10MB 上限');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('无法打开' . $label . ': ' . $path);
    }
    try {
        $json = stream_get_contents($handle, roundSteelMaxJsonBytes() + 1);
        if ($json === false) {
            throw new RuntimeException('读取' . $label . '失败: ' . $path);
        }
    } finally {
        if (!fclose($handle)) {
            error_log('[round-steel-archive] 关闭文件失败: ' . $path);
        }
    }

    if (strlen($json) > roundSteelMaxJsonBytes()) {
        throw new RuntimeException($label . '超过 10MB 上限');
    }

    return $json;
}

/**
 * @param string $mappingPath
 * @return array<int, array{material_id:int,expected:array<string,string>,target:array<string,string>}>
 * @throws RuntimeException
 */
function roundSteelLoadMappingFile(string $mappingPath): array
{
    $json = roundSteelReadLimitedJson($mappingPath, '映射文件');
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['format'] ?? '') !== 'round-steel-archive-map/v1') {
        throw new RuntimeException('映射文件 format 必须为 round-steel-archive-map/v1');
    }
    if (!is_array($payload['records'] ?? null)) {
        throw new RuntimeException('映射文件 records 必须为数组');
    }

    $normalizedMapping = [];
    foreach ($payload['records'] as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('映射文件 records 包含无效记录');
        }

        $normalized = roundSteelNormalizeMappingEntry($entry);
        $materialId = $normalized['material_id'];
        if (isset($normalizedMapping[$materialId])) {
            throw new RuntimeException("映射文件 material_id={$materialId} 重复");
        }
        $normalizedMapping[$materialId] = $normalized;
    }

    ksort($normalizedMapping);
    return $normalizedMapping;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param array<int, array<string, mixed>> $mappingInput
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function roundSteelAnalyzeRows(array $rows, array $mappingInput): array
{
    $rowsById = [];
    foreach ($rows as $row) {
        $materialId = (int) ($row['id'] ?? 0);
        if ($materialId <= 0 || isset($rowsById[$materialId])) {
            throw new RuntimeException('圆钢档案存在无效或重复 material_id');
        }
        $rowsById[$materialId] = $row;
    }

    $normalizedMapping = [];
    foreach ($mappingInput as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('映射记录格式无效');
        }
        $normalized = roundSteelNormalizeMappingEntry($entry);
        $materialId = $normalized['material_id'];
        if (!isset($rowsById[$materialId])) {
            throw new RuntimeException("映射 material_id={$materialId} 不属于当前有效圆钢档案");
        }
        if (isset($normalizedMapping[$materialId])) {
            throw new RuntimeException("映射 material_id={$materialId} 重复");
        }

        $current = roundSteelRawFourFields($rowsById[$materialId]);
        foreach ($current as $fieldName => $currentValue) {
            if ($normalized['expected'][$fieldName] !== $currentValue) {
                throw new RuntimeException(
                    "映射 material_id={$materialId} 的 {$fieldName} 旧值不匹配"
                );
            }
        }
        $normalizedMapping[$materialId] = $normalized;
    }

    $emptyManufacturerIds = [];
    $mixedCandidateIds = [];
    $separatorNameIds = [];
    $unresolvedIds = [];
    $affectedIds = [];
    $effectiveById = [];

    foreach ($rowsById as $materialId => $row) {
        $currentRaw = roundSteelRawFourFields($row);
        $current = roundSteelFourFields($row);
        $mapped = $normalizedMapping[$materialId]['target'] ?? null;
        $effective = roundSteelFourFields($mapped ?? $currentRaw);
        $effectiveById[$materialId] = $effective;

        $hasSeparator = roundSteelNameHasSeparator($current['material_name']);
        $isMixed = roundSteelIsMixedMaterialCandidate($current['material_name']);
        if ($hasSeparator) {
            $separatorNameIds[] = $materialId;
        }
        if ($isMixed) {
            $mixedCandidateIds[] = $materialId;
        }
        if ($effective['manufacturer'] === '') {
            $emptyManufacturerIds[] = $materialId;
        }

        $currentNeedsResolution = in_array('', array_values($current), true)
            || $hasSeparator
            || $isMixed;
        $targetStillInvalid = in_array('', array_values($effective), true)
            || roundSteelNameHasSeparator($effective['material_name'])
            || roundSteelIsMixedMaterialCandidate($effective['material_name']);
        if (($currentNeedsResolution && $mapped === null) || $targetStillInvalid) {
            $unresolvedIds[] = $materialId;
        }
        if ($mapped !== null && $mapped !== $currentRaw) {
            $affectedIds[] = $materialId;
        }
    }

    $idsByKey = [];
    foreach ($effectiveById as $materialId => $effective) {
        $idsByKey[roundSteelFourFieldKey($effective)][] = $materialId;
    }
    $conflictIds = [];
    foreach ($idsByKey as $ids) {
        if (count($ids) > 1) {
            array_push($conflictIds, ...$ids);
        }
    }

    foreach ([
        &$emptyManufacturerIds,
        &$mixedCandidateIds,
        &$separatorNameIds,
        &$unresolvedIds,
        &$affectedIds,
        &$conflictIds,
    ] as &$ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
    }
    unset($ids);

    return [
        'total' => count($rowsById),
        'empty_manufacturer_ids' => $emptyManufacturerIds,
        'mixed_material_candidate_ids' => $mixedCandidateIds,
        'separator_name_ids' => $separatorNameIds,
        'conflict_ids' => $conflictIds,
        'unresolved_ids' => $unresolvedIds,
        'affected_material_ids' => $affectedIds,
        'effective_rows' => $effectiveById,
        'normalized_mapping' => $normalizedMapping,
    ];
}

/**
 * @param array<string, mixed> $analysis
 * @return void
 * @throws RuntimeException
 */
function roundSteelAssertApplyAllowed(array $analysis): void
{
    $blocked = [];
    foreach ([
        'unresolved' => 'unresolved_ids',
        'empty manufacturer' => 'empty_manufacturer_ids',
        'conflict' => 'conflict_ids',
    ] as $label => $key) {
        $ids = $analysis[$key] ?? [];
        if (is_array($ids) && $ids !== []) {
            $blocked[] = $label . '=' . count($ids);
        }
    }

    if ($blocked !== []) {
        throw new RuntimeException('apply 前置校验失败: ' . implode(', ', $blocked));
    }
}

/**
 * @param array<int, array<string, mixed>> $inventoryRows
 * @param array<int, array<string, mixed>> $materialRows
 * @return array<int, array<string, mixed>>
 */
function roundSteelBuildInventoryCandidates(array $inventoryRows, array $materialRows): array
{
    $idsByTriple = [];
    foreach ($materialRows as $row) {
        $key = implode("\0", [
            trim((string) ($row['material_name'] ?? '')),
            trim((string) ($row['material_spec'] ?? '')),
            trim((string) ($row['material_model'] ?? '')),
        ]);
        $idsByTriple[$key][] = (int) ($row['id'] ?? 0);
    }

    $result = [];
    foreach ($inventoryRows as $row) {
        $key = implode("\0", [
            trim((string) ($row['material_name'] ?? '')),
            trim((string) ($row['y_material_spec'] ?? '')),
            trim((string) ($row['y_material_model'] ?? '')),
        ]);
        $candidateIds = array_values(array_filter(
            array_unique(array_map('intval', $idsByTriple[$key] ?? [])),
            static fn (int $materialId): bool => $materialId > 0
        ));
        sort($candidateIds);

        $result[] = [
            'inventory_id' => (int) ($row['inventory_id'] ?? 0),
            'invalid_material_id' => (int) ($row['material_id'] ?? 0),
            'material_name' => trim((string) ($row['material_name'] ?? '')),
            'y_material_spec' => trim((string) ($row['y_material_spec'] ?? '')),
            'y_material_model' => trim((string) ($row['y_material_model'] ?? '')),
            'candidate_material_ids' => $candidateIds,
        ];
    }

    return $result;
}

/**
 * @param array<int, array<string, mixed>> $beforeRows
 * @param array<int, array<string, mixed>> $afterRows
 * @param string $mappingSha256
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function roundSteelBuildBackupPayload(
    array $beforeRows,
    array $afterRows,
    string $mappingSha256
): array {
    if (count($beforeRows) !== count($afterRows)) {
        throw new RuntimeException('备份前后记录数不一致');
    }

    $afterById = [];
    foreach ($afterRows as $row) {
        $afterById[(int) ($row['id'] ?? 0)] = $row;
    }

    $records = [];
    foreach ($beforeRows as $beforeRow) {
        $materialId = (int) ($beforeRow['id'] ?? 0);
        if ($materialId <= 0 || !isset($afterById[$materialId])) {
            throw new RuntimeException('备份前后 material_id 不一致');
        }
        $records[] = [
            'material_id' => $materialId,
            'before' => roundSteelRawFourFields($beforeRow),
            'after' => roundSteelRawFourFields($afterById[$materialId]),
        ];
    }

    usort(
        $records,
        static fn (array $left, array $right): int => $left['material_id'] <=> $right['material_id']
    );

    $payload = [
        'format' => 'round-steel-archive-backup/v1',
        'created_at' => date(DATE_ATOM),
        'material_property' => '圆钢',
        'mapping_sha256' => $mappingSha256,
        'records' => $records,
    ];
    $payload['payload_sha256'] = roundSteelPayloadChecksum($payload);

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 * @return string
 * @throws RuntimeException
 */
function roundSteelPayloadChecksum(array $payload): string
{
    unset($payload['payload_sha256']);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    return hash('sha256', $json);
}

/**
 * @param array<string, mixed> $payload
 * @return bool
 * @throws RuntimeException
 */
function roundSteelValidateBackupPayload(array $payload): bool
{
    if (($payload['format'] ?? '') !== 'round-steel-archive-backup/v1') {
        throw new RuntimeException('备份格式无效');
    }
    if (($payload['material_property'] ?? '') !== '圆钢') {
        throw new RuntimeException('备份物料属性无效');
    }
    if (!is_array($payload['records'] ?? null)) {
        throw new RuntimeException('备份 records 无效');
    }

    $expectedChecksum = (string) ($payload['payload_sha256'] ?? '');
    if ($expectedChecksum === '' || !hash_equals($expectedChecksum, roundSteelPayloadChecksum($payload))) {
        throw new RuntimeException('备份校验和不匹配');
    }

    $seenIds = [];
    foreach ($payload['records'] as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('备份记录格式无效');
        }
        $materialId = filter_var($record['material_id'] ?? null, FILTER_VALIDATE_INT);
        if ($materialId === false || $materialId <= 0 || isset($seenIds[$materialId])) {
            throw new RuntimeException('备份 material_id 无效或重复');
        }
        if (!is_array($record['before'] ?? null) || !is_array($record['after'] ?? null)) {
            throw new RuntimeException("备份 material_id={$materialId} 缺少 before/after");
        }
        roundSteelValidateStrictFieldSet(
            $record['before'],
            "备份 material_id={$materialId} before"
        );
        roundSteelValidateStrictFieldSet(
            $record['after'],
            "备份 material_id={$materialId} after"
        );
        $seenIds[$materialId] = true;
    }

    return true;
}
