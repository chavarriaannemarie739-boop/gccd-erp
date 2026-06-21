<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\model\admin\StockModel;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class StockService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'stock_code' => '', //仓库编码
        'stock_name' => '',
    ];
    /*搜索的字段内容获取*/
    public static $search_field = [
        'stock_name' => '',
        'stock_code' => '',
        'create_start_date' => '',
        'create_end_date' => '',
        'track_id' => '',
        'user_id' => '',
    ];
    /*仓库列表*/
    public static $add_field = [
        'id' => '',
        'stock_name' => '',
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
        $model = new StockModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }

        if ($page == 0 || $limit == 0) {
            return $model->with(['getTrack'])->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getTrack'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
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
            $model = new StockModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的申请信息不存在');
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
            $model = new StockModel();
            $pk = $model->getPk();
            if (empty($param['stock_code'])) {
                /*生层仓库编码*/
                $param['stock_code'] = PublicService::getCode(11)['code'];
            }
            $result = $model->where('stock_name', $param['stock_name'])->find();
            if (!empty($result)) {
                exception('不允许创建相同名称的仓库');
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
        $model = new StockModel();
        $pk = $model->getPk();
        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();
        $res = $model->where($pk, 'in', $ids)->update($param);
        if (empty($res)) {
            exception();
        }
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
    public static function dele($ids, $real = false)
    {
        $model = new StockModel();
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
