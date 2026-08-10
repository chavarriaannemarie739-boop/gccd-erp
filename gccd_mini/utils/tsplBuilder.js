/**
 * TSPL 指令构建器
 * [AI-2026-06] 适配得力 DL-888TW，30mm×30mm 标签
 *
 * 203 DPI: 1mm ≈ 8dot
 * 30mm = 240dot
 */

/**
 * 构建流转卡标签的 TSPL 指令
 * @param {object} data - flowCardLabel API 返回的标签数据
 * @param {number} copies - 打印份数
 * @returns {ArrayBuffer}
 */
const buildFlowCardLabel = (data, copies = 1) => {
  // [AI-2026-06] 优先使用服务端预编译的 TSPL 指令（GBK 编码，排版一致）
  if (data && data.tspl_cmd) {
    let buffer = wx.base64ToArrayBuffer(data.tspl_cmd);
    if (copies > 1) {
      buffer = modifyTsplCopies(buffer, copies);
    }
    return buffer;
  }

  // --- 以下为客户端兜底生成（服务端不可用时） ---
  const planName = (data.production_plan_name || '').trim();
  // [AI-2026-08] 标准四字段优先，历史错误拼写仅用于旧响应读取兼容
  const roundSteel = getRoundSteelFields(data);
  const cSpec = (data.c_material_spec || '').trim();
  const cModel = (data.c_material_model || '').trim();
  const finished = (cSpec && cModel) ? `${cSpec}×${cModel}` : `${cSpec}${cModel}`;
  const num = data.plan_num || 0;
  const deviceName = data.device_name || '';
  const qrPayload = data.qr_payload || '';

  let line1 = planName;
  if (deviceName) {
    line1 = `${line1} ${deviceName}`.trim();
  }
  const [line2, line3] = buildRoundSteelLines(roundSteel, num);

  const cmds = [];

  cmds.push('SIZE 30 mm,30 mm');
  cmds.push('GAP 2 mm,0 mm');
  cmds.push('DIRECTION 1');
  cmds.push('CODEPAGE UTF-8');
  cmds.push('CLS');

  const leftX = 4;
  if (line1) {
    cmds.push(`TEXT ${leftX},4,"TSS16.BF2",0,1,1,"${esc(truncate(line1, 14))}"`);
  }
  cmds.push(`TEXT ${leftX},26,"TSS12.BF2",0,1,1,"${esc(line2)}"`);
  cmds.push(`TEXT ${leftX},44,"TSS12.BF2",0,1,1,"${esc(line3)}"`);
  if (finished) {
    appendProductLineTspl(cmds, finished);
  }

  // 二维码（保持原来大小位置不变）
  if (qrPayload) {
    cmds.push(`QRCODE 60,85,H,4,A,0,"${esc(qrPayload)}"`);
  }

  cmds.push(`PRINT ${copies}`);

  const cmd = cmds.join('\r\n') + '\r\n';
  return stringToBuffer(cmd);
};

/**
 * 修改 TSPL 预编译指令中的份数 (将 PRINT 1 替换为 PRINT N)
 * @param {ArrayBuffer} buffer - 原始指令 Buffer
 * @param {number} copies - 份数
 * @returns {ArrayBuffer}
 */
const modifyTsplCopies = (buffer, copies) => {
  if (copies <= 1) return buffer;
  const bytes = new Uint8Array(buffer);

  // 从后往前寻找 "PRINT " (字节: 80, 82, 73, 78, 84, 32)
  let printIdx = -1;
  for (let i = bytes.length - 7; i >= 0; i--) {
    if (
      bytes[i] === 80 && // P
      bytes[i + 1] === 82 && // R
      bytes[i + 2] === 73 && // I
      bytes[i + 3] === 78 && // N
      bytes[i + 4] === 84 && // T
      bytes[i + 5] === 32   // ' '
    ) {
      printIdx = i;
      break;
    }
  }

  if (printIdx === -1) {
    return buffer;
  }

  const copiesStr = String(copies);
  const newPrintStr = `PRINT ${copiesStr}\r\n`;
  const newPrintBytes = [];
  for (let i = 0; i < newPrintStr.length; i++) {
    newPrintBytes.push(newPrintStr.charCodeAt(i));
  }

  const newBuffer = new ArrayBuffer(printIdx + newPrintBytes.length);
  const newView = new Uint8Array(newBuffer);
  newView.set(bytes.subarray(0, printIdx));
  newView.set(newPrintBytes, printIdx);

  return newBuffer;
};

/**
 * 构建测试打印指令（用于验证打印机连接）
 * @returns {ArrayBuffer}
 */
const buildTestLabel = () => {
  const lines = [
    'SIZE 30 mm,30 mm',
    'GAP 2 mm,0 mm',
    'CLS',
    'TEXT 40,60,"3",0,1,1,"GCCD TEST OK"',
    'TEXT 40,100,"3",0,1,1,"30x30mm"',
    'PRINT 1',
  ];
  return stringToBuffer(lines.join('\r\n') + '\r\n');
};

/**
 * 第三行成品规格：单行 Y=56，品: + 规格 font"1" 一次打完
 */
const appendProductLineTspl = (cmds, finished) => {
  const lineY = 64;
  const bodyX = 28;
  cmds.push(`TEXT 4,${lineY},"TSS12.BF2",0,1,1,"${esc('品:')}"`);
  const body = finished.replace(/×/g, 'x');
  cmds.push(`TEXT ${bodyX},${lineY},"1",0,1,1,"${esc(body)}"`);
};

/**
 * 读取标准圆钢四字段，兼容历史 y_mateial_* 响应。
 */
