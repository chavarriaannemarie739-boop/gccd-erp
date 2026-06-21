<?php

namespace app\common\model\admin;

use think\Model;

class DailySummaryModel extends Model
{
    protected $name = 'daily_summary';
    protected $pk = 'id';

    protected $schema = [
        'id'                        => 'int',
        'report_date'               => 'date',
        'processes_name'            => 'string',
        'class'                     => 'int',
        'production_qty'            => 'float',
        'defective_report_qty'      => 'float',
        'defective_qc_qty'          => 'float',
        'gas_consume'               => 'float',
        'power_consume'             => 'float',
        'gas_amount'                => 'float',
        'power_amount'              => 'float',
        'accessory_amount'          => 'float',
        'guide_amount'              => 'float',
        'wage_amount'               => 'float',
        'report_defect_category_json' => 'string',
        'qc_defect_category_json'   => 'string',
        'create_time'               => 'datetime',
        'update_time'               => 'datetime',
    ];
}
