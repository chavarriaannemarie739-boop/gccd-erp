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
use app\common\model\wechat\WxUserModel;
use app\common\service\admin\PublicService;
use app\common\service\wechat\WxRegisterService;
use app\common\validate\mes\SheetValidate;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("微信操作接口")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class WeChat extends BaseController
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
        $param = $this->params(WxRegisterService::$search_field);
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
        $data = WxRegisterService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = WxRegisterService::info($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("创建注册用户申请")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        /*创建调试单*/
        $param = $this->params(WxRegisterService::$edit_field);
        /**/
        $data = WxRegisterService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(WxRegisterService::$edit_field);


        $data = WxRegisterService::edit($param['id'], $param);

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

        $data = WxRegisterService::dele($param['ids']);
        return success($data);
    }
    /*检索用户是否已经注册*/
    public function upload()
    {
//        $param = \request()->file('files');
//        if (!$param->isValid()) {
//            exception(
//                '文件是无效的'
//            );
//        }
//        // 上传多张照片
//        $info = [];
//        if ($param) {
//            foreach ($param as $file) {
//                // 移动到框架应用根目录/public/uploads/ 目录下
//                $data = PublicService::upLoad($file);
//                /*获取当前文件地址*/
//                $dir = $this->request->domain();
//                $data = $dir.'/public/'.$data;
//                array_push($info, $data);
//            }
//        }
//        return success('文件上传成功',$data);
        $param = request()->file('files');
        // 先检查文件是否存在
        if (!$param) {
            exception('请选择要上传的文件');
        }
        // 如果是单个文件，转为数组统一处理
        if (!is_array($param)) {
            $param = [$param];
        }
        $info = [];
        foreach ($param as $file) {
            // 检查每个文件是否有效
            if (!$file->isValid()) {
                exception('文件 ' . $file->getClientOriginalName() . ' 是无效的');
            }
            // 上传文件
            $uploadPath = PublicService::upLoad($file);
            if (!$uploadPath) {
                exception('文件上传失败');
            }
            // 构建完整URL（根据实际情况调整）
            $domain = $this->request->domain();
            // 如果uploadPath已经是完整路径或相对public的路径，需要适当处理
            $fullUrl = $domain . '/storage/' . ltrim($uploadPath, '/');
            array_push($info, $fullUrl);
        }
        return success('文件上传成功', $info); // 返回所有文件信息
    }

    /*大屏数据*/
    public function index()
    {
        $data =  WxRegisterService::index();

        return success($data);
    }

    /** 首页驾驶舱聚合数据 */
    public function dashboardBoard()
    {
        $data = WxRegisterService::dashboardBoard();

        return success($data);
    }

    /**
     * 车间大屏 / TV 只读接口（Header: X-Kiosk-Key，见 .env kiosk.dashboard_key）
     *
     * @return \think\Response
     */
    public function dashboardBoardKiosk()
    {
        $configuredKey = (string) config('admin.kiosk_dashboard_key');
        if ($configuredKey === '') {
            exception('大屏 Kiosk 未配置，请在 .env 设置 kiosk.dashboard_key');
        }

        $headerKey = (string) $this->request->header('X-Kiosk-Key', '');
        if ($headerKey === '' || !hash_equals($configuredKey, $headerKey)) {
            exception('Kiosk 密钥无效', 401);
        }

        $data = WxRegisterService::dashboardBoard();

        return success($data);
    }
    /*新增设备列表*/
    public function getDevice()
    {
        $param = $this->params([
            'start_date' => '',
            'end_date' => ''
        ]);

        $startDate = !empty($param['start_date']) ? $param['start_date'] : null;
        $endDate = !empty($param['end_date']) ? $param['end_date'] : null;

        $data = WxRegisterService::getDevice($startDate, $endDate);

        return success($data);
    }

    /**
     * @Apidoc\Title("工序每日生产报表")
     * @Apidoc\Query("start_date", type="string", desc="开始日期(YYYY-MM-DD)，默认今天")
     * @Apidoc\Query("end_date", type="string", desc="结束日期(YYYY-MM-DD)，默认今天")
     * @Apidoc\Returned("list", type="array", desc="报表列表", children={
     *   @Apidoc\Returned("processes_code", type="string", desc="工序编码"),
     *   @Apidoc\Returned("report_date", type="string", desc="报表日期"),
     *   @Apidoc\Returned("format_date", type="string", desc="格式化日期"),
     *   @Apidoc\Returned("weekday", type="string", desc="星期"),
     *   @Apidoc\Returned("total_report_num", type="float", desc="总生产数量"),
     *   @Apidoc\Returned("total_defective_num", type="float", desc="总不良品数量"),
     *   @Apidoc\Returned("devices", type="array", desc="设备明细", children={
     *     @Apidoc\Returned("device_code", type="string", desc="设备编码"),
     *     @Apidoc\Returned("device_name", type="string", desc="设备名称"),
     *     @Apidoc\Returned("report_num", type="float", desc="生产数量"),
     *     @Apidoc\Returned("defective_num", type="float", desc="不良品数量")
     *   }),
     *   @Apidoc\Returned("materials", type="array", desc="用料明细", children={
     *     @Apidoc\Returned("material_id", type="int", desc="物料ID"),
     *     @Apidoc\Returned("used_quantity", type="float", desc="使用数量"),
     *     @Apidoc\Returned("stock_in_num", type="float", desc="入库数量")
     *   })
     * })
     * @Apidoc\Returned("summary", type="object", desc="汇总统计", children={
     *   @Apidoc\Returned("total_report_num", type="float", desc="总生产数量"),
     *   @Apidoc\Returned("total_defective_num", type="float", desc="总不良品数量"),
     *   @Apidoc\Returned("total_days", type="int", desc="统计天数"),
     *   @Apidoc\Returned("total_processes", type="int", desc="工序数量"),
     *   @Apidoc\Returned("daily_average", type="float", desc="日均产量")
     * })
     */
    public function processDailyReport()
    {
        $param = $this->params([
            'start_date' => '',
            'end_date' => ''
        ]);

        $startDate = !empty($param['start_date']) ? $param['start_date'] : null;
        $endDate = !empty($param['end_date']) ? $param['end_date'] : null;

        $data = WxRegisterService::getProcessDailyReport($startDate, $endDate);

        return success($data);
    }

    public function exportProcessDailyReport()
    {
        $param = $this->params([
            'start_date' => '',
            'end_date' => ''
        ]);

        $startDate = !empty($param['start_date']) ? $param['start_date'] : null;
        $endDate = !empty($param['end_date']) ? $param['end_date'] : null;

        $data = WxRegisterService::exportProcessDailyReport($startDate, $endDate);

        return success($data);
    }
}
