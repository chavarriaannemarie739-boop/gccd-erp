<?php

namespace app\admin\controller\production;

use app\common\controller\BaseController;
use app\common\service\admin\DuangangMergeService;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("断钢合并报工")
 */
class DuangangMerge extends BaseController
{
    public function list()
    {
        $param = $this->param();
        $status = isset($param['status']) ? (int) $param['status'] : 0;
        $keyword = trim((string) ($param['keyword'] ?? ''));
        $data = DuangangMergeService::list($status, $this->page(), $this->limit(), $keyword);

        return success($data);
    }

    public function info()
    {
        $param = $this->params(['merge_group_id/d' => 0]);
        $data = DuangangMergeService::info((int) $param['merge_group_id']);

        return success($data);
    }

    public function report()
    {
        $param = $this->param();
        $data = DuangangMergeService::report($param);

        return success($data);
    }

    public function rebuild()
    {
        DuangangMergeService::rebuildAll();

        return success([], '重建完成');
    }
}
