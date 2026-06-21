<template>
	<view class="dashboard-root">
		<!-- 纯原生加载层 -->
		<view class="native-loading" v-if="loading">
			<view class="spinner"></view>
			<text class="loading-text">{{ loadingText }}</text>
		</view>

		<!-- 原生大屏主体 -->
		<view class="cockpit" v-if="!loading && !loadError">
			<!-- 头部 -->
			<view class="hero-header">
				<view class="hero-header__left">
					<view class="hero-header__mark"></view>
					<view>
						<text class="hero-header__title">生产总览</text>
						<text class="hero-header__desc">交付 | 质量 | 消耗 | 设备健康</text>
					</view>
				</view>
				<view class="hero-header__right">
					<text class="hero-header__clock">{{ clock }}</text>
				</view>
			</view>

			<!-- KPI 区 -->
			<view class="kpi-strip">
				<view class="kpi-card">
					<text class="kpi-card__label">在制订单</text>
					<text class="kpi-card__value">{{ kpi.inProducing }}</text>
					<text class="kpi-card__hint">状态为生产中</text>
				</view>
				<view class="kpi-card kpi-card--accent">
					<text class="kpi-card__label">设备正常运行</text>
					<text class="kpi-card__value">{{ kpi.deviceOk }}<text class="kpi-card__slash">/{{ kpi.deviceTotal }}</text></text>
					<text class="kpi-card__hint">主数据台账</text>
				</view>
				<view class="kpi-card kpi-card--warn">
					<text class="kpi-card__label">本月不良累计</text>
					<text class="kpi-card__value">{{ kpi.defectTotal }}</text>
					<text class="kpi-card__hint">报工 + 质检</text>
				</view>
				<view class="kpi-card">
					<text class="kpi-card__label">今日工序产出合计</text>
					<text class="kpi-card__value">{{ kpi.dayOutputSum }}</text>
					<text class="kpi-card__hint">数采区 TOP 工序汇总</text>
				</view>
			</view>

			<view class="layout-main">
				<!-- 左侧：生产执行与交付 (直接使用原生循环) -->
				<view class="panel panel-plans">
					<view class="panel__head">
						<text class="panel__title">生产执行与交付</text>
					</view>
					<view class="panel__body">
						<view class="table-header">
							<text class="th col-name">计划名称</text>
							<text class="th col-code">单号</text>
							<text class="th col-st">状态</text>
							<text class="th col-pct">进度</text>
						</view>
						<view class="table-row" v-for="(p, index) in plans" :key="index">
							<text class="td col-name">{{ p.production_plan_name || '—' }}</text>
							<text class="td col-code">{{ p.production_plan_code || '—' }}</text>
							<text class="td col-st badge" :class="p.status === 2 ? 'badge-ok' : (p.status === 1 ? 'badge-accent' : '')">
								{{ p.status === 2 ? '已完成' : (p.status === 1 ? '生产中' : '未开始') }}
							</text>
							<text class="td col-pct">{{ p.status === 2 ? '100%' : '0%' }}</text>
						</view>
					</view>
				</view>

				<!-- 右侧：质量风险 (RenderJS Echarts 占位) -->
				<view class="panel panel-defect">
					<view class="panel__head">
						<text class="panel__title">质量风险（当月）</text>
					</view>
					<view class="panel__body" style="display:flex; justify-content:space-around; align-items:center;">
						<!-- 这里触发 renderjs 的更新 -->
						<view class="echarts-view" :prop="echartsData" :change:prop="echartsModule.updateEcharts" id="echarts-pie1"></view>
						<view class="echarts-view" id="echarts-pie2"></view>
					</view>
				</view>
			</view>

			<view class="layout-bottom">
				<!-- 左下：现场产出脉冲 -->
				<view class="panel panel-energy">
					<view class="panel__head">
						<text class="panel__title">现场产出脉冲</text>
					</view>
					<view class="panel__body pulse-row">
						<view class="pulse-card" v-for="(out, i) in realtimeOutputs" :key="i">
							<text class="pulse-label">{{ out.processes_name }}</text>
							<text class="pulse-value">{{ out.qty }}</text>
						</view>
					</view>
				</view>

				<!-- 右下：设备健康 -->
				<view class="panel panel-devices">
					<view class="panel__head">
						<text class="panel__title">设备健康</text>
					</view>
					<view class="panel__body">
						<view class="table-header">
							<text class="th col-name">设备名称</text>
							<text class="th col-st">状态</text>
						</view>
						<view class="table-row" v-for="(d, i) in devices" :key="i">
							<text class="td col-name">{{ d.device_name || '—' }}</text>
							<text class="td col-st badge" :class="d.status_code === 0 ? 'badge-ok' : 'badge-warn'">
								{{ d.status_label || (d.status_code === 0 ? '正常' : '异常') }}
							</text>
						</view>
					</view>
				</view>
			</view>
		</view>

		<view class="error-overlay" v-if="loadError">
			<text class="error-text">数据获取失败，请重试</text>
			<button class="retry-btn" @click="doLoginAndFetch">重新连接</button>
		</view>
	</view>
