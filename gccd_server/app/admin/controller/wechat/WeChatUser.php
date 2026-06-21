<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\wechat;

use app\common\controller\BaseController;
use app\common\model\wechat\UserModel;
use app\common\model\wechat\WxUserModel;
use app\common\service\wechat\WeChatUserService;
use app\common\validate\mes\SheetValidate;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("微信用户")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class WeChatUser extends BaseController
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
        $param = $this->params(WeChatUserService::$search_field);
            /*用户名*/
        if (!empty($param['wechat_name'])) {
            $where[] = ['wechat_name', 'like', '%' . $param['wechat_name'] . '%'];
        }
        /*微信昵称*/
        if (!empty($param['username'])) {
            $where[] = ['username', '=', $param['username']];
        }
        /*需要处理数据*/
        if (!empty($param['public_name'])) {
            $where[] = ['public_name', 'like', '%' . $param['public_name'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        if (!empty($param['track_id'])) {
            $where[] = ['track_id','=',$param['track_id']];
        }
        if ($param['is_disable'] >= 0) {
            $where[] = ['is_disable','=',$param['is_disable']];
        }
        if (!empty($param['reference'])) {
            $where[] = ['reference','=',$param['reference']];
        }
        $data = WeChatUserService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = WeChatUserService::info($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("后台添加申请单")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        $file = request()->file('img');
        // 验证文件是否存在
        if (empty($file)) {
            exception('请选择图片');
        }
        /*创建调试单*/
        $param = $this->params(WeChatUserService::$edit_field);
        /**/
        $data = WeChatUserService::add($param, $file);

        return success($data, '已注册成功，请登录');
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(WeChatUserService::$edit);


        $data = WeChatUserService::edit($param['id'], $param);

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

        $data = WeChatUserService::dele($param['ids']);
        return success($data);
    }

    /**
     * @return
     * 修改调试单的状态
     */
    public function updatestatus()
    {
        $param = $this->params(['status' => '','id' => '']);
        /*修改订单的状态*/
        $data = WeChatUserService::updatestatus($param);

        return success($data);
    }

    /**
     * 会员登录小程序
     * @return void
     */
    public function login()
    {
        $param = $this->params(['username' => '','password' => '','wx_appid' => '']);
        /*修改订单的状态*/
        $data = WeChatUserService::login($param);
        return success($data);
    }

    /*修改密码*/
    public function edit_password()
    {
        $param = $this->params(['username' => '','password' => '','public_name' => '']);
        /*修改订单的状态*/
        $data = WeChatUserService::edit_password($param);

        return success($data);
    }
    /*重置用户的下载次数*/
    public function updatedowond()
    {
        $model = new UserModel();
        $model->select()->update(['rest_num' => 0]);
        return success('', '重置成功');
    }
}
