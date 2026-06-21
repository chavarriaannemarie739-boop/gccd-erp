<?php

namespace app\common\model\admin;

use think\Model;

class StockModel extends Model
{
    protected $name = "wms_stock";
    protected $pk = 'id';

    /*与物料拓展表建立绑定关系*/
//    public function getMaterial()
//    {
//        return $this->hasOne(MesSheetExtModel::class,'sheet_id','id')
//            ->where('action','=',1)
//            ->bind(
//                [
//                    'material_name'=>'material_name',
//                    'material_code'=>'material_code',
//                    'material_spec'=>'material_spec',
//                    'material_texture'=>'material_texture',
//                    'material_old_spec'=>'material_old_spec'
//                ]
//            );
//    }
    public function getTrack()
    {
        return $this->hasOne(ProcessesModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
