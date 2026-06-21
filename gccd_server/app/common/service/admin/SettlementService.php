<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\model\admin\ProductionModel;
use app\common\model\admin\WmsMaterialModel;
use app\common\model\admin\StockModel;
use app\common\model\wechat\UserModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class SettlementService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'username' => '', //调试前物料编号
        'public_name' => '',
        'wechat_name' => '',
        'month_send' => '',
        'month_money' => '',
        'reference' => '',
        'track_id' => '',
        'user_id' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'username' => '', //调试前物料编号
        'public_name' => '',
        'wechat_name' => '',
        'month_send' => '',
        'month_money' => '',
        'reference' => '',
        'track_id' => '',
        'user_id' => '',
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
        $model = new WmsMaterialModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }

        if ($page == 0 || $limit == 0) {
            return $model->with(['getTrack'])->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getTrack'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
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
            $model = new WmsMaterialModel();
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
//            /*获取文件上传*/
            $model = new WmsMaterialModel();
            $pk = $model->getPk();
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
        $model = new WmsMaterialModel();
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
        $model = new WmsMaterialModel();
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

    /*审核用户申请*/
    public static function updatestatus($param)
    {
        $model = new WmsMaterialModel();
        $data = $model->find($param['id']);
        if (empty($data)) {
            exception('找不到对应信息');
        }
        if ($data['status'] == 1) {
            \exception('该记录已经打款');
        }
        /*将用户的余额减去结算的钱*/
        if ($param['status'] == 1) {
            /*更改订单的结算金额*/
            $user = new UserModel();
            $user_data = $user->where('id', $data['user_id'])->find();
            if (empty($user_data)) {
                \exception('找不到用户信息');
            }
            /*用当月的钱减去结算的金额*/
            if ($user_data['money'] === 0) {
                /*本月余额-本月应发*/
                $user_data->money = (float)($data['month_money'] - $data['month_send']);
                $user_data->save();
            } else {
                /*更改余额*/
                /*剩余余额+本月收入-本月应发*/
                $user_data->money = (float)($user_data['money'] + ($data['month_money'] - $data['month_send']));
                $user_data->save();
            }
        }
        $data->status = $param['status'];
        /*保存方法*/
        $data->save();
        return $data;
    }

    /**
     * 查询用户账户所剩余额
     * @param $user_id
     * @return array
     */
    public static function getMoney($user_id)
    {
        $model = new UserModel();
        $data = $model->where('id', $user_id)->find();
        if (empty($data)) {
            \exception('不存在当前用户');
        }
        /*不需要查找回填数据*/
        /*判断用户对应的信息是否已经结算*/
        $settlement = new WmsMaterialModel();
        $se_data = $settlement->where('user_id', $user_id)
            ->order('create_time', 'desc')
            ->find();
        /*获取当月的结算金额*/
        $user_settlement = new StockModel();
        $user_data = $user_settlement->where('user_id', $user_id)
            ->where('send_status', 'in', [0,1])
            ->order('create_time', 'desc')
            ->find();
        if (empty($se_data)) {
            $data['money'] = 0;
        } else {
            if ($se_data['status'] != 1) {
                $data['money'] = 0;
            } else {
                if (!empty($user_data)) {
                    $data['money'] = (float)$se_data['month_send'] - $user_data['clear_amount'];
                } else {
                    $data['money'] = (float)$se_data['month_send'];
                }
            }
        }

        return ['money' => (float)$data['money']];
    }
}
