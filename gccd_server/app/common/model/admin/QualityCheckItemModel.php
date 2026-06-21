<?php

namespace app\common\model\admin;

use think\Model;

class QualityCheckItemModel extends Model
{
    protected $name = 'quality_check_item';
    protected $pk = 'id';

    public function defects()
    {
        return $this->hasMany(QualityCheckDefectModel::class, 'item_id', 'id');
    }
}