</template>

<script>
export default {
	data() {
		return {
			// #ifdef H5
			baseUrl: '', // H5 环境使用 manifest.json 中的代理
			// #endif
			// #ifndef H5
			baseUrl: 'https://gccd.xnssoft.com', // App 环境直接连线上域名
			// #endif
			loading: true,
			loadingText: '正在进行系统级安全认证...',
			loadError: false,
			clock: '',
			timer: null,
			token: '',
			// 真实数据
			kpi: { inProducing: 0, deviceOk: 0, deviceTotal: 0, defectTotal: 0, dayOutputSum: 0 },
			plans: [],
			devices: [],
			realtimeOutputs: [],
			echartsData: null // 用于传递给 renderjs 的数据
		}
	},
	mounted() {
		this.tickClock()
		this.timer = setInterval(this.tickClock, 1000)
		this.doLoginAndFetch()
	},
	destroyed() {
		if (this.timer) clearInterval(this.timer)
	},
	methods: {
		tickClock() {
			const d = new Date()
			const pad = (n) => (n < 10 ? '0' + n : n)
			this.clock = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
		},
		async doLoginAndFetch() {
			this.loading = true
			this.loadError = false
			this.loadingText = '正在安全连入线上服务器...'
			
			// 1. 原生请求云端登录接口获取 Token
			uni.request({
				url: this.baseUrl + '/admin/system/login/login',
				method: 'POST',
				data: { username: 'skyselang', password: 'xnssoft@888' },
				success: (res) => {
					if (res.data && res.data.code === 200) {
						this.token = res.data.data.token || res.data.data.AdminToken
						this.loadingText = '认证成功，正在拉取最新看板数据...'
						this.fetchDashboard()
					} else {
						this.loadError = true
						this.loading = false
					}
				},
				fail: () => {
					this.loadError = true
					this.loading = false
				}
			})
		},
		fetchDashboard() {
			// 2. 携带 Token 原生请求线上大屏数据
			uni.request({
				url: this.baseUrl + '/admin/mes/DataDashboard/DataDashboard/dashboardBoard',
				method: 'GET',
				header: {
					'AdminToken': this.token,
					'Token': this.token
				},
				success: (res) => {
					if (res.data && res.data.code === 200) {
						this.processData(res.data.data)
						this.loading = false
					} else {
						this.loadError = true
						this.loading = false
					}
				},
				fail: () => {
					this.loadError = true
					this.loading = false
				}
			})
		},
		processData(data) {
			// 解析计划
			const plans = Array.isArray(data.plans) ? data.plans : []
			this.plans = plans.slice(0, 6)
			
			// 解析设备
			let devices = Array.isArray(data.devices) ? data.devices : (Array.isArray(data.device_list) ? data.device_list : [])
			this.devices = devices.slice(0, 6)
			
			// 解析 KPI
			const inProducing = plans.filter(p => Number(p.status) === 1).length
			const deviceOk = devices.filter(d => Number(d.status_code) === 0).length
			const dm = data.defect_month_meta || {}
			const defectTotal = (Number(dm.report_defective_sum) || 0) + (Number(dm.qc_defective_sum) || 0)
			const rt = data.realtime_outputs || []
			const dayOutputSum = rt.reduce((s, x) => s + (Number(x.qty) || 0), 0)
			
			this.kpi = {
				inProducing,
				deviceOk,
				deviceTotal: devices.length,
				defectTotal,
				dayOutputSum
			}
			
			this.realtimeOutputs = rt.slice(0, 6)
			
			// 把 ECharts 需要的数据推给 RenderJS 渲染层
			this.echartsData = {
				report: data.defect_report_pie_by_category || [],
				qc: data.defect_qc_pie_by_category || []
			}
		}
	}
}
</script>

