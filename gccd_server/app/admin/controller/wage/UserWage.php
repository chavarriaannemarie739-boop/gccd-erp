<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\wage;

use app\common\controller\BaseController;
use app\common\service\admin\UserWageService;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("检验项管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class UserWage extends BaseController
{
    /**
    * @Apidoc\Title("检验列表")
    * @Apidoc\Query(ref="pagingQuery")
    * @Apidoc\Query(ref="sortQuery")
    * @Apidoc\Query(ref="searchQuery")
    * @Apidoc\Query(ref="dateQuery")
    * @Apidoc\Returned(ref="expsReturn")
    * @Apidoc\Returned(ref="pagingReturn")
    * @Apidoc\Returned("list", type="array", desc="列表", children={
    *   @Apidoc\Returned(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable,create_time,update_time")
    * })
    */
    public function list()
    {
        $where = [['is_delete','=','0']];
        /*添加搜索条件*/
        $param = $this->params(UserWageService::$search_field);

        // 用户名搜索（需要关联 user 表）
        if (!empty($param['username'])) {
            $userModel = new \app\common\model\system\UserModel();
            $users = $userModel->where('nickname', 'like', '%' . $param['username'] . '%')
                ->column('user_id');

            if (!empty($users)) {
                $where[] = ['user_id', 'in', $users];
            } else {
                $where[] = ['user_id', '=', 0];
            }
        }

        if (!empty($param['user_id'])) {
            $where[] = ['user_id', '=', $param['user_id']];
        }

        if (!empty($param['check_name'])) {
            $where[] = ['check_name', 'like', '%' . $param['check_name'] . '%'];
        }
        if (!empty($param['check_code'])) {
            $where[] = ['check_code', 'like', '%' . $param['check_code'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }

        $periodRange = null;
        if (!empty($param['period_start']) && !empty($param['period_end'])) {
            $periodRange = [
                'period_start' => $param['period_start'],
                'period_end' => $param['period_end'],
            ];
        } else {
            if (isset($param['year']) && $param['year'] !== '' && $param['year'] !== null) {
                $where[] = ['year', '=', (string) $param['year']];
            }
            if (isset($param['month']) && $param['month'] !== '' && $param['month'] !== null) {
                $where[] = ['month', '=', (string) $param['month']];
            }
        }

        $data = UserWageService::list($where, $this->page(), $this->limit(), $this->order(), [], $periodRange);
        return success($data);
    }
    /**
    * @Apidoc\Title("信息")
    * @Apidoc\Query(ref="app\common\model\MesOrderModel", field="id")
    * @Apidoc\Returned(ref="app\common\model\MesOrderModel")
    */
    public function info()
    {
        $param = $this->params(['id/d' => '']);
        $data = UserWageService::info($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("添加工序")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        /*创建*/
        $param = $this->params(UserWageService::$edit_field);
        $data = UserWageService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(UserWageService::$edit_field);


        $data = UserWageService::edit($param['id'], $param);

        return success($data);
    }
    /**
    * @Apidoc\Title("删除")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="idsParam")
    */
    public function dele()
    {
        $param = $this->params(['ids/a' => []]);

        $data = UserWageService::dele($param['ids']);
        return success($data);
    }

    /**
     *
     * 查询员工工资的详细信息
     * @return \json
     */
    public function getUserDayWage()
    {

        $where = [['is_delete','=','0']];
        /*添加搜索条件*/
        $param = $this->params(UserWageService::$detail_field);
        /*用户名*/
        if (!empty($param['username'])) {
            /*根据用户姓名查找用户ID*/
            $where[] = ['username', 'like', '%' . $param['username'] . '%'];
        }
        if (!empty($param['relation_code'])) {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string) $param['relation_code']))));
            if (count($codes) > 1) {
                $where[] = ['relation_code', 'in', $codes];
            } else {
                $where[] = ['relation_code', 'like', '%' . $param['relation_code'] . '%'];
            }
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $data = UserWageService::getUserDayWage($where, $this->page(), $this->limit(), $this->order());

        return success($data);
    }
}
