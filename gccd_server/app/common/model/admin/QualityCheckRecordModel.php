<?php

namespace app\common\model\admin;

use think\Model;

class QualityCheckRecordModel extends Model
{
    protected $name = 'quality_check_record';
    protected $pk = 'id';

    public function getCreator()
    {
        return $this->belongsTo(\app\common\model\system\UserModel::class, 'user_id', 'user_id')->bind(['create_username' => 'username', 'create_nickname' => 'nickname']);
    }

    public function items()
    {
        return $this->hasMany(QualityCheckItemModel::class, 'record_id', 'id');
    }
}
