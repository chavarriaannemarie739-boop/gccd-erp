<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\model\admin\InStockModel;
use app\common\model\admin\ProductCountModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *报工支数服务类
 */
class ProductCountService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'parent_id' => 0,
        'sort' => 0,
        'repair_name' => '',
        'remark' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'id' => '',
        'parent_id' => '',
        'repair_name' => '',
        'remark' => '',
        'is_product' => '',
        'material_thickness' => '',
        'material_model' => '',
        'supplier' => '',
        'manufacturer' => '',
        'material_property' => '',
        'create_start_date' => '',
        'create_end_date' => '',
    ];
    /**
     * 列表
     *
     * @param array  $where 条件
     * @param int    $page  页数
     * @param int    $limit 数量
     * @param array  $order 排序
     * @param string $field 字段
     *
     * @return array
     */
    public static function list($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {
        $model = new ProductCountModel();
        if (empty($order)) {
            $order = ['sort' => 'asc', 'id' => 'asc'];
        }

        // 检查 where 条件中是否包含 parent_id
        $parentId = 0;
        foreach ($where as $w) {
            if (is_array($w) && $w[0] === 'parent_id' && $w[1] === '=') {
                $parentId = $w[2];
                break;
            }
        }

        $count = $model->where($where)->count();
        $all = $model->where($where)->order($order)->select()->toArray();

        // 如果指定了具体的 parent_id 且不为 0，则返回扁平列表，不再构建树（因为 buildTree 默认从 0 开始）
        // 或者如果 parent_id 为 0，则返回树
        if ($parentId == 0) {
            $list = self::buildTree($all);
        } else {
            $list = $all;
        }

        $pages = ceil($count / $limit);
        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 构建树形结构
     */
    private static function buildTree(array $items, $parentId = 0): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int)$item['parent_id'] === (int)$parentId) {
                $children = self::buildTree($items, $item['id']);
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }

    /**
     * 大类列表（供下拉选择）
     */
    public static function category()
    {
        $model = new ProductCountModel();
        return $model->where('is_delete', 0)
            ->where('parent_id', 0)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->field('id, repair_name')
            ->select()
            ->toArray();
    }
    /**
     * 信息
     *
     * @param int  $id   id
     * @param bool $exce 不存在是否抛出异常
     *
     * @return array|Exception
     */
    public static function info($id, $exce = true)
    {
        $info = null;

        if (empty($info)) {
            $model = new ProductCountModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的物料信息不存在');
                }
                return [];
            }
            $info = $info->toArray();
        }
        /*生成调试单号*/
        return $info;
    }
    /**
     * 添加
     *
     * @param array $param 信息
     *
     * @return array|Exception
     */
    public static function add($param)
    {
        $model = new ProductCountModel();
        $exist = $model->where('parent_id', $param['parent_id'])
            ->where('repair_name', $param['repair_name'])
            ->where('is_delete', 0)
            ->find();
        if ($exist) {
            $label = $param['parent_id'] == 0 ? '大类' : '子项目';
            exception("同一分类下已存在名为「{$param['repair_name']}」的{$label}");
        }
        Db::startTrans();
        try {
            $pk = $model->getPk();
            /*根据支数计算出吨数*/
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            $id = $model->insertGetId($param);
            $param[$pk] = $id;
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('创建失败:' . $e->getMessage());
        }
        return $param;
    }

     /**
     * 修改
     *
     * @param int|array $ids   id
     * @param array     $param 信息
     *
     * @return array|Exception
     */
    public static function edit($ids, $param = [])
    {
        $model = new ProductCountModel();
        $pk = $model->getPk();

        $exist = $model->where('parent_id', $param['parent_id'])
            ->where('repair_name', $param['repair_name'])
            ->where('is_delete', 0)
            ->where($pk, '<>', $ids)
            ->find();
        if ($exist) {
            $label = $param['parent_id'] == 0 ? '大类' : '子项目';
            exception("同一分类下已存在名为「{$param['repair_name']}」的{$label}");
        }

        unset($param[$pk], $param['ids']);

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();

        $res = $model->where($pk, 'in', $ids)->update($param);
        if (empty($res)) {
            exception();
        }
        $param['ids'] = $ids;


        return $param;
    }

    /**
     * 删除
     *
     * @param array $ids  id
     * @param bool  $real 是否真实删除
     *
     * @return array|Exception
     */
    public static function dele($ids, $real = true)
    {
        $model = new ProductCountModel();
        $pk = $model->getPk();

        if ($real) {
            $res = $model->where($pk, 'in', $ids)->delete();
        } else {
            $update = delete_update();
            $res = $model->where($pk, 'in', $ids)->update($update);
        }

        if (empty($res)) {
            exception();
        }
        $update['ids'] = $ids;
        return $update;
    }
}
