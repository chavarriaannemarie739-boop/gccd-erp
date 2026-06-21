<?php

namespace app\admin\controller\production;

use app\common\controller\BaseController;
use app\common\service\admin\QualityCheckRecordService;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("质量检验记录")
 * @Apidoc\Group("production")
 */
class QualityCheckRecord extends BaseController
{
    /**
     * @Apidoc\Title("质检列表")
     */
    public function list()
    {
        $where = [['is_delete', '=', '0']];
        $param = $this->params(QualityCheckRecordService::$search_field);

        if (!empty($param['process_name'])) {
            $where[] = ['process_name', 'like', '%' . $param['process_name'] . '%'];
        }
        if (!empty($param['check_start_date'])) {
            $where[] = ['check_date', '>=', $param['check_start_date']];
        }
        if (!empty($param['check_end_date'])) {
            $where[] = ['check_date', '<=', $param['check_end_date']];
        }

        $data = QualityCheckRecordService::list($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }

    /**
     * @Apidoc\Title("小程序提报质检数据")
     */
    public function submitQualityCheck()
    {
        $param = $this->request->post();
        $data = QualityCheckRecordService::batchSubmitQualityCheck($param);
        return success($data);
    }
}
