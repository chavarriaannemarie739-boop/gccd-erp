// [AI-2026-08] 微信小程序圆钢四字段静态契约。
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const sourceRoot = new URL('../', import.meta.url);
const readSource = async (file) => readFile(new URL(file, sourceRoot), 'utf8');

const decodeUtf8Buffer = (buffer) => new TextDecoder().decode(new Uint8Array(buffer));

const loadMiniPage = async (file, request) => {
  const source = (await readSource(file)).replace(/^import\s+Toast[^\n]+\n/m, '');
  let pageConfig = null;
  vm.runInNewContext(source, {
    Page: (config) => {
      pageConfig = config;
    },
    require: () => ({ request }),
    wx: {
      getStorageSync: () => '',
    },
    console,
    Map,
    Number,
    Promise,
  });
  return pageConfig;
};

const businessPages = [
  'pages/workOrder/workOrder',
  'pageWorkOrder/pages/processTaskList/processTaskList',
  'pageWorkOrder/pages/workOrderAdd/workOrderAdd',
  'pageWorkOrder/pages/workOrderInfo/workOrderInfo',
  'pageWorkOrder/pages/stocktakeAdd/stocktakeAdd',
  'pageWorkOrder/pages/stocktakeList/stocktakeList',
  'pageWorkOrder/pages/duangangList/duangangList',
  'pageWorkOrder/pages/duangangAdd/duangangAdd',
  'pageMine/pages/workRecord/workRecord',
  'pages/workbench/workbench',
];

const directPages = [
  'pages/material/material',
  'pageMaterial/pages/receiveMaterial/receiveMaterial',
];

const assertFields = (source, fields, file) => {
  fields.forEach((field) => {
    assert.match(source, new RegExp(`\\b${field}\\b`), `${file} 缺少 ${field}`);
  });
};

const assertOrder = (source, fields, file) => {
  let cursor = -1;
  fields.forEach((field) => {
    const next = source.indexOf(field, cursor + 1);
    assert.ok(next > cursor, `${file} 未按 ${fields.join(' → ')} 排列`);
    cursor = next;
  });
};

test('圆钢页面具备名称、厂商、材质、型号四字段并标记改动边界', async () => {
  for (const page of directPages) {
    const source = `${await readSource(`${page}.js`)}\n${await readSource(`${page}.wxml`)}`;
    assertFields(source, ['material_name', 'manufacturer', 'material_spec', 'material_model'], page);
    assert.match(source, /\[AI-2026-08\]/, `${page} 缺少改动边界注释`);
  }
  for (const page of businessPages) {
    const source = `${await readSource(`${page}.js`)}\n${await readSource(`${page}.wxml`)}`;
    assertFields(
      source,
      ['y_material_name', 'y_material_manufacturer', 'y_material_spec', 'y_material_model'],
      page,
    );
    assert.match(source, /\[AI-2026-08\]/, `${page} 缺少改动边界注释`);
  }
});

test('圆钢展示与选择按名称、厂商、材质、型号排序', async () => {
  const orderedFiles = [
    'pages/material/material.wxml',
    'pageMaterial/pages/receiveMaterial/receiveMaterial.wxml',
    'pages/workOrder/workOrder.wxml',
    'pageWorkOrder/pages/processTaskList/processTaskList.wxml',
    'pageWorkOrder/pages/workOrderAdd/workOrderAdd.wxml',
    'pageWorkOrder/pages/workOrderInfo/workOrderInfo.wxml',
    'pageWorkOrder/pages/stocktakeAdd/stocktakeAdd.wxml',
    'pageWorkOrder/pages/stocktakeList/stocktakeList.wxml',
    'pageWorkOrder/pages/duangangList/duangangList.wxml',
    'pageWorkOrder/pages/duangangAdd/duangangAdd.wxml',
    'pageMine/pages/workRecord/workRecord.wxml',
    'pages/workbench/workbench.wxml',
  ];
  for (const file of orderedFiles) {
    const source = await readSource(file);
    assertOrder(
      source,
      ['material_name', 'manufacturer', 'material_spec', 'material_model'],
      file,
    );
  }
});

test('圆钢筛选请求携带四字段', async () => {
  const directRequestFiles = [
    'pages/material/material.js',
    'pageMaterial/pages/receiveMaterial/receiveMaterial.js',
  ];
  for (const file of directRequestFiles) {
    assertFields(
      await readSource(file),
      ['material_name', 'manufacturer', 'material_spec', 'material_model'],
      file,
    );
  }

  const businessRequestFiles = [
    'pages/workOrder/workOrder.js',
    'pageWorkOrder/pages/processTaskList/processTaskList.js',
    'pageWorkOrder/pages/duangangList/duangangList.js',
    'pageMine/pages/workRecord/workRecord.js',
  ];
  for (const file of businessRequestFiles) {
    assertFields(
      await readSource(file),
      ['y_material_name', 'y_material_manufacturer', 'y_material_spec', 'y_material_model'],
      file,
    );
  }
});

test('盘点匹配去重优先 material_id，回退键包含厂商', async () => {
  const addSource = await readSource('pageWorkOrder/pages/stocktakeAdd/stocktakeAdd.js');
  const listSource = await readSource('pageWorkOrder/pages/stocktakeList/stocktakeList.js');
  assert.match(addSource, /materialId\s*>\s*0/);
  assert.match(addSource, /y_material_manufacturer/);
  assert.match(addSource, /material_id[^]*?y_material_manufacturer[^]*?y_material_spec[^]*?y_material_model/);
  assert.match(listSource, /material_id[^]*?manufacturer[^]*?material_spec[^]*?material_model/);
});

