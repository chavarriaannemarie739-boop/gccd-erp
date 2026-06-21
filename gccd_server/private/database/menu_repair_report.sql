-- =============================================================================
-- 设备管理下增加「维修报表」菜单（默认表前缀 ya_，请按库中 prefix 替换表名）
-- 执行后务必清菜单缓存：后台「菜单管理」保存一次，或执行 MenuService::clearCachesAfterMenuDataChange()
-- =============================================================================

-- 0）先看你库里设备相关菜单长什么样（复制到客户端执行，核对 path / component）
-- SELECT menu_id, menu_pid, menu_type, menu_name, menu_url, `path`, `name`, component
-- FROM ya_system_menu
-- WHERE is_delete = 0 AND (menu_name LIKE '%设备%' OR menu_name LIKE '%维修%' OR `path` LIKE '%Equip%' OR component LIKE '%Equipment%');

-- =============================================================================
-- 1）解析父级 menu_id（先清空会话变量，避免上次执行的值残留）
-- =============================================================================
SET @equipment_parent = NULL;

-- 1a 最稳：已有「维修记录」页时，取其父菜单作为设备分组父级
SELECT p.menu_id INTO @equipment_parent
FROM ya_system_menu AS c
INNER JOIN ya_system_menu AS p ON p.menu_id = c.menu_pid AND p.is_delete = 0
WHERE c.is_delete = 0
  AND (
    c.component LIKE '%MaintenanceRecord%'
    OR c.component LIKE '%/EquipmentLedger/MaintenanceRecord%'
  )
ORDER BY c.menu_id DESC
LIMIT 1;

-- 1b 若上一步无命中：用 path / name / 菜单名匹配常见「设备管理」目录
SELECT IFNULL(
  @equipment_parent,
  (
    SELECT menu_id
    FROM ya_system_menu
    WHERE is_delete = 0
      AND (
        `path` IN ('EquipmentLedger', '/EquipmentLedger')
        OR `name` IN ('EquipmentLedger', '/EquipmentLedger')
        OR menu_name IN ('设备管理', '设备台账')
      )
    ORDER BY menu_type ASC, menu_id ASC
    LIMIT 1
  )
) INTO @equipment_parent;

-- 1c 若仍为 NULL：请手工指定父菜单 id（把 0 改成你在 0）里查到的父级 menu_id）
-- SET @equipment_parent = 0;

-- 2）检查变量（应为非 NULL 正整数，否则不要继续）
-- SELECT @equipment_parent AS equipment_parent_menu_id;

-- 3）插入菜单（父级下尚无同一组件时）
INSERT INTO `ya_system_menu` (
  `menu_pid`,
  `menu_type`,
  `menu_name`,
  `menu_url`,
  `path`,
  `name`,
  `component`,
  `meta_icon`,
  `meta_query`,
  `hidden`,
  `keep_alive`,
  `always_show`,
  `sort`,
  `is_unlogin`,
  `is_unauth`,
  `is_unrate`,
  `is_disable`,
  `is_delete`,
  `create_uid`,
  `update_uid`,
  `delete_uid`,
  `create_time`,
  `update_time`,
  `delete_time`
)
SELECT
  @equipment_parent,
  1,
  '维修报表',
  'admin/repair.Device/repairlist',
  'repair-maintenance-report',
  'RepairMaintenanceReport',
  'mes/EquipmentLedger/RepairReport',
  'document',
  '',
  0,
  1,
  0,
  245,
  0,
  0,
  0,
  0,
  0,
  0,
  0,
  0,
  NOW(),
  NULL,
  NULL
WHERE @equipment_parent IS NOT NULL
  AND @equipment_parent > 0
  AND NOT EXISTS (
    SELECT 1
    FROM `ya_system_menu` AS x
    WHERE x.is_delete = 0
      AND x.menu_pid = @equipment_parent
      AND x.component = 'mes/EquipmentLedger/RepairReport'
  );

-- 4）授权：给「所有已拥有父级菜单」的角色挂上本页（比只写 role_id=1 更符合实际）
INSERT INTO `ya_system_role_menus` (`role_id`, `menu_id`)
SELECT DISTINCT rscope.role_id, m.menu_id
FROM `ya_system_menu` AS m
INNER JOIN (
  SELECT DISTINCT role_id
  FROM `ya_system_role_menus`
  WHERE menu_id = @equipment_parent
) AS rscope ON 1 = 1
WHERE @equipment_parent IS NOT NULL
  AND @equipment_parent > 0
  AND m.is_delete = 0
  AND m.menu_pid = @equipment_parent
  AND m.`path` = 'repair-maintenance-report'
  AND m.component = 'mes/EquipmentLedger/RepairReport'
  AND NOT EXISTS (
    SELECT 1
    FROM `ya_system_role_menus` AS z
    WHERE z.role_id = rscope.role_id
      AND z.menu_id = m.menu_id
  );

-- =============================================================================
-- 5）若侧栏仍出现两条「维修报表」：库中可能有多条 component 相同的菜单，软删多余记录（先 SELECT 核对）
-- =============================================================================
-- SELECT menu_id, menu_pid, menu_name, `path`, component, sort
-- FROM ya_system_menu
-- WHERE is_delete = 0 AND component LIKE '%EquipmentLedger/RepairReport%';
--
-- 保留 menu_id 最小的一条，其余软删（表前缀、delete_update() 字段按你库实际调整）：
-- UPDATE ya_system_menu SET is_delete = 1, delete_time = NOW() WHERE is_delete = 0
--   AND component = 'mes/EquipmentLedger/RepairReport'
--   AND menu_id NOT IN (
--     SELECT t.keep_id FROM (
--       SELECT MIN(menu_id) AS keep_id FROM ya_system_menu
--       WHERE is_delete = 0 AND component = 'mes/EquipmentLedger/RepairReport'
--     ) AS t
--   );
