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
use app\common\model\admin\ShipModel;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *发货服务类
 */
class ShipService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'material_id' => '',
        'material_batch' => '', //
        'material_num' => '',
        'relation_code' => '',
        'content' => '',
        'customer_name' => '',//客户名称
        'y_material_spec' => '',
        'product_info' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'id' => '',
        'material_name' => '',
        'material_spec' => '', //
        'material_thickness' => '',
        'material_model' => '',
        'supplier' => '',
        'manufacturer' => '',
        'material_num' => '',//物料属性
        'relation_code' => '',
        'ship_code' => '',
        'material_batch' => '',
        'customer_name' => '',//客户名称
        'y_material_spec' => '',
        'product_info' => '',
        'create_start_date' => '',
        'create_end_date' => '',
        'ship_date' => '',
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
        $model = new ShipModel();
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
        /* 按计划单号补全圆钢信息 / 成品信息（与成品库选料展示一致；不依赖 ya_ship 是否已加扩展字段） */
        $labelMap = InStockService::labelsForShipRelationCodes(array_column($list, 'relation_code'));
        foreach ($list as &$row) {
            $code = isset($row['relation_code']) ? trim((string) $row['relation_code']) : '';
            if ($code !== '' && isset($labelMap[$code])) {
                $row['y_material_spec'] = $labelMap[$code]['y_material_spec'];
                $row['product_info'] = $labelMap[$code]['product_info'];
            }
        }
        unset($row);

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
            $model = new ShipModel();
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
        /*发货单的状态需要多选*/
//        $add = [
//            [
//                'material_id'=>1853,
//                'material_batch'=>'WLPC-202511230002', //
//                'material_num'=>1,
//                'relation_code'=>'ssss-ceshi-002335566',
//                'remark'=>'发货测试',
//            ]
//        ];
        Db::startTrans();
        try {
            $model = new ShipModel();
            $pk = $model->getPk();
            $shio_code = PublicService::getCode(13)['code'];
            $arr = [];
            if (is_array($param['content'])) {
                foreach ($param['content'] as $k => &$v) {
                    $qty = isset($v['material_num']) ? round((float) $v['material_num'], 2) : 0.0;
                    if ($qty <= 0) {
                        exception('发货数量须为大于 0 的数字，最多保留两位小数');
                    }
                    $planCode = trim((string) ($v['relation_code'] ?? $v['material_batch'] ?? ''));
                    $stockOwner = trim((string) ($v['stock_owner'] ?? ''));
                    $check = FinishedStockService::validateShipQty($planCode, $stockOwner, $qty);
                    if (!$check['allowed']) {
                        exception(
                            '发货数量超过容差上限（系统库存 '
                            . $check['available']
                            . ' 吨，最多可发 '
                            . $check['max_allowed']
                            . ' 吨，含 ±'
                            . (FinishedStockService::SHIP_OVER_TOLERANCE_RATIO * 100)
                            . '% 容差）'
                        );
                    }
                    if ($stockOwner === '' && $planCode !== '') {
                        $planRow = Db::name('production')
                            ->where('production_plan_code', $planCode)
                            ->where('is_delete', 0)
                            ->find();
                        $stockOwner = trim((string) ($planRow['production_plan_name'] ?? ''));
                        $v['stock_owner'] = $stockOwner;
                    }
                    $v['material_num'] = $qty;
                    $v['ship_code'] = $shio_code;
                    $v['create_time'] = datetime();
                    $v['create_uid'] = user_id();
                    $id = $model->insertGetId($v);
                    $v[$pk] = $id;
                    array_push($arr, $v);
                    if ($check['over_qty'] > 0) {
                        FinishedStockService::recordOverShipAdjust(
                            $v,
                            $shio_code,
                            $check['over_qty'],
                            $check['available']
                        );
                    }
                    FinishedStockService::deductWmsMaterial(
                        (int) ($v['material_id'] ?? 0),
                        (string) ($v['material_batch'] ?? ''),
                        $qty
                    );
                }
            } else {
                \exception('请检查传输数据的格式');
            }
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('创建发货单失败:' . $e->getMessage());
        }
        return $arr;
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
        $model = new ShipModel();
        $pk = $model->getPk();

        unset($param[$pk], $param['ids']);

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();

        $res = $model->where($pk, 'in', $ids)->update($param);
        if (empty($res)) {
            exception();
        }
        $param['ids'] = $ids;


        return $param;
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
        $model = new ShipModel();
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

    /**
     * 获取历史客户列表
     * @return array
     */
    public static function customer_list()
    {
        $model = new ShipModel();
        $list = $model->where('is_delete', 0)
            ->where('customer_name', '<>', '')
            ->group('customer_name')
            ->column('customer_name');

        $result = [];
        foreach ($list as $name) {
            $result[] = ['value' => $name];
        }
        return $result;
    }
}
