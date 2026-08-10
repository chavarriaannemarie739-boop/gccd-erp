#!/usr/bin/env node
/**
 * 检索契约台账生成器
 * 用法:
 *   node scripts/audit-search-registry.mjs           # 生成 docs/SEARCH_REGISTRY.json + REPORT
 *   node scripts/audit-search-registry.mjs --check   # 存在 ui_unbound 则 exit 1
 */
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(__dirname, '..')
const VIEWS = path.join(ROOT, 'yarn/yarn/src/views')
const API_DIR = path.join(ROOT, 'yarn/yarn/src/api')
const MINI_ROOT = path.join(ROOT, 'gccd_mini')
const SERVER_CTRL = path.join(ROOT, 'gccd_server/app/admin/controller')
const SERVER_SVC = path.join(ROOT, 'gccd_server/app/common/service')
const OUT_JSON = path.join(ROOT, 'docs/SEARCH_REGISTRY.json')
const OUT_MD = path.join(ROOT, 'docs/SEARCH_REGISTRY_REPORT.md')

const CHECK_MODE = process.argv.includes('--check')

const walk = (dir, ext, out = []) => {
  if (!fs.existsSync(dir)) return out
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name)
    const st = fs.statSync(p)
    if (st.isDirectory()) walk(p, ext, out)
    else if (p.endsWith(ext)) out.push(p)
  }
  return out
}

export const parseVueFilters = (content, filePath) => {
  const hasFilterForm =
    /filter-form|placeholder=/.test(content) &&
    (/<el-form|<el-input/.test(content) || content.includes('placeholder'))
  if (!hasFilterForm && !filePath.includes('report/')) return null

  const filterSection =
    content.match(/class="filter-card[^"]*"[\s\S]*?<\/el-card>/)?.[0] ||
    content.match(/class="filter-form[^"]*"[\s\S]*?<\/el-form>/)?.[0] ||
    content.split('<div class="contenter">')[0] ||
    content

  const uiFields = []
  const inputRe = /<el-input[^>]*>/g
  let m
  while ((m = inputRe.exec(filterSection)) !== null) {
    const tag = m[0]
    const ph = tag.match(/placeholder="([^"]*)"/)?.[1] || ''
    const vm = tag.match(/v-model="([^"]*)"/)?.[1] || ''
    if (ph || vm) uiFields.push({ placeholder: ph, vModel: vm, bound: !!vm })
  }
  const dateRe = /<el-date-picker[^>]*v-model="([^"]*)"[^>]*>/g
  while ((m = dateRe.exec(filterSection)) !== null) {
    uiFields.push({ placeholder: 'dateRange', vModel: m[1], bound: true, type: 'date' })
  }
  const radioRe = /<el-radio-group[^>]*v-model="([^"]*)"/
  const rm = filterSection.match(radioRe)
  if (rm) uiFields.push({ placeholder: 'radio', vModel: rm[1], bound: true, type: 'radio' })

  // [AI-2026-08] 列表函数必须从同一条 API import 提取，避免误取 ElMessage 等命名导入。
  const apiImportMatches = [
    ...content.matchAll(
      /import\s*\{([^}]+)\}\s*from\s+['"]@\/api\/([^'"]+)['"]/g
    )
  ]
  const apiImportMatch = apiImportMatches.find((match) =>
    match[1].split(',').some((name) => name.trim().split(/\s+as\s+/)[0] === 'list')
  )
    || apiImportMatches[0]
  const apiImport = apiImportMatch?.[2] || ''
  const listFn = apiImportMatch?.[1] || ''
  const queryBlock = content.match(/query\s*:\s*\{([^}]+)\}/s)?.[1] || ''
  const queryKeys = [...queryBlock.matchAll(/(\w+)\s*:/g)].map((x) => x[1])

  const hasSearchBtn = /@click="handleSearch"|@click="onSearch"|@click="getList"/.test(content)
  const unbound = uiFields.filter((f) => f.placeholder && !f.bound)

  const boundParams = [
    ...new Set(
      uiFields
        .filter((f) => f.bound && f.vModel)
        .map((f) => f.vModel.split('.').pop())
        .filter(Boolean)
    )
  ]
  const dateStartKeys = [
    ...content.matchAll(/this\.query\.(\w+)\s*=\s*val(?:\?\.)?\[0\](?!\s*\+)/g)
  ].map((x) => x[1])
  const dateEndKeys = [
    ...content.matchAll(/this\.query\.(\w+)\s*=\s*val(?:\?\.)?\[1\](?!\s*\+)/g)
  ].map((x) => x[1])
  if (dateStartKeys.length) boundParams.push(...dateStartKeys, ...dateEndKeys)
  else if (uiFields.some((f) => f.type === 'date') && /create_start_date/.test(content)) {
    boundParams.push('create_start_date', 'create_end_date')
  }
  const uiOnlyDateKeys = new Set(['dateRange', 'order_times', 'periodRange'])
  const boundParamsFinal = boundParams.filter((k) => !uiOnlyDateKeys.has(k))

  let status = 'ok'
  if (unbound.length > 0) status = 'ui_unbound'
  else if (uiFields.length === 0 && hasFilterForm) status = 'ui_empty'

  return {
    page: path.relative(ROOT, filePath),
    platform: 'admin',
    uiFields,
    boundParams: [...new Set(boundParamsFinal)],
    queryKeys,
    api: apiImport,
    listImport: listFn.trim(),
    hasSearchBtn,
    status,
    issues: unbound.map((u) => `未绑定: ${u.placeholder}`)
  }
}

