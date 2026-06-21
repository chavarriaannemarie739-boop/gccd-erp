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
use app\common\model\admin\RoutingDetailModel;
use app\common\model\admin\RoutingModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class RoutingService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'routing_code' => '',
        'routing_name' => '', //
        'content' => ''
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'id' => '',
        'material_name' => '',
        'material_spec' => '', //
        'is_product' => '',
        'routing_code' => '',
        'routing_name' => '',
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
        $model = new RoutingModel();
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
     * 工艺详情
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
            $model = new RoutingModel();
            $detail = new RoutingDetailModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的工艺信息不存在');
                }
                return [];
            }
            /*添加工艺归属的工序信息*/
            $content = $detail->where('routing_code', $info['routing_code'])->where('is_delete', 0)->select()->toArray();
            $info['content'] = $content;
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
            /*生成工艺编码*/
            $model = new RoutingModel();
            $param['routing_code'] = PublicService::getCode(5)['code'];
            $pk = $model->getPk();
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            $id = $model->insertGetId($param);

            /* 同步创建工艺顺序：先规范化顺序号并校验本批内是否重复（避免空 sort 导致多行同为 NULL 误判） */
            $content = $param['content'] ?? [];
            foreach ($content as $idx => &$row) {
                if (!isset($row['sort']) || $row['sort'] === '' || $row['sort'] === null) {
                    $row['sort'] = $idx + 1;
                } else {
                    $row['sort'] = (int) $row['sort'];
                }
            }
            unset($row);
            $sorts = array_column($content, 'sort');
            if (count($sorts) !== count(array_unique($sorts))) {
                \exception('同一个工艺不允许出现相同顺序的工序');
            }
            $param['content'] = $content;

            foreach ($param['content'] as $k => &$v) {
                $v['routing_code'] = $param['routing_code'];
                $v['create_time'] = datetime();
                $v['create_uid'] = user_id();
                (new RoutingDetailModel())->insert($v);
            }
            unset($v);
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
     * 选择工序顺序
     * @param $param
     * @return mixed
     */
    public static function addProcess($param)
    {
        Db::startTrans();
        try {
            $model = new RoutingDetailModel();
            /*生成工艺编码*/
            foreach ($param['content'] as $k => &$v) {
                $data  = $model->where('sort', $v['sort'])
                    ->where('routing_code', $param['routing_code'])
                    ->find();
//                if (!empty($data)) {
//                    \exception('同一个工艺不允许出现相同顺序的工序');
//                }
                /*获取排序不允许存在相同的排序*/
                $v['routing_code'] = $param['routing_code'];
                $v['create_time'] = datetime();
                $v['create_uid'] = user_id();
                $model->insert($v);
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
        $routingModel = new RoutingModel();
        $pk = $routingModel->getPk();

        unset($param[$pk], $param['ids']);

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();

        $content = $param['content'] ?? [];
        unset($param['content']);

        /* 先同步子表工序顺序，再更新主表（原逻辑误用 RoutingDetailModel 执行主键 update，导致主表不更新且易误判失败） */
        $detailModel = new RoutingDetailModel();
        $detailModel->where('routing_code', $param['routing_code'])->delete();
        foreach ($content as $k => &$v) {
            $v['routing_code'] = $param['routing_code'];
            $v['create_time'] = datetime();
            $v['create_uid'] = user_id();
            $detailModel->insert($v);
        }

        if ($routingModel->where($pk, 'in', $ids)->count() < 1) {
            exception('工艺记录不存在');
        }
        $res = $routingModel->where($pk, 'in', $ids)->update($param);
        if ($res === false) {
            exception('更新工艺失败');
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
        $model = new RoutingModel();
        $detail = new  RoutingDetailModel();
        $pk = $model->getPk();

        $data = $model->find($ids);
        if ($real) {
            $res = $model->where($pk, 'in', $ids)->delete();
        } else {
            $update = delete_update();
            $res = $model->where($pk, 'in', $ids)->update($update);
        }
        /*连带着工序也删除*/
        $detail->where('routing_code', $data['routing_code'])->delete();
        if (empty($res)) {
            exception();
        }
        $update['ids'] = $ids;
        return $update;
    }
    public static function getProcess($routing_code)
    {
        $info = null;
        if (empty($info)) {
            $model = new RoutingModel();
            $detail = new RoutingDetailModel();
            $info = $model->where('routing_code', $routing_code)->find();
            if (empty($info)) {
                    exception('编码' . $routing_code . '的工艺信息不存在');
            }
            /*添加工艺归属的工序信息*/
            $content = $detail->with(['getProcessName'])->where('routing_code', $info['routing_code'])->where('is_delete', 0)->select()->toArray();
            $info['content'] = $content;
            $info = $info->toArray();
        }
        /*生成调试单号*/
        return $info;
    }
}