<!-- RenderJS 模块，用于在 App 层面原生渲染 ECharts -->
<script module="echartsModule" lang="renderjs">
export default {
	mounted() {
		// 动态引入 ECharts (因为原生环境没有 npm 包)
		if (typeof window.echarts === 'function') {
			this.initEcharts()
		} else {
			const script = document.createElement('script')
			script.src = 'https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js'
			script.onload = this.initEcharts
			document.head.appendChild(script)
		}
	},
	methods: {
		initEcharts() {
			this.chartReady = true
		},
		updateEcharts(newValue, oldValue, ownerInstance, instance) {
			if (!newValue || !this.chartReady) return
			setTimeout(() => {
				const echarts = window.echarts
				if (!echarts) return
				
				// 渲染饼图 1
				const dom1 = document.getElementById('echarts-pie1')
				if (dom1) {
					let myChart1 = echarts.getInstanceByDom(dom1) || echarts.init(dom1)
					myChart1.setOption(this.getPieOption('报工不良', newValue.report))
				}
				
				// 渲染饼图 2
				const dom2 = document.getElementById('echarts-pie2')
				if (dom2) {
					let myChart2 = echarts.getInstanceByDom(dom2) || echarts.init(dom2)
					myChart2.setOption(this.getPieOption('质检不良', newValue.qc))
				}
			}, 300)
		},
		getPieOption(title, data) {
			const total = data.reduce((s, x) => s + (Number(x.value) || 0), 0)
			return {
				backgroundColor: 'transparent',
				title: { text: title, left: 'center', top: 5, textStyle: { color: '#bae6fd', fontSize: 12 } },
				series: [{
					type: 'pie',
					radius: ['40%', '65%'],
					center: ['50%', '55%'],
					label: { color: '#e0f2fe', fontSize: 10, formatter: p => `${p.name}\n${p.value}` },
					data: total > 0 ? data : [{ name: '无数据', value: 1 }],
					itemStyle: { borderColor: '#071018', borderWidth: 1 }
				}]
			}
		}
	}
}
</script>

<style scoped>
/* 原生深色容器 */
.dashboard-root {
	width: 100vw;
	height: 100vh;
	background: #04080f;
	color: #f0f9ff;
	display: flex;
	flex-direction: column;
	font-family: sans-serif;
	overflow: hidden;
}

