<?php

namespace app\common\model\admin;

use think\Model;

class MaterialInventoryModel extends Model
{
    // 表名
    protected $name = 'material_inventory';

    // 主键
    protected $pk = 'id';

    /**
     * 显式字段类型（须与表 ya_material_inventory 实际列一致）。
     * 断钢/圆钢盘点：断钢规格不再用 duangang_spec 列（老库多无此列），已合并进 current_process_spec，避免 ORM/insertAll 产生对不存在列的写入。
     */
    protected $schema = [
        'id'                    => 'int',
        'inventory_code'        => 'string',
        'inventory_date'        => 'date',
        'process_name'          => 'string',
        'material_id'           => 'int',
        'material_name'         => 'string',
        // [AI-2026-08] 圆钢厂商历史快照（可空，不反写旧记录）
        'y_material_manufacturer' => 'string',
        'y_material_spec'       => 'string',
        'y_material_model'      => 'string',
        'c_material_spec'       => 'string',
        'c_material_model'      => 'string',
        'c_product_spec'        => 'string',
        'finished_product_spec' => 'string',
        'day_shift_tonnage'     => 'float',
        'night_shift_tonnage'   => 'float',
        'total_tonnage'         => 'float',
        'remark'                => 'string',
        'source'                => 'string',
        'confirmed'             => 'int',
        'create_uid'            => 'int',
        'create_time'           => 'datetime',
        'update_uid'            => 'int',
        'update_time'           => 'datetime',
        'is_delete'             => 'int',
    ];

    public function getMaterial()
    {
        return $this->belongsTo(MaterialModel::class, 'material_id', 'id')->bind(['material_code']);
    }

    public function getCreator()
    {
        return $this->belongsTo(\app\common\model\system\UserModel::class, 'create_uid', 'user_id')->bind(['create_username' => 'username', 'create_nickname' => 'nickname']);
    }
}