const getRoundSteelFields = (data = {}) => {
  const legacyKey = (field) => ['y', 'mateial', field].join('_');
  return {
    name: String(data.y_material_name ?? data[legacyKey('name')] ?? '').trim(),
    manufacturer: String(
      data.y_material_manufacturer ?? data[legacyKey('manufacturer')] ?? ''
    ).trim(),
    spec: String(data.y_material_spec ?? data[legacyKey('spec')] ?? '').trim(),
    model: String(data.y_material_model ?? data[legacyKey('model')] ?? '').trim(),
  };
};

/**
 * [AI-2026-08] 名称 / 厂商 / 材质 / 型号统一展示文本。
 */
const formatRoundSteelText = (data = {}) => {
  const fields = getRoundSteelFields(data);
  const values = [fields.name, fields.manufacturer, fields.spec, fields.model].filter(Boolean);
  return values.length ? `圆:${values.join(' / ')}` : '';
};

/**
 * 生成与 30mm TSPL 实际排版一致的两行圆钢信息。
 */
const buildRoundSteelLines = (data = {}, num = 0) => {
  const fields = Object.prototype.hasOwnProperty.call(data, 'name')
    ? data
    : getRoundSteelFields(data);
  const first = fitTsplFieldPair(fields.name, fields.manufacturer, '圆:', '', 232, 12);
  const second = fitTsplFieldPair(fields.spec, fields.model, '', ` 数:${num}`, 232, 12);
  return [first, second];
};

/**
 * 按 TSPL 点阵宽度计算文本占用：中文/全角字符占字号宽，ASCII 占一半。
 */
const measureTsplTextWidth = (text, fontSize = 12) => {
  const asciiWidth = Math.ceil(fontSize / 2);
  return Array.from(String(text || '')).reduce(
    (width, char) => width + (char.codePointAt(0) < 128 ? asciiWidth : fontSize),
    0
  );
};

/**
 * 为同一行两个字段动态分配宽度，短字段可让出空间。
 */
const fitTsplFieldPair = (left, right, prefix, suffix, maxWidth, fontSize) => {
  const leftText = String(left || '');
  const rightText = String(right || '');
  const separator = leftText && rightText ? ' / ' : '';
  const fixedWidth = measureTsplTextWidth(`${prefix}${separator}${suffix}`, fontSize);
  const available = Math.max(0, maxWidth - fixedWidth);
  const leftWidth = measureTsplTextWidth(leftText, fontSize);
  const rightWidth = measureTsplTextWidth(rightText, fontSize);
  let leftBudget = leftWidth;
  let rightBudget = rightWidth;

  if (leftWidth + rightWidth > available) {
    if (!leftText) {
      rightBudget = available;
    } else if (!rightText) {
      leftBudget = available;
    } else {
      const half = Math.floor(available / 2);
      if (leftWidth <= half) {
        leftBudget = leftWidth;
        rightBudget = available - leftBudget;
      } else if (rightWidth <= half) {
        rightBudget = rightWidth;
        leftBudget = available - rightBudget;
      } else {
        leftBudget = half;
        rightBudget = available - half;
      }
    }
  }

  return `${prefix}${truncateTsplText(leftText, leftBudget, fontSize)}${separator}`
    + `${truncateTsplText(rightText, rightBudget, fontSize)}${suffix}`;
};

/**
 * 按点阵宽度截断，省略号计入预算。
 */
const truncateTsplText = (text, maxWidth, fontSize) => {
  if (measureTsplTextWidth(text, fontSize) <= maxWidth) return text;
  const ellipsis = '…';
  const ellipsisWidth = measureTsplTextWidth(ellipsis, fontSize);
  if (maxWidth < ellipsisWidth) return '';

  const targetWidth = maxWidth - ellipsisWidth;
  let result = '';
  let width = 0;
  for (const char of Array.from(text)) {
    const charWidth = measureTsplTextWidth(char, fontSize);
    if (width + charWidth > targetWidth) break;
    result += char;
    width += charWidth;
  }
  return `${result}${ellipsis}`;
};

/**
 * 截断字符串
 */
const truncate = (str, maxLen) => {
  if (!str) return '';
  if (str.length <= maxLen) return str;
  return str.substring(0, maxLen - 1) + '…';
};

/**
 * 转义 TSPL 字符串
 */
const esc = (str) => {
  if (!str) return '';
  return str.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
};

/**
 * 字符串转 ArrayBuffer (UTF-8)
 * [AI-2026-06] 兼容小程序所有平台的 UTF-8 编码器
 */
const stringToBuffer = (str) => {
  const bytes = [];
  for (let i = 0; i < str.length; i++) {
    let code = str.charCodeAt(i);
    if (code < 0x80) {
      bytes.push(code);
    } else if (code < 0x800) {
      bytes.push(0xc0 | (code >> 6), 0x80 | (code & 0x3f));
    } else if (code < 0xd800 || code >= 0xe000) {
      bytes.push(
        0xe0 | (code >> 12),
        0x80 | ((code >> 6) & 0x3f),
        0x80 | (code & 0x3f)
      );
    } else {
      // surrogate pair
      i++;
      code = 0x10000 + (((code & 0x3ff) << 10) | (str.charCodeAt(i) & 0x3ff));
      bytes.push(
        0xf0 | (code >> 18),
        0x80 | ((code >> 12) & 0x3f),
        0x80 | ((code >> 6) & 0x3f),
        0x80 | (code & 0x3f)
      );
    }
  }
  const buffer = new ArrayBuffer(bytes.length);
  const view = new Uint8Array(buffer);
  for (let i = 0; i < bytes.length; i++) {
    view[i] = bytes[i];
  }
  return buffer;
};

module.exports = {
  buildFlowCardLabel,
  buildTestLabel,
  buildRoundSteelLines,
  formatRoundSteelText,
  measureTsplTextWidth,
};