test('圆钢材质标签正确且工单详情标签绑定不再错位', async () => {
  for (const page of [...directPages, ...businessPages]) {
    const source = await readSource(`${page}.wxml`);
    assert.doesNotMatch(source, /圆钢规格/, `${page} 仍含“圆钢规格”`);
  }

  for (const file of [
    'pageWorkOrder/pages/workOrderAdd/workOrderAdd.wxml',
    'pageWorkOrder/pages/workOrderInfo/workOrderInfo.wxml',
  ]) {
    const source = await readSource(file);
    assert.match(source, /label="圆钢名称"[^>]+y_material_name/);
    assert.match(source, /label="圆钢厂商"[^>]+y_material_manufacturer/);
    assert.match(source, /label="圆钢材质"[^>]+y_material_spec/);
    assert.match(source, /label="圆钢型号"[^>]+y_material_model/);
  }
});

test('报工记录翻页仅合并新页后拼接已有列表', async () => {
  const request = async () => ({
    data: {
      code: 200,
      data: {
        list: [{
          id: 3,
          is_daily_work: 1,
          create_time: '2026-08-07 08:00:00',
          create_uid: 9,
          daily_work_user_name: '张三',
        }],
        pages: 3,
      },
    },
  });
  const page = await loadMiniPage('pageMine/pages/workRecord/workRecord.js', request);
  const existing = {
    id: 1,
    is_daily_work: 1,
    create_time: '2026-08-07 08:00:00',
    create_uid: 9,
    daily_work_user_name: '张三、李四',
    product_user: '张三、李四',
    _dailyUserNames: ['张三', '李四'],
  };
  const context = {
    data: {
      ...page.data,
      page: 2,
      limit: 10,
      list: [existing],
    },
    setData(next) {
      Object.assign(this.data, next);
    },
  };

  await page.getReportList.call(context);

  assert.equal(context.data.list.length, 2);
  assert.equal(context.data.list[0].daily_work_user_name, '张三、李四');
  assert.deepEqual(context.data.list[0]._dailyUserNames, ['张三', '李四']);
});

test('断钢列表材质优先展示业务字段并兼容旧展示字段', async () => {
  const source = await readSource('pageWorkOrder/pages/duangangList/duangangList.wxml');
  assert.match(
    source,
    /\{\{item\.y_material_spec \|\| item\.display_y_material_spec \|\| '—'\}\}/,
  );
});

test('流转卡客户端兜底 TSPL 按四字段统一输出且保留打印能力', async () => {
  const source = await readSource('utils/tsplBuilder.js');
  let exported = null;
  vm.runInNewContext(source, {
    module: {
      set exports(value) {
        exported = value;
      },
      get exports() {
        return exported;
      },
    },
    exports: {},
    wx: {
      base64ToArrayBuffer: () => new ArrayBuffer(0),
    },
    ArrayBuffer,
    Uint8Array,
  });

  const buffer = exported.buildFlowCardLabel({
    production_plan_name: '计划A',
    y_material_name: '合结圆钢',
    y_material_manufacturer: '宝钢',
    y_material_spec: '40Cr',
    y_material_model: 'Φ50',
    c_material_spec: 'M20',
    c_material_model: '100',
    plan_num: 3,
    qr_payload: 'GCCD:FLOW:P1:1:-',
  }, 3);
  const command = decodeUtf8Buffer(buffer);

  assert.equal(exported.formatRoundSteelText({
    y_material_name: '合结圆钢',
    y_material_manufacturer: '宝钢',
    y_material_spec: '40Cr',
    y_material_model: 'Φ50',
  }), '圆:合结圆钢 / 宝钢 / 40Cr / Φ50');
  assert.match(command, /圆:合结圆钢 \/ 宝钢/);
  assert.match(command, /40Cr \/ Φ50 数:3/);
  assert.match(command, /CODEPAGE UTF-8/);
  assert.match(command, /SIZE 30 mm,30 mm/);
  assert.match(command, /QRCODE 60,85/);
  assert.match(command, /PRINT 3/);
});

test('流转卡客户端长圆钢字段按 240 点标签宽度安全分配', async () => {
  const source = await readSource('utils/tsplBuilder.js');
  let exported = null;
  vm.runInNewContext(source, {
    module: {
      set exports(value) {
        exported = value;
      },
      get exports() {
        return exported;
      },
    },
    exports: {},
    wx: {
      base64ToArrayBuffer: () => new ArrayBuffer(0),
    },
    ArrayBuffer,
    Uint8Array,
  });

  const lines = exported.buildRoundSteelLines({
    y_material_name: 'A'.repeat(30),
    y_material_manufacturer: 'B'.repeat(30),
    y_material_spec: 'C'.repeat(30),
    y_material_model: 'D'.repeat(30),
  }, 7);

  assert.deepEqual(Array.from(lines), [
    `圆:${'A'.repeat(14)}… / ${'B'.repeat(14)}…`,
    `${'C'.repeat(13)}… / ${'D'.repeat(13)}… 数:7`,
  ]);
  lines.forEach((line) => {
    assert.ok(exported.measureTsplTextWidth(line, 12) <= 232);
  });

  const chineseLines = exported.buildRoundSteelLines({
    y_material_name: '超长圆钢名称'.repeat(8),
    y_material_manufacturer: '超长生产厂商'.repeat(8),
    y_material_spec: '超长材质'.repeat(8),
    y_material_model: '超长型号'.repeat(8),
  }, 123456);
  chineseLines.forEach((line) => {
    assert.match(line, /…/);
    assert.ok(exported.measureTsplTextWidth(line, 12) <= 232);
  });
});
