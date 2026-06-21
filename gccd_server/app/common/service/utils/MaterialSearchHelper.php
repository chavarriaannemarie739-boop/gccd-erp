<?php

declare(strict_types=1);

namespace app\common\service\utils;

use think\facade\Db;

/**
 * 成品/圆钢物料检索公共层（展示路径与 list 筛选路径一致）
 */
class MaterialSearchHelper
{
    /**
     * 转义 LIKE 通配符，保留 * 等普通字符
     *
     * @param string $keyword
     * @return string
     */
    public static function likeKeyword(string $keyword): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $keyword);
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
        $spec = trim((string) ($param['y_material_spec'] ?? ''));
        if ($spec === '') {
            $spec = trim((string) ($param['y_mateial_spec'] ?? ''));
        }
        $model = trim((string) ($param['y_material_model'] ?? ''));
        if ($model === '') {
            $model = trim((string) ($param['y_mateial_model'] ?? ''));
        }

        return [$spec, $model];
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
    public static function planCodesByDetailMaterial(string $spec = '', string $model = ''): array
    {
        if ($spec === '' && $model === '') {
            return [];
        }
        $query = Db::name('production_detail')
            ->alias('a')
            ->join('production p', 'p.production_plan_code = a.production_plan_code')
            ->leftJoin('material b', 'b.id=a.material_id')
            ->where('p.is_delete', '=', '0')
            ->where('a.is_delete', '=', '0')
            ->whereRaw('(p.english_material IS NULL OR p.english_material = 0 OR a.material_id <> p.english_material)');
        if ($spec !== '') {
            $query->where('b.material_spec', 'like', '%' . self::likeKeyword($spec) . '%');
        }
        if ($model !== '') {
            $query->where('b.material_model', 'like', '%' . self::likeKeyword($model) . '%');
        }

        return $query->column('DISTINCT a.production_plan_code');
    }

    /**
     * 生产计划单号：按已下推工单圆钢（product_task.material_id）
     *
     * @param string $spec
     * @param string $model
     * @return array
     */
    public static function planCodesByTaskRawMaterial(string $spec = '', string $model = ''): array
    {
        if ($spec === '' && $model === '') {
            return [];
        }
        $query = Db::name('product_task')
            ->alias('t')
            ->join('production p', 'p.production_plan_code = t.relation_code')
            ->leftJoin('material m', 'm.id=t.material_id')
            ->where('t.is_delete', '=', '0')
            ->where('p.is_delete', '=', '0');
        if ($spec !== '') {
            $query->where('m.material_spec', 'like', '%' . self::likeKeyword($spec) . '%');
        }
        if ($model !== '') {
            $query->where('m.material_model', 'like', '%' . self::likeKeyword($model) . '%');
        }

        return $query->column('DISTINCT t.relation_code');
    }

    /**
     * 生产计划单号：圆钢检索（计划明细 + 工单，取并集）
     *
     * @param string $spec
     * @param string $model
     * @return array
     */
    public static function planCodesByRawMaterial(string $spec = '', string $model = ''): array
    {
        return array_values(array_unique(array_merge(
            self::planCodesByDetailMaterial($spec, $model),
            self::planCodesByTaskRawMaterial($spec, $model)
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
    public static function taskCodesByRawMaterial(string $spec = '', string $model = ''): array
    {
        if ($spec === '' && $model === '') {
            return [];
        }
        $query = Db::name('product_task')
            ->alias('t')
            ->leftJoin('material m', 'm.id=t.material_id')
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
}
