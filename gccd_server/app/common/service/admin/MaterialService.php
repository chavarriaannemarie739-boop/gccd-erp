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
use app\common\model\admin\MaterialModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class MaterialService
{
    /**
     * [AI-2026-08] 物料档案按类型查重：圆钢使用四字段，其余类型保持原三字段规则。
     *
     * @param MaterialModel $model
     * @param array{name:string,spec:string,model:string,manufacturer?:string} $identity
     * @param array<int> $excludeIds 编辑时排除自身 id
     * @param string $materialProperty 圆钢|成品|配件
     * @return void
     */
    private static function assertUniqueMaterialTriple(
        MaterialModel $model,
        array $identity,
        array $excludeIds = [],
        string $materialProperty = ''
    ): void {
        $name = $identity['name'];
        $spec = $identity['spec'];
        $modelStr = $identity['model'];
        $prop = trim($materialProperty);
        if ($prop === '') {
            exception('无法校验重复：缺少物料属性');
        }
        /* [AI-2026-08] 与库内 NULL/空串统一按 trim 后字符串比较，参数均由查询构造器绑定。 */
        $q = $model->where('is_delete', 0)
            ->where('material_property', $prop)
            ->whereRaw('TRIM(IFNULL(material_name, \'\')) = ?', [$name])
            ->whereRaw('TRIM(IFNULL(material_spec, \'\')) = ?', [$spec])
            ->whereRaw('TRIM(IFNULL(material_model, \'\')) = ?', [$modelStr]);
        if ($prop === '圆钢') {
            $q->whereRaw(
                'TRIM(IFNULL(manufacturer, \'\')) = ?',
                [$identity['manufacturer'] ?? '']
            );
        }
        if ($excludeIds !== []) {
            $q->whereNotIn('id', $excludeIds);
        }
        $exists = $q->find();
        if (!empty($exists)) {
            if ($prop === '圆钢') {
                exception('已存在名称、生产厂商、材质、型号完全相同的圆钢档案，请勿重复建档');
            }
            exception('已存在名称、规格、型号完全相同的' . $prop . '档案，请勿重复建档');
        }
    }

    /**
     * [AI-2026-08] 规范化档案查重标识；编辑时仅对未提交字段使用原档案值。
     *
     * @param array<string, mixed> $param
     * @param array<string, mixed>|null $existing 编辑时库中原记录，用于补全未传字段
     * @param string $materialProperty 圆钢|成品|配件
     * @return array{name:string,spec:string,model:string,manufacturer?:string}
     */
    private static function normalizeMaterialTriple(
        array $param,
        ?array $existing = null,
        string $materialProperty = ''
    ): array {
        $e = $existing ?? [];
        $fieldValue = static function (string $field) use ($param, $e): string {
            $value = array_key_exists($field, $param)
                ? $param[$field]
                : ($e[$field] ?? '');

            return trim((string) $value);
        };
        $identity = [
            'name' => $fieldValue('material_name'),
            'spec' => $fieldValue('material_spec'),
            'model' => $fieldValue('material_model'),
        ];
        if ($materialProperty !== '圆钢') {
            return $identity;
        }

        $identity = [
            'name' => $identity['name'],
            'manufacturer' => $fieldValue('manufacturer'),
            'spec' => $identity['spec'],
            'model' => $identity['model'],
        ];
        $requiredLabels = [
            'name' => '圆钢名称',
            'manufacturer' => '生产厂商',
            'spec' => '材质',
            'model' => '型号',
        ];
        foreach ($requiredLabels as $field => $label) {
            if ($identity[$field] === '') {
                exception($label . '不能为空');
            }
        }

        return $identity;
    }

    /**
     * [AI-2026-08] 所有物料身份字段必须逐条编辑，避免批量补全制造重复档案。
     *
     * @param int $recordCount
     * @param array<string, mixed> $param
     * @return void
     */
    private static function assertBatchIdentityEditAllowed(
        int $recordCount,
        array $param
    ): void {
        if ($recordCount <= 1 || !self::hasMaterialIdentityField($param)) {
            return;
        }

        exception('物料名称、生产厂商、规格（材质）、型号及物料属性请逐条修改，不支持批量编辑');
    }

    /**
     * [AI-2026-08] 判断本次编辑是否涉及物料身份字段。
     *
     * @param array<string, mixed> $param
     * @return bool
     */
    private static function hasMaterialIdentityField(array $param): bool
    {
        $identityFields = [
            'material_property',
            'material_name',
            'manufacturer',
            'material_spec',
            'material_model',
        ];
        foreach ($identityFields as $field) {
            if (array_key_exists($field, $param)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 物料档案三类属性（与圆钢/成品/配件档案列表筛选一致）
     *
     * @param mixed $raw
     */
    private static function assertMaterialPropertyAllowed($raw): string
    {
        $v = trim((string) $raw);
        $allowed = ['圆钢', '成品', '配件'];
        if (!in_array($v, $allowed, true)) {
            exception('物料属性必须为「圆钢」「成品」或「配件」。当前值无效或未提交，请从对应档案页新增，或联系管理员检查接口参数与数据库默认值。');
        }
        return $v;
    }

    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'material_name' => '',
        'material_spec' => '', //
        'is_product' => '',
        'material_thickness' => '',
        'material_model' => '',
        'supplier' => '',
        'manufacturer' => '',
        'material_property' => '',//物料属性
        'stock' => '',//所属仓库
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'id' => '',
        'material_name' => '',
        'material_spec' => '', //
        'is_product' => '',
        'material_thickness' => '',
        'material_model' => '',
        'supplier' => '',
        'manufacturer' => '',
        'material_property' => '',//物料属性
        'material_scope' => '',
        'stock' => '',//所属仓库
        'stock_alert_only' => '',
        'create_start_date' => '',
        'create_end_date' => '',
    ];
    /** 树节点空值哨兵，前端筛选未填写字段时使用 */
    public const ROUND_STEEL_TREE_EMPTY = '__EMPTY__';

    /**
     * [AI-2026-08] 将圆钢四字段行聚合成名称>厂商>材质>型号导航树。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function buildRoundSteelTree(array $rows): array
    {
        $levels = [
            ['field' => 'material_name', 'tag' => '大类'],
            ['field' => 'manufacturer', 'tag' => '厂商'],
            ['field' => 'material_spec', 'tag' => '材质'],
            ['field' => 'material_model', 'tag' => '型号'],
        ];

        $normalize = static function ($raw): array {
            $trimmed = trim((string) $raw);
            if ($trimmed === '') {
                return [
                    'value' => self::ROUND_STEEL_TREE_EMPTY,
                    'label' => '未填写',
                ];
            }

            return [
                'value' => $trimmed,
                'label' => $trimmed,
            ];
        };

        $root = [];
        foreach ($rows as $row) {
            $cursor = &$root;
            $path = [];
            foreach ($levels as $depth => $meta) {
                $normalized = $normalize($row[$meta['field']] ?? '');
                $value = $normalized['value'];
                $path[] = $meta['field'] . ':' . $value;
                $key = implode('|', $path);
                if (!isset($cursor[$key])) {
                    $cursor[$key] = [
                        'id' => $key,
                        'label' => $normalized['label'],
                        'value' => $value,
                        'field' => $meta['field'],
                        'tag' => $meta['tag'],
                        'level' => $depth + 1,
                        'count' => 0,
                        'children' => [],
                    ];
                }
                $cursor[$key]['count']++;
                $cursor = &$cursor[$key]['children'];
            }
            unset($cursor);
        }

        $sortNodes = static function (array &$nodes) use (&$sortNodes): void {
            uasort($nodes, static function (array $a, array $b): int {
                if ($a['value'] === self::ROUND_STEEL_TREE_EMPTY && $b['value'] !== self::ROUND_STEEL_TREE_EMPTY) {
                    return 1;
                }
                if ($b['value'] === self::ROUND_STEEL_TREE_EMPTY && $a['value'] !== self::ROUND_STEEL_TREE_EMPTY) {
                    return -1;
                }

                return strnatcasecmp((string) $a['label'], (string) $b['label']);
            });
            foreach ($nodes as &$node) {
                if ($node['children'] !== []) {
                    $sortNodes($node['children']);
                    $node['children'] = array_values($node['children']);
                }
            }
            unset($node);
        };

        $sortNodes($root);

        return array_values($root);
    }

    /**
     * [AI-2026-08] 读取未删除圆钢档案并返回导航树。
     *
     * @return array{tree: array<int, array<string, mixed>>, total: int}
     */
    public static function roundSteelTree(): array
    {
        $rows = (new MaterialModel())
            ->where('is_delete', 0)
            ->where('material_property', '圆钢')
            ->field('material_name, manufacturer, material_spec, material_model')
            ->order('material_name', 'asc')
            ->order('manufacturer', 'asc')
            ->order('material_spec', 'asc')
            ->order('material_model', 'asc')
            ->select()
            ->toArray();

        return [
            'tree' => self::buildRoundSteelTree($rows),
            'total' => count($rows),
        ];
    }

    /**
     * 列表
     *
     * @param array  $where 条件
     * @param int    $page  页数
     * @param int    $limit 数量
     * @param array  $order 排序
     * @param string $field 字段
     * @param array<int, string> $emptyFields 需要匹配空字符串/NULL 的字段名
     *
     * @return array
     */
    public static function list($where = [], $page = 1, $limit = 10, $order = [], $field = [], $emptyFields = [])
    {
        $model = new MaterialModel();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        $applyEmpty = static function ($query) use ($emptyFields) {
            foreach ($emptyFields as $emptyField) {
                if (!in_array($emptyField, ['material_name', 'manufacturer', 'material_spec', 'material_model'], true)) {
                    continue;
                }
                $query->whereRaw('TRIM(IFNULL(`' . $emptyField . '`, \'\')) = \'\'');
            }

            return $query;
        };
        if ($page == 0 || $limit == 0) {
            return $applyEmpty($model->where($where))->order($order)->select()->toArray();
        }
        $count = $applyEmpty($model->where($where))->count();
        $pages = ceil($count / $limit);
        $list = $applyEmpty($model->where($where))->page($page)->limit($limit)->order($order)->select()->toArray();
        $materialProperty = '';
        foreach ($where as $cond) {
            if (!is_array($cond)) {
                continue;
            }
            if (($cond[0] ?? '') === 'material_property' && ($cond[1] ?? '') === 'like' && !empty($cond[2])) {
                $materialProperty = trim(str_replace('%', '', (string) $cond[2]));
                break;
            }
        }
        if (in_array($materialProperty, ['配件', '圆钢'], true)) {
            $list = StockAlertService::enrichMaterialListRows($list, $materialProperty);
        }

        return compact('count', 'pages', 'page', 'limit', 'list');
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
            $model = new MaterialModel();
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
        Db::startTrans();
        try {
            $model = new MaterialModel();
            $pk = $model->getPk();
            $param['material_property'] = self::assertMaterialPropertyAllowed($param['material_property'] ?? '');
            /* [AI-2026-08] 圆钢新增强制四字段非空，并按四字段联合查重。 */
            $triple = self::normalizeMaterialTriple($param, null, $param['material_property']);
            self::assertUniqueMaterialTriple($model, $triple, [], $param['material_property']);
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
        Db::startTrans();
        try {
            $model = new MaterialModel();
            $pk = $model->getPk();

            unset($param[$pk], $param['ids']);

            $rawIdList = is_array($ids) ? $ids : [$ids];
            $idList = array_values(array_unique(array_filter(array_map('intval', $rawIdList))));
            if ($idList === []) {
                exception('物料记录不存在或已删除');
            }
            $recordCount = count($idList);
            $hasIdentityEdit = self::hasMaterialIdentityField($param);

            /* [AI-2026-08] 事务内锁定全部未删除目标，确保读取、校验和更新使用一致记录集。 */
            $existingRows = $model->where($pk, 'in', $idList)
                ->where('is_delete', 0)
                ->lock(true)
                ->select()
                ->toArray();
            if (count($existingRows) !== $recordCount) {
                exception('物料记录不存在或已删除');
            }
            self::assertBatchIdentityEditAllowed($recordCount, $param);

            if ($recordCount === 1) {
                /* [AI-2026-08] 单条编辑始终读取原档案，以原属性决定圆钢四字段校验。 */
                $existingRow = $existingRows[0];
                $materialProperty = array_key_exists('material_property', $param)
                    ? trim((string) $param['material_property'])
                    : trim((string) ($existingRow['material_property'] ?? ''));
                $materialProperty = self::assertMaterialPropertyAllowed($materialProperty);
                if ($materialProperty === '圆钢' || $hasIdentityEdit) {
                    $identity = self::normalizeMaterialTriple($param, $existingRow, $materialProperty);
                    if ($hasIdentityEdit) {
                        /* [AI-2026-08] 只有身份字段发生修改时才执行联合查重。 */
                        self::assertUniqueMaterialTriple(
                            $model,
                            $identity,
                            array_map('intval', $idList),
                            $materialProperty
                        );
                    }
                }
            }

            if (array_key_exists('material_property', $param)) {
                $mp = trim((string) ($param['material_property'] ?? ''));
                if ($mp !== '') {
                    $param['material_property'] = self::assertMaterialPropertyAllowed($mp);
                }
            }

            $param['update_uid']  = user_id();
            $param['update_time'] = datetime();

            $model->where($pk, 'in', $idList)
                ->where('is_delete', 0)
                ->update($param);
            $param['ids'] = $ids;

            Db::commit();
        } catch (\Throwable $throwable) {
            Db::rollback();
            throw $throwable;
        }

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
        $model = new MaterialModel();
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
