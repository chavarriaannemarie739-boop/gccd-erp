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
use app\common\model\admin\MessageModel;
use app\common\model\admin\ProductionModel;
use app\common\model\admin\DeviceModel;
use app\common\model\admin\ProcessesModel;
use app\common\model\admin\ProductCountModel;
use app\common\model\admin\RepairDetailModel;
use app\common\model\admin\RepairModel;
use app\common\model\detailed\DetailedModel;
use app\common\model\order\OrderModel;
use app\common\model\system\RoleModel;
use app\common\model\system\UserAttributesModel;
use app\common\model\wechat\UserModel;
use app\common\model\wechat\WxUserModel;
use app\common\service\wechat\Exception;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;
use think\facade\Log;

/**
 *记录跟踪
 */
class DeviceService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'device_name' => '',
        'device_code' => '',
        'processes_code' => '',
        'img' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'device_code' => '',
        'device_name' => '',
        'processes_code' => '',
        'task_id' => '',
        'title' => '',
        'create_start_date' => '',
        'create_end_date' => '',
        'user_id' => '',
        'repair_name' => '',
        'create_name' => '',
        'status' => '',
        'reason' => '',
        'track_id' => '',
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
        $model = new DeviceModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            return $model->with(['getTrack','getArticle'])->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getTrack','getArticle'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
        /*上传照片信息*/
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
            $model = new DeviceModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的申请信息不存在');
                }
                return [];
            }
            $info = $info->toArray();
        }
        /*生成调试单号*/
        return $info;
    }

    /**
     * 维修记录详情
     *
     * @param int  $id   id
     * @param bool $exce 不存在是否抛出异常
     *
     * @return array|Exception
     */
    public static function repairinfo($id, $exce = true)
    {
        /*处理照片数据*/
        $info = null;
        if (empty($info)) {
            $model = new RepairModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的维修信息不存在');
                }
                return [];
            }
            if (!empty($info['img'])) {
                $info['imgs'] = array_map(
                    'trim',
                    array_filter(
                        explode(';', $info['img']),
                        function ($item) {
                            return $item !== '';
                        }
                    )
                );
            } else {
                $info['imgs'] = [];
            }
            /*查询出对应的维修记录*/
            $record = new RepairDetailModel();
            $result = $record->where('repair_code', $info['repair_code'])->select()->toArray();
            foreach ($result as $key => &$value) {
                if (!empty($v['img'])) {
                    if (!empty($value['img'])) {
                        $value['imgs'] = array_map(
                            'trim',
                            array_filter(
                                explode(';', $value['img']),
                                function ($item) {
                                    return $item !== '';
                                }
                            )
                        );
                    } else {
                        $value['imgs'] = [];
                    }
                } else {
                    $value['imgs'] = [];
                }
                unset($value['img']);
            }
            unset($info['img']);
            $info['detail'] = $result;
            $info['create_name'] = user_name($info['create_uid']);
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
            if (empty($param['device_code'])) {
                /*生成机器编码*/
                $param['device_code'] = PublicService::getCode(7)['code'];
            }
            $model = new DeviceModel();
            $pk = $model->getPk();
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            /*生成二维码信息*/
            $param['img'] = self::codes($param['device_code'], $param['device_name']);
            $id = $model->insertGetId($param);
            $param[$pk] = $id;
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
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
        $model = new DeviceModel();
        $pk = $model->getPk();

        unset($param[$pk], $param['ids']);

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();
        /*生成二维码*/
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
        $model = new DeviceModel();
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

    /**
     * 批量删除维修记录（报修主表 + 维修明细表，软删除）
     *
     * @param array $ids repair 表主键 id 列表
     */
    public static function repairDele(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            exception('请选择要删除的维修记录');
        }

        Db::startTrans();
        try {
            $repairModel = new RepairModel();
            $pk = $repairModel->getPk();
            $rows = $repairModel->where($pk, 'in', $ids)->where('is_delete', '=', 0)->select()->toArray();
            if (empty($rows)) {
                exception('记录不存在或已被删除');
            }

            $update = delete_update();
            $repairModel->where($pk, 'in', $ids)->update($update);

            $codes = array_unique(array_filter(array_column($rows, 'repair_code')));
            if (!empty($codes)) {
                $detail = new RepairDetailModel();
                $detail->where('repair_code', 'in', $codes)->where('is_delete', '=', 0)->update($update);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return ['ids' => $ids];
    }

    public static function codes($data, $device_name)
    {
        /*数据加密*/
//        $key = 'test123';
//        $md5_code = self::encryt($data,$key);
        //生成二维码
        $code = QrCode::create($data)
            ->setEncoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
            ->setSize(300)
            ->setMargin(10);
        $lable = Label::create("设备名称:{$device_name}")
        ->setTextColor(new Color(0, 0, 0));
        $wirter = new PngWriter();
        $result = $wirter->write($code, null, $lable);
        /*随机生成加密的唯一的文件名*/
        $filename = md5(uniqid(rand(), true));
        $file_path = App::getRootPath() . 'public/storage/' . $filename . '.png';

        file_put_contents($file_path, $result->getString());
        /*上传到服务器指定目录*/
//        获取当前路由的地址
        $file_path = request()->domain() . '/storage/' . $filename . '.png';
        return $file_path;
    }

    /*创建保修*/
    public static function report($param)
    {
        $model = new RepairModel();
        /*生成报修编号*/
        $param['repair_code'] = PublicService::getCode(12)['code'];
        $param['create_time'] = datetime();
        $param['create_uid'] = user_id();
        $img = '';
        /*对照片的数字的地址进行处理*/
        foreach ($param['img'] as $value) {
            $img = $img . '' . $value . ';';
        }
        // 保存原始维修项名称数组，后面写入 maintenance_report 用
        $repair_list_names = $param['repair_list'];
        $repair_list = '';
        /*循环维修项*/
        foreach ($param['repair_list'] as $item) {
            $repair_list = $repair_list . '' . $item . ';';
        }
        $param['repair_list'] =  $repair_list;
        /*修改设备的维状态*/
        $device = new DeviceModel();
        $device->where('device_code', $param['device_code'])->update(['status' => 1]);
        $param['img'] = $img;
        $model->save($param);

        // 同步写入 maintenance_report 表，供 PC 端 maintenance-report 页面展示
        $device_data = $device->where('device_code', $param['device_code'])->find();
        if (!empty($device_data)) {
            $device_name = $device_data['device_name'] ?? '';
            $device_id = $device_data['id'] ?? 0;
            // 根据设备名称判断工序
            $process = '其他';
            if (strpos($device_name, '断钢') !== false) {
                $process = '断钢';
            } elseif (strpos($device_name, '穿孔') !== false) {
                $process = '穿孔';
            } elseif (strpos($device_name, '球化') !== false || strpos($device_name, '矫直') !== false) {
                $process = '球化';
            } elseif (strpos($device_name, '酸洗') !== false) {
                $process = '酸洗';
            }
            $productCountModel = new ProductCountModel();
            foreach ($repair_list_names as $item_name) {
                $pc = $productCountModel->where('repair_name', $item_name)->find();
                try {
                    Db::name('maintenance_report')->insert([
                    'report_type'     => '机修',
                    'device_id'       => $device_id,
                    'device_name'     => $device_name,
                    'device_code'     => $param['device_code'],
                    'process'         => $process,
                    'repair_item_id'  => $pc ? $pc['id'] : 0,
                    'repair_item_name' => $item_name,
                    'repair_content'  => !empty($param['reason']) ? $param['reason'] : $item_name,
                    'user_ids'        => '',
                    'user_names'      => '',
                    'remark'          => '',
                    'create_time'     => datetime(),
                    'create_uid'      => user_id(),
                    ]);
                } catch (\Exception $e) {
                    Log::error('maintenance_report insert failed: ' . $e->getMessage());
                }
            }
        }

        /*添加推送消息*/
        $role =  new RoleModel();
        $role_data = $role->field('role_id')
            ->whereOr("role_name", "like", "%" . "总经理" . "%")
            ->whereOr("role_name", "like", "%" . "车间主任" . "%")
            ->whereOr("role_name", "like", "%" . "设备维修" . "%")
            ->select()->toArray();
        /*出去用户ID*/
        $role_data = array_column($role_data, 'role_id');
        /*查找对应用户ID*/
        $user_model = new UserAttributesModel();
        $user_data = $user_model
            ->field('user_id')
            ->where('role_id', 'in', $role_data)
            ->select()->toArray();
        $user_data = array_column($user_data, 'user_id');
        /*创建通知消息*/
        if (empty($device_data)) {
            $device_data = ['device_name' => ''];
        }
        $message_model = new MessageModel();
        foreach ($user_data as $value) {
            $add_array = [
                'device_code' => $param['device_code'],
                'device_name' => $device_data['device_name'],
                'reason' => $param['reason'],
                'create_time' => datetime(),
                'user_id' => $value,
                'device_project' => $param['repair_list'],
                'create_uid' => user_id(),
            ];
            $message_model->insert($add_array);
        }
        /**/

        return $param;
    }

    public static function decret($data, $key)
    {
        $method = "AES-256-CBC";
        list($encryptedData, $iv) = explode('::', base64_decode($data), 2);
        return openssl_decrypt($encryptedData, $method, $key, 0, $iv);
    }
    public static function encryt($data, $key)
    {
        $method = "AES-256-CBC";
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }

    /**
     * 报修记录列表
     * @param $where
     * @param $page
     * @param $limit
     * @param $order
     * @param $field
     * @return array
     */
    public static function repairlist($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {
        $model = new RepairModel();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        $count = $model->where($where)->count();
        $query = $model->with(['getTrack', 'getDeviceName'])->where($where)->order($order);
        if ($page == 0 || $limit == 0) {
            $list = $query->select()->toArray();
        } else {
            $list = $query->page($page)->limit($limit)->select()->toArray();
        }
        $list = self::enrichRepairListRows($list);
        $limit = (int) $limit;
        $page = (int) $page;
        if ($limit <= 0) {
            $limit = $count > 0 ? $count : 1;
            $page = 1;
        }
        $pages = $limit > 0 ? (int) ceil($count / $limit) : 1;

        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 维修列表行：明细、图片、维修人（供 repairlist 全量/分页共用）
     */
    private static function enrichRepairListRows(array $list): array
    {
        foreach ($list as $k => &$v) {
            $v['time'] = 0;
            if (!empty($v['img'])) {
                $v['imgs'] = array_map(
                    'trim',
                    array_filter(
                        explode(';', $v['img']),
                        static function ($item) {
                            return $item !== '';
                        }
                    )
                );
            } else {
                $v['imgs'] = [];
            }
            $record = new RepairDetailModel();
            $result = $record->where('repair_code', $v['repair_code'])->select()->toArray();
            $repair_name = '';
            foreach ($result as $key => &$value) {
                $repair_name = user_name($value['create_uid']);
                if (!empty($v['img'])) {
                    if (!empty($value['img'])) {
                        $value['imgs'] = array_map(
                            'trim',
                            array_filter(
                                explode(';', $value['img']),
                                static function ($item) {
                                    return $item !== '';
                                }
                            )
                        );
                    } else {
                        $value['imgs'] = [];
                    }
                } else {
                    $value['imgs'] = [];
                }
                unset($value['img']);
                $v['time'] = $value['time'];
            }
            unset($value);
            $repairTimeAt = '';
            foreach ($result as $detailRow) {
                $ct = isset($detailRow['create_time']) ? trim((string) $detailRow['create_time']) : '';
                if ($ct !== '' && ($repairTimeAt === '' || strcmp($ct, $repairTimeAt) > 0)) {
                    $repairTimeAt = $ct;
                }
            }
            $v['repair_time'] = $repairTimeAt;
            $v['detail'] = $result;
            $v['create_name'] = user_name($v['create_uid']);
            $v['repair_name'] = $repair_name;
            /* 工序名称：关联 track_id 可能为空，用报修单 processes_code 或设备档案工序兜底 */
            $tn = trim((string) ($v['track_name'] ?? ''));
            if ($tn === '') {
                $pc = trim((string) ($v['processes_code'] ?? ''));
                if ($pc !== '') {
                    $nm = (new ProcessesModel())->where('processes_code', $pc)->value('processes_name');
                    if (!empty($nm)) {
                        $v['track_name'] = $nm;
                    }
                }
            }
            if (trim((string) ($v['track_name'] ?? '')) === '' && !empty($v['device_code'])) {
                $dev = (new DeviceModel())->where('device_code', $v['device_code'])->where('is_delete', 0)->find();
                if ($dev && !empty($dev['processes_code'])) {
                    $nm = (new ProcessesModel())->where('processes_code', $dev['processes_code'])->value('processes_name');
                    if (!empty($nm)) {
                        $v['track_name'] = $nm;
                    }
                }
            }
        }
        unset($v);

        return $list;
    }

    /**
     * 创建维修记录
     * @param $param
     * @return mixed
     */
    public static function addrecords($param)
    {
        Db::startTrans();
        try {
            /*新建维修记录*/
            $model = new RepairDetailModel();
            /*创建日期*/
            $pk = $model->getPk();
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            /*处理照片数据*/
            $img = '';
            /*对照片的地址进行处理 - 确保是数组*/
            if (!empty($param['img']) && is_array($param['img'])) {
                foreach ($param['img'] as $value) {
                    $img .= $value . ';';
                }
            }
            $param['img'] = $img;
            /*计算维修时长*/
            $repair = new RepairModel();
            $data = $repair->where('repair_code', $param['repair_code'])->find();
            if (!empty($data)) {
                $timestamp1 = strtotime($data['create_time']);
                $timestamp2 = strtotime(date('Y-m-d H:i:s'));
                $time_diff = $timestamp2 - $timestamp1; // 时间差（秒）

                /*确保时间差为非负数*/
                if ($time_diff < 0) {
                    $time_diff = 0;
                }

                /*转换成时分格式*/
                $hours = floor($time_diff / 3600);
                $minutes = floor(($time_diff % 3600) / 60);
                $param['time'] = $hours . '小时' . $minutes . '分';
            } else {
                /*如果找不到报修记录，默认时长为0*/
                $param['time'] = '0小时0分';
            }
            $id = $model->insertGetId($param);
            $param[$pk] = $id;
            // 提交事务
            $device_model = new DeviceModel();
            /*修改当前设备状态---和当前报修单的状态和设备的维修状态*/
            $device_model->where('device_code', '=', $param['device_code'])->update(['status' => 0]);
            /*修改当前工单的状态--待确认*/
            $repair = new RepairModel();
            $repair->where('repair_code', '=', $param['repair_code'])->update(['status' => 1]);
            Db::commit();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            // 回滚事务
            Db::rollback();
            exception('创建失败:' . $e->getMessage());
        }
        return $param;
    }
    /*确认维修(在申请的界面确定)*/
    public static function editStatus($param)
    {
//        $model = new  RepairDetailModel();
//       $data = $model->where('id', '=', $param['id'])->update(['status' => $param['status']]);
        $model = new RepairModel();
        $result = $model->find($param['id']);
        if (!empty($result)) {
            if ($result['create_uid'] != user_id()) {
                \exception('只能申请报修的人点击确认');
            }
        }
        $data = $model->where('id', '=', $param['id'])->update(['status' => $param['status']]);
        return $data;
    }

    /**
     * 批量报工提交 (机修/电工日常报工)
     */
    public static function reportBatch($param)
    {
        Db::startTrans();
        try {
            $type = $param['type'] ?? '';
            $typeMap = ['jixiu' => '机修', 'diangong' => '电工'];
            $type = $typeMap[$type] ?? $type;
            $userIds = $param['user_ids'] ?? [];
            $userNames = $param['user_names'] ?? [];
            $remark = $param['remark'] ?? '';
            $records = $param['records'] ?? [];

            $userIdsStr = implode(',', $userIds);
            $userNamesStr = implode(',', $userNames);

            $model = new \app\common\model\admin\MaintenanceReportModel();

            foreach ($records as $record) {
                $deviceId = $record['device_id'] ?? 0;
                $deviceName = $record['device_name'] ?? '';
                $deviceCode = $record['device_code'] ?? '';

                // 根据设备名称判断工序
                $process = '其他';
                if (strpos($deviceName, '断钢') !== false) {
                    $process = '断钢';
                } elseif (strpos($deviceName, '穿孔') !== false) {
                    $process = '穿孔';
                } elseif (strpos($deviceName, '球化') !== false || strpos($deviceName, '矫直') !== false) {
                    $process = '球化';
                } elseif (strpos($deviceName, '酸洗') !== false) {
                    $process = '酸洗';
                }

                $items = $record['items'] ?? [];
                foreach ($items as $item) {
                    $insertData = [
                        'report_type' => $type,
                        'device_id' => $deviceId,
                        'device_name' => $deviceName,
                        'device_code' => $deviceCode,
                        'process' => $process,
                        'repair_item_id' => $item['id'] ?? 0,
                        'repair_item_name' => $item['repair_name'] ?? '',
                        'repair_content' => $item['repair_content'] ?? '',
                        'user_ids' => $userIdsStr,
                        'user_names' => $userNamesStr,
                        'remark' => $remark,
                        'create_time' => datetime(),
                        'create_uid' => user_id(),
                    ];
                    Db::name('maintenance_report')->insert($insertData);
                }
            }

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('reportBatch error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            exception('提交失败: ' . $e->getMessage());
        }
    }

    /**
     * 机修电工日常报工列表
     */
    public static function maintenanceReportList($where = [], $page = 1, $limit = 10, $order = [])
    {
        $model = new \app\common\model\admin\MaintenanceReportModel();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }

        $where[] = ['is_delete', '=', 0];
        $count = $model->where($where)->count();
        $list = $model->where($where)
            ->page($page)
            ->limit($limit)
            ->order($order)
            ->select()
            ->toArray();

        $pages = ceil($count / $limit);
        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 机修电工日常报工编辑
     */
    public static function maintenanceReportEdit($ids, $param = [])
    {
        $model = new \app\common\model\admin\MaintenanceReportModel();
        $pk = $model->getPk();
        unset($param[$pk], $param['ids']);
        $param['update_uid'] = user_id();
        $param['update_time'] = datetime();
        $res = $model->where($pk, 'in', $ids)->update($param);
        if (empty($res)) {
            exception();
        }
        $param['ids'] = $ids;
        return $param;
    }

    /**
     * 机修电工日常报工删除（软删除）
     */
    public static function maintenanceReportDele($ids)
    {
        $model = new \app\common\model\admin\MaintenanceReportModel();
        $pk = $model->getPk();
        $update = delete_update();
        $res = $model->where($pk, 'in', $ids)->update($update);
        if (empty($res)) {
            exception();
        }
        $update['ids'] = $ids;
        return $update;
    }
}
