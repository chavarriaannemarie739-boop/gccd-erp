<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\material;

use app\common\controller\BaseController;
use app\common\model\wechat\WxUserModel;
use app\common\service\admin\SettlementService;
use app\common\validate\mes\SheetValidate;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("物料列表增加改查")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class Settlement extends BaseController
{
    /**
    * @Apidoc\Title("调试单列表")
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
        $wh = [];
        /*添加搜索条件*/
        $param = $this->params(SettlementService::$search_field);
            /*用户名*/
        if (!empty($param['wechat_name'])) {
            $where[] = ['wechat_name', 'like', '%' . $param['wechat_name'] . '%'];
        }
        /*微信昵称*/
        if (!empty($param['username'])) {
            $where[] = ['username', '=', $param['username']];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        /*需要处理数据*/
        if (!empty($param['public_name'])) {
            $where[] = ['public_name', 'like', '%' . $param['public_name'] . '%'];
        }
        if (!empty($param['track_id'])) {
            $where[] = ['track_id','=',$param['track_id']];
        }
        if (!empty($param['user_id'])) {
            $where[] = ['user_id','=',$param['user_id']];
        }
        $data = SettlementService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = SettlementService::info($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("添加调试申请单")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        /*创建调试单*/
        $param = $this->params(SettlementService::$edit_field);
        $data = SettlementService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(SettlementService::$edit_field);


        $data = SettlementService::edit($param['id'], $param);

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

        $data = SettlementService::dele($param['ids']);
        return success($data);
    }

    /**
     * @return
     * 结算金额
     */
    public function updatestatus()
    {
        $param = $this->params(['status' => '','id' => '']);
        /*修改订单的状态*/
        $data = SettlementService::updatestatus($param);

        return success($data);
    }
    /**
     * 查询当前用户的余额
     * @return \json
     */
    public function getMoney()
    {
        $param = $this->param('user_id');
        /*修改订单的状态*/
        $data = SettlementService::getMoney($param);

        return success($data);
    }
}
