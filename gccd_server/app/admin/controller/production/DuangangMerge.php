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
        /* [AI-2026-08] 小程序断钢列表按圆钢四字段筛选；keyword 保持兼容。 */
        $param = $this->param();
        $status = isset($param['status']) ? (int) $param['status'] : 0;
        $keyword = trim((string) ($param['keyword'] ?? ''));
        $filters = [
            'keyword' => $keyword,
            'y_material_name' => trim((string) ($param['y_material_name'] ?? '')),
            'y_material_manufacturer' => trim((string) ($param['y_material_manufacturer'] ?? '')),
            'y_material_spec' => trim((string) ($param['y_material_spec'] ?? '')),
            'y_material_model' => trim((string) ($param['y_material_model'] ?? '')),
        ];
        $data = DuangangMergeService::list($status, $this->page(), $this->limit(), $filters);

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
