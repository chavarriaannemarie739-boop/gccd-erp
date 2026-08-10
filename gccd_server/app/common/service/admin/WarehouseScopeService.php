<?php
/**
 * [AI-2026-08] 仓库权限范围：菜单权限码 ∪ 旧角色名 → 圆钢/配件/成品
 */

declare(strict_types=1);

namespace app\common\service\admin;

use app\common\model\system\MenuModel;
use app\common\model\system\RoleModel;
use app\common\model\system\UserAttributesModel;
use app\common\model\system\UserModel;
use app\common\service\system\MenuService;
use app\common\service\system\RoleService;

class WarehouseScopeService
{
    public const TYPE_ROUND = '圆钢';
    public const TYPE_ACCESSORY = '配件';
    public const TYPE_PRODUCT = '成品';

    public const ACTION_ARCHIVE = 'archive';
    public const ACTION_INVENTORY = 'inventory';
    public const ACTION_INBOUND = 'inbound';
    public const ACTION_OUTBOUND = 'outbound';

    /** @var array<int, array<string, mixed>> 请求内 scopes 缓存 */
    private static $scopesMemo = [];

    /** @var array<int, string[]> 请求内菜单 URL 缓存 */
    private static $menuUrlsMemo = [];

    /** @var array<int, int[]> 请求内角色 ID 缓存 */
    private static $roleIdsMemo = [];

    /** @var array<int, int[]> 请求内菜单 ID 缓存 */
    private static $menuIdsMemo = [];

    /** @var array<int, bool> 请求内超管判定缓存 */
    private static $unlimitedMemo = [];

    /** @var array<string, string> menu_url => 仓库类型 */
    private const PERM_TO_TYPE = [
        'warehouse.archive.roundSteel' => self::TYPE_ROUND,
        'warehouse.archive.accessory' => self::TYPE_ACCESSORY,
        'warehouse.archive.product' => self::TYPE_PRODUCT,
        'warehouse.inventory.roundSteel' => self::TYPE_ROUND,
        'warehouse.inventory.accessory' => self::TYPE_ACCESSORY,
        'warehouse.inventory.product' => self::TYPE_PRODUCT,
        'warehouse.inbound.roundSteel' => self::TYPE_ROUND,
        'warehouse.inbound.accessory' => self::TYPE_ACCESSORY,
        'warehouse.inbound.product' => self::TYPE_PRODUCT,
        'warehouse.outbound.roundSteel' => self::TYPE_ROUND,
        'warehouse.outbound.accessory' => self::TYPE_ACCESSORY,
        'warehouse.outbound.product' => self::TYPE_PRODUCT,
    ];

    /** @var array<string, string> 角色名 => 仓库类型（兼容旧配置） */
    private const ROLE_NAME_TO_TYPE = [
        '配件仓库' => self::TYPE_ACCESSORY,
        '配件库' => self::TYPE_ACCESSORY,
        '圆钢仓库' => self::TYPE_ROUND,
        '圆钢库' => self::TYPE_ROUND,
        '原料库' => self::TYPE_ROUND,
        '原料仓库' => self::TYPE_ROUND,
        '成品仓库' => self::TYPE_PRODUCT,
        '成品库' => self::TYPE_PRODUCT,
        '成品仓' => self::TYPE_PRODUCT,
        '圆钢、成品' => self::TYPE_PRODUCT,
    ];

    /**
     * 登录信息用：各动作允许的仓库类型；unlimited=true 表示不限（超管）
     *
     * @return array{
     *   unlimited: bool,
     *   archive: string[],
     *   inventory: string[],
     *   inbound: string[],
     *   outbound: string[]
     * }
     */
    public static function scopesForUser(?int $userId = null): array
    {
        $userId = $userId ?: (int) user_id();
        if ($userId > 0 && isset(self::$scopesMemo[$userId])) {
            return self::$scopesMemo[$userId];
        }

        if (self::isUnlimitedUser($userId)) {
            $all = [self::TYPE_ROUND, self::TYPE_ACCESSORY, self::TYPE_PRODUCT];
            $scopes = [
                'unlimited' => true,
                'archive' => $all,
                'inventory' => $all,
                'inbound' => $all,
                'outbound' => $all,
            ];
            if ($userId > 0) {
                self::$scopesMemo[$userId] = $scopes;
            }

            return $scopes;
        }

        // [AI-2026-08] 一次取菜单/角色，派生四类动作；避免 allowedTypes×4 重复打库（登录卡十几秒）
        $scopes = [
            'unlimited' => false,
            'archive' => self::allowedTypes(self::ACTION_ARCHIVE, $userId),
            'inventory' => self::allowedTypes(self::ACTION_INVENTORY, $userId),
            'inbound' => self::allowedTypes(self::ACTION_INBOUND, $userId),
            'outbound' => self::allowedTypes(self::ACTION_OUTBOUND, $userId),
        ];
        if ($userId > 0) {
            self::$scopesMemo[$userId] = $scopes;
        }

        return $scopes;
    }

