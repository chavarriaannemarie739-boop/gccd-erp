/**
 * 安装依赖后自动把 Vant 里写死的品牌蓝 #1989fa 换成项目主色 #0d9488。
 * 与 app.wxss 中 page 的 CSS 变量一起构成完整主题；重装 node_modules 后由 postinstall 再次执行即可。
 */
const fs = require('fs');
const path = require('path');

const PRIMARY = '#0d9488';
const LEGACY_BLUE = '#1989fa';

const roots = [
  path.join(__dirname, '..', 'node_modules', '@vant', 'weapp'),
  path.join(__dirname, '..', 'miniprogram_npm', '@vant', 'weapp'),
];

/** @type {{ rel: string, apply: (s: string) => string }[]} */
const jobs = [
  {
    rel: path.join('lib', 'common', 'color.js'),
    apply: (s) => s.replace(/exports\.BLUE = '#1989fa'/g, `exports.BLUE = '${PRIMARY}'`),
  },
  {
    rel: path.join('dist', 'common', 'color.js'),
    apply: (s) => s.replace(/export const BLUE = '#1989fa'/g, `export const BLUE = '${PRIMARY}'`),
  },
  {
    rel: path.join('lib', 'common', 'color.d.ts'),
    apply: (s) => s.replace(/BLUE = "#1989fa"/g, `BLUE = "${PRIMARY}"`),
  },
  {
    rel: path.join('dist', 'common', 'color.d.ts'),
    apply: (s) => s.replace(/BLUE = "#1989fa"/g, `BLUE = "${PRIMARY}"`),
  },
  {
    rel: path.join('lib', 'cascader', 'index.js'),
    apply: (s) => s.replace(/value: '#1989fa'/g, `value: '${PRIMARY}'`),
  },
  {
    rel: path.join('dist', 'cascader', 'index.js'),
    apply: (s) => s.replace(/value: '#1989fa'/g, `value: '${PRIMARY}'`),
  },
  {
    rel: path.join('lib', 'cascader', 'index.wxss'),
    apply: (s) =>
      s.replace(
        /\.van-cascader__option--selected\{color:#1989fa/g,
        `.van-cascader__option--selected{color:${PRIMARY}`,
      ),
  },
  {
    rel: path.join('dist', 'cascader', 'index.wxss'),
    apply: (s) =>
      s.replace(
        /\.van-cascader__option--selected\{color:#1989fa/g,
        `.van-cascader__option--selected{color:${PRIMARY}`,
      ),
  },
  {
    rel: path.join('lib', 'picker', 'index.wxml'),
    apply: (s) => s.replace(/color="#1989fa"/g, `color="${PRIMARY}"`),
  },
  {
    rel: path.join('dist', 'picker', 'index.wxml'),
    apply: (s) => s.replace(/color="#1989fa"/g, `color="${PRIMARY}"`),
  },
  {
    rel: path.join('lib', 'switch', 'index.wxs'),
    apply: (s) => s.replace(/var BLUE = '#1989fa'/g, `var BLUE = '${PRIMARY}'`),
  },
  {
    rel: path.join('dist', 'switch', 'index.wxs'),
    apply: (s) => s.replace(/var BLUE = '#1989fa'/g, `var BLUE = '${PRIMARY}'`),
  },
];

const patchRoot = (root) => {
  if (!fs.existsSync(root)) return;
  let touched = 0;
  for (const { rel, apply } of jobs) {
    const full = path.join(root, rel);
    if (!fs.existsSync(full)) continue;
    const before = fs.readFileSync(full, 'utf8');
    const after = apply(before);
    if (after !== before) {
      fs.writeFileSync(full, after, 'utf8');
      touched += 1;
    }
  }
  if (touched) {
    console.log(`[apply-vant-theme] 已更新 ${touched} 个文件: ${root}`);
  }
};

roots.forEach(patchRoot);
