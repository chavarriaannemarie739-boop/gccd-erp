<?php

namespace app\common\model\admin;

use think\Model;

class SupervisorAttendanceModel extends Model
{
    protected $name = 'supervisor_attendance';
    protected $pk   = 'id';

    protected $schema = [
        'id'              => 'int',
        'user_id'         => 'int',
        'attendance_date' => 'date',
        'check_in_time'   => 'datetime',
        'check_out_time'  => 'datetime',
        'work_hours'      => 'float',
        'status'          => 'int',
        'remark'          => 'string',
        'create_time'     => 'datetime',
        'update_time'     => 'datetime',
    ];
}
