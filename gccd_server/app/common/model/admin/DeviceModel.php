<?php

namespace app\common\model\admin;

use think\Model;

class DeviceModel extends Model
{
    protected $name = "device";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(ProcessesModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'processes_name',
                ]
            );
    }
    /*获取文章地址*/
    public function getArticle()
    {
        return $this->hasOne(InStockModel::class, 'id', 'task_id')
            ->bind(
                [
                    'article_arc' => 'article_src'
                ]
            );
    }
}
