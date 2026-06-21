<?php

namespace app\common\service\admin;

use app\common\model\admin\QualityCheckRecordModel;
use app\common\model\admin\QualityCheckItemModel;
use app\common\model\admin\QualityCheckDefectModel;
use think\facade\Db;

class QualityCheckRecordService
{
    public static $search_field = [
        'check_start_date' => '',
        'check_end_date' => '',
        'process_name' => '',
    ];

    /**
     * 列表
     */
    public static function list($where = [], $page = 1, $limit = 10, $order = [])
    {
        $model = new QualityCheckRecordModel();
        if (empty($order)) {
            $order = ['check_date' => 'desc', 'create_time' => 'desc'];
        }

        $query = $model->with(['getCreator', 'items' => function ($query) {
            $query->with(['defects']);
        }])->where($where)->order($order);

        if ($page == 0 || $limit == 0) {
            return $query->select()->toArray();
        }

        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $query->page($page)->limit($limit)->select();

        // 严谨：手动计算统计字段，确保 Web 端前端显示正常
        $list = $list->map(function ($record) {
            $totalDefects = 0;
            if (!empty($record['items'])) {
                foreach ($record['items'] as $item) {
                    if (!empty($item['defects'])) {
                        foreach ($item['defects'] as $defect) {
                            $totalDefects += (float)($defect['day_qty'] ?? 0) + (float)($defect['night_qty'] ?? 0);
                        }
                    }
                }
            }
            $record['total_defects_count'] = $totalDefects;
            return $record;
        })->toArray();

        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 小程序/API 批量提交质检数据
     */
    public static function batchSubmitQualityCheck($data)
    {
        Db::startTrans();
        try {
            $checkDate = $data['check_date'] ?? date('Y-m-d');
            $processName = $data['process_name'] ?? '';
            $userId = user_id() ?: ($data['user_id'] ?? 0);
            $records = $data['records'] ?? [];

            if (empty($records)) {
                exception('质检记录不能为空');
            }

            // 1. 插入主表
            $recordModel = new QualityCheckRecordModel();
            $recordId = $recordModel->insertGetId([
                'check_date' => $checkDate,
                'process_name' => $processName,
                'user_id' => $userId,
                'create_time' => datetime(),
                'update_time' => datetime(),
            ]);

            $itemModel = new QualityCheckItemModel();
            $defectModel = new QualityCheckDefectModel();

            // 2. 遍历物料明细并插入
            foreach ($records as $record) {
                $itemId = $itemModel->insertGetId([
                    'record_id' => $recordId,
                    'y_material_name' => $record['y_material_name'] ?? '',
                    'y_material_spec' => $record['y_material_spec'] ?? '',
                    'c_material_spec' => $record['c_material_spec'] ?? '',
                    'c_material_model' => $record['c_material_model'] ?? '',
                ]);

                // 3. 遍历缺陷类型并插入
                if (!empty($record['defective_items'])) {
                    $defectData = [];
                    foreach ($record['defective_items'] as $defect) {
                        $defectData[] = [
                            'item_id' => $itemId,
                            'defect_type' => $defect['type'] ?? '',
                            'defect_text' => $defect['typeText'] ?? '',
                            'day_qty' => floatval($defect['day_qty'] ?? 0),
                            'night_qty' => floatval($defect['night_qty'] ?? 0),
                        ];
                    }
                    if (!empty($defectData)) {
                        $defectModel->insertAll($defectData);
                    }
                }
            }

            $itemCount = count($records);
            Db::commit();
            return "提报成功，已入库 {$itemCount} 条明细记录";
        } catch (\Exception $e) {
            Db::rollback();
            exception('提报失败:' . $e->getMessage());
        }
    }
}
