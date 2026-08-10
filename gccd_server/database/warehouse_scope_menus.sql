-- [AI-2026-08] 仓库权限细化：按钮级权限码 + 角色默认勾选
-- 可重复执行（按 menu_url 去重）

-- ========== 1. 按钮菜单 ==========
-- 库存 832
INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 832, 2, '库存-圆钢', 'warehouse.inventory.roundSteel', '', '', '', 0, 10, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.inventory.roundSteel' AND is_delete = 0);

INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 832, 2, '库存-配件', 'warehouse.inventory.accessory', '', '', '', 0, 20, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.inventory.accessory' AND is_delete = 0);

INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 832, 2, '库存-成品', 'warehouse.inventory.product', '', '', '', 0, 30, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.inventory.product' AND is_delete = 0);

-- 入库 777
INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 777, 2, '入库-圆钢', 'warehouse.inbound.roundSteel', '', '', '', 0, 10, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.inbound.roundSteel' AND is_delete = 0);

INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 777, 2, '入库-配件', 'warehouse.inbound.accessory', '', '', '', 0, 20, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.inbound.accessory' AND is_delete = 0);

INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 777, 2, '入库-成品', 'warehouse.inbound.product', '', '', '', 0, 30, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.inbound.product' AND is_delete = 0);

-- 出库 803
INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 803, 2, '出库-圆钢', 'warehouse.outbound.roundSteel', '', '', '', 0, 10, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.outbound.roundSteel' AND is_delete = 0);

INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 803, 2, '出库-配件', 'warehouse.outbound.accessory', '', '', '', 0, 20, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.outbound.accessory' AND is_delete = 0);

INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 803, 2, '出库-成品', 'warehouse.outbound.product', '', '', '', 0, 30, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.outbound.product' AND is_delete = 0);

-- 档案（按钮，挂在各档案页下；同时更新页面 menu_url 便于勾选识别）
INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 865, 2, '档案权限-圆钢', 'warehouse.archive.roundSteel', '', '', '', 0, 10, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.archive.roundSteel' AND is_delete = 0);

INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 860, 2, '档案权限-配件', 'warehouse.archive.accessory', '', '', '', 0, 10, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.archive.accessory' AND is_delete = 0);

INSERT INTO ya_system_menu (menu_pid, menu_type, menu_name, menu_url, path, name, component, hidden, sort, is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time)
SELECT 866, 2, '档案权限-成品', 'warehouse.archive.product', '', '', '', 0, 10, 0, 0, 0, 0, 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ya_system_menu WHERE menu_url = 'warehouse.archive.product' AND is_delete = 0);

-- ========== 2. 补齐角色基础菜单 ==========
-- 圆钢库(10)：补仓库管理顶层、圆钢档案
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id) VALUES (10, 775), (10, 861), (10, 865), (10, 778), (10, 779), (10, 780), (10, 847);

-- 圆钢、成品(12)：补圆钢档案、出库相关按需（先给出库列表权限码，出库按钮另勾）
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id) VALUES (12, 865);

-- ========== 3. 角色默认勾选仓库权限按钮 ==========
-- 配件库(9)
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id)
SELECT 9, menu_id FROM ya_system_menu
WHERE is_delete = 0 AND menu_url IN (
  'warehouse.archive.accessory',
  'warehouse.inventory.accessory',
  'warehouse.inbound.accessory',
  'warehouse.outbound.accessory'
);

-- 圆钢库(10)：档案+库存+入库（默认不含出库，可后台再勾）
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id)
SELECT 10, menu_id FROM ya_system_menu
WHERE is_delete = 0 AND menu_url IN (
  'warehouse.archive.roundSteel',
  'warehouse.inventory.roundSteel',
  'warehouse.inbound.roundSteel'
);

-- 圆钢、成品(12)：圆钢入库/库存/档案 + 成品档案/库存/入库 + 成品发货已有菜单
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id)
SELECT 12, menu_id FROM ya_system_menu
WHERE is_delete = 0 AND menu_url IN (
  'warehouse.archive.roundSteel',
  'warehouse.inventory.roundSteel',
  'warehouse.inbound.roundSteel',
  'warehouse.archive.product',
  'warehouse.inventory.product',
  'warehouse.inbound.product'
);

-- 管理员(1)：全部仓库权限按钮
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id)
SELECT 1, menu_id FROM ya_system_menu
WHERE is_delete = 0 AND menu_url LIKE 'warehouse.%';
