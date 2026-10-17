<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\message;

use app\common\controller\BaseController;
use app\common\service\admin\MessageService;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("通知消息管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class Message extends BaseController
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
        $where[] = ['user_id','=',user_id()];
        /*添加搜索条件*/
        $param = $this->params(MessageService::$search_field);
            /*用户名*/
        if (!empty($param['material_code'])) {
            $where[] = ['material_code', 'like', '%' . $param['material_code'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $data = MessageService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = MessageService::info($param['id']);
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
        $param = $this->params(MessageService::$edit_field);
        $data = MessageService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(MessageService::$edit_field);


        $data = MessageService::edit($param['id'], $param);

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

        $data = MessageService::dele($param['ids']);
        return success($data);
    }


    /**
     * 生产报表导出已关闭，避免把文件写到公网可下载目录。
     *
     * @return void
     * @throws \Exception
     */
    public function exportExcel()
    {
        // [AI-2026-10] 禁用 message.Message/exportExcel
        exception('导出功能已关闭');
    }
}
