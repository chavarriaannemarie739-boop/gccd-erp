<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\repair;

use app\common\controller\BaseController;
use app\common\model\admin\DeviceModel;
use app\common\model\wechat\WxUserModel;
use app\common\service\admin\DeviceService;
use app\common\service\admin\RoutingProcessHelper;
use app\common\service\detailed\DetailedService;
use app\common\validate\detailed\DetailedValidate;
use app\common\validate\mes\SheetValidate;
use app\Request;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use hg\apidoc\annotation as Apidoc;
use think\facade\App;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("微信操作接口")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class Device extends BaseController
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
        /*添加搜索条件*/
        $param = $this->params(DeviceService::$search_field);
        if (!empty($param['device_code'])) {
            $where[] = ['device_code', 'like','%' . $param['device_code'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        /*需要处理数据*/
        if (!empty($param['device_name'])) {
            $where[] = ['device_name', 'like', '%' . $param['device_name'] . '%'];
        }
        if (!empty($param['track_id'])) {
            $where[] = ['track_id','like','%' . $param['track_id'] . '%'];
        }
        if (!empty($param['processes_code'])) {
            $where[] = ['processes_code', '=', $param['processes_code']];
        }
        // 仅当显式传入数字 user_id 时才筛选（空字符串满足 >=0 会误加条件导致列表为空）
        if ($param['user_id'] !== '' && $param['user_id'] !== null && is_numeric($param['user_id'])) {
            $where[] = ['user_id', '=', $param['user_id']];
        }
        $data = DeviceService::list($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }

    /**
     * 按工序名称筛选设备（小程序穿孔选机台）
     */
    public function listByProcess()
    {
        $param = $this->param();
        $processName = trim((string) ($param['processes_name'] ?? ''));
        $processCode = trim((string) ($param['processes_code'] ?? ''));
        if ($processCode === '' && $processName !== '') {
            $processCode = RoutingProcessHelper::getProcessCodeByName($processName);
        }
        $where = [['is_delete', '=', '0']];
        if ($processCode !== '') {
            $where[] = ['processes_code', '=', $processCode];
        }
        // page/limit=0 时 DeviceService::list 返回纯数组，需包装为 list 供小程序 picker 使用
        $list = DeviceService::list($where, 0, 0, $this->order());

        return success([
            'list' => $list,
            'count' => count($list),
        ]);
    }
    /**
    * @Apidoc\Title("信息")
    * @Apidoc\Query(ref="app\common\model\MesOrderModel", field="id")
    * @Apidoc\Returned(ref="app\common\model\MesOrderModel")
    */
    public function info()
    {
        $param = $this->params(['id/d' => '']);
        $data = DeviceService::info($param['id']);
        return success($data);
    }
    public function repairinfo()
    {
        $param = $this->params(['id/d' => '']);
        $data = DeviceService::repairinfo($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("添加调试申请单")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        /*创建记录*/
        $param = $this->params(DeviceService::$edit_field);
        /**/
        $data = DeviceService::add($param);

        return success($data);
    }
    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(DeviceService::$edit_field);


        $data = DeviceService::edit($param['id'], $param);

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

        $data = DeviceService::dele($param['ids']);
        return success($data);
    }

    /**
     * 维修记录批量删除（软删除）
     */
    public function repairDele()
    {
        $param = $this->params(['ids/a' => []]);
        $data = DeviceService::repairDele($param['ids']);
        return success($data);
    }

    /**
     * 扫码报修
     * @return
     *
     */
    public function report()
    {
        $param = $this->params(['device_code' => '','reason' => '','img' => [],'repair_list' => []]);
        /*修改订单的状态*/
        $data = DeviceService::report($param);
        return success($data);
    }
    /*解密数据*/
    /*扫描二维码获取数据*/
    public function decodes()
    {
        /*数据解密*/
        $result = $this->param('code');
        $key = 'test123';
        /*解密后的数据*/
        $data = DeviceService::decret($result, $key);
        return success($data);
    }
    public function repairlist()
    {
        $where = [['is_delete','=','0']];
        /*添加搜索条件*/
        $param = $this->params(DeviceService::$search_field);
        /*微信昵称*/
        if (!empty($param['device_code'])) {
            $where[] = ['device_code', 'like','%' . $param['device_code'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }

        if (!empty($param['reason'])) {
            $where[] = ['reason', 'like', '%' . $param['reason'] . '%'];
        }

        // 设备名称搜索（需要关联 device 表）
        if (!empty($param['device_name'])) {
            $deviceModel = new \app\common\model\admin\DeviceModel();
            $devices = $deviceModel->where('device_name', 'like', '%' . $param['device_name'] . '%')
                ->column('device_code');

            if (!empty($devices)) {
                $where[] = ['device_code', 'in', $devices];
            } else {
                $where[] = ['device_code', '=', ''];
            }
        }

        if (!empty($param['processes_code'])) {
            $where[] = ['processes_code','like','%' . $param['processes_code'] . '%'];
        }
        // 仅当显式传入数字 user_id 时才筛选（空字符串满足 >=0 会误加条件导致列表为空）
        if ($param['user_id'] !== '' && $param['user_id'] !== null && is_numeric($param['user_id'])) {
            $where[] = ['user_id', '=', $param['user_id']];
        }

        // 维修人姓名搜索（需要关联 repair_detail 表）
        if (!empty($param['repair_name'])) {
            $userModel = new \app\common\model\system\UserModel();
            $users = $userModel->where('nickname', 'like', '%' . $param['repair_name'] . '%')
                ->column('user_id');

            if (!empty($users)) {
                // 通过维修人姓名查找对应的维修记录
                $repairDetailModel = new \app\common\model\admin\RepairDetailModel();
                $repairs = $repairDetailModel->whereIn('create_uid', $users)
                    ->column('repair_code');

                if (!empty($repairs)) {
                    $where[] = ['repair_code', 'in', $repairs];
                } else {
                    $where[] = ['repair_code', '=', ''];
                }
            } else {
                $where[] = ['repair_code', '=', ''];
            }
        }

        // 报修人姓名搜索（需要关联 user 表）
        if (!empty($param['create_name'])) {
            $userModel = new \app\common\model\system\UserModel();
            $users = $userModel->where('nickname', 'like', '%' . $param['create_name'] . '%')
                ->column('user_id');

            if (!empty($users)) {
                $where[] = ['create_uid', 'in', $users];
            } else {
                $where[] = ['create_uid', '=', 0];
            }
        }

        // 设备状态搜索
        if ($param['status'] !== '' && $param['status'] !== null) {
            $where[] = ['status', '=', $param['status']];
        }

        $data = DeviceService::repairlist($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }

    /**
     * 替换生成的二维码
     * @return \json
     */
    public function test()
    {
        $param = $this->params(['device_code' => '','device_name' => '']);
        $device = DeviceService::codes($param['device_code'], $param['device_name']);
        /*获取返回的地址*/
        /*修改数据库的地址*/
        $model = new DeviceModel();
        $model->where('device_code', $param['device_code'])->update(['img' => $device]);

        return success($device);
    }

    /**
     * 新增设备维修记录
     * @return \json
     */
    public function addrecords()
    {
        $param = $this->params(['device_code' => '','user' => '','img' => '','detail' => '','repair_code' => '','repair_list' => []]);
        $data = DeviceService::addrecords($param);
        return success($data);
    }

    /**
     * 确认维修记录的状态
     * @return \json
     */
    public function editStatus()
    {
        $param = $this->params(['id' => '','status' => '']);
        $data = DeviceService::editStatus($param);
        return success($data);
    }

    /**
     * @Apidoc\Title("机修/电工批量报工提交")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("type", type="string", desc="类型 jixiu/diangong")
     * @Apidoc\Param("user_ids", type="array", desc="人员ID数组")
     * @Apidoc\Param("user_names", type="array", desc="人员姓名数组")
     * @Apidoc\Param("remark", type="string", desc="备注")
     * @Apidoc\Param("records", type="array", desc="报工记录数组")
     */
    public function reportBatch()
    {
        $param = $this->params(['type' => '', 'user_ids/a' => [], 'user_names/a' => [], 'remark' => '', 'records/a' => []]);
        $data = DeviceService::reportBatch($param);
        return success($data);
    }

    /**
     * @Apidoc\Title("机修电工日常报工列表")
     * @Apidoc\Query("device_name", type="string", desc="设备名称")
     * @Apidoc\Query("process", type="string", desc="工序")
     * @Apidoc\Query("user_names", type="string", desc="人员")
     * @Apidoc\Query("create_start_date", type="string", desc="开始日期")
     * @Apidoc\Query("create_end_date", type="string", desc="结束日期")
     */
    public function maintenanceReportList()
    {
        $where = [];
        $param = $this->params(['device_name' => '', 'report_type' => '', 'process' => '', 'user_names' => '', 'repair_item_name' => '', 'create_start_date' => '', 'create_end_date' => '']);

        if (!empty($param['device_name'])) {
            $where[] = ['device_name', 'like', '%' . $param['device_name'] . '%'];
        }
        if (!empty($param['report_type'])) {
            $where[] = ['report_type', '=', $param['report_type']];
        }
        if (!empty($param['process'])) {
            $where[] = ['process', '=', $param['process']];
        }
        if (!empty($param['user_names'])) {
            $where[] = ['user_names', 'like', '%' . $param['user_names'] . '%'];
        }
        if (!empty($param['repair_item_name'])) {
            $where[] = ['repair_item_name', 'like', '%' . $param['repair_item_name'] . '%'];
        }
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' 00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' 23:59:59'];
        }

        $data = DeviceService::maintenanceReportList($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }

    /**
     * @Apidoc\Title("机修电工日常报工编辑")
     * @Apidoc\Method("POST")
     */
    public function maintenanceReportEdit()
    {
        $param = $this->params(['ids/a' => [], 'report_type' => '', 'process' => '', 'device_name' => '', 'repair_item_name' => '', 'repair_content' => '', 'user_names' => '', 'remark' => '']);
        $data = DeviceService::maintenanceReportEdit($param['ids'], $param);
        return success($data);
    }

    /**
     * @Apidoc\Title("机修电工日常报工删除")
     * @Apidoc\Method("POST")
     */
    public function maintenanceReportDele()
    {
        $param = $this->params(['ids/a' => []]);
        $data = DeviceService::maintenanceReportDele($param['ids']);
        return success($data);
    }
}
