<?php

// +----------------------------------------------------------------------
// | 高齿传动生产管理系统
// +----------------------------------------------------------------------
// | 日报表控制器
// +----------------------------------------------------------------------

namespace app\admin\controller\production;

use app\common\controller\BaseController;
use app\common\service\admin\DailyReportService;
use app\common\service\admin\DailySummaryService;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("日报表管理")
 * @Apidoc\Group("report")
 * @Apidoc\Sort("300")
 */
class DailyReport extends BaseController
{
    /**
     * @Apidoc\Title("获取报表数据（支持日期范围）")
     * @Apidoc\Param("start_date", type="string", require=true, desc="开始日期，格式：Y-m-d")
     * @Apidoc\Param("end_date", type="string", require=true, desc="结束日期，格式：Y-m-d")
     * @Apidoc\Param("processes_name", type="string", desc="工序名称（可选）")
     * @Apidoc\Returned("data", type="array", desc="报表数据")
     */
    public function getReport()
    {
        $startDate = $this->request->param('start_date');
        $endDate = $this->request->param('end_date');
        $processesName = $this->request->param('processes_name', '');

        if (empty($startDate) || empty($endDate)) {
            return error('请选择日期范围');
        }

        try {
            $service = new DailyReportService();
            $data = $service->getReportByDateRange($startDate, $endDate, $processesName);

            return success($data, '获取成功');
        } catch (\Exception $e) {
            return error($e->getMessage());
        }
    }

    /**
     * @Apidoc\Title("保存手动输入数据")
     * @Apidoc\Param("report_date", type="string", require=true, desc="报表日期")
     * @Apidoc\Param("processes_name", type="string", require=true, desc="工序名称")
     * @Apidoc\Param("accessory_amount", type="float", desc="配件金额")
     * @Apidoc\Param("guide_amount", type="float", desc="顶头导板金额")
     * @Apidoc\Param("gas_num", type="float", desc="天然气量")
     * @Apidoc\Param("power_num", type="float", desc="电表读数")
     * @Apidoc\Param("wage_amount", type="float", desc="工资总额")
     */
    public function saveManualData()
    {
        $reportDate = $this->request->param('report_date');
        $processName = $this->request->param('processes_name');
        $data = $this->request->param();

        if (empty($reportDate) || empty($processName)) {
            return error('参数错误');
        }

        try {
            $service = new DailyReportService();
            $result = $service->saveManualData($reportDate, $processName, $data);

            if ($result) {
                return success(null, '保存成功');
            } else {
                return error('保存失败');
            }
        } catch (\Exception $e) {
            return error($e->getMessage());
        }
    }

    /**
     * @Apidoc\Title("批量保存手动输入数据")
     * @Apidoc\Param("report_date", type="string", require=true, desc="报表日期")
     * @Apidoc\Param("items", type="array", require=true, desc="数据项数组")
     */
    public function batchSaveManualData()
    {
        $reportDate = $this->request->param('report_date');
        $items = $this->request->param('items', []);

        if (empty($reportDate) || empty($items)) {
            return error('参数错误');
        }

        try {
            $service = new DailyReportService();
            $successCount = 0;

            foreach ($items as $item) {
                if (isset($item['processes_name'])) {
                    $result = $service->saveManualData(
                        $reportDate,
                        $item['processes_name'],
                        $item
                    );
                    if ($result) {
                        $successCount++;
                    }
                }
            }

            return success([
                'success_count' => $successCount,
                'total_count' => count($items)
            ], "保存成功 {$successCount}/" . count($items));
        } catch (\Exception $e) {
            return error($e->getMessage());
        }
    }

    /**
     * @Apidoc\Title("导出Excel报表")
     * @Apidoc\Param("report_date", type="string", desc="报表日期")
     */
    public function export()
    {
        $reportDate = $this->request->param('report_date', date('Y-m-d'));

        try {
            $service = new DailyReportService();
            $data = $service->getDailyReport($reportDate);

            // TODO: 实现Excel导出逻辑
            // 使用 PhpOffice\PhpSpreadsheet 生成Excel文件

            return success(null, '导出功能开发中...');
        } catch (\Exception $e) {
            return error($e->getMessage());
        }
    }

    /**
     * 回填历史汇总数据
     */
    public function refreshSummary()
    {
        $startDate = $this->request->param('start_date', date('Y-m-01'));
        $endDate = $this->request->param('end_date', date('Y-m-d'));

        try {
            $msg = DailySummaryService::refreshHistory($startDate, $endDate);
            return success(null, $msg);
        } catch (\Exception $e) {
            return error($e->getMessage());
        }
    }
}
