<?php

declare(strict_types=1);

namespace app\common\service\utils;

use think\facade\Db;

/**
 * 成品/圆钢物料检索公共层（展示路径与 list 筛选路径一致）
 */
class MaterialSearchHelper
{
    /* [AI-2026-08] 圆钢四字段 DTO 与检索边界开始 */

    /**
     * 转义 LIKE 通配符，保留 * 等普通字符
     *
     * @param string $keyword
     * @return string
     */
    public static function likeKeyword(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
    }

    /**
     * 解析圆钢规格/型号检索词（标准 y_material_* 与历史 y_mateial_* 兼容）
     * params() 会为未传字段填 ''，不可用 ?? 链，须按「先标准名、再历史名、取非空」合并。
     *
     * @param array $param
     * @return array{0:string,1:string}
     */
    public static function resolveRoundSteelFilter(array $param): array
    {
        $filters = self::resolveRoundSteelFilters($param);

        return [$filters['spec'], $filters['model']];
    }

    /**
     * 解析圆钢四字段检索词，标准字段优先并兼容历史拼写。
     *
     * @param array<string,mixed> $param
     * @return array{name:string,manufacturer:string,spec:string,model:string}
     */
    public static function resolveRoundSteelFilters(array $param): array
    {
        return [
            'name' => self::firstNonEmpty($param, ['y_material_name', 'y_mateial_name']),
            'manufacturer' => self::firstNonEmpty(
                $param,
                ['y_material_manufacturer', 'y_mateial_manufacturer']
            ),
            'spec' => self::firstNonEmpty($param, ['y_material_spec', 'y_mateial_spec']),
            'model' => self::firstNonEmpty($param, ['y_material_model', 'y_mateial_model']),
        ];
    }

