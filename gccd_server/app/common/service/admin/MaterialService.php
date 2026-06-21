<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\model\admin\InStockModel;
use app\common\model\admin\MaterialModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class MaterialService
{
    /**
     * 物料档案查重：仅在同一 material_property（圆钢/成品/配件）内判断；
     * 名称、型号、规格三项 trim 后完全一致视为重复（仅两项相同允许并存）。
     *
     * @param MaterialModel $model
     * @param array{name:string,spec:string,model:string} $triple
     * @param array<int> $excludeIds 编辑时排除自身 id
     * @param string $materialProperty 圆钢|成品|配件
     */
    private static function assertUniqueMaterialTriple(
        MaterialModel $model,
        array $triple,
        array $excludeIds = [],
        string $materialProperty = ''
    ): void {
        $name = $triple['name'];
        $spec = $triple['spec'];
        $modelStr = $triple['model'];
        $prop = trim($materialProperty);
        if ($prop === '') {
            exception('无法校验重复：缺少物料属性');
        }
        /* 与库内 NULL/空串统一按 trim 后字符串比较，避免同义重复 */
        $q = $model->where('is_delete', 0)
            ->where('material_property', $prop)
            ->whereRaw('TRIM(IFNULL(material_name, \'\')) = ?', [$name])
            ->whereRaw('TRIM(IFNULL(material_spec, \'\')) = ?', [$spec])
            ->whereRaw('TRIM(IFNULL(material_model, \'\')) = ?', [$modelStr]);
        if ($excludeIds !== []) {
            $q->whereNotIn('id', $excludeIds);
        }
        $exists = $q->find();
        if (!empty($exists)) {
            exception(
                '同一档案分类（' . $prop . '）下已存在名称、型号、规格完全相同的物料，请勿重复建档'
            );
        }
    }

    /**
     * @param array<string, mixed> $param
     * @param array<string, mixed>|null $existing 编辑时库中原记录，用于补全未传字段
     * @return array{name:string,spec:string,model:string}
     */
    private static function normalizeMaterialTriple(array $param, ?array $existing = null): array
    {
        $e = $existing ?? [];
        $name = trim((string) ($param['material_name'] ?? $e['material_name'] ?? ''));
        $spec = trim((string) ($param['material_spec'] ?? $e['material_spec'] ?? ''));
        $modelStr = trim((string) ($param['material_model'] ?? $e['material_model'] ?? ''));
        return ['name' => $name, 'spec' => $spec, 'model' => $modelStr];
    }

    /**
     * 物料档案三类属性（与圆钢/成品/配件档案列表筛选一致）
     *
     * @param mixed $raw
     */
    private static function assertMaterialPropertyAllowed($raw): string
    {
        $v = trim((string) $raw);
        $allowed = ['圆钢', '成品', '配件'];
        if (!in_array($v, $allowed, true)) {
            exception('物料属性必须为「圆钢」「成品」或「配件」。当前值无效或未提交，请从对应档案页新增，或联系管理员检查接口参数与数据库默认值。');
        }
        return $v;
    }

    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'material_name' => '',
        'material_spec' => '', //
        'is_product' => '',
        'material_thickness' => '',
        'material_model' => '',
        'supplier' => '',
        'manufacturer' => '',
        'material_property' => '',//物料属性
        'stock' => '',//所属仓库
        'min_stock_qty' => '',//最低库存预警阈值
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'id' => '',
        'material_name' => '',
        'material_spec' => '', //
        'is_product' => '',
        'material_thickness' => '',
        'material_model' => '',
        'supplier' => '',
        'manufacturer' => '',
        'material_property' => '',//物料属性
        'material_scope' => '',
        'stock' => '',//所属仓库
        'stock_alert_only' => '',
        'create_start_date' => '',
        'create_end_date' => '',
    ];
    /**
     * 列表
     *
     * @param array  $where 条件
     * @param int    $page  页数
     * @param int    $limit 数量
     * @param array  $order 排序
     * @param string $field 字段
     *
     * @return array
     */
    public static function list($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {
        $model = new MaterialModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            return $model->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
        $materialProperty = '';
        foreach ($where as $cond) {
            if (!is_array($cond)) {
                continue;
            }
            if (($cond[0] ?? '') === 'material_property' && ($cond[1] ?? '') === 'like' && !empty($cond[2])) {
                $materialProperty = trim(str_replace('%', '', (string) $cond[2]));
                break;
            }
        }
        if (in_array($materialProperty, ['配件', '圆钢'], true)) {
            $list = StockAlertService::enrichMaterialListRows($list, $materialProperty);
        }

        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 信息
     *
     * @param int  $id   id
     * @param bool $exce 不存在是否抛出异常
     *
     * @return array|Exception
     */
    public static function info($id, $exce = true)
    {
        $info = null;

        if (empty($info)) {
            $model = new MaterialModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的物料信息不存在');
                }
                return [];
            }
            $info = $info->toArray();
        }
        /*生成调试单号*/
        return $info;
    }
    /**
     * 添加
     *
     * @param array $param 信息
     *
     * @return array|Exception
     */
    public static function add($param)
    {
        Db::startTrans();
        try {
            $model = new MaterialModel();
            $pk = $model->getPk();
            $param['material_property'] = self::assertMaterialPropertyAllowed($param['material_property'] ?? '');
            $triple = self::normalizeMaterialTriple($param, null);
            self::assertUniqueMaterialTriple($model, $triple, [], $param['material_property']);
            if (array_key_exists('min_stock_qty', $param)) {
                $param['min_stock_qty'] = StockAlertService::normalizeMinStockQty($param['min_stock_qty']);
            }
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            $id = $model->insertGetId($param);
            $param[$pk] = $id;
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('创建失败:' . $e->getMessage());
        }
        return $param;
    }

     /**
     * 修改
     *
     * @param int|array $ids   id
     * @param array     $param 信息
     *
     * @return array|Exception
     */
    public static function edit($ids, $param = [])
    {
        $model = new MaterialModel();
        $pk = $model->getPk();

        unset($param[$pk], $param['ids']);

        $idList = is_array($ids) ? $ids : [$ids];
        $first = $model->find($idList[0] ?? 0);
        $existingRow = $first ? $first->toArray() : null;
        $triple = self::normalizeMaterialTriple($param, $existingRow);
        /* 查重范围：以提交的属性为准，未传则用原记录（保证圆钢/成品/配件互不干扰） */
        $propForUniq = trim((string) ($param['material_property'] ?? $existingRow['material_property'] ?? ''));
        $propForUniq = self::assertMaterialPropertyAllowed($propForUniq);
        self::assertUniqueMaterialTriple($model, $triple, array_map('intval', $idList), $propForUniq);

        if (array_key_exists('material_property', $param)) {
            $mp = trim((string) ($param['material_property'] ?? ''));
            if ($mp !== '') {
                $param['material_property'] = self::assertMaterialPropertyAllowed($mp);
            }
        }

        if (array_key_exists('min_stock_qty', $param)) {
            $param['min_stock_qty'] = StockAlertService::normalizeMinStockQty($param['min_stock_qty']);
        }

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();

        if ($model->where($pk, 'in', $ids)->count() < 1) {
            exception('物料记录不存在');
        }
        $res = $model->where($pk, 'in', $ids)->update($param);
        if ($res === false) {
            exception('更新物料失败');
        }
        $param['ids'] = $ids;


        return $param;
    }

    /**
     * 批量更新最低库存阈值（仅档案维护）
     *
     * @param int[] $ids
     * @param mixed $minStockQty
     * @return array<string, mixed>
     */
    public static function batchUpdateMinStockQty(array $ids, $minStockQty): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            exception('请选择要设置的物料');
        }
        $model = new MaterialModel();
        $pk = $model->getPk();
        $normalized = StockAlertService::normalizeMinStockQty($minStockQty);
        $count = $model->where($pk, 'in', $ids)->where('is_delete', 0)->count();
        if ($count < 1) {
            exception('未找到可更新的物料记录');
        }
        $res = $model->where($pk, 'in', $ids)->where('is_delete', 0)->update([
            'min_stock_qty' => $normalized,
            'update_uid' => user_id(),
            'update_time' => datetime(),
        ]);
        if ($res === false) {
            exception('批量更新最低库存失败');
        }

        return ['ids' => $ids, 'min_stock_qty' => $normalized, 'updated' => (int) $res];
    }

    /**
     * 删除
     *
     * @param array $ids  id
     * @param bool  $real 是否真实删除
     *
     * @return array|Exception
     */
    public static function dele($ids, $real = true)
    {
        $model = new MaterialModel();
        $pk = $model->getPk();

        if ($real) {
            $res = $model->where($pk, 'in', $ids)->delete();
        } else {
            $update = delete_update();
            $res = $model->where($pk, 'in', $ids)->update($update);
        }

        if (empty($res)) {
            exception();
        }
        $update['ids'] = $ids;
        return $update;
    }
}
