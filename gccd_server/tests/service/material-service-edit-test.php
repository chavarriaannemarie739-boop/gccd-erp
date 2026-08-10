<?php

/**
 * [AI-2026-08] 物料单条非身份字段编辑控制流测试。
 */

declare(strict_types=1);

namespace app\common\model\admin {
    /**
     * 测试用物料记录。
     */
    class MaterialServiceEditRecord
    {
        /**
         * @param array<string, mixed> $row
         */
        public function __construct(private array $row)
        {
        }

        /**
         * @return array<string, mixed>
         */
        public function toArray(): array
        {
            return $this->row;
        }
    }

    /**
     * 测试用物料记录集合。
     */
    class MaterialServiceEditCollection
    {
        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function __construct(private array $rows)
        {
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function toArray(): array
        {
            return $this->rows;
        }
    }

    /**
     * 测试用最小查询模型，驱动真实 MaterialService::edit 控制流。
     */
    class MaterialModel
    {
        /** @var array<string, mixed> */
        public static array $row = [];

        /** @var string[] */
        public static array $events = [];

        public static int $updateResult = 1;

        /** @var array<int, array<string, mixed>>|null */
        public static ?array $selectRowsOverride = null;

        /**
         * @return string
         */
        public function getPk(): string
        {
            return 'id';
        }

        /**
         * @param mixed $field
         * @param mixed $operator
         * @param mixed $value
         * @return self
         */
        public function where($field, $operator = null, $value = null): self
        {
            self::$events[] = 'where';
            return $this;
        }

        /**
         * @param string $condition
         * @param array<int, mixed> $bindings
         * @return self
         */
        public function whereRaw(string $condition, array $bindings = []): self
        {
            self::$events[] = 'where-raw';
            return $this;
        }

        /**
         * @param string $field
         * @param int[] $values
         * @return self
         */
        public function whereNotIn(string $field, array $values): self
        {
            self::$events[] = 'where-not-in';
            return $this;
        }

        /**
         * @param bool $lock
         * @return self
         */
        public function lock(bool $lock): self
        {
            self::$events[] = 'lock';
            return $this;
        }

        /**
         * @return MaterialServiceEditCollection
         */
        public function select(): MaterialServiceEditCollection
        {
            self::$events[] = 'select';
            if (self::$selectRowsOverride !== null) {
                return new MaterialServiceEditCollection(self::$selectRowsOverride);
            }
            $isActive = (int) (self::$row['is_delete'] ?? 0) === 0;
            $rows = self::$row !== [] && $isActive ? [self::$row] : [];

            return new MaterialServiceEditCollection($rows);
        }

        /**
         * @param mixed $id
         * @return MaterialServiceEditRecord|null
         */
        public function find($id = null): ?MaterialServiceEditRecord
        {
            self::$events[] = $id === null ? 'find-duplicate' : 'find-record';
            if ($id === null) {
                return null;
            }
            return self::$row === [] ? null : new MaterialServiceEditRecord(self::$row);
        }

        /**
         * @return int
         */
        public function count(): int
        {
            self::$events[] = 'count';
            return self::$row === [] ? 0 : 1;
        }

        /**
         * @param array<string, mixed> $param
         * @return int
         */
        public function update(array $param): int
        {
            self::$events[] = 'update';
            return self::$updateResult;
        }
    }
}

namespace think\facade {
    /**
     * 测试用事务门面。
     */
    class Db
    {
        /** @var string[] */
        public static array $events = [];

        /**
         * @return void
         */
        public static function startTrans(): void
        {
            self::$events[] = 'start';
        }

        /**
         * @return void
         */
        public static function commit(): void
        {
            self::$events[] = 'commit';
        }

        /**
         * @return void
         */
        public static function rollback(): void
        {
            self::$events[] = 'rollback';
        }
    }
}

namespace {
    use app\common\model\admin\MaterialModel;
    use app\common\service\admin\MaterialService;
    use think\facade\Db;

    /**
     * @param string $message
     * @return never
     * @throws \RuntimeException
     */
    function exception(string $message = ''): never
    {
        throw new \RuntimeException($message);
    }

