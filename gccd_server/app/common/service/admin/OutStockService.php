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
use app\common\model\admin\OutStockModel;
use app\common\model\admin\StockMaterialModel;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class OutStockService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'out_code' => '', //文章分类id
        'out_num' => '', //文章标题
        'material_id' => '',
        'remark' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'material_name' => '', //物料名称
        'material_spec' => '', //物料规格
        'out_code' => '', //入库编码
        'relation_code' => '', //关联编码
        'material_batch' => '',
        'warehouse' => '',
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
        $model = new OutStockModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            return $model->with(['getMaterial','getStock'])->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getMaterial','getStock'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
        foreach ($list as $k => &$v) {
            $v['create_name'] = user_name($v['create_uid']);
        }
        /*为列表添加采购前的数据*/
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
            $model = new OutStockModel();
            $info = $model->find($id);
            /*检查入库单查找相同单号的数据*/
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的出库单信息不存在');
                }
                return [];
            }
            $content = $model->with(['getMaterial'])->where('out_code', $info['out_code'])->select()->toArray();
            $info['content'] =  $content;
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
            /*判断出库编码是否为空*/
            if (empty($param['out_code'])) {
                $param['out_code'] = PublicService::getCode(2)['code'];
            }
            $model = new OutStockModel();
            $stockModel = new StockMaterialModel();
            foreach ($param['content'] as $k => &$v) {
                $v['out_code'] = $param['out_code'];
                if ($v['out_num'] < 0 || empty($v['out_num'])) {
                    exception('请检查出库数量为正常值');
                }
                $batch = isset($v['material_batch']) ? trim((string) $v['material_batch']) : '';
                if ($batch !== '') {
                    $stockRow = $stockModel
                        ->where('material_id', $v['material_id'])
                        ->where('material_batch', $batch)
                        ->where('is_delete', 0)
                        ->find();
                    if (empty($stockRow)) {
                        exception('库存中不存在该物料与批次的记录，请从物料库存重新选择');
                    }
                    $avail = (float) ($stockRow['material_num'] ?? 0);
                    $need = (float) $v['out_num'];
                    if ($need > $avail) {
                        exception('出库数量不能超过该批次可用库存（当前：' . $avail . '）');
                    }
                }
                $pk = $model->getPk();
                $v['create_time'] = datetime();
                $v['create_uid'] = user_id();
                $model->insert($v);
                /*修改仓库中物料的数量*/
            }
            /*获取最后一个值*/
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('出库单创建失败:' . $e->getMessage());
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
        $model = new OutStockModel();
        $pk = $model->getPk();
        unset($param[$pk], $param['ids']);
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
        $model = new OutStockModel();
        $pk = $model->getPk();
        /*删除对应文件*/
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
     * 修改仓库物料的数量
     * @param $param
     * @return OutStockModel|array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function AddmaterialNum($material_id, $num, $material_batch = '')
    {
        $model = new StockMaterialModel();
        if (empty($material_batch)) {
            /*按照物料id扣除数量*/
            $data = $model
                ->where('material_id', $material_id)
                ->where('is_delete', 0)
                ->find();
            if (empty($data)) {
                exception('当前物料信息不存在库存信息,请先入库');
            } else {
                if ($data['material_num'] - $num < 0) {
                    exception('当前出库物料不足:' . $material_id);
                } else {
                    $model->where('material_id', $material_id)->update(['material_num' => $data['material_num'] - $num]);
                }
            }
        } else {
            /*按照批次号扣除数量*/
            $data = $model
                ->where('material_id', $material_id)
                ->where('material_batch', $material_batch)
                ->where('is_delete', 0)
                ->find();
            if (empty($data)) {
                exception('当前物料信息不存在库存信息,请先入库');
            } else {
                if ($data['material_num'] - $num < 0) {
                    exception('当前批次出库物料不足:' . $material_id);
                } else {
                    $model
                        ->where('material_batch', $material_batch)
                        ->where('material_id', $material_id)
                        ->update(['material_num' => $data['material_num'] - $num]);
                }
            }
        }
        return $data;
    }

    public static function editStatus($id)
    {
        Db::startTrans();
        try {
            $model = new OutStockModel();
            /*获取物料id信息*/
            $info = $model->find($id['id']);
            /* 按批次扣减 wms_material，必须与库管确认的 material_batch 一致；勿省略第三参否则会按 material_id 误扣一行 */
            $confirmBatch = isset($id['material_batch']) ? trim((string) $id['material_batch']) : '';
            if ($confirmBatch === '') {
                exception('确认出库必须选择物料批次');
            }
            $stockModel = new StockMaterialModel();
            $stockRow = $stockModel
                ->where('material_id', $info['material_id'])
                ->where('material_batch', $confirmBatch)
                ->where('is_delete', 0)
                ->find();
            if (empty($stockRow)) {
                exception('当前库存中不存在该物料与批次的记录，请核对后重试');
            }
            $avail = (float) ($stockRow['material_num'] ?? 0);
            $need = (float) ($info['out_num'] ?? 0);
            if ($need > $avail) {
                exception('出库数量不能超过该批次可用库存（当前：' . $avail . '，本单：' . $need . '）');
            }
            $result = self::AddmaterialNum($info['material_id'], $info['out_num'], $confirmBatch);
            /*修改订单审核状态*/
            $model->where('id', $id['id'])->update(['status' => 1,'material_batch' => $id['material_batch']]);

            if ($info['remark'] == '领料单') {
                $in_model = new InStockModel();
                /*扣去领取物料的价格*/
                $in_data = $in_model
                    ->where('material_id', $info['material_id'])
                    ->where('material_batch', $id['material_batch'])
                    ->find();
                /*扣除对应领料人的工资*/
                if (empty($in_data)) {
                    exception('没有找到对应批次的出库物料');
                }
                $money = $info['out_num'] * $in_data['money'];
                $add_field = [
                    [
                        'user_id' => $info['picker'],
                        'relation_code' => $info['out_code'],
                        'material_id' => $info['material_id'],
                        'wage' => -$money,
                        'report_num' => $info['out_num'],
                        'wage_sum' => 0,
                        'create_time' => datetime(),
                        'create_uid' => user_id(),
                        'device_code' => '22222'
                    ]
                ];
                $data = ProductReportService::addWage($add_field);
            }
            /*添加关联上批次号*/
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception($e->getMessage());
        }
        return $id;
    }
}