/* ================== 加载动画 ================== */
.native-loading, .error-overlay {
	position: fixed;
	top: 0; left: 0; right: 0; bottom: 0;
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: center;
	background: #04080f;
	z-index: 999;
}
.spinner {
	width: 60px; height: 60px;
	border: 4px solid rgba(56, 189, 248, 0.2);
	border-top: 4px solid #38bdf8;
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin-bottom: 20px;
}
@keyframes spin { 100% { transform: rotate(360deg); } }
.loading-text, .error-text { font-size: 18px; color: #38bdf8; letter-spacing: 2px; }
.retry-btn { margin-top: 20px; background: #0ea5e9; color: #fff; padding: 10px 30px; border-radius: 20px; font-size: 16px; border: none;}

/* ================== 主体布局 ================== */
.cockpit {
	flex: 1;
	display: flex;
	flex-direction: column;
	padding: 15px;
	background: radial-gradient(ellipse at 50% 0%, rgba(14,116,144,0.15), transparent 60%);
}

.hero-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	background: linear-gradient(95deg, rgba(14,165,233,0.1), rgba(99,102,241,0.05));
	border: 1px solid rgba(56,189,248,0.2);
	border-radius: 8px;
	padding: 15px;
	margin-bottom: 15px;
}
.hero-header__left { display: flex; align-items: center; }
.hero-header__mark { width: 30px; height: 30px; background: conic-gradient(#38bdf8, #6366f1, #22d3ee); border-radius: 6px; margin-right: 15px;}
.hero-header__title { font-size: 24px; font-weight: bold; color: #fff; }
.hero-header__desc { font-size: 14px; color: rgba(255,255,255,0.7); margin-top: 5px; }
.hero-header__clock { font-size: 20px; font-weight: bold; color: #fff; font-family: monospace;}

.kpi-strip {
	display: flex;
	gap: 15px;
	margin-bottom: 15px;
}
.kpi-card {
	flex: 1;
	background: rgba(6,16,28,0.8);
	border: 1px solid rgba(56,189,248,0.2);
	border-radius: 8px;
	padding: 15px;
	display: flex;
	flex-direction: column;
}
.kpi-card--accent { border-color: rgba(56,189,248,0.4); background: rgba(14,165,233,0.1); }
.kpi-card--warn { border-color: rgba(251,191,36,0.3); background: rgba(245,158,11,0.1); }
.kpi-card__label { font-size: 14px; color: rgba(255,255,255,0.7); }
.kpi-card__value { font-size: 28px; font-weight: bold; color: #fff; margin: 8px 0;}
.kpi-card__slash { font-size: 16px; opacity: 0.7; }
.kpi-card__hint { font-size: 12px; color: rgba(255,255,255,0.5); }

.layout-main, .layout-bottom {
	display: flex;
	gap: 15px;
	flex: 1;
	margin-bottom: 15px;
}

.panel {
	background: rgba(6,18,32,0.7);
	border: 1px solid rgba(56,189,248,0.15);
	border-radius: 8px;
	display: flex;
	flex-direction: column;
}
.panel-plans { flex: 1.2; }
.panel-defect { flex: 0.8; }
.panel-energy { flex: 1.2; }
.panel-devices { flex: 0.8; }

.panel__head { padding: 12px; border-bottom: 1px solid rgba(56,189,248,0.1); }
.panel__title { font-size: 16px; font-weight: bold; color: #f0f9ff; }
.panel__body { flex: 1; padding: 10px; overflow: hidden; }

/* 表格样式 */
.table-header, .table-row { display: flex; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
.th { color: #e0f2fe; font-size: 14px; font-weight: bold; }
.td { color: rgba(255,255,255,0.9); font-size: 14px; }
.col-name { flex: 2; }
.col-code { flex: 1.5; }
.col-st { flex: 1; }
.col-pct { flex: 1; }

.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
.badge-ok { background: rgba(34,197,94,0.2); color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
.badge-accent { background: rgba(56,189,248,0.2); color: #7dd3fc; border: 1px solid rgba(56,189,248,0.3); }
.badge-warn { background: rgba(251,191,36,0.2); color: #fcd34d; border: 1px solid rgba(251,191,36,0.3); }

/* 图表占位 */
.echarts-view {
	width: 48%;
	height: 100%;
}

/* 脉冲区 */
.pulse-row { display: flex; flex-wrap: wrap; gap: 10px; align-content: flex-start; }
.pulse-card { flex: 1; min-width: 120px; background: rgba(56,189,248,0.1); border: 1px solid rgba(56,189,248,0.2); border-radius: 6px; padding: 10px; text-align: center; }
.pulse-label { font-size: 14px; color: rgba(255,255,255,0.7); display: block; margin-bottom: 5px;}
.pulse-value { font-size: 22px; font-weight: bold; color: #7dd3fc; }
</style>
