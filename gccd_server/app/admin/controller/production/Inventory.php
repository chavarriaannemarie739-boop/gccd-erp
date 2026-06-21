<?php

namespace app\admin\controller\production;

use app\common\controller\BaseController;
use app\common\service\admin\MaterialInventoryService;
use app\common\service\utils\MaterialSearchHelper;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("生产盘点")
 * @Apidoc\Group("production")
 */
class Inventory extends BaseController
{
    /**
     * @Apidoc\Title("盘点列表")
     */
    public function list()
    {
        $where = [['is_delete', '=', '0']];
        $param = $this->params(MaterialInventoryService::$inventory_search_field);

        if (!empty($param['material_name'])) {
            $where[] = ['material_name', 'like', '%' . $param['material_name'] . '%'];
        }
        if (!empty($param['process_name'])) {
            $where[] = ['process_name', 'like', '%' . $param['process_name'] . '%'];
        }
        $ySpec = $param['y_material_spec'] ?? $param['finished_product_spec'] ?? '';
        $yModel = $param['y_material_model'] ?? '';
        $cSpec = $param['c_material_spec'] ?? $param['current_process_spec'] ?? '';
        $cModel = $param['c_material_model'] ?? '';
        if ($ySpec !== '') {
            $where[] = ['y_material_spec', 'like', '%' . MaterialSearchHelper::likeKeyword((string) $ySpec) . '%'];
        }
        if ($yModel !== '') {
            $where[] = ['y_material_model', 'like', '%' . MaterialSearchHelper::likeKeyword((string) $yModel) . '%'];
        }
        if ($cSpec !== '') {
            $where[] = ['c_material_spec', 'like', '%' . MaterialSearchHelper::likeKeyword((string) $cSpec) . '%'];
        }
        if ($cModel !== '') {
            $where[] = ['c_material_model', 'like', '%' . MaterialSearchHelper::likeKeyword((string) $cModel) . '%'];
        }
        if (!empty($param['inventory_start_date'])) {
            $where[] = ['inventory_date', '>=', $param['inventory_start_date']];
        }
        if (!empty($param['inventory_end_date'])) {
            $where[] = ['inventory_date', '<=', $param['inventory_end_date']];
        }

        $data = MaterialInventoryService::inventoryList($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }

    /**
     * @Apidoc\Title("详情")
     */
    public function info()
    {
        $param = $this->params(['id/d' => '']);
        $data = MaterialInventoryService::inventoryInfo($param['id']);
        return success($data);
    }

    /**
     * @Apidoc\Title("添加盘点")
     */
    public function add()
    {
        $param = $this->params(MaterialInventoryService::$inventory_edit_field);
        $data = MaterialInventoryService::addInventory($param);
        return success($data);
    }

    /**
     * @Apidoc\Title("修改盘点")
     */
    public function edit()
    {
        $param = $this->params(MaterialInventoryService::$inventory_edit_field);
        $data = MaterialInventoryService::editInventory($param['id'], $param);
        return success($data);
    }

    /**
     * @Apidoc\Title("删除盘点")
     */
    public function dele()
    {
        $param = $this->params(['ids/a' => []]);
        $data = MaterialInventoryService::deleInventory($param['ids']);
        return success($data);
    }

    /**
     * @Apidoc\Title("小程序提报批量数据")
     */
    public function submitStocktake()
    {
        $param = $this->request->post();
        $data = MaterialInventoryService::batchSubmitStocktake($param);
        return success($data);
    }

    /**
     * @Apidoc\Title("自动推算线边仓库存")
     * @Apidoc\Desc("基于最新盘点期初 + 报工数据，套用工序上下游公式自动推算今日库存")
     * @Apidoc\Param("calc_date", type="string", desc="目标日期（默认今天，格式Y-m-d）")
     * @Apidoc\Returned("data", type="array", desc="推算结果数组")
     */
    public function autoCalc()
    {
        $calcDate = $this->request->param('calc_date', '');
        $data = MaterialInventoryService::autoCalculateInventory($calcDate);

        if (isset($data['error'])) {
            return error($data['error']);
        }

        return success($data, '推算成功');
    }

    /**
     * @Apidoc\Title("月度库存台账")
     * @Apidoc\Desc("以上月结存为期初，按月统计各工序逐日出入变化和当前结存")
     * @Apidoc\Param("year_month", type="string", desc="年月（默认本月，格式Y-m）")
     */
    public function monthlyLedger()
    {
        $yearMonth = $this->request->param('year_month', '');
        $data = MaterialInventoryService::getMonthlyLedger($yearMonth);

        if (isset($data['error'])) {
            return error($data['error']);
        }

        return success($data, '获取成功');
    }
}
