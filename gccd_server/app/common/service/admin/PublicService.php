<?php

namespace app\common\service\admin;

use app\common\model\admin\InStockModel;
use app\common\model\file\FileModel;
use app\common\model\admin\MesCodeModel;
use app\common\model\system\PostModel;
use app\common\model\system\UserAttributesModel;
use app\common\model\system\UserModel;
use app\common\model\system\Wxuser;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PhpParser\Node\Scalar\String_;
use think\exception\FileException;
use think\facade\Filesystem;

/**
 * 管理系统公共类
 */
class PublicService
{
    /**
     * 上传文件   返回文件上传地址
     * @param $file
     * @param $file_name
     * @return bool|string|void
     */
    public static function upLoad($file, $file_name = 'file')
    {
        try {
//            /*获取文件上传*/
            $saveName = Filesystem::disk('public')->putFile('file', $file);
            return $saveName;
            // 提交事务
        } catch (\Exception $e) {
            // 回滚事务
            exception('文件上传失败失败:' . $e->getMessage());
        }
    }
    /**
     * 上传文件--已文件名保存   返回文件上传地址
     * @param $file
     * @param $file_name
     * @return bool|string|void
     */
    public static function upLoaddoc($file)
    {
        try {
//            /*获取文件上传*/
            $uploadDir = public_path('storage'); // 绝对路径
            // 获取原始文件名（含扩展名）
            $originalName = $file->getOriginalName();
            // 直接使用原文件名保存（注意：需防止重名覆盖！）
            $newName = date('YmdHis') . '_' . $originalName;
            $data = $file->move($uploadDir, $newName);
            // 提交事务
            return $newName;
        } catch (\Exception $e) {
            // 回滚事务
            exception('文件上传失败失败:' . $e->getMessage());
        }
    }

