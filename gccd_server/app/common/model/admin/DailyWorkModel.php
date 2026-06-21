<?php

namespace app\common\model\admin;

use think\Model;

/**
 * 日工记录模型
 */
class DailyWorkModel extends Model
{
    protected $name = 'daily_work';
    protected $pk = 'id';

    // 自动时间戳
    public $autoTime = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * 获取报工人员信息
     */
    public function getUser()
    {
        return $this->hasOne(\app\common\model\system\UserModel::class, 'user_id', 'user_id')
            ->bind(['nickname' => 'nickname', 'phone' => 'phone']);
    }
}