const parseMiniPage = (jsPath) => {
  if (jsPath.includes('echarts') || jsPath.includes('/components/ec-canvas/')) return null
  const content = fs.readFileSync(jsPath, 'utf8')
  const wxmlPath = jsPath.replace(/\.js$/, '.wxml')
  const wxml = fs.existsSync(wxmlPath) ? fs.readFileSync(wxmlPath, 'utf8') : ''
  const hasSearchUi = /placeholder=|bindinput|onSearch|searchValue|van-search|type="search"/.test(
    content + wxml
  )
  const hasSearchLogic = /applyFilter|filterList|onSearch|searchKeyword|keyword/.test(content)
  if (!hasSearchUi && !hasSearchLogic) return null

  const clientFilter = /applyFilter|filterList|\.filter\(/.test(content)
  const apiCalls = [
    ...content.matchAll(/util\.request\(\s*['"]([^'"]+)['"]/g),
    ...content.matchAll(/request\(\s*['"]([^'"]+)['"]/g)
  ]
    .map((x) => x[1])
    .filter((u) => u.includes('admin/'))

  const apiEndpoints = [...new Set(apiCalls)]
  let status = 'ok'
  if (clientFilter && apiEndpoints.length === 0) status = 'client_side_only'
  else if (apiEndpoints.length > 0) status = 'api_search'
  else if (clientFilter) status = 'client_side_only'
  else status = 'unknown'

  return {
    page: path.relative(ROOT, jsPath),
    platform: 'mini',
    clientSideOnly: status === 'client_side_only',
    apiEndpoints,
    status,
    issues: status === 'unknown' ? ['未识别检索方式，需人工确认'] : []
  }
}

const loadSearchFields = () => {
  const map = {}
  const files = walk(SERVER_SVC, '.php')
  for (const f of files) {
    const c = fs.readFileSync(f, 'utf8')
    const m = c.match(/\$(?:inventory_)?search_field\s*=\s*\[([\s\S]*?)\];/)
    if (!m) continue
    const keys = [...m[1].matchAll(/['"](\w+)['"]\s*=>/g)].map((x) => x[1])
    map[path.relative(ROOT, f)] = keys
  }
  return map
}

const loadControllerHandlers = () => {
  const map = {}
  const files = walk(SERVER_CTRL, '.php')
  for (const f of files) {
    const c = fs.readFileSync(f, 'utf8')
    const fnMatches = [...c.matchAll(/function\s+(\w+)\s*\(/g)].map((x) => x[1])
    const listFns = fnMatches.filter((fn) =>
      ['list', 'powerlist', 'repairlist', 'mateiallist', 'maintenanceReportList', 'getUserDayWage'].includes(fn)
    )
    const extractFnBlock = (src, fnName) => {
      const fnStart = src.search(new RegExp(`function\\s+${fnName}\\s*\\(`))
      if (fnStart < 0) return ''
      const braceStart = src.indexOf('{', fnStart)
      if (braceStart < 0) return ''
      let depth = 0
      for (let i = braceStart; i < src.length; i++) {
        if (src[i] === '{') depth++
        else if (src[i] === '}') {
          depth--
          if (depth === 0) return src.slice(fnStart, i + 1)
        }
      }
      return src.slice(fnStart, fnStart + 12000)
    }
    for (const listFn of listFns) {
      const block = extractFnBlock(c, listFn)
      if (!block) continue
      const paramRefs = [...block.matchAll(/\$param\[['"](\w+)['"]\]/g)].map((x) => x[1])
      // [AI-2026-08] 公共解析器已显式处理标准圆钢四字段，纳入 controller 分支审计。
      if (/MaterialSearchHelper::resolveRoundSteelFilters\(\$param\)/.test(block)) {
        paramRefs.push(
          'y_material_name',
          'y_material_manufacturer',
          'y_material_spec',
          'y_material_model'
        )
      }
      const serviceRef = block.match(/params\((\w+)::\$(search_field|detail_field)\)/)?.[1]
      const key = `${path.relative(ROOT, f)}::${listFn}`
      map[key] = { handledParams: [...new Set(paramRefs)], service: serviceRef }
    }
  }
  return map
}

const LIST_FN_URL = {
  powerlist: 'powerlist',
  maintenanceReportList: 'maintenanceReportList',
  mateiallist: 'mateiallist',
  repairlist: 'repairlist',
  getUserDayWage: 'getUserDayWage',
  autoCalc: 'autoCalc',
  monthlyLedger: 'monthlyLedger',
  traceMaterialFlow: 'traceMaterialFlow'
}

const apiToController = (apiPath, listImport = '') => {
  if (!apiPath) return null
  const jsFiles = walk(API_DIR, '.js')
  for (const f of jsFiles) {
    if (!f.includes(apiPath.replace(/\//g, path.sep))) continue
    const c = fs.readFileSync(f, 'utf8')
    const url = c.match(/const\s+url\s*=\s*['"]([^'"]+)['"]/)?.[1]
    if (!url) continue
    const parts = url.replace(/^\/admin\//, '').split('.')
    if (parts.length >= 2) {
      const module = parts[0]
      const cls = parts[1].replace(/\/$/, '')
      const ctrlPath = `gccd_server/app/admin/controller/${module}/${cls}.php`
      const fn = (listImport.match(/\b(\w+)\b/) || [])[1] || 'list'
      return { path: ctrlPath, listFn: LIST_FN_URL[fn] || fn }
    }
  }
  return null
}

const enrichEntries = (entries, searchFields, controllers) => {
  for (const e of entries) {
    if (e.platform !== 'admin') continue
    const ctrlInfo = apiToController(e.api, e.listImport)
    e.backend = ctrlInfo?.path || null
    e.listFn = ctrlInfo?.listFn || 'list'
    const ctrlKey = ctrlInfo ? `${ctrlInfo.path}::${ctrlInfo.listFn}` : null
    if (ctrlKey && controllers[ctrlKey]) {
      e.handledParams = controllers[ctrlKey].handledParams
      e.searchFieldService = controllers[ctrlKey].service
    }
    if (e.status === 'ui_unbound') continue
    const uiParams = (e.boundParams?.length ? e.boundParams : e.queryKeys).filter(
      (k) => !['page', 'limit', 'AdminToken', 'class', 'order_times'].includes(k)
    )
    const missing = uiParams.filter((k) => e.handledParams && !e.handledParams.includes(k))
    if (missing.length) {
      e.status = 'missing_backend'
      e.issues = [...(e.issues || []), ...missing.map((k) => `后端 list 未处理: ${k}`)]
    }
    const mismatch = uiParams.filter((k) => {
      if (!e.handledParams) return false
      return e.handledParams.includes(k) ? false : true
    })
    if (e.status === 'ok' && mismatch.length === uiParams.length && uiParams.length > 0) {
      e.status = 'field_mismatch'
    }
  }
  return entries
}

const main = () => {
  const vueFiles = walk(VIEWS, '.vue').filter((f) => {
    if (!f.includes('/mes/') && !f.includes('/report/')) return false
    if (f.includes('/components/')) return false
    if (f.includes('ProductStocktakeReport')) return true
    return true
  })
  const adminEntries = vueFiles.map((f) => parseVueFilters(fs.readFileSync(f, 'utf8'), f)).filter(Boolean)

  const miniJs = walk(MINI_ROOT, '.js').filter(
    (f) => /\/pages?\//.test(f) && !f.includes('/miniprogram_npm/')
  )
  const miniEntries = miniJs.map(parseMiniPage).filter(Boolean)

  const searchFields = loadSearchFields()
  const controllers = loadControllerHandlers()
  enrichEntries(adminEntries, searchFields, controllers)

  const smokeCases = [
    {
      name: '生产计划-成品型号模糊',
      endpoint: '/index.php/admin/production.Production/list',
      payload: { page: 1, limit: 3, plan_finish: '0' },
      filter: { material_model: '50' },
      expect: 'narrow'
    },
    {
      name: '报工-成品型号模糊',
      endpoint: '/index.php/admin/production.ProductReport/list',
      payload: { page: 1, limit: 3 },
      filter: { c_material_model: '51' },
      expect: 'narrow'
    },
    {
      name: '盘点-圆钢规格',
      endpoint: '/index.php/admin/production.Inventory/list',
      payload: { page: 1, limit: 3 },
      filter: { y_material_spec: '50' },
      expect: 'narrow_or_zero'
    }
  ]

  const registry = {
    generatedAt: new Date().toISOString(),
    smokeCases,
    summary: {
      adminPages: adminEntries.length,
      miniPages: miniEntries.length,
      issues: {
        ui_unbound: adminEntries.filter((e) => e.status === 'ui_unbound').length,
        missing_backend: adminEntries.filter((e) => e.status === 'missing_backend').length,
        field_mismatch: adminEntries.filter((e) => e.status === 'field_mismatch').length,
        ok: adminEntries.filter((e) => e.status === 'ok').length
      }
    },
    searchFieldDefinitions: searchFields,
    admin: adminEntries,
    mini: miniEntries
  }

  fs.mkdirSync(path.dirname(OUT_JSON), { recursive: true })
  fs.writeFileSync(OUT_JSON, JSON.stringify(registry, null, 2))

  const bad = adminEntries.filter((e) => e.status !== 'ok')
  let md = `# 检索契约审计报告\n\n生成时间: ${registry.generatedAt}\n\n`
  md += `| 指标 | 数量 |\n|------|------|\n`
  md += `| 管理后台筛选页 | ${adminEntries.length} |\n`
  md += `| 状态 ok | ${registry.summary.issues.ok} |\n`
  md += `| ui_unbound | ${registry.summary.issues.ui_unbound} |\n`
  md += `| missing_backend | ${registry.summary.issues.missing_backend} |\n`
  md += `| 小程序搜索页 | ${miniEntries.length} |\n\n`
  md += `## 待处理项\n\n`
  for (const e of bad) {
    md += `### ${e.page}\n- status: **${e.status}**\n`
    if (e.issues?.length) md += `- ${e.issues.join('\n- ')}\n`
    md += `\n`
  }
  fs.writeFileSync(OUT_MD, md)

  console.log(`Wrote ${OUT_JSON}`)
  console.log(`Wrote ${OUT_MD}`)
  console.log(`Issues: ui_unbound=${registry.summary.issues.ui_unbound}, missing_backend=${registry.summary.issues.missing_backend}`)

  if (CHECK_MODE && registry.summary.issues.ui_unbound > 0) {
    console.error('--check failed: ui_unbound > 0')
    process.exit(1)
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main()
}
