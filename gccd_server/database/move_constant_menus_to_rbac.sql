-- [AI-2026-08] 报表中心 / 盘点检验 从前端静态路由迁到 system_menu + 角色授权
-- 可重复执行；配件库(9)/圆钢库(10)/圆钢成品(12)/销售(13)/后勤(11) 不自动授权

-- ========== 1. 盘点检验：补齐报表子菜单 + 统一名称 ==========
UPDATE ya_system_menu
SET menu_name = '盘点记录', always_show = 0, update_time = NOW()
WHERE menu_id = 863 AND is_delete = 0;

UPDATE ya_system_menu
SET menu_name = '质检记录', always_show = 0, update_time = NOW()
WHERE menu_id = 864 AND is_delete = 0;

UPDATE ya_system_menu
SET always_show = 1, meta_icon = 'finished', update_time = NOW()
WHERE menu_id = 862 AND is_delete = 0;

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT 862, 1, '盘点报表', 'admin/production.Inventory/autoCalc', 'test-product-stocktake-report',
       'TestProductStocktakeReport', 'mes/ProductStocktake/ProductStocktakeReport',
       'document', 0, 0, 0, 150, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM ya_system_menu
  WHERE is_delete = 0 AND path = 'test-product-stocktake-report' AND menu_pid = 862
);

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT 862, 1, '质检报表', 'admin/production.QualityCheckRecord/list', 'test-quality-inspection-report',
       'TestQualityInspectionReport', 'mes/QualityInspectionRecord/QualityInspectionReport',
       'document', 0, 0, 0, 50, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM ya_system_menu
  WHERE is_delete = 0 AND path = 'test-quality-inspection-report' AND menu_pid = 862
);

-- ========== 2. 报表中心目录 + 子菜单 ==========
INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT 0, 0, '报表中心', '', '/report', 'ReportCenter', '',
       'document', 0, 0, 1, 9996, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM ya_system_menu WHERE is_delete = 0 AND path = '/report'
);

SET @reportPid := (
  SELECT menu_id FROM ya_system_menu WHERE is_delete = 0 AND path = '/report' LIMIT 1
);

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT @reportPid, 1, '生产统计日报', 'admin/wechat.WeChat/getProcessDailyReport', 'daily-production',
       'DailyProductionReport', 'report/DailyProductionReport',
       'menu', 0, 0, 0, 10, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE @reportPid IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ya_system_menu WHERE is_delete = 0 AND path = 'daily-production' AND menu_pid = @reportPid
  );

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT @reportPid, 1, '工序每日生产报表', 'admin/wechat.WeChat/getProcessDailyReport', 'process-daily-production',
       'ProcessDailyProductionReport', 'report/ProcessDailyProductionReport',
       'document', 0, 0, 0, 20, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE @reportPid IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ya_system_menu WHERE is_delete = 0 AND path = 'process-daily-production' AND menu_pid = @reportPid
  );

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT @reportPid, 1, '工序成本日报表', 'admin/production.Production/getProcessCostDailyReport', 'process-cost-daily',
       'ProcessCostDailyReport', 'report/ProcessCostDailyReport',
       'document', 0, 0, 0, 30, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE @reportPid IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ya_system_menu WHERE is_delete = 0 AND path = 'process-cost-daily' AND menu_pid = @reportPid
  );

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT @reportPid, 1, '系数配置', 'admin/ReportCoefficient/list', 'coefficient',
       'CoefficientManage', 'report/CoefficientManage',
       'setting', 0, 0, 0, 40, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE @reportPid IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ya_system_menu WHERE is_delete = 0 AND path = 'coefficient' AND menu_pid = @reportPid
  );

INSERT INTO ya_system_menu (
  menu_pid, menu_type, menu_name, menu_url, path, name, component,
  meta_icon, hidden, keep_alive, always_show, sort,
  is_unlogin, is_unauth, is_unrate, is_disable, is_delete, create_time
)
SELECT @reportPid, 1, '批次物料追溯', 'admin/production.Material/list', 'batch-material-trace',
       'BatchMaterialTrace', 'report/BatchMaterialTrace',
       'connection', 0, 0, 0, 50, 0, 0, 0, 0, 0, NOW()
FROM DUAL
WHERE @reportPid IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ya_system_menu WHERE is_delete = 0 AND path = 'batch-material-trace' AND menu_pid = @reportPid
  );

-- ========== 3. 关掉误标免权的生产计划列表 ==========
UPDATE ya_system_menu
SET is_unauth = 0, is_unlogin = 0, update_time = NOW()
WHERE menu_id = 808 AND is_delete = 0;

-- ========== 4. 角色授权：沿用原「盘点检验」已授权角色，不含仓管专岗 ==========
-- 盘点检验完整子树 → 角色 1/2/3/5/6
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id)
SELECT r.role_id, m.menu_id
FROM ya_system_role r
JOIN ya_system_menu m ON m.is_delete = 0
  AND (
    m.menu_id IN (862, 863, 864)
    OR (m.menu_pid = 862 AND m.path IN ('test-product-stocktake-report', 'test-quality-inspection-report'))
  )
WHERE r.is_delete = 0 AND r.role_id IN (1, 2, 3, 5, 6);

-- 报表中心完整子树 → 角色 1/2/5/6（统计/管理向；一线工人不默认开）
INSERT IGNORE INTO ya_system_role_menus (role_id, menu_id)
SELECT r.role_id, m.menu_id
FROM ya_system_role r
JOIN ya_system_menu m ON m.is_delete = 0
  AND (m.path = '/report' OR m.menu_pid = @reportPid)
WHERE r.is_delete = 0 AND r.role_id IN (1, 2, 5, 6);