    /**
     * 删除文件
     * @param $filePath  文件路径
     * @return \think\response\Json
     */
    public static function deletefile($filePath)
    {

        // 安全检查：防止路径穿越攻击
        if (empty($filePath) || str_contains($filePath, '..')) {
            exception('非法文件路径');
        }
        try {
            /*取出对应网址信息*/
            $data = self::extractPath($filePath);
            // 拼接物理路径（假设文件存储在 public/storage）
            $fullPath = public_path() . ltrim($data, '/');
            // 验证文件是否存在且可删除
            if (!file_exists($fullPath)) {
                exception('文件不存在');
            }
            if (!is_writable($fullPath)) {
                exception('文件不可写');
            }
            // 删除文件
            unlink($fullPath);
            // 可选：删除空目录
            self::removeEmptyDir(dirname($fullPath));
            return json(['code' => 200, 'msg' => '删除成功']);
        } catch (FileException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 递归删除空目录
     */
    protected static function removeEmptyDir(string $dir): void
    {
        if (is_dir($dir) && count(scandir($dir)) == 2) {
            rmdir($dir);
            self::removeEmptyDir(dirname($dir));
        }
    }

    /**
     * 去除文件地址中的网址
     * @param $fullUrl
     * @return array|false|int|string|string[]|null
     */
    public static function extractPath($fullUrl)
    {
        // 方法1：使用parse_url（推荐）
        $path = parse_url($fullUrl, PHP_URL_PATH);

        // 安全过滤（防止路径穿越）
        $safePath = str_replace(['..', '\\'], '/', $path);

        return $safePath;
    }
    /*获取微信小程序openid*/
    public static function getOpenID($code)
    {
        $setting = \app\common\service\member\SettingService::info();
        //将微信登录登录初始化
        $miniapp     = new \thirdsdk\WxMiniapp($setting['wx_miniapp_appid'], $setting['wx_miniapp_appsecret']);
        $arr  = $miniapp->login($code);
        $openid = $arr['openid'];

        return $openid;
    }

    /*生成单据编号*/
    public static function getCode($type)
    {
        $old = date('Y-m-d') . ' 00' . ':00' . ':00';
        $now = date('Y-m-d') . ' 23' . ':59' . ':59';
        if (empty($type)) {
            return error('参数错误！');
        }
        $info = [];
        if ($type == 1) {
            //1入库单号
            $Count = self::getcodenum('rkd');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'rkd-' . date('Ymd') . $strand;
        } elseif ($type == 2) {
            $Count = self::getcodenum('ckd');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'ckd-' . date('Ymd') . $strand;
        } elseif ($type == 3) {
            //3生产计划单号
            $Count = self::getcodenum('scjh');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'scjh-' . date('Ymd') . $strand;
        } elseif ($type == 4) {
            //4生产工单号
            $Count = self::getcodenum('scgd');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'scgd-' . date('Ymd') . $strand;
        } elseif ($type == 5) {
            //5工艺编码
            $Count = self::getcodenum('gy');//获取当日增加总数
            $strand = str_pad($Count, 2, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'gy-' . $strand;
        } elseif ($type == 6) {
            //6报工单号
            $Count = self::getcodenum('scbg');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'scbg-' . date('Ymd') . $strand;
        } elseif ($type == 7) {
            //7 设备编号
            $Count = self::getcodenum('device');//获取当日增加总数
            $strand = str_pad($Count, 2, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'device-' . date('Ymd') . $strand;
        } elseif ($type == 8) {
            //8 质检编号
            $Count = self::getcodenum('zj');//获取当日增加总数
            $strand = str_pad($Count, 3, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'zj-' . date('Ymd') . $strand;
        } elseif ($type == 9) {
            //领料单
            $Count = self::getcodenum('ll');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'll-' . date('Ymd') . $strand;
        } elseif ($type == 10) {
            //10 物料批次号
            $Count = self::getcodenum('WLPC');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'WLPC-' . date('Ymd') . $strand;
        } elseif ($type == 11) {
            //11仓库编码编号
            $Count = self::getcodenum('CK');//获取当日增加总数
            $strand = str_pad($Count, 2, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'CK-' . date('Ymd') . $strand;
        } elseif ($type == 12) {
            //12 生成报修编号
            $Count = self::getcodenum('BX');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'BX-' . date('Ymd') . $strand;
        } elseif ($type == 13) {
            //12 生成报修编号
            $Count = self::getcodenum('FH');//获取当日增加总数
            $strand = str_pad($Count, 4, "0", STR_PAD_LEFT);//当前数补齐为4位，0补齐在左侧
            $code = 'FH-' . date('Ymd') . $strand;
        }

        $info['code'] = $code;
        return $info;
    }

    /**
     * @param $prefix
     * @return int|mixed
     * 生成编码的数字
     */
    public static function getcodenum($prefix)
    {
        /* [AI-2026-06] 行锁取号，避免并发建计划/下推时 mes_code 死锁 */
        $nums = self::allocateCodeNums($prefix, 1);

        return $nums[0];
    }

    /**
     * [AI-2026-08] 批量行锁取号：一次锁定递增，避免盘点批量提交时逐条 lock 拖死事务
     *
     * @param string $prefix 编码前缀
     * @param int $count 需要的数量
     * @return int[] 连续序号（已写入 mes_code）
     */
    public static function allocateCodeNums($prefix, $count)
    {
        $count = (int) $count;
        if ($count <= 0) {
            return [];
        }
        if ($prefix === '' || $prefix === null) {
            exception('参数错误！');
        }

        $model = new MesCodeModel();
        $row = $model->where('prefix', $prefix)->lock(true)->find();
        if (empty($row)) {
            exception('编码前缀不存在：' . $prefix);
        }

        $today = date('Y-m-d');
        if (strtotime((string) $row['date']) < strtotime($today)) {
            $start = 1;
            $end = $count;
            $model->where('prefix', $prefix)->update([
                'date' => $today,
                'code_num' => $end,
            ]);
        } else {
            $start = (int) $row['code_num'] + 1;
            $end = $start + $count - 1;
            $model->where('prefix', $prefix)->update(['code_num' => $end]);
        }

        $nums = [];
        for ($i = $start; $i <= $end; $i++) {
            $nums[] = $i;
        }

        return $nums;
    }

    /**
     * [AI-2026-08] 按 getCode 规则批量生成完整编码
     *
     * @param int $type getCode 类型（当前盘点使用 10=WLPC）
     * @param int $count 数量
     * @return string[]
     */
    public static function allocateCodes($type, $count)
    {
        $count = (int) $count;
        if ($count <= 0) {
            return [];
        }
        if (empty($type)) {
            exception('参数错误！');
        }

        // 与 getCode() 前缀/补位规则保持一致；盘点批量仅用 type=10
        $prefixMap = [
            1 => ['rkd', 4],
            2 => ['ckd', 4],
            3 => ['scjh', 4],
            4 => ['scgd', 4],
            5 => ['gy', 2],
            6 => ['scbg', 4],
            7 => ['device', 2],
            8 => ['zj', 3],
            9 => ['ll', 4],
            10 => ['WLPC', 4],
            11 => ['CK', 2],
            12 => ['BX', 4],
            13 => ['FH', 4],
        ];
        if (!isset($prefixMap[$type])) {
            exception('参数错误！');
        }

        [$prefix, $pad] = $prefixMap[$type];
        $nums = self::allocateCodeNums($prefix, $count);
        $ymd = date('Ymd');
        $codes = [];
        foreach ($nums as $num) {
            $strand = str_pad((string) $num, $pad, '0', STR_PAD_LEFT);
            if ($type == 5) {
                $codes[] = 'gy-' . $strand;
            } else {
                $codes[] = $prefix . '-' . $ymd . $strand;
            }
        }

        return $codes;
    }

    /**
     * @param $key '关键字'
     * @param $arr '数组'
     * @return array
     * 将带有键值对的数组处理成只有值的数组
     */
    public static function handlearray($key, $arr = [])
    {
        $data = [];
        foreach ($arr as $v) {
            array_push($data, $v[$key]);
        }
        return $data;
    }

    /**
     * 数组转字符串
     * @param array $arr
     * @return String
     */
    public static function ArrayToString(array $arr): string
    {
        if (empty($arr)) {
            exception('数组不能为空');
        }
        $String = '';
        foreach ($arr as $v) {
            $String .= $v . ',';
        }
        return $String;
    }

    /**
     * 发送http请求地址
     * @return \think\response\Json
     */
    public static function Get($url)
    {
        $client = new Client();
        // 使用快捷方法
        $response = $client->get($url);
        // 获取响应数据
        $data = json_decode($response->getBody()->getContents(), true);
        return $data;
    }

    /**
     * 根据id判断用户是否是班长
     * @param $user_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function is_class($user_id): bool
    {
        if (user_is_super($user_id)) {
            return false;
        }
        $model = new UserAttributesModel();
        $data = $model->where('user_id', $user_id)->find();
        $post = new PostModel();
        $result = $post->where('post_name', '生产班长')->find();
        /*查找对应职位信息*/
        if (empty($data)) {
            exception('用户信息不存在');
        }
        $data = $model->where('user_id', $user_id)
            ->where('post_id', $result['post_id'])
            ->find();
        if (empty($data)) {
            return false;
        }
        return true;
    }
    /**
     * 根据用户名判断用户是否是班长
     * @param $user_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function is_class_nickname($nick_name): bool
    {
        $user_model = new UserModel();
        $user_data = $user_model->where('nickname', $nick_name)->find();
        if (empty($user_data)) {
            exception('没有找到对应用户信息');
        }
        $model = new UserAttributesModel();
        $data = $model->where('user_id', $user_data['user_id'])->find();
        $post = new PostModel();
        $result = $post->where('post_name', '生产班长')->find();
        /*查找对应职位信息*/
        if (empty($data)) {
            exception('用户信息不存在');
        }
        $data = $model->where('user_id', $user_data['user_id'])
            ->where('post_id', $result['post_id'])
            ->find();
        if (empty($data)) {
            return false;
        }
        return true;
    }
}