    /**
     * @return string[] 空数组且非超管 = 无任何仓权限
     */
    public static function allowedTypes(string $action, ?int $userId = null): array
    {
        $userId = $userId ?: (int) user_id();
        if (self::isUnlimitedUser($userId)) {
            return [self::TYPE_ROUND, self::TYPE_ACCESSORY, self::TYPE_PRODUCT];
        }

        $prefix = 'warehouse.' . $action . '.';
        $types = [];
        $menuUrls = self::userMenuUrls($userId);
        $hasAnyWarehousePerm = false;

        foreach ($menuUrls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            if (isset(self::PERM_TO_TYPE[$url])) {
                $hasAnyWarehousePerm = true;
            }
            if (isset(self::PERM_TO_TYPE[$url]) && str_starts_with($url, $prefix)) {
                $types[self::PERM_TO_TYPE[$url]] = true;
            }
        }

        // 已有档案页面菜单（按 path/component 识别）→ 仅贡献 archive
        if ($action === self::ACTION_ARCHIVE) {
            foreach (self::typesFromArchiveMenus($userId) as $type) {
                $types[$type] = true;
            }
        }

        /*
         * 旧角色名兼容：仅当用户尚未勾选任何 warehouse.* 按钮权限时生效，
         * 避免「角色名全开」冲掉按动作勾选（例如有入库权限却无出库权限）。
         */
        if (!$hasAnyWarehousePerm) {
            foreach (self::typesFromRoleNames($userId) as $type) {
                $types[$type] = true;
            }
            if (self::userHasRoleName($userId, '圆钢、成品')) {
                $types[self::TYPE_ROUND] = true;
                $types[self::TYPE_PRODUCT] = true;
            }
        }

        return array_keys($types);
    }

    public static function isUnlimitedUser(?int $userId = null): bool
    {
        $userId = $userId ?: (int) user_id();
        if ($userId <= 0) {
            return false;
        }
        if (isset(self::$unlimitedMemo[$userId])) {
            return self::$unlimitedMemo[$userId];
        }
        if (user_is_super($userId) || $userId === 1) {
            return self::$unlimitedMemo[$userId] = true;
        }
        $user = (new UserModel())->find($userId);
        return self::$unlimitedMemo[$userId] = ($user && (int) ($user['is_super'] ?? 0) === 1);
    }

    public static function allows(string $action, string $type, ?int $userId = null): bool
    {
        if (self::isUnlimitedUser($userId)) {
            return true;
        }
        return in_array($type, self::allowedTypes($action, $userId), true);
    }