    /**
     * @param array<string,mixed> $param
     * @param string[] $keys
     * @return string
     */
    private static function firstNonEmpty(array $param, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($param[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * 标准圆钢 DTO。
     *
     * @param array<string,mixed> $material
     * @return array<string,string>
     */
    public static function roundSteelDto(array $material): array
    {
        if (
            (int) ($material['is_delete'] ?? 0) !== 0
            || trim((string) ($material['material_property'] ?? '')) !== '圆钢'
        ) {
            return [];
        }

        return [
            'y_material_name' => (string) ($material['material_name'] ?? ''),
            'y_material_manufacturer' => (string) ($material['manufacturer'] ?? ''),
            'y_material_spec' => (string) ($material['material_spec'] ?? ''),
            'y_material_model' => (string) ($material['material_model'] ?? ''),
        ];
    }

    /**
     * 仅为圆钢档案行追加标准 DTO。
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function enrichRoundSteelArchiveRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $dto = self::roundSteelDto($row);
            if ($dto !== []) {
                $row = array_merge($row, $dto);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * 内存行是否满足圆钢四字段组合检索。
     *
     * @param array<string,mixed> $material
     * @param array<string,string> $filters
     * @return bool
     */
    public static function materialMatchesRoundSteel(array $material, array $filters): bool
    {
        $fieldMap = [
            'name' => 'material_name',
            'manufacturer' => 'manufacturer',
            'spec' => 'material_spec',
            'model' => 'material_model',
        ];
        foreach ($fieldMap as $filterKey => $field) {
            $filter = trim((string) ($filters[$filterKey] ?? ''));
            if ($filter === '') {
                continue;
            }
            if (mb_stripos((string) ($material[$field] ?? ''), $filter) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 合并计划明细与工单圆钢来源，过滤删除/非圆钢并按物料 ID 去重。
     *
     * @param array<int,array<string,mixed>> $detailRows
     * @param array<int,array<string,mixed>> $taskRows
     * @return array<int,array<string,mixed>>
     */
    public static function mergeRoundSteelMaterialRows(array $detailRows, array $taskRows): array
    {
        $result = [];
        $seen = [];
        foreach (array_merge($detailRows, $taskRows) as $row) {
            if (
                (int) ($row['is_delete'] ?? 0) !== 0
                || trim((string) ($row['material_property'] ?? '')) !== '圆钢'
            ) {
                continue;
            }
            $materialId = (int) ($row['id'] ?? $row['material_id'] ?? 0);
            $key = $materialId > 0
                ? 'mid:' . $materialId
                : implode("\x00", [
                    trim((string) ($row['material_name'] ?? '')),
                    trim((string) ($row['manufacturer'] ?? '')),
                    trim((string) ($row['material_spec'] ?? '')),
                    trim((string) ($row['material_model'] ?? '')),
                ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }

        return $result;
    }

    /**
     * 按计划聚合筛选和展示共同使用的圆钢来源（计划明细 + 工单）。
     *
     * @param string[] $planCodes
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function roundSteelMaterialsByPlanCodes(array $planCodes): array
    {
        $planCodes = array_values(array_unique(array_filter(array_map(
            static fn ($code): string => trim((string) $code),
            $planCodes
        ))));
        if ($planCodes === []) {
            return [];
        }

        $detailRows = Db::name('production_detail')
            ->alias('pd')
            ->join('material m', 'm.id=pd.material_id')
            ->whereIn('pd.production_plan_code', $planCodes)
            ->where('pd.is_delete', 0)
            ->where('m.is_delete', 0)
            ->where('m.material_property', '圆钢')
            ->field(
                'pd.production_plan_code,m.id,m.material_name,m.manufacturer,'
                . 'm.material_spec,m.material_model,m.material_property,m.is_delete'
            )
            ->select()
            ->toArray();
        $taskRows = Db::name('product_task')
            ->alias('pt')
            ->join('material m', 'm.id=pt.material_id')
            ->whereIn('pt.relation_code', $planCodes)
            ->where('pt.is_delete', 0)
            ->where('m.is_delete', 0)
            ->where('m.material_property', '圆钢')
            ->field(
                'pt.relation_code AS production_plan_code,m.id,m.material_name,m.manufacturer,'
                . 'm.material_spec,m.material_model,m.material_property,m.is_delete'
            )
            ->select()
            ->toArray();

        $detailByPlan = [];
        foreach ($detailRows as $row) {
            $detailByPlan[(string) ($row['production_plan_code'] ?? '')][] = $row;
        }
        $taskByPlan = [];
        foreach ($taskRows as $row) {
            $taskByPlan[(string) ($row['production_plan_code'] ?? '')][] = $row;
        }

        $result = [];
        foreach ($planCodes as $planCode) {
            $result[$planCode] = self::mergeRoundSteelMaterialRows(
                $detailByPlan[$planCode] ?? [],
                $taskByPlan[$planCode] ?? []
            );
        }

        return $result;
    }

    /**
     * 将圆钢四字段追加到查询构造器；字段名固定，值由构造器参数化。
     *
     * @param mixed $query
     * @param array<string,string> $filters
     * @param string $alias
     * @return mixed
     */
    public static function applyRoundSteelFilters($query, array $filters, string $alias = 'm')
    {
        $fieldMap = [
            'name' => 'material_name',
            'manufacturer' => 'manufacturer',
            'spec' => 'material_spec',
            'model' => 'material_model',
        ];
        foreach ($fieldMap as $filterKey => $field) {
            $value = trim((string) ($filters[$filterKey] ?? ''));
            if ($value !== '') {
                $query->where(
                    $alias . '.' . $field,
                    'like',
                    '%' . self::likeKeyword($value) . '%'
                );
            }
        }

        return $query;
    }

    /**
     * 盘点列表圆钢筛选：有效档案匹配实时四字段，否则匹配同一行历史快照。
     *
     * @param mixed $query
     * @param array<string,string> $filters
     * @param string $inventoryAlias
     * @param string $archiveAlias
     * @return mixed
     */
    public static function applyInventoryRoundSteelFilters(
        $query,
        array $filters,
        string $inventoryAlias = 'mi',
        string $archiveAlias = 'ym'
    ) {
        if (!self::hasRoundSteelFilters($filters)) {
            return $query;
        }
        if (
            !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $inventoryAlias)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $archiveAlias)
        ) {
            throw new \InvalidArgumentException('查询别名不合法');
        }
        $query->leftJoin(
            'material ' . $archiveAlias,
            $archiveAlias . '.id=' . $inventoryAlias . '.material_id'
        );
        $query->where(function ($outer) use ($filters, $inventoryAlias, $archiveAlias) {
            $outer->where(function ($live) use ($filters, $archiveAlias) {
                $live->whereNotNull($archiveAlias . '.id')
                    ->where($archiveAlias . '.is_delete', 0)
                    ->where($archiveAlias . '.material_property', '圆钢');
                self::applyRoundSteelFilters($live, $filters, $archiveAlias);
            });
            $outer->whereOr(function ($history) use ($filters, $inventoryAlias, $archiveAlias) {
                $history->where(function ($invalidArchive) use ($archiveAlias) {
                    $invalidArchive->whereNull($archiveAlias . '.id')
                        ->whereOr($archiveAlias . '.is_delete', '<>', 0)
                        ->whereOr($archiveAlias . '.material_property', '<>', '圆钢')
                        ->whereOr(function ($nullProperty) use ($archiveAlias) {
                            $nullProperty->whereNull($archiveAlias . '.material_property');
                        });
                });
                $snapshotFields = [
                    'name' => 'material_name',
                    'manufacturer' => 'y_material_manufacturer',
                    'spec' => 'y_material_spec',
                    'model' => 'y_material_model',
                ];
                foreach ($snapshotFields as $filterKey => $field) {
                    $value = trim((string) ($filters[$filterKey] ?? ''));
                    if ($value !== '') {
                        $history->where(
                            $inventoryAlias . '.' . $field,
                            'like',
                            '%' . self::likeKeyword($value) . '%'
                        );
                    }
                }
            });
        });

        return $query;
    }

    /**
     * 报工列表通过相关子查询筛选圆钢，不加载全量工单 ID。
     *
     * @param mixed $query
     * @param array<string,string> $filters
     * @param string $reportAlias
     * @return mixed
     */
    public static function applyProductReportRoundSteelFilters(
        $query,
        array $filters,
        string $reportAlias = 'pr'
    ) {
        if (!self::hasRoundSteelFilters($filters)) {
            return $query;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $reportAlias)) {
            throw new \InvalidArgumentException('查询别名不合法');
        }
        $subQuery = Db::name('product_task')
            ->alias('rpt')
            ->join('material rym', 'rym.id=rpt.material_id')
            ->whereColumn('rpt.task_code', $reportAlias . '.relation_code')
            ->where('rpt.is_delete', 0)
            ->where('rym.is_delete', 0)
            ->where('rym.material_property', '圆钢')
            ->field('1');
        self::applyRoundSteelFilters($subQuery, $filters, 'rym');
        $query->whereExists($subQuery);

        return $query;
    }

    /**
     * @param array<string,string> $filters
     * @return bool
     */
    public static function hasRoundSteelFilters(array $filters): bool
    {
        return implode('', array_map('trim', $filters)) !== '';
    }

    /**
     * 生产计划单号：按成品物料（production.english_material）
     *
     * @param string $spec
     * @param string $model
     * @return array
     */
    public static function planCodesByFinishedMaterial(string $spec = '', string $model = ''): array
    {
        if ($spec === '' && $model === '') {
            return [];
        }
        $query = Db::name('production')
            ->alias('p')
            ->leftJoin('material m', 'm.id=p.english_material')
            ->where('p.is_delete', '=', '0');
        if ($spec !== '') {
            $query->where('m.material_spec', 'like', '%' . self::likeKeyword($spec) . '%');
        }
        if ($model !== '') {
            $query->where('m.material_model', 'like', '%' . self::likeKeyword($model) . '%');
        }
        return $query->column('DISTINCT p.production_plan_code');
    }

    /**
     * 物料规格/型号是否命中模糊检索（与 SQL LIKE 一致，同一行 AND）
     *
     * @param string $matSpec
     * @param string $matModel
     * @param string $filterSpec
     * @param string $filterModel
     * @return bool
     */
    public static function materialMatchesSpecModel(
        string $matSpec,
        string $matModel,
        string $filterSpec = '',
        string $filterModel = ''
    ): bool {
        if ($filterSpec === '' && $filterModel === '') {
            return true;
        }
        if ($filterSpec !== '' && mb_stripos($matSpec, $filterSpec) === false) {
            return false;
        }
        if ($filterModel !== '' && mb_stripos($matModel, $filterModel) === false) {
            return false;
        }

        return true;
    }

    /**
     * 生产计划单号：按圆钢明细（production_detail.material_id）
     *
     * @param string $spec
     * @param string $model
     * @return array
     */
    public static function planCodesByDetailMaterial(
        string $spec = '',
        string $model = '',
        string $name = '',
        string $manufacturer = ''
    ): array
    {
        $filters = compact('name', 'manufacturer', 'spec', 'model');
        if (!self::hasRoundSteelFilters($filters)) {
            return [];
        }
        $query = Db::name('production_detail')
            ->alias('a')
            ->join('production p', 'p.production_plan_code = a.production_plan_code')
            ->leftJoin('material b', 'b.id=a.material_id')
            ->where('p.is_delete', '=', '0')
            ->where('a.is_delete', '=', '0')
            ->where('b.is_delete', '=', '0')
            ->where('b.material_property', '=', '圆钢')
            ->whereRaw('(p.english_material IS NULL OR p.english_material = 0 OR a.material_id <> p.english_material)');
        self::applyRoundSteelFilters($query, $filters, 'b');

        return $query->column('DISTINCT a.production_plan_code');
    }

    /**
     * 生产计划单号：按已下推工单圆钢（product_task.material_id）
     *
     * @param string $spec
     * @param string $model
     * @return array
     */
    public static function planCodesByTaskRawMaterial(
        string $spec = '',
        string $model = '',
        string $name = '',
        string $manufacturer = ''
    ): array
    {
        $filters = compact('name', 'manufacturer', 'spec', 'model');
        if (!self::hasRoundSteelFilters($filters)) {
            return [];
        }
        $query = Db::name('product_task')
            ->alias('t')
            ->join('production p', 'p.production_plan_code = t.relation_code')
            ->leftJoin('material m', 'm.id=t.material_id')
            ->where('t.is_delete', '=', '0')
            ->where('p.is_delete', '=', '0')
            ->where('m.is_delete', '=', '0')
            ->where('m.material_property', '=', '圆钢');
        self::applyRoundSteelFilters($query, $filters, 'm');

        return $query->column('DISTINCT t.relation_code');
    }

    /**
     * 生产计划单号：圆钢检索（计划明细 + 工单，取并集）
     *
     * @param string $spec
     * @param string $model
     * @return array
     */
    public static function planCodesByRawMaterial(
        string $spec = '',
        string $model = '',
        string $name = '',
        string $manufacturer = ''
    ): array
    {
        return array_values(array_unique(array_merge(
            self::planCodesByDetailMaterial($spec, $model, $name, $manufacturer),
            self::planCodesByTaskRawMaterial($spec, $model, $name, $manufacturer)
        )));
    }

    /**
     * 工单号 task_code：按成品（product_task → production.english_material）
     *
     * @param string $spec
     * @param string $model
     * @return array
     */
    public static function taskCodesByFinishedMaterial(string $spec = '', string $model = ''): array
    {
        if ($spec === '' && $model === '') {
            return [];
        }
        $query = Db::name('product_task')
            ->alias('t')
            ->leftJoin('production p', 'p.production_plan_code = t.relation_code')
            ->leftJoin('material m', 'm.id = p.english_material')
            ->where('t.is_delete', '=', '0');
        if ($spec !== '') {
            $query->where('m.material_spec', 'like', '%' . self::likeKeyword($spec) . '%');
        }
        if ($model !== '') {
            $query->where('m.material_model', 'like', '%' . self::likeKeyword($model) . '%');
        }
        return $query->column('DISTINCT t.task_code');
    }

    /**
     * 工单号 task_code：按圆钢（product_task.material_id）
     *
     * @param string $spec
     * @param string $model
     * @return array
     */
    public static function taskCodesByRawMaterial(
        string $spec = '',
        string $model = '',
        string $name = '',
        string $manufacturer = ''
    ): array
    {
        $filters = compact('name', 'manufacturer', 'spec', 'model');
        if (!self::hasRoundSteelFilters($filters)) {
            return [];
        }
        $query = Db::name('product_task')
            ->alias('t')
            ->leftJoin('material m', 'm.id=t.material_id')
            ->where('t.is_delete', '=', '0')
            ->where('m.is_delete', '=', '0')
            ->where('m.material_property', '=', '圆钢');
        self::applyRoundSteelFilters($query, $filters, 'm');
        return $query->column('DISTINCT t.task_code');
    }

    /**
     * 生产计划单号：成品物料多字段 OR 模糊（发货 product_info）
     *
     * @param string $keyword
     * @return array
     */
    public static function planCodesByFinishedKeyword(string $keyword): array
    {
        if ($keyword === '') {
            return [];
        }
        $like = '%' . self::likeKeyword($keyword) . '%';
        return Db::name('production')
            ->alias('p')
            ->join('material m_yg', 'p.english_material = m_yg.id')
            ->where('p.is_delete', 0)
            ->where(function ($query) use ($like) {
                $query->whereOr('m_yg.material_name', 'like', $like)
                    ->whereOr('m_yg.material_spec', 'like', $like)
                    ->whereOr('m_yg.material_model', 'like', $like);
            })
            ->column('p.production_plan_code');
    }

    /**
     * 生产计划单号：圆钢多字段 OR 模糊（发货 y_material_spec 字段复用）
     *
     * @param string $keyword
     * @return array
     */
    public static function planCodesByRawTaskKeyword(string $keyword): array
    {
        if ($keyword === '') {
            return [];
        }
        $like = '%' . self::likeKeyword($keyword) . '%';
        return Db::name('product_task')
            ->alias('pt')
            ->join('material m_cp', 'pt.material_id = m_cp.id')
            ->where('pt.is_delete', 0)
            ->where('m_cp.is_delete', 0)
            ->where('m_cp.material_property', '圆钢')
            ->where(function ($query) use ($like) {
                $query->whereOr('m_cp.material_name', 'like', $like)
                    ->whereOr('m_cp.material_spec', 'like', $like)
                    ->whereOr('m_cp.material_model', 'like', $like);
            })
            ->column('DISTINCT pt.relation_code');
    }

    /**
     * 合并多组 plan_code 过滤（交集）
     *
     * @param array $groups
     * @return array
     */
    public static function intersectPlanCodeGroups(array $groups): array
    {
        $groups = array_values(array_filter($groups, static fn ($g) => is_array($g)));
        if ($groups === []) {
            return [];
        }
        $result = array_shift($groups);
        foreach ($groups as $codes) {
            $result = array_values(array_intersect($result, $codes));
        }
        return $result;
    }

    /* [AI-2026-08] 圆钢四字段 DTO 与检索边界结束 */
}
