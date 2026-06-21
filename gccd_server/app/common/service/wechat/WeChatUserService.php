<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\wechat;

use app\common\model\wechat\UserModel;
use app\common\service\admin\PublicService;
use app\common\service\system\UserService;
use app\common\service\system\UserTokenService;
use Firebase\JWT\JWT;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class WeChatUserService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'username' => '', //调试前物料编号
        'password' => '', //
        'public_name' => '',
        'wechat_name' => '',
        'track_name' => '',
        'img' => '',
        'wx_appid' => '',
        'reference' => '',
        'track_id' => '',
         'download_num' => '',
        'is_disable' => '',
    ];
    /*修改字段*/
    public static $edit = [
        'id' => '',
        'level' => '',
        'download_num' => '',
        'is_disable' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'username' => '', //
        'password' => '', //
        'public_name' => '',
        'wechat_name' => '',
        'track_id' => '',
        'img' => '',
        'wx_appid' => '',
        'reference' => '',
        'download_num' => '',
        'is_disable' => '',
        'create_start_date' => '',
        'create_end_date' => ''
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
        $model = new UserModel();
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
            $model = new UserModel();
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
    public static function add($param, $file)
    {
        Db::startTrans();
        try {
//            /*获取文件上传*/
            $saveName = Filesystem::disk('public')->putFile('image', $file);
            $model = new UserModel();
            $param['img'] = 'https://www.djgzhyw.cn/storage/' . $saveName;
            $pk = $model->getPk();
            $param['create_time'] = datetime();
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

    /*无需上传文件的创建用户*/
    public static function adduser($param)
    {
        Db::startTrans();
        try {
            /*检查appid是否有重复的*/
            $model = new UserModel();
            $data = $model->where('wx_appid', $param['wx_appid'])->select()->toArray();
            if (!empty($data)) {
                exception('已存在appid相同的用户,请勿重复创建');
            }
            $pk = $model->getPk();
            $param['create_time'] = datetime();
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
        $model = new UserModel();
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
        $model = new UserModel();
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
        $model = new UserModel();
        $data = $model->find($param['id']);
        if (empty($data)) {
            exception('找不到对应信息');
        }
        $data->is_disable = $param['status'];
        $data->update_uid = user_id();
        $data->update_time = datetime();
        /*保存方法*/
        $data->save();

        return $data;
    }

    public static function login($param)
    {
        /*拿着appid去换取openid*/
        $openId = PublicService::getOpenID($param['wx_appid']);
        $model = new UserModel();
        $data = $model->where('username', $param['username'])
            ->where('password', $param['password'])
            ->where('wx_appid', $openId)
            ->find();
        if (empty($data)) {
            exception('账号或者密码错误，请重试');
        }
        if ($data['is_disable'] == 1) {
            exception('当前账号已经被禁用,请联系管理员');
        }
        $param['user_id'] = 1;
        $param['login_time'] = datetime();
        /*生成token信息*/
        $Token = self::create($param);
        /*测试token是否可以访问其他接口*/
        $result = [
            'AdminToken' => $Token,
            'username' => $param['username'],
            'user_id' => $data['id'],
            'openid' => $openId,
            'public_name' => $data['public_name'],
            'wechat_name' => $data['wechat_name'],
            'type' => $data['level'], //类型
            'reference' => $data['reference'], //推荐人
            'track_id' => $data['track_id']   //分类id
        ];
        return $result;
    }

    public static function create($user)
    {
        $config = UserTokenService::config();
        $payload = [
            'iat'  => time(),                           //签发时间
            'nbf'  => time(),                           //生效时间
            'exp'  => time() + $config['token_exps'],   //过期时间
            'data' => [
                'user_id'    => $user['user_id'],
                'login_time' => $user['login_time'],
            ],
        ];
        return JWT::encode($payload, $config['token_key'], $config['token_alg']);
    }

    /**
     * 修改密码   微信小程序
     * @param $param
     * @return void
     */
    public static function edit_password($param)
    {
        $model = new UserModel();
        $data = $model->where('username', $param['username'])
            ->where('public_name', $param['public_name'])
            ->find();
        if (empty($data)) {
            exception('不存此管理的账号,请检查用户名和公众号名称');
        }
        /**/
        $data->password = $param['password'];
        $data->save();
        return $data;
    }
}
