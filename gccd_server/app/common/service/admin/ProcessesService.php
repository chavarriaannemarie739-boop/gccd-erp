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
use app\common\model\admin\ProcessesCheckModel;
use app\common\model\admin\ProcessesModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class ProcessesService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'processes_name' => '',
        'processes_code' => '', //
        'is_finally' => '',
        'check_list' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'processes_name' => '',
        'processes_code' => '', //
        'is_finally' => '',
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
        $model = new ProcessesModel();
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
            $model = new ProcessesModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的工序信息不存在');
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
//            $param['check_list'] = [
//                [
//                    'check_code'=>'2522xx',
//                    'check_name'=>'ceshi',
//                ]
//            ];
            $model = new ProcessesModel();
            $pk = $model->getPk();
            /*自动生产编码*/
            $data = $model->max('id');
            $param['processes_code'] = 'gx_00' . $data + 1;
            if (empty($data)) {
                $result = $model->where('processes_code', $param['processes_code'])->isEmpty();
                if (!$result) {
                    $param['processes_code'] = 'gx_00' . $data + 2;
                }
            }
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            $id = $model->insertGetId($param);
            $param[$pk] = $id;
            /*创建检验项列表*/
            if (is_array($param['check_list'])) {
                foreach ($param['check_list'] as $key => &$value) {
                    $value['processes_code'] = $param['processes_code'];
                    self::addCheck($value);
                }
            }
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
        $model = new ProcessesModel();
        $pk = $model->getPk();

        unset($param[$pk], $param['ids']);

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();

        if ($model->where($pk, 'in', $ids)->count() < 1) {
            exception('工序记录不存在');
        }
        $res = $model->where($pk, 'in', $ids)->update($param);
        if ($res === false) {
            exception('更新工序失败');
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
        $model = new ProcessesModel();
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
    /*添加工序检验*/
    public static function addcheck($param)
    {
        Db::startTrans();
        try {
            $model = new ProcessesCheckModel();
            $pk = $model->getPk();
            /*自动生产编码*/
            $data = $model->where('check_code', $param['check_code'])->find();
            if (!empty($data)) {
                \exception('同一道工序不允许创建相同的检验项');
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

    public static function checklist($param)
    {
        $model = new ProcessesCheckModel();
        $data = $model->where('processes_code', $param)->select()->toArray();
        return $data;
    }
}
