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
use app\common\model\admin\ProcessesReceivingModel;
use app\common\model\admin\ReceivingModel;
use app\common\service\admin\DailySummaryService;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class ReceivingService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'material_id' => '',
        'material_num' => '', //
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'receiving_code' => '',
        'relation_code' => '', //
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
        $model = new ReceivingModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            return $model->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getMaterial'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
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
            $model = new ReceivingModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的领料信息不存在');
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
        $param['content'] = [
            [
                'material_id' => '1',
                'material_num' => '2',
            ]
        ];
        Db::startTrans();
        try {
            $model = new ReceivingModel();
            $pk = $model->getPk();
            /*自动生产编码*/
            $param['receiving_code'] = PublicService::getCode(9)['code'];
            $material = [];
            /*对物料进行创建*/
            foreach ($param['content'] as $value) {
                $param['material_id'] = $value['material_id'];
                $param['material_num'] = $value['material_num'];
                $param['create_time'] = datetime();
                $param['relation_code'] = $param['device_code'];
                $param['create_uid'] = user_id();
                $id = $model->insertGetId($param);
                $add = [
                    'out_num' => $value['material_num'], //出库数量
                    'material_id' => $value['material_id'], //物料id
                    'relation_code' => $param['receiving_code'], //非必填值 关联单号
                    'remark' => '领料单', //非必填值  备注
                    'picker' => user_id()
                ];
                array_push($material, $add);
            }
            $param[$pk] = $id;
            /*需不需要同步创建出库单*/
            $add_field = [
                'out_code' => PublicService::getCode(2)['code'],
                'content' => $material
            ];
            /*同步创建出库单*/
            OutStockService::add($add_field);
            // 提交事务
            Db::commit();
            // 更新日汇总表
            try {
                DailySummaryService::refresh(date('Y-m-d'));
            } catch (\Exception $ex) {
            }
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
        $model = new ReceivingModel();
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
        $model = new ReceivingModel();
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
