<?php

/**
 * [AI-2026-08] 圆钢四字段统一业务链路回归测试。
 */

declare(strict_types=1);

namespace {
    use app\common\service\admin\MaterialInventoryService;
    use app\common\service\admin\FlowCardService;
    use app\common\service\admin\ProductReportService;
    use app\common\service\admin\ProductionService;
    use app\common\service\admin\ShipService;
    use app\common\service\utils\DuangangMergeKeyHelper;
    use app\common\service\utils\MaterialSearchHelper;

    require dirname(__DIR__, 2) . '/app/common/service/utils/MaterialSearchHelper.php';
    require dirname(__DIR__, 2) . '/app/common/service/utils/DuangangMergeKeyHelper.php';
    require dirname(__DIR__, 2) . '/app/common/service/admin/MaterialInventoryService.php';
    require dirname(__DIR__, 2) . '/app/common/service/admin/FlowCardService.php';
    require dirname(__DIR__, 2) . '/app/common/service/admin/ProductReportService.php';
    require dirname(__DIR__, 2) . '/app/common/service/admin/ProductionService.php';
    require dirname(__DIR__, 2) . '/app/common/service/admin/ShipService.php';

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
        } catch (\Throwable $exception) {
            $failed[] = $name . ': ' . $exception->getMessage();
        }
    }

    /**
     * @param bool $condition
     * @param string $message
     * @return void
     */
    function expectRoundSteel(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    runRoundSteelCase('standard_and_legacy_filters_resolve_four_fields', static function (): void {
        $filters = MaterialSearchHelper::resolveRoundSteelFilters([
            'y_material_name' => '圆钢',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '',
            'y_mateial_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ]);
        expectRoundSteel($filters === [
            'name' => '圆钢',
            'manufacturer' => '宝钢',
            'spec' => '40Cr',
            'model' => 'Φ50',
        ], '标准名优先且历史拼写应读兼容');
    });

    runRoundSteelCase('four_fields_support_independent_and_combined_match', static function (): void {
        $row = [
            'material_name' => '合结圆钢',
            'manufacturer' => '宝钢股份',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ];
        expectRoundSteel(
            MaterialSearchHelper::materialMatchesRoundSteel($row, ['manufacturer' => '宝钢']),
            '厂商独立筛选未命中'
        );
        expectRoundSteel(
            !MaterialSearchHelper::materialMatchesRoundSteel($row, [
                'manufacturer' => '宝钢',
                'model' => 'Φ60',
            ]),
            '组合筛选未执行同一物料 AND'
        );
        expectRoundSteel(
            MaterialSearchHelper::likeKeyword('40%_Cr') === '40\\%\\_Cr',
            'LIKE 通配符未安全转义'
        );
        expectRoundSteel(
            MaterialSearchHelper::likeKeyword('40\\Cr') === '40\\\\Cr',
            'LIKE 转义字符本身未安全转义'
        );
    });

    runRoundSteelCase('dto_contains_canonical_four_fields', static function (): void {
        $dto = MaterialSearchHelper::roundSteelDto([
            'material_property' => '圆钢',
            'material_name' => '圆钢',
            'manufacturer' => '宝钢',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ]);
        expectRoundSteel($dto === [
            'y_material_name' => '圆钢',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ], 'DTO 未输出标准四字段');
        expectRoundSteel(
            MaterialSearchHelper::roundSteelDto([
                'material_property' => '成品',
                'material_name' => '成品',
                'manufacturer' => '错误厂商',
            ]) === [],
            '非圆钢档案不应生成圆钢 DTO'
        );
    });

    runRoundSteelCase('flow_card_round_steel_text_uses_canonical_four_field_order', static function (): void {
        $text = FlowCardService::formatRoundSteelText([
            'y_material_name' => '合结圆钢',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ]);
        expectRoundSteel(
            $text === '圆:合结圆钢 / 宝钢 / 40Cr / Φ50',
            '流转卡圆钢四字段格式或分隔符不统一'
        );
    });

    runRoundSteelCase('flow_card_tspl_lines_fit_240_dot_label_with_equal_field_allocation', static function (): void {
        $lines = FlowCardService::buildRoundSteelTsplLines([
            'y_material_name' => str_repeat('A', 30),
            'y_material_manufacturer' => str_repeat('B', 30),
            'y_material_spec' => str_repeat('C', 30),
            'y_material_model' => str_repeat('D', 30),
        ], 7);
        expectRoundSteel(
            $lines === [
                '圆:' . str_repeat('A', 14) . '… / ' . str_repeat('B', 14) . '…',
                str_repeat('C', 13) . '… / ' . str_repeat('D', 13) . '… 数:7',
            ],
            '服务端 TSPL 长字段分配结果不符合 240 点标签约束'
        );
        foreach ($lines as $line) {
            expectRoundSteel(
                FlowCardService::measureTsplTextWidth($line, 12) <= 232,
                '服务端 TSPL 圆钢行超出可打印宽度'
            );
        }
        $chineseLines = FlowCardService::buildRoundSteelTsplLines([
            'y_material_name' => str_repeat('超长圆钢名称', 8),
            'y_material_manufacturer' => str_repeat('超长生产厂商', 8),
            'y_material_spec' => str_repeat('超长材质', 8),
            'y_material_model' => str_repeat('超长型号', 8),
        ], 123456);
        foreach ($chineseLines as $line) {
            expectRoundSteel(
                str_contains($line, '…') && FlowCardService::measureTsplTextWidth($line, 12) <= 232,
                '服务端中文长字段未安全截断'
            );
        }
    });

    runRoundSteelCase('mini_task_controller_passes_parameterized_four_field_filters', static function (): void {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/admin/controller/production/ProductTask.php'
        );
        $service = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/common/service/admin/ProductTaskService.php'
        );
        foreach ([
            'y_material_name',
            'y_material_manufacturer',
            'y_material_spec',
            'y_material_model',
        ] as $field) {
            expectRoundSteel(str_contains($controller, "'" . $field . "'"), '小程序工单接口未接收 ' . $field);
        }
        expectRoundSteel(
            str_contains($controller, 'MaterialSearchHelper::resolveRoundSteelFilters($param)')
                && str_contains($controller, '$roundSteelFilters'),
            '小程序工单接口未解析或传递圆钢四字段'
        );
        expectRoundSteel(
            str_contains($service, 'MaterialSearchHelper::applyRoundSteelFilters(')
                && str_contains($service, "'ym'"),
            '小程序工单服务未使用参数化圆钢四字段查询'
        );
    });

    runRoundSteelCase('inventory_prefers_valid_material_archive', static function (): void {
        $resolved = MaterialInventoryService::resolveRoundSteelHistoryFields(
            [
                'material_id' => 18,
                'material_name' => '旧名称',
                'y_material_manufacturer' => '旧厂商',
                'y_material_spec' => '旧材质',
                'y_material_model' => '旧型号',
            ],
            [
                'id' => 18,
                'material_property' => '圆钢',
                'material_name' => '圆钢',
                'manufacturer' => '宝钢',
                'material_spec' => '40Cr',
                'material_model' => 'Φ50',
            ]
        );
        expectRoundSteel($resolved['y_material_manufacturer'] === '宝钢', '有效 material_id 未优先当前档案');
        expectRoundSteel($resolved['y_material_spec'] === '40Cr', '有效 material_id 未优先当前材质');
    });

    runRoundSteelCase('inventory_falls_back_to_snapshot_and_separates_manufacturer', static function (): void {
        $snapshot = [
            'material_id' => 0,
            'material_name' => '圆钢',
            'y_material_manufacturer' => '太钢',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ];
        $resolved = MaterialInventoryService::resolveRoundSteelHistoryFields($snapshot, null);
        expectRoundSteel($resolved['y_material_manufacturer'] === '太钢', '无效 material_id 未回退历史快照');
        expectRoundSteel(
            !MaterialInventoryService::roundSteelIdentityMatches(
                $snapshot,
                array_merge($snapshot, ['y_material_manufacturer' => '宝钢'])
            ),
            '不同厂商被错误串档'
        );
    });

    runRoundSteelCase('inventory_rejects_non_round_archive_enrichment', static function (): void {
        $snapshot = [
            'material_id' => 88,
            'material_name' => '旧圆钢',
            'y_material_manufacturer' => '旧厂商',
            'y_material_spec' => '旧材质',
            'y_material_model' => '旧型号',
        ];
        $resolved = MaterialInventoryService::resolveRoundSteelHistoryFields($snapshot, [
            'id' => 88,
            'material_property' => '成品',
            'material_name' => '错误成品',
            'manufacturer' => '错误厂商',
            'material_spec' => '错误规格',
            'material_model' => '错误型号',
        ]);
        expectRoundSteel($resolved['y_material_spec'] === '旧材质', '成品档案错误覆盖圆钢快照');
        expectRoundSteel($resolved['y_material_manufacturer'] === '旧厂商', '非圆钢档案错误覆盖厂商');
    });

    runRoundSteelCase('inventory_candidate_resolution_never_guesses_legacy_or_ambiguous', static function (): void {
        $legacy = [
            'material_id' => 0,
            'y_material_name' => '圆钢',
            'y_material_manufacturer' => '',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ];
        $candidate = [
            'id' => 11,
            'material_property' => '圆钢',
            'material_name' => '圆钢',
            'manufacturer' => '宝钢',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ];
        expectRoundSteel(
            MaterialInventoryService::selectRoundSteelArchiveCandidate($legacy, [$candidate]) === null,
            '旧快照厂商为空时不应猜测档案'
        );

        $snapshot = array_merge($legacy, ['y_material_manufacturer' => '宝钢']);
        expectRoundSteel(
            MaterialInventoryService::selectRoundSteelArchiveCandidate(
                $snapshot,
                [$candidate, array_merge($candidate, ['id' => 12])]
            ) === null,
            '四字段候选不唯一时不应自动绑定'
        );
        $selected = MaterialInventoryService::selectRoundSteelArchiveCandidate($snapshot, [$candidate]);
        expectRoundSteel(($selected['id'] ?? 0) === 11, '四字段唯一候选未正确解析');
    });

    runRoundSteelCase('inventory_identity_key_prefers_material_id_and_isolates_legacy', static function (): void {
        $base = [
            'inventory_code' => 'PD-A',
            'material_id' => 19,
            'y_material_name' => '圆钢',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ];
        expectRoundSteel(
            MaterialInventoryService::roundSteelIdentityKey($base)
                === MaterialInventoryService::roundSteelIdentityKey(array_merge($base, [
                    'y_material_manufacturer' => '太钢',
                ])),
            '有效 material_id 未作为身份键最高优先级'
        );

        $noId = array_merge($base, ['material_id' => 0]);
        expectRoundSteel(
            MaterialInventoryService::roundSteelIdentityKey($noId)
                !== MaterialInventoryService::roundSteelIdentityKey(array_merge($noId, [
                    'y_material_manufacturer' => '太钢',
                ])),
            '不同厂商仍被合并'
        );
        expectRoundSteel(
            MaterialInventoryService::roundSteelIdentityKey(array_merge($noId, [
                'inventory_code' => 'PD-A',
                'y_material_manufacturer' => '',
            ]))
                !== MaterialInventoryService::roundSteelIdentityKey(array_merge($noId, [
                    'inventory_code' => 'PD-B',
                    'y_material_manufacturer' => '',
                ])),
            '无法解析厂商的 legacy 记录未使用独立键'
        );
        expectRoundSteel(
            MaterialInventoryService::roundSteelIdentityKey(array_merge($noId, [
                'material_id' => 999,
                '_round_material_id_valid' => false,
                'y_material_manufacturer' => '宝钢',
            ]))
                !== MaterialInventoryService::roundSteelIdentityKey(array_merge($noId, [
                    'material_id' => 999,
                    '_round_material_id_valid' => false,
                    'y_material_manufacturer' => '太钢',
                ])),
            '相同无效 material_id 不得压过厂商身份'
        );
    });

    runRoundSteelCase('stocktake_write_fields_include_nullable_manufacturer_snapshot', static function (): void {
        $fields = MaterialInventoryService::buildStocktakeRoundSteelFields([
            'material_id' => 11,
            'y_material_name' => '圆钢',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ], 11);
        expectRoundSteel($fields['y_material_manufacturer'] === '宝钢', '盘点写入未保存厂商快照');

        $legacy = MaterialInventoryService::buildStocktakeRoundSteelFields([
            'material_id' => 0,
            'y_material_name' => '旧圆钢',
            'y_material_manufacturer' => '',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ], 0);
        expectRoundSteel($legacy['y_material_manufacturer'] === null, '空厂商快照应写为 NULL');
        expectRoundSteel($legacy['material_id'] === 0, '旧快照不得自动绑定 material_id');
    });

    runRoundSteelCase('auto_calc_baseline_merge_prefers_material_id_and_manufacturer', static function (): void {
        $legacyRows = [
            [
                'process_name' => '圆钢',
                'material_id' => 0,
                'material_name' => '圆钢',
                'y_material_manufacturer' => '宝钢',
                'y_material_spec' => '40Cr',
                'y_material_model' => 'Φ50',
                'total_tonnage' => 1,
            ],
            [
                'process_name' => '圆钢',
                'material_id' => 0,
                'material_name' => '圆钢',
                'y_material_manufacturer' => '太钢',
                'y_material_spec' => '40Cr',
                'y_material_model' => 'Φ50',
                'total_tonnage' => 2,
            ],
        ];
        expectRoundSteel(
            count(MaterialInventoryService::mergeRoundSteelBaselineRows($legacyRows)) === 2,
            '自动推算基线错误合并不同厂商'
        );

        $sameMaterialRows = [
            array_merge($legacyRows[0], ['material_id' => 18]),
            array_merge($legacyRows[1], ['material_id' => 18]),
        ];
        $merged = MaterialInventoryService::mergeRoundSteelBaselineRows($sameMaterialRows);
        expectRoundSteel(count($merged) === 1, '有效 material_id 未优先合并基线');
        expectRoundSteel((float) $merged[0]['total_tonnage'] === 3.0, '基线合并吨位错误');
    });

    runRoundSteelCase('audit_and_calc_legacy_identity_is_stable', static function (): void {
        $legacy = [
            'material_id' => 0,
            'material_name' => '圆钢',
            'y_material_manufacturer' => '',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ];
        expectRoundSteel(
            MaterialInventoryService::roundSteelCalculationIdentityKey($legacy)
                === MaterialInventoryService::roundSteelCalculationIdentityKey(array_merge($legacy, [
                    'inventory_code' => '另一次加载生成的编码',
                    'predicted_qty' => 99,
                ])),
            '空厂商历史圆钢审计身份键不稳定'
        );
        expectRoundSteel(
            MaterialInventoryService::roundSteelCalculationIdentityKey(array_merge($legacy, [
                'y_material_manufacturer' => '宝钢',
            ]))
                !== MaterialInventoryService::roundSteelCalculationIdentityKey(array_merge($legacy, [
                    'y_material_manufacturer' => '太钢',
                ])),
            '历史圆钢审计身份键未区分厂商'
        );
    });

    runRoundSteelCase('legacy_three_field_seen_key_is_recognized', static function (): void {
        expectRoundSteel(
            MaterialInventoryService::roundSteelCalculationIdentityWasSeen(
                ['圆钢|40Cr|Φ50' => true],
                [
                    'material_id' => 0,
                    'material_name' => '圆钢',
                    'y_material_manufacturer' => '',
                    'y_material_spec' => '40Cr',
                    'y_material_model' => 'Φ50',
                ]
            ),
            'legacy 空厂商行未命中旧 name|spec|model seenKeys'
        );
    });

    runRoundSteelCase('new_inbound_dedupe_uses_material_id_or_four_fields', static function (): void {
        $baseline = [[
            'process_name' => '圆钢',
            'material_id' => 0,
            'material_name' => '圆钢',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '40Cr',
            'y_material_model' => 'Φ50',
        ]];
        expectRoundSteel(
            !MaterialInventoryService::hasRoundSteelIdentity($baseline, array_merge($baseline[0], [
                'y_material_manufacturer' => '太钢',
            ])),
            '另一厂商同材质型号被错误跳过'
        );
        expectRoundSteel(
            MaterialInventoryService::hasRoundSteelIdentity($baseline, $baseline[0]),
            '相同历史四字段未去重'
        );
        expectRoundSteel(
            MaterialInventoryService::hasRoundSteelIdentity(
                [array_merge($baseline[0], ['material_id' => 8])],
                array_merge($baseline[0], ['material_id' => 8, 'y_material_manufacturer' => '太钢'])
            ),
            '相同有效 material_id 未去重'
        );
    });

    runRoundSteelCase('batch_stocktake_trusts_valid_archive_only', static function (): void {
        $client = [
            'material_id' => 11,
            'y_material_name' => '伪造名称',
            'y_material_manufacturer' => '伪造厂商',
            'y_material_spec' => '伪造材质',
            'y_material_model' => '伪造型号',
        ];
        $archive = [
            'id' => 11,
            'is_delete' => 0,
            'material_property' => '圆钢',
            'material_name' => '圆钢',
            'manufacturer' => '宝钢',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ];
        $resolved = MaterialInventoryService::resolveStocktakeRoundSteelSnapshot($client, $archive);
        expectRoundSteel($resolved['material_name'] === '圆钢', '有效档案未权威覆盖名称');
        expectRoundSteel($resolved['y_material_manufacturer'] === '宝钢', '有效档案未权威覆盖厂商');
        expectRoundSteel($resolved['y_material_spec'] === '40Cr', '有效档案未权威覆盖材质');
        expectRoundSteel($resolved['y_material_model'] === 'Φ50', '有效档案未权威覆盖型号');

        $legacy = MaterialInventoryService::resolveStocktakeRoundSteelSnapshot(
            array_merge($client, ['material_id' => 999, 'y_material_manufacturer' => '旧厂商']),
            null
        );
        expectRoundSteel($legacy['material_id'] === 999, '无效 material_id 未保留原值');
        expectRoundSteel($legacy['y_material_manufacturer'] === '旧厂商', '无效档案错误猜测厂商');
        $nameFallback = MaterialInventoryService::resolveStocktakeRoundSteelSnapshot([
            'material_id' => 999,
            'material_name' => '旧名称',
            'y_material_name' => '',
            'y_material_manufacturer' => '',
            'y_material_spec' => '旧材质',
            'y_material_model' => '旧型号',
        ], null);
        expectRoundSteel($nameFallback['material_name'] === '旧名称', '无效档案未保留历史名称快照');
    });

    runRoundSteelCase('inventory_search_matches_live_or_snapshot_same_row', static function (): void {
        $matches = static function (array $snapshot, ?array $archive, array $filters): bool {
            $resolved = MaterialInventoryService::resolveRoundSteelHistoryFields($snapshot, $archive);
            return MaterialSearchHelper::materialMatchesRoundSteel([
                'material_name' => $resolved['y_material_name'] ?? $resolved['material_name'] ?? '',
                'manufacturer' => $resolved['y_material_manufacturer'] ?? '',
                'material_spec' => $resolved['y_material_spec'] ?? '',
                'material_model' => $resolved['y_material_model'] ?? '',
            ], $filters);
        };
        $snapshot = [
            'material_id' => 11,
            'material_name' => '旧名称',
            'y_material_manufacturer' => '旧厂商',
            'y_material_spec' => '旧材质',
            'y_material_model' => '旧型号',
        ];
        $archive = [
            'id' => 11,
            'is_delete' => 0,
            'material_property' => '圆钢',
            'material_name' => '实时圆钢',
            'manufacturer' => '宝钢',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ];
        expectRoundSteel(
            $matches(
                $snapshot,
                $archive,
                ['name' => '实时', 'manufacturer' => '宝', 'spec' => '40', 'model' => '50']
            ),
            '有效档案四字段组合筛选未命中'
        );
        expectRoundSteel(
            !$matches(
                $snapshot,
                $archive,
                ['name' => '旧名称']
            ),
            '有效档案存在时错误匹配历史快照'
        );
        expectRoundSteel(
            $matches(
                $snapshot,
                null,
                ['name' => '旧名称', 'manufacturer' => '旧厂商', 'spec' => '旧材质', 'model' => '旧型号']
            ),
            '无效档案未回退同一行历史快照'
        );
        expectRoundSteel(
            !$matches(
                $snapshot,
                null,
                ['manufacturer' => '旧厂商', 'model' => '不存在']
            ),
            '历史快照组合筛选未执行同一行 AND'
        );
    });

    runRoundSteelCase('historical_task_relations_do_not_hard_filter_archive', static function (): void {
        foreach (['ProductTaskModel.php', 'ProductReportModel.php'] as $fileName) {
            $source = (string) file_get_contents(
                dirname(__DIR__, 2) . '/app/common/model/admin/' . $fileName
            );
            $relationStart = strpos($source, 'function getYMaterial');
            $relation = substr($source, $relationStart, 700);
            expectRoundSteel(
                !str_contains($relation, "->where('material_property', '圆钢')")
                    && !str_contains($relation, "->where('is_delete', 0)"),
                $fileName . ' 历史物料关联仍被硬过滤'
            );
        }
    });

    runRoundSteelCase('inventory_join_qualifies_all_main_table_fields', static function (): void {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/admin/controller/production/Inventory.php'
        );
        foreach ([
            'mi.is_delete',
            'mi.process_name',
            'mi.c_material_spec',
            'mi.c_material_model',
            'mi.inventory_date',
        ] as $qualifiedField) {
            expectRoundSteel(
                str_contains($source, "'" . $qualifiedField . "'"),
                '盘点 JOIN 主表字段未加别名：' . $qualifiedField
            );
        }
    });

    runRoundSteelCase('product_report_round_filter_avoids_full_id_columns', static function (): void {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/admin/controller/production/ProductReport.php'
        );
        $service = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/common/service/admin/ProductReportService.php'
        );
        expectRoundSteel(
            !str_contains($controller, 'MaterialSearchHelper::taskCodesByRawMaterial('),
            '报工圆钢筛选仍先加载全量工单 ID'
        );
        expectRoundSteel(
            str_contains($service, 'applyProductReportRoundSteelFilters'),
            '报工列表未在分页查询中应用圆钢子查询'
        );
    });

    runRoundSteelCase('product_report_grouping_is_chunked_for_current_page', static function (): void {
        $service = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/common/service/admin/ProductReportService.php'
        );
        $methodStart = strpos($service, 'private static function buildMergedDisplayGroups');
        $methodEnd = strpos($service, 'private static function enrichReportListBatch', $methodStart);
        $method = substr($service, $methodStart, $methodEnd - $methodStart);
        expectRoundSteel(
            str_contains($method, 'limit($offset, $chunkSize)')
                && str_contains($method, '$page')
                && str_contains($method, '$limit'),
            '报工分组仍执行无约束全量 select'
        );
    });

    runRoundSteelCase('round_steel_display_sources_merge_detail_and_task_consistently', static function (): void {
        $detailRows = [
            [
                'id' => 11,
                'material_property' => '圆钢',
                'is_delete' => 0,
                'material_name' => '圆钢A',
                'manufacturer' => '宝钢',
                'material_spec' => '40Cr',
                'material_model' => 'Φ50',
            ],
            [
                'id' => 99,
                'material_property' => '成品',
                'is_delete' => 0,
                'material_name' => '成品',
            ],
        ];
        $taskRows = [
            $detailRows[0],
            [
                'id' => 12,
                'material_property' => '圆钢',
                'is_delete' => 0,
                'material_name' => '圆钢B',
                'manufacturer' => '太钢',
                'material_spec' => '42CrMo',
                'material_model' => 'Φ60',
            ],
        ];
        $merged = MaterialSearchHelper::mergeRoundSteelMaterialRows($detailRows, $taskRows);
        expectRoundSteel(array_column($merged, 'id') === [11, 12], '展示源未按筛选源合并去重');
    });

    runRoundSteelCase('production_no_page_rows_dual_write_standard_and_legacy_fields', static function (): void {
        $rows = ProductionService::applyRoundSteelDtosToProductionRows(
            [['production_plan_code' => 'P1']],
            ['P1' => [[
                'material_name' => '圆钢',
                'manufacturer' => '宝钢',
                'material_spec' => '40Cr',
                'material_model' => 'Φ50',
            ]]]
        );
        expectRoundSteel($rows[0]['y_material_manufacturer'] === '宝钢', '无分页未输出标准厂商字段');
        expectRoundSteel(
            $rows[0]['y_mateial_manufacturer'] === $rows[0]['y_material_manufacturer'],
            '无分页未双写历史拼写字段'
        );
    });

    runRoundSteelCase('ship_rows_receive_four_fields_in_all_branches', static function (): void {
        $rows = ShipService::applyRoundSteelLabelsToRows(
            [['relation_code' => 'P1']],
            ['P1' => [
                'y_material_name' => '圆钢',
                'y_material_manufacturer' => '宝钢',
                'y_material_spec' => '40Cr',
                'y_material_model' => 'Φ50',
                'y_material_info' => '圆钢 · 40Cr · Φ50',
                'product_info' => '成品 · 20CrMnTi · M20',
            ]]
        );
        expectRoundSteel($rows[0]['y_material_name'] === '圆钢', '发货行未补圆钢名称');
        expectRoundSteel($rows[0]['y_material_manufacturer'] === '宝钢', '发货行未补圆钢厂商');
        expectRoundSteel($rows[0]['y_material_spec'] === '40Cr', '发货行未补圆钢材质');
        expectRoundSteel($rows[0]['y_material_model'] === 'Φ50', '发货行未补圆钢型号');
    });

    runRoundSteelCase('powerlist_does_not_declare_unsupported_round_steel_filters', static function (): void {
        expectRoundSteel(
            !array_key_exists('y_material_name', ProductReportService::$powerSearchField)
                && !array_key_exists('y_material_manufacturer', ProductReportService::$powerSearchField)
                && !array_key_exists('y_material_spec', ProductReportService::$powerSearchField)
                && !array_key_exists('y_material_model', ProductReportService::$powerSearchField),
            '能耗接口仍声明无法处理的圆钢筛选参数'
        );
    });

    runRoundSteelCase('inventory_manufacturer_snapshot_migration_is_mysql55_compatible', static function (): void {
        $path = dirname(__DIR__, 2) . '/database/material_inventory_manufacturer_snapshot.sql';
        expectRoundSteel(is_file($path), '缺少盘点厂商快照迁移文件');
        $sql = (string) file_get_contents($path);
        expectRoundSteel(
            str_contains($sql, 'y_material_manufacturer')
                && str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')
                && !str_contains($sql, 'ADD COLUMN IF NOT EXISTS'),
            '迁移文件未兼容 MySQL 5.5'
        );
    });

    runRoundSteelCase('duangang_key_includes_name_manufacturer_grade_model_product', static function (): void {
        expectRoundSteel(
            DuangangMergeKeyHelper::cleanMaterialSpec('特钢45#', '钢') === '特钢45#',
            '厂商不是材质头部完整前缀时不应破坏材质'
        );
        expectRoundSteel(
            DuangangMergeKeyHelper::cleanMaterialSpec('天钢45#', '天钢') === '45#',
            '材质头部完整厂商前缀未正确移除'
        );

        $a = DuangangMergeKeyHelper::buildFromTaskRow([
            'y_material_name' => '溯源',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '宝钢 45#',
            'y_material_model' => ' Φ50 ',
            'c_material_spec' => ' 20*2 ',
        ]);
        $b = DuangangMergeKeyHelper::buildFromTaskRow([
            'y_material_name' => '溯源',
            'y_material_manufacturer' => '太钢',
            'y_material_spec' => '45#',
            'y_material_model' => 'Φ50',
            'c_material_spec' => '20*2',
        ]);
        expectRoundSteel($a['mergeKey'] !== $b['mergeKey'], '不同厂商不得并入同一断钢合并键');
        expectRoundSteel(
            $a['mergeKey'] === '溯源|宝钢|45#|Φ50|20*2',
            '断钢合并键清洗结果错误'
        );

        $sameA = DuangangMergeKeyHelper::buildFromTaskRow([
            'y_material_name' => '溯源',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '宝钢45#',
            'y_material_model' => 'Φ50',
            'c_material_spec' => '20*2',
        ]);
        $sameB = DuangangMergeKeyHelper::buildFromTaskRow([
            'y_material_name' => '溯源',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '45#',
            'y_material_model' => 'Φ50',
            'c_material_spec' => '20*2',
        ]);
        expectRoundSteel($sameA['mergeKey'] === $sameB['mergeKey'], '同厂同钢号清洗后合并键应稳定');

        $alloyA = DuangangMergeKeyHelper::buildFromTaskRow([
            'y_material_name' => '凯亚',
            'y_material_manufacturer' => '宝钢',
            'y_material_spec' => '宝钢 40Cr',
            'y_material_model' => 'Φ50',
            'c_material_spec' => '20*2',
        ]);
        $alloyB = DuangangMergeKeyHelper::buildFromTaskRow([
            'y_material_name' => '凯亚',
            'y_material_manufacturer' => '太钢',
            'y_material_spec' => '太钢40Cr',
            'y_material_model' => 'Φ50',
            'c_material_spec' => '20*2',
        ]);
        expectRoundSteel($alloyA['mergeKey'] !== $alloyB['mergeKey'], '非数字材质也必须按厂商拆分合并池');
        expectRoundSteel($alloyA['mergeKey'] === '凯亚|宝钢|40Cr|Φ50|20*2', '合金材质合并键错误');
    });

    runRoundSteelCase('wage_segment_keeps_model_first_fallback_chain', static function (): void {
        expectRoundSteel(
            ProductReportService::resolveRoundSteelWageSegment([
                'y_material_model' => 'Φ50',
                'y_material_spec' => '40Cr',
                'c_material_spec' => '20*2',
            ]) === 'Φ50',
            '工资规则未保持型号优先'
        );
        expectRoundSteel(
            ProductReportService::resolveRoundSteelWageSegment([
                'y_material_model' => '',
                'y_material_spec' => '40Cr',
                'c_material_spec' => '20*2',
            ]) === '40Cr',
            '工资规则规格回退改变'
        );
        expectRoundSteel(
            ProductReportService::resolveRoundSteelWageSegment([
                'y_material_model' => '',
                'y_material_spec' => '',
                'c_material_spec' => '20*2',
            ]) === '20*2',
            '工资规则成品规格兜底改变'
        );
    });

    if ($failed !== []) {
        fwrite(STDERR, "FAIL " . count($failed) . " cases\n- " . implode("\n- ", $failed) . PHP_EOL);
        exit(1);
    }

    echo "PASS {$passed} cases\n";
}