    /**
     * Tab/短关键字（圆钢|配件|成品 或 圆钢仓库 等）→ 标准类型；无法识别返回 null
     */
    public static function normalizeType(string $keyword): ?string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }
        if (preg_match('/配件/u', $keyword)) {
            return self::TYPE_ACCESSORY;
        }
        if (preg_match('/圆钢|原料/u', $keyword)) {
            return self::TYPE_ROUND;
        }
        if (preg_match('/成品/u', $keyword)) {
            return self::TYPE_PRODUCT;
        }
        return null;
    }

    /**
     * 将类型转为 ya_wms_in.warehouse / stock 可匹配的取值列表（与 InStockService 兼容）
     *
     * @param string[] $types
     * @return string[]
     */
    public static function warehouseValuesForTypes(array $types): array
    {
        $map = [
            self::TYPE_ACCESSORY => ['配件仓库', '配件库'],
            self::TYPE_ROUND => ['圆钢仓库', '圆钢库', '原料库', '原料仓库'],
            self::TYPE_PRODUCT => ['成品仓库', '成品库', '成品仓'],
        ];
        $out = [];
        foreach ($types as $t) {
            foreach ($map[$t] ?? [] as $v) {
                $out[$v] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * 列表过滤用：unlimited → null；有类型 → values；无权限 → ['__NO_WAREHOUSE_ACCESS__']
     *
     * @return string[]|null null 表示不限制
     */
    public static function warehouseFilterValues(string $action, ?int $userId = null): ?array
    {
        if (self::isUnlimitedUser($userId)) {
            return null;
        }
        $types = self::allowedTypes($action, $userId);
        if ($types === []) {
            return ['__NO_WAREHOUSE_ACCESS__'];
        }
        return self::warehouseValuesForTypes($types);
    }

    /**
     * @return string[]|null null 不限制 material_property；空权限返回不可能值
     */
    public static function materialPropertyFilter(string $action = self::ACTION_ARCHIVE, ?int $userId = null): ?array
    {
        if (self::isUnlimitedUser($userId)) {
            return null;
        }
        $types = self::allowedTypes($action, $userId);
        if ($types === []) {
            return ['__NO_MATERIAL_ACCESS__'];
        }
        return $types;
    }

    /**
     * 请求的仓库关键字是否在权限内；超管恒 true
     */
    public static function tabAllowed(string $action, string $tabKeyword, ?int $userId = null): bool
    {
        if (self::isUnlimitedUser($userId)) {
            return true;
        }
        $type = self::normalizeType($tabKeyword);
        if ($type === null) {
            return false;
        }
        return self::allows($action, $type, $userId);
    }

    /**
     * @return string[]
     */
    /**
     * @return int[]
     */
    private static function userRoleIds(int $userId): array
    {
        if (isset(self::$roleIdsMemo[$userId])) {
            return self::$roleIdsMemo[$userId];
        }
        $roleIds = (new UserAttributesModel())->where('user_id', $userId)->column('role_id');
        $roleIds = array_values(array_filter(array_map('intval', $roleIds)));

        return self::$roleIdsMemo[$userId] = $roleIds;
    }

    /**
     * @return int[]
     */
    private static function userMenuIds(int $userId): array
    {
        if (isset(self::$menuIdsMemo[$userId])) {
            return self::$menuIdsMemo[$userId];
        }
        $roleIds = self::userRoleIds($userId);
        if ($roleIds === []) {
            return self::$menuIdsMemo[$userId] = [];
        }
        $menuIds = RoleService::menu_ids($roleIds, where_disdel());
        $unauth = MenuService::unauthList('id');
        $menuIds = array_merge($menuIds ?: [], $unauth ?: []);

        return self::$menuIdsMemo[$userId] = array_values(array_unique(array_map('intval', $menuIds ?: [])));
    }

    private static function userMenuUrls(int $userId): array
    {
        if (isset(self::$menuUrlsMemo[$userId])) {
            return self::$menuUrlsMemo[$userId];
        }
        $menuIds = self::userMenuIds($userId);
        if ($menuIds === []) {
            return self::$menuUrlsMemo[$userId] = [];
        }
        $urls = (new MenuModel())
            ->where('menu_id', 'in', $menuIds)
            ->where(where_disdel())
            ->column('menu_url');

        return self::$menuUrlsMemo[$userId] = array_values(array_filter(array_map('strval', $urls)));
    }

    /**
     * @return string[]
     */
    private static function typesFromRoleNames(int $userId): array
    {
        $roleIds = self::userRoleIds($userId);
        if ($roleIds === []) {
            return [];
        }
        $names = (new RoleModel())->whereIn('role_id', $roleIds)->column('role_name');
        $types = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if (isset(self::ROLE_NAME_TO_TYPE[$name])) {
                $types[self::ROLE_NAME_TO_TYPE[$name]] = true;
            }
        }

        return array_keys($types);
    }

    private static function userHasRoleName(int $userId, string $roleName): bool
    {
        $roleIds = self::userRoleIds($userId);
        if ($roleIds === []) {
            return false;
        }
        $names = (new RoleModel())->whereIn('role_id', $roleIds)->column('role_name');
        foreach ($names as $name) {
            if ((string) $name === $roleName) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从已授权菜单的 path/component/name 识别三类档案页
     *
     * @return string[]
     */
    private static function typesFromArchiveMenus(int $userId): array
    {
        $menuIds = self::userMenuIds($userId);
        if ($menuIds === []) {
            return [];
        }
        $rows = (new MenuModel())
            ->where('menu_id', 'in', $menuIds)
            ->where(where_disdel())
            ->field('menu_name,path,name,component')
            ->select()
            ->toArray();
        $types = [];
        foreach ($rows as $row) {
            $blob = strtolower(
                (string) ($row['menu_name'] ?? '') . ' '
                . (string) ($row['path'] ?? '') . ' '
                . (string) ($row['name'] ?? '') . ' '
                . (string) ($row['component'] ?? '')
            );
            if (str_contains($blob, 'accessory') || str_contains($blob, '配件档案')) {
                $types[self::TYPE_ACCESSORY] = true;
            }
            if (str_contains($blob, 'roundsteel') || str_contains($blob, '圆钢档案')) {
                $types[self::TYPE_ROUND] = true;
            }
            if (str_contains($blob, 'productlist') || str_contains($blob, '成品档案')) {
                $types[self::TYPE_PRODUCT] = true;
            }
        }
        return array_keys($types);
    }
}
