<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

// 应用服务类
declare(strict_types=1);

namespace app;

use think\Service;

class AppService extends Service
{
    public function register()
    {
        // 服务注册
    }

    public function boot()
    {
        // think\initializer\Error 会把 error_reporting 设为 E_ALL，PHP 8.1+ 下 E_DEPRECATED
        // （如「Using null as an array offset」）会被转成 ErrorException 直接中断接口。
        // 在业务代码与部分依赖未全部升级前，关闭「弃用」级别的可抛出报告，其余错误不变。
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        // 数据库结构初始化（幂等，仅在首次请求时执行）
        \app\common\service\admin\DatabaseInitService::ensure();
    }
}