    /**
     * @return int
     */
    function user_id(): int
    {
        return 1;
    }

    /**
     * @return string
     */
    function datetime(): string
    {
        return '2026-08-07 15:52:00';
    }

    require dirname(__DIR__, 2) . '/app/common/service/admin/MaterialService.php';

    $passed = 0;
    $failed = [];

    /**
     * @param string $name
     * @param callable $callback
     * @return void
     */
    function runMaterialServiceEditCase(string $name, callable $callback): void
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
     * @throws \RuntimeException
     */
    function expectMaterialServiceEdit(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return void
     */
    function resetMaterialServiceEditModel(array $row): void
    {
        MaterialModel::$row = $row;
        MaterialModel::$events = [];
        MaterialModel::$updateResult = 1;
        MaterialModel::$selectRowsOverride = null;
        Db::$events = [];
    }

    runMaterialServiceEditCase('single_round_steel_non_identity_edit_validates_archive', static function (): void {
        resetMaterialServiceEditModel([
            'id' => 1,
            'material_property' => '圆钢',
            'material_name' => '45#',
            'manufacturer' => '',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ]);

        try {
            MaterialService::edit(1, ['supplier' => '甲供']);
        } catch (\Throwable $exception) {
            expectMaterialServiceEdit(
                str_contains($exception->getMessage(), '生产厂商'),
                '历史圆钢缺少厂商时错误提示不明确'
            );
            expectMaterialServiceEdit(
                Db::$events === ['start', 'rollback'],
                '校验异常必须回滚且保留原始业务异常'
            );
            return;
        }

        throw new \RuntimeException('单条圆钢非身份更新跳过了四字段非空校验');
    });

    runMaterialServiceEditCase('single_round_steel_non_identity_edit_skips_duplicate_query', static function (): void {
        resetMaterialServiceEditModel([
            'id' => 1,
            'material_property' => '圆钢',
            'material_name' => '45#',
            'manufacturer' => '宝钢',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ]);

        MaterialService::edit(1, ['supplier' => '甲供']);
        expectMaterialServiceEdit(
            Db::$events === ['start', 'commit'],
            '编辑成功必须在事务内提交'
        );
        expectMaterialServiceEdit(
            in_array('select', MaterialModel::$events, true)
                && in_array('lock', MaterialModel::$events, true),
            '单条圆钢编辑未锁定读取原档案'
        );
        expectMaterialServiceEdit(
            !in_array('find-duplicate', MaterialModel::$events, true),
            '未提交身份字段时不应执行查重'
        );
        expectMaterialServiceEdit(
            !in_array('count', MaterialModel::$events, true),
            '单条读取后不应再次 count'
        );
    });

    runMaterialServiceEditCase('single_product_non_identity_edit_keeps_no_duplicate_query', static function (): void {
        resetMaterialServiceEditModel([
            'id' => 2,
            'material_property' => '成品',
            'material_name' => '齿轮',
            'manufacturer' => '',
            'material_spec' => '20CrMnTi',
            'material_model' => 'M20',
        ]);

        MaterialService::edit(2, ['supplier' => '乙供']);
        expectMaterialServiceEdit(
            in_array('select', MaterialModel::$events, true)
                && in_array('lock', MaterialModel::$events, true),
            '单条成品编辑未锁定读取原档案'
        );
        expectMaterialServiceEdit(
            !in_array('find-duplicate', MaterialModel::$events, true),
            '成品非身份更新不应执行查重'
        );
    });

    runMaterialServiceEditCase('single_round_steel_identity_edit_runs_duplicate_query', static function (): void {
        resetMaterialServiceEditModel([
            'id' => 3,
            'material_property' => '圆钢',
            'material_name' => '45#',
            'manufacturer' => '宝钢',
            'material_spec' => '40Cr',
            'material_model' => 'Φ50',
        ]);

        MaterialService::edit(3, ['material_model' => 'Φ60']);
        expectMaterialServiceEdit(
            in_array('find-duplicate', MaterialModel::$events, true),
            '单条圆钢身份字段更新未执行查重'
        );
    });

    runMaterialServiceEditCase('single_deleted_record_is_rejected', static function (): void {
        resetMaterialServiceEditModel([
            'id' => 4,
            'is_delete' => 1,
            'material_property' => '成品',
            'material_name' => '齿轮',
            'manufacturer' => '',
            'material_spec' => '20CrMnTi',
            'material_model' => 'M20',
        ]);

        try {
            MaterialService::edit(4, ['supplier' => '丙供']);
        } catch (\Throwable $exception) {
            expectMaterialServiceEdit(
                str_contains($exception->getMessage(), '不存在'),
                '已删除档案错误提示不明确'
            );
            expectMaterialServiceEdit(
                Db::$events === ['start', 'rollback'],
                '已删除档案必须回滚事务'
            );
            return;
        }

        throw new \RuntimeException('已删除档案仍可编辑');
    });

    runMaterialServiceEditCase('batch_non_identity_edit_requires_all_active_ids', static function (): void {
        resetMaterialServiceEditModel([
            'id' => 5,
            'is_delete' => 0,
            'material_property' => '配件',
            'material_name' => '轴承',
            'manufacturer' => '',
            'material_spec' => '6205',
            'material_model' => 'ZZ',
        ]);

        try {
            MaterialService::edit([5, 6], ['supplier' => '丁供']);
        } catch (\Throwable $exception) {
            expectMaterialServiceEdit(
                str_contains($exception->getMessage(), '不存在'),
                '批量目标不完整时错误提示不明确'
            );
            expectMaterialServiceEdit(
                !in_array('update', MaterialModel::$events, true),
                '批量目标不完整时不得更新'
            );
            return;
        }

        throw new \RuntimeException('批量目标缺失时仍执行更新');
    });

    runMaterialServiceEditCase('missing_batch_target_precedes_identity_guard', static function (): void {
        resetMaterialServiceEditModel([
            'id' => 7,
            'is_delete' => 0,
            'material_property' => '成品',
            'material_name' => '齿轮',
            'manufacturer' => '',
            'material_spec' => '20CrMnTi',
            'material_model' => 'M20',
        ]);

        try {
            MaterialService::edit([7, 8], ['material_model' => 'M30']);
        } catch (\Throwable $exception) {
            expectMaterialServiceEdit(
                str_contains($exception->getMessage(), '不存在'),
                '应先报告批量目标不存在或已删除'
            );
            return;
        }

        throw new \RuntimeException('批量身份编辑未确认全部目标');
    });

    runMaterialServiceEditCase('zero_row_update_after_locked_validation_commits', static function (): void {
        resetMaterialServiceEditModel([
            'id' => 9,
            'is_delete' => 0,
            'material_property' => '成品',
            'material_name' => '齿轮',
            'manufacturer' => '',
            'material_spec' => '20CrMnTi',
            'material_model' => 'M20',
        ]);
        MaterialModel::$updateResult = 0;

        MaterialService::edit(9, ['supplier' => '戊供']);
        expectMaterialServiceEdit(
            Db::$events === ['start', 'commit'],
            '目标已锁定验证后，零行更新不应误判为数据库异常'
        );
    });

    runMaterialServiceEditCase('batch_non_identity_edit_all_active_ids_commits', static function (): void {
        resetMaterialServiceEditModel([]);
        MaterialModel::$selectRowsOverride = [
            [
                'id' => 10,
                'is_delete' => 0,
                'material_property' => '配件',
            ],
            [
                'id' => 11,
                'is_delete' => 0,
                'material_property' => '配件',
            ],
        ];

        MaterialService::edit([10, 11], ['supplier' => '己供']);
        expectMaterialServiceEdit(
            Db::$events === ['start', 'commit'],
            '批量非身份更新全部有效时应提交'
        );
        expectMaterialServiceEdit(
            in_array('update', MaterialModel::$events, true),
            '批量非身份更新未执行写入'
        );
    });

    if ($failed !== []) {
        fwrite(STDERR, "FAIL " . count($failed) . " cases\n- " . implode("\n- ", $failed) . PHP_EOL);
        exit(1);
    }

    echo "PASS {$passed} cases\n";
}
