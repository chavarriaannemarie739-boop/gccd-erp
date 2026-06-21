<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\production;

use app\common\controller\BaseController;
use app\common\service\admin\RoutingService;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("工序管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class Routing extends BaseController
{
    /**
    * @Apidoc\Title("工艺列表")
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
        $param = $this->params(RoutingService::$search_field);
            /*用户名*/
        if (!empty($param['routing_code'])) {
            $where[] = ['routing_code', 'like', '%' . $param['routing_code'] . '%'];
        }
        if (!empty($param['routing_name'])) {
            $where[] = ['routing_name', 'like',"%" . $param['routing_name'] . "%"];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $data = RoutingService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = RoutingService::info($param['id']);
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
        $param = $this->params(RoutingService::$edit_field);
//        $param['content'] =  [
//            [
//                'sort'=>1,
//                'process_code'=>'gx_001',
//            ],
//            [
//                'sort'=>2,
//                'process_code'=>'gx_002',
//            ]
//        ];
        $data = RoutingService::add($param);

        return success($data);
    }

    public function addProcess()
    {
        /*创建*/
        $param = $this->param();
//        $param['routing_code'] = 'gy-03';
//        $param['content'] = [
//            [
//                'sort'=>1,
//                'process_code'=>'gx_001',
//            ],
//                [
//                    'sort'=>2,
//                    'process_code'=>'gx_002',
//                ]
//        ];
        $data = RoutingService::addProcess($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(RoutingService::$edit_field);
        $data = RoutingService::edit($param['id'], $param);

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

        $data = RoutingService::dele($param['ids']);
        return success($data);
    }

    /**
     * @return
     * 修改调试单的状态
     */
    public function updatestatus()
    {
        $param = $this->params(['status' => '','id' => '','refuse_reason' => '']);
        /*修改订单的状态*/
        $data = RoutingService::updatestatus($param);

        return success($data);
    }
    /**
     * 根据工艺编码获取对应的工序号
     */
    public function getProcess()
    {
        $param = $this->param('routing_code');
        $data = RoutingService::getProcess($param);
        return success($data);
    }
}
