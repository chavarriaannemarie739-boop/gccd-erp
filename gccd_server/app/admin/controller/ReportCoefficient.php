<?php

// +----------------------------------------------------------------------
// | 高齿传动生产管理系统
// +----------------------------------------------------------------------
// | 报表系数配置控制器
// +----------------------------------------------------------------------

namespace app\admin\controller;

use app\common\controller\BaseController;
use app\common\model\admin\ReportCoefficientModel;
use think\facade\Db;

/**
 * 报表系数配置管理
 */
class ReportCoefficient extends BaseController
{
    /**
     * 系数列表
     * @return \think\response\Json
     */
    public function list()
    {
        $page = input('page/d', 1);
        $limit = input('limit/d', 15);
        $type = input('type', '');

        $query = Db::name('report_coefficient')->where('is_delete', 0);
        if ($type) {
            $query->where('type', $type);
        }

        $count = (clone $query)->count();
        $list = (clone $query)
            ->order('effective_date', 'desc')
            ->order('create_time', 'desc')
            ->page($page)
            ->limit($limit)
            ->select()
            ->toArray();

        // 添加类型名称
        $typeMap = ReportCoefficientModel::$typeMap;
        foreach ($list as &$item) {
            $typeKey = $item['type'] ?? '';
            $item['type_name_display'] = $typeMap[$typeKey] ?? ($item['type_name'] ?? $typeKey);
        }

        return success([
            'count' => $count,
            'pages' => ceil($count / $limit),
            'page' => $page,
            'limit' => $limit,
            'list' => $list
        ], '查询成功');
    }

    /**
     * 新增系数
     * @return \think\response\Json
     */
    public function add()
    {
        if (request()->isPost()) {
            $param = input('post.');

            // 验证必填字段
            if (empty($param['type']) || empty($param['value']) || empty($param['effective_date'])) {
                return error('参数不完整');
            }

            // 验证类型
            if (!isset(ReportCoefficientModel::$typeMap[$param['type']])) {
                return error('系数类型不正确');
            }

            $model = new ReportCoefficientModel();
            $param['type_name'] = ReportCoefficientModel::$typeMap[$param['type']];
            $param['create_uid'] = user_id();
            $param['create_time'] = date('Y-m-d H:i:s');

            $id = $model->insertGetId($param);
            if ($id) {
                return success(['id' => $id], '添加成功');
            } else {
                return error('添加失败');
            }
        }

        // 返回类型选项
        return success([
            'type_options' => ReportCoefficientModel::$typeMap
        ], '获取类型选项');
    }

    /**
     * 编辑系数
     * @return \think\response\Json
     */
    public function edit()
    {
        if (request()->isPost()) {
            $param = input('post.');

            if (empty($param['id'])) {
                return error('参数错误');
            }

            $model = new ReportCoefficientModel();
            $info = $model->find($param['id']);
            if (empty($info)) {
                return error('数据不存在');
            }

            $param['update_uid'] = user_id();
            $param['update_time'] = date('Y-m-d H:i:s');
            $res = $info->save($param);

            if ($res !== false) {
                return success([], '修改成功');
            } else {
                return error('修改失败');
            }
        }

        // 返回详情
        $id = input('id');
        $model = new ReportCoefficientModel();
        $info = $model->find($id);

        if (empty($info)) {
            return error('数据不存在');
        }

        return success($info->toArray(), '查询成功');
    }

    /**
     * 删除系数
     * @return \think\response\Json
     */
    public function delete()
    {
        $ids = input('ids');
        if (empty($ids)) {
            return error('参数错误');
        }

        $model = new ReportCoefficientModel();
        $res = $model->where('id', 'in', $ids)->update(['is_delete' => 1, 'delete_uid' => user_id()]);

        if ($res) {
            return success([], '删除成功');
        } else {
            return error('删除失败');
        }
    }
}
