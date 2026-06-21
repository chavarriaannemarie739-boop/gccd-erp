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
use app\common\model\admin\CheckModel;
use app\common\model\admin\ProductReportModel;
use app\common\model\admin\ProductTaskModel;
use app\common\model\admin\WageModel;
use app\common\service\wechat\Exception;
use think\console\output\Ask;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class CheckService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'check_name' => '',
        'check_code' => '', //
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'check_name' => '',
        'check_code' => '', //
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
        $model = new CheckModel();
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
            $model = new CheckModel();
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
            $model = new CheckModel();
            $pk = $model->getPk();
            /*自动生产编码*/
            $param['check_code'] = PublicService::getCode(8)['code'];
            $data = $model->where('check_name', $param['check_name'])->find();
            if (!empty($data)) {
                \exception('不允许存在相同名字的质检项');
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
        $model = new CheckModel();
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
        $model = new CheckModel();
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
            $data = $model
                ->where('check_code', $param['check_code'])
                ->where('process_code', $param['process_code'])
                ->find();
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

    public static function check($params)
    {
//        $params['repair_list'] = [
//            [
//                'name'=>'2222',
//                'num'=>'22',
//            ]
//        ];
        Db::startTrans();
        try {
            /*还需要新增一个质检记录*/
            $model = new ProductReportModel();
            $data = $model->where('report_code', $params['report_code'])->find();
            if (empty($data)) {
                \exception('检查报工编号');
            }
            /*查询当前物料的成品id*/
            $task_model = new ProductTaskModel();
            $task_data = $task_model->alias('a')
                ->where('task_code', $data['relation_code'])
                ->leftJoin('ya_production b', 'b.production_plan_code = a.relation_code')
                ->field('b.english_material')
                ->find();
            if (!empty($task_data)) {
                if ($task_data['english_material'] == null || empty($task_data['english_material'])) {
                    return false;
                }
                $material = MaterialService::info($task_data['english_material']);
                /*对 物料成品规格进行限制--现在只有断钢/穿孔的价格*/
                $material_spec = explode('*', $material['material_spec'])[0];
                /*按照物料的价格进行结算*/
                $wage_model  = new WageModel();
                $wage_data = $wage_model
                    ->where('material_spec', 'like', $params['device_name'] . '%')
                    ->where('material_spec', 'like', '%' . $material_spec . '%')
                    ->find();
                if (empty($wage_data)) {
                    $wage = 0;
                } else {
                    $wage  = $wage_data['wage'];
                }
                /*处理不良品项*/
                $repair_list = '';
                $num = 0;
                /*处理不良品项---名称*/
                if (!empty($params['repair_list'])) {
                    foreach ($params['repair_list'] as $item) {
                        /*处理名称*/
                        $repair_list = $repair_list . '' . $item['name'] . ';';
                        $num =  $num + (float)$item['num'];
                    }
                }
                /*计算所有不良品的价格*/
                $wage = $num * $wage;
                /*每个人需要扣除多少钱*/
                $people_wage = $wage / count($params['user_id']);
                /*创建检验单-----可以查询*/
                foreach ($params['user_id'] as $item) {
                    /*确定每个人需要扣掉的工资*/
                    $add_field = [
                        [
                            'user_id' => $item,
                            'relation_code' => 'zj',
                            'material_id' => $task_data['english_material'],
                            'wage' => -$people_wage,
                            'report_num' => $num,
                            'wage_sum' => $wage,
                            'create_time' => datetime(),
                            'create_uid' => user_id(),
                            'device_code' => '22222'
                        ]
                    ];
                    $data = ProductReportService::addWage($add_field);
                }
            }
            /*提交数据*/
            Db::commit();
            return $data;
            /*计算价格*/
        } catch (\Exception $e) {
            Db::rollback();
            exception('错误信息:' . $e->getMessage());
        }
    }
}
