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
use app\common\model\admin\ProcessesProcessWageModel;
use app\common\model\admin\ProcessWageModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *工序工资服务类
 */
class ProcessWageService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'processes_code' => '',
        'processes_name' => '',
        'wage' => '', //
        'post_name' => '',
        'user_id' => '',
        'is_month' => '',
        'status' => '',
    ];

    /** 列表接口可接收的查询参数（与 ProcessWage 控制器 list 对齐） */
    public static $search_field = [
        'processes_code' => '',
        'processes_name' => '',
        'post_name' => '',
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
        $model = new ProcessWageModel();
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
        foreach ($list as &$row) {
            $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            $row['nickname'] = $uid > 0 ? (string) user_name($uid) : '';
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
            $model = new ProcessWageModel();
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
            $model = new ProcessWageModel();
            $pk = $model->getPk();
            /*自动生产编码*/
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            if (!array_key_exists('status', $param) || $param['status'] === '' || $param['status'] === null) {
                $param['status'] = 1;
            }
            $param['status'] = (int) $param['status'];
            if (!isset($param['is_delete'])) {
                $param['is_delete'] = 0;
            }
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
        $model = new ProcessWageModel();
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
        $model = new ProcessWageModel();
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
    /*启动当前工资*/
    public static function is_start($param)
    {
        Db::startTrans();
        try {
            $model = new ProcessWageModel();
            $pk = $model->getPk();
            /*自动生产编码*/
            $data = $model->find($param['id']);
            if (empty($data)) {
                \exception('未找到对应ID的内容');
            }
            $model->where('processes_code', $data['processes_code'])
                ->where('post_name', $data['post_name'])
                ->update(['status' => 0]);
            $model->where($pk, 'in', $param)->update(['status' => $param['status']]);
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('创建失败:' . $e->getMessage());
        }
        return $param;
    }
}
