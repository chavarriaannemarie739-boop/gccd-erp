-- [AI-2026-08] 首页驾驶舱改为角色菜单授权（配件库等仓管专岗默认不授权）

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT 0, 0, '首页', 'admin/system.Index/index', '/dashboard', 'DashboardRoot', '',
       'home-filled', 0, 0, 0, 0, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM ya_system_menu WHERE is_delete = 0 AND path = '/dashboard' AND menu_pid = 0
);

SET @dashPid := (
  SELECT menu_id FROM ya_system_menu WHERE is_delete = 0 AND path = '/dashboard' AND menu_pid = 0 LIMIT 1
);

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT @dashPid, 1, '首页', 'admin/system.Index/index', 'index', 'Dashboard', 'system/index',
       'home-filled', 0, 1, 0, 10, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE @dashPid IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ya_system_menu
    WHERE is_delete = 0 AND menu_pid = @dashPid AND name = 'Dashboard'
  );

-- 授权给非仓管专岗角色；配件库(9)/圆钢库(10)/后勤(11)/圆钢成品(12)/销售(13) 默认不给
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id)
SELECT r.role_id, m.menu_id
FROM ya_system_role r
JOIN ya_system_menu m ON m.is_delete = 0
  AND (m.menu_id = @dashPid OR m.menu_pid = @dashPid)
WHERE r.is_delete = 0 AND r.role_id IN (1, 2, 3, 4, 5, 6, 7, 8);
