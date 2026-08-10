<?php

/**
 * [AI-2026-08] 物料按仓库范围维护的最低库存阈值模型。
 */

declare(strict_types=1);

namespace app\common\model\admin;

use think\Model;

class MaterialStockThresholdModel extends Model
{
    protected $name = 'material_stock_threshold';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'material_id' => 'int',
        'warehouse_scope' => 'string',
        'min_stock_qty' => 'float',
        'create_time' => 'datetime',
        'create_uid' => 'int',
        'update_time' => 'datetime',
        'update_uid' => 'int',
    ];
}
