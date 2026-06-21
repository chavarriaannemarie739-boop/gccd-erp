<template>
	<view class="tv-container">
		<!-- 左：品牌区域 -->
		<view class="brand-panel">
			<view class="brand-bg"></view>
			<view class="brand-content">
				<view class="logo-wrapper">
					<view class="logo-icon">
						<text class="logo-letter">G</text>
					</view>
				</view>
				<text class="brand-title">高齿传动</text>
				<text class="brand-subtitle">生产管理系统</text>
				<view class="brand-divider"></view>
				<text class="brand-desc">高效 · 精准 · 智能</text>
				<text class="brand-slogan">驱动齿轮制造数字化升级</text>
			</view>
		</view>

		<!-- 右：登录表单 -->
		<view class="form-panel">
			<view class="form-card">
				<text class="form-title">系统登录</text>
				<text class="form-hint">请使用管理员账号登录</text>

				<view class="input-group">
					<text class="input-label">账 号</text>
					<input
						class="input-field"
						type="text"
						v-model="username"
						placeholder="请输入账号"
						placeholder-class="placeholder"
						@confirm="handleLogin"
					/>
				</view>

				<view class="input-group">
					<text class="input-label">密 码</text>
					<input
						class="input-field"
						type="password"
						v-model="password"
						placeholder="请输入密码"
						placeholder-class="placeholder"
						:password="!showPassword"
						@confirm="handleLogin"
					/>
					<text class="toggle-pwd" @click="showPassword = !showPassword">
						{{ showPassword ? '隐藏' : '显示' }}
					</text>
				</view>

				<view class="options-row">
					<label class="remember-me" @click="rememberMe = !rememberMe">
						<view :class="['check-box', rememberMe ? 'checked' : '']">
							<text v-if="rememberMe">&#10003;</text>
						</view>
						<text class="remember-text">记住密码</text>
					</label>
				</view>

				<button class="login-btn" :loading="loading" :disabled="loading" @click="handleLogin">
					{{ loading ? '登录中...' : '登  录' }}
				</button>

				<view class="error-msg" v-if="errorMsg">
					<text>{{ errorMsg }}</text>
				</view>
			</view>

			<text class="footer-text">高齿传动 &copy; 2026 &nbsp;|&nbsp; 技术支持：西安新思软件</text>
		</view>
	</view>
</template>

<script>
const BASE_URL = 'https://gccd.xnssoft.com'

export default {
	data() {
		return {
			username: '',
			password: '',
			showPassword: false,
			rememberMe: true,
			loading: false,
			errorMsg: ''
		}
	},

	onLoad() {
		// 读取记住的密码
		const savedUser = uni.getStorageSync('saved_username')
		const savedPwd = uni.getStorageSync('saved_password')
		if (savedUser) this.username = savedUser
		if (savedPwd) this.password = savedPwd

		// 如果没有保存过，用默认账号
		if (!savedUser) {
			this.username = 'skyselang'
			this.password = 'xnssoft@888'
		}

		// 已有 token 直接跳转
		const token = uni.getStorageSync('admin_AdminToken')
		if (token) {
			this.goToDashboard()
			return
		}

		// 自动登录（延迟确保页面渲染）
		if (this.username && this.password) {
			setTimeout(() => {
				this.handleLogin()
			}, 800)
		}
	},

	methods: {
		handleLogin() {
			if (!this.username.trim()) {
				this.errorMsg = '请输入账号'
				return
			}
			if (!this.password.trim()) {
				this.errorMsg = '请输入密码'
				return
			}

			this.errorMsg = ''
			this.loading = true

			uni.request({
				url: `${BASE_URL}/admin/admin.Login/login`,
				method: 'POST',
				header: { 'Content-Type': 'application/json' },
				data: {
					username: this.username,
					password: this.password
				},
				success: (res) => {
					if (res.statusCode === 200 && res.data && res.data.code === 200) {
						const data = res.data.data
						const token = data.AdminToken || data.token || data.admin_token

						if (token) {
							uni.setStorageSync('admin_AdminToken', token)
							uni.setStorageSync('userInfo', JSON.stringify(data))

							if (this.rememberMe) {
								uni.setStorageSync('saved_username', this.username)
								uni.setStorageSync('saved_password', this.password)
							} else {
								uni.removeStorageSync('saved_username')
								uni.removeStorageSync('saved_password')
							}

							uni.showToast({ title: '登录成功', icon: 'success' })

							setTimeout(() => {
								this.goToDashboard()
							}, 500)
						} else {
							this.errorMsg = '登录返回数据异常，未获取到 Token'
						}
					} else {
						this.errorMsg = (res.data && res.data.msg) || '登录失败，请检查账号密码'
					}
				},
				fail: () => {
					this.errorMsg = '网络请求失败，请检查网络连接'
				},
				complete: () => {
					this.loading = false
				}
			})
		},

		goToDashboard() {
			uni.reLaunch({ url: '/pages/index/index' })
		}
	}
}
</script>

<style scoped>
/* ========== TV 横屏布局 ========== */
.tv-container {
	width: 100vw;
	height: 100vh;
	display: flex;
	flex-direction: row;
	overflow: hidden;
	background: #e8edf3;
	font-family: 'Microsoft YaHei', 'PingFang SC', -apple-system, sans-serif;
}

/* ===== 左：品牌面板 (40%) ===== */
.brand-panel {
	flex: 0 0 38%;
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
}

.brand-bg {
	position: absolute;
	top: 0; left: 0; right: 0; bottom: 0;
	background: linear-gradient(145deg, #0d47a1 0%, #1565c0 40%, #1a73e8 70%, #42a5f5 100%);
}

.brand-bg::after {
	content: '';
	position: absolute;
	top: -30%; right: -20%;
	width: 80%;
	height: 140%;
	background: radial-gradient(ellipse, rgba(255,255,255,0.08) 0%, transparent 70%);
	border-radius: 50%;
}

.brand-content {
	position: relative;
	z-index: 2;
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	padding: 0 40px;
}

.logo-wrapper {
	margin-bottom: 28px;
}

.logo-icon {
	width: 100px;
	height: 100px;
	background: rgba(255, 255, 255, 0.15);
	border-radius: 28px;
	display: flex;
	align-items: center;
	justify-content: center;
	border: 3px solid rgba(255, 255, 255, 0.25);
	backdrop-filter: blur(8px);
	box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
}

.logo-letter {
	font-size: 52px;
	font-weight: 800;
	color: #ffffff;
	letter-spacing: 2px;
}

.brand-title {
	font-size: 52px;
	font-weight: 700;
	color: #ffffff;
	letter-spacing: 6px;
	margin-bottom: 8px;
	text-shadow: 0 2px 12px rgba(0, 0, 0, 0.2);
}

.brand-subtitle {
	font-size: 26px;
	color: rgba(255, 255, 255, 0.9);
	letter-spacing: 8px;
	margin-bottom: 24px;
}

.brand-divider {
	width: 60px;
	height: 3px;
	background: rgba(255, 255, 255, 0.5);
	border-radius: 2px;
	margin-bottom: 24px;
}

.brand-desc {
	font-size: 22px;
	color: rgba(255, 255, 255, 0.85);
	letter-spacing: 6px;
	margin-bottom: 8px;
}

.brand-slogan {
	font-size: 18px;
	color: rgba(255, 255, 255, 0.6);
	letter-spacing: 2px;
}

/* ===== 右：表单面板 (60%) ===== */
.form-panel {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	position: relative;
	padding: 40px;
}

.form-card {
	width: 460px;
	max-width: 90%;
	background: #ffffff;
	border-radius: 20px;
	padding: 56px 52px 48px;
	box-shadow: 0 12px 60px rgba(0, 0, 0, 0.08);
}

.form-title {
	display: block;
	font-size: 38px;
	font-weight: 700;
	color: #1a1a2e;
	text-align: center;
	margin-bottom: 8px;
}

.form-hint {
	display: block;
	font-size: 18px;
	color: #999;
	text-align: center;
	margin-bottom: 40px;
}

/* 输入组 */
.input-group {
	margin-bottom: 28px;
	position: relative;
}

.input-label {
	display: block;
	font-size: 18px;
	color: #555;
	margin-bottom: 10px;
	font-weight: 500;
}

.input-field {
	width: 100%;
	height: 60px;
	line-height: 60px;
	font-size: 22px;
	color: #333;
	background: #f5f7fa;
	border: 2px solid transparent;
	border-radius: 12px;
	padding: 0 20px;
	box-sizing: border-box;
	transition: all 0.2s;
}

.input-field:focus {
	outline: none;
	border-color: #1a73e8;
	background: #ffffff;
	box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.10);
}

.placeholder {
	color: #bbb;
	font-size: 20px;
}

.toggle-pwd {
	position: absolute;
	right: 16px;
	bottom: 16px;
	font-size: 16px;
	color: #999;
	cursor: pointer;
	padding: 6px 10px;
	border-radius: 6px;
	user-select: none;
}

.toggle-pwd:active {
	background: #f0f0f0;
}

/* 选项行 */
.options-row {
	display: flex;
	justify-content: flex-start;
	align-items: center;
	margin-bottom: 36px;
}

.remember-me {
	display: flex;
	align-items: center;
	cursor: pointer;
	user-select: none;
}

.check-box {
	width: 24px;
	height: 24px;
	border: 2px solid #ccc;
	border-radius: 6px;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-right: 10px;
	font-size: 16px;
	color: #fff;
	transition: all 0.2s;
}

.check-box.checked {
	background: #1a73e8;
	border-color: #1a73e8;
}

.remember-text {
	font-size: 18px;
	color: #888;
}

/* 登录按钮 */
.login-btn {
	width: 100%;
	height: 64px;
	line-height: 64px;
	font-size: 26px;
	font-weight: 700;
	color: #ffffff;
	background: linear-gradient(135deg, #1a73e8, #1565c0);
	border: none;
	border-radius: 14px;
	letter-spacing: 10px;
	box-shadow: 0 6px 24px rgba(26, 115, 232, 0.35);
	cursor: pointer;
	transition: all 0.2s;
	margin: 0;
}

.login-btn:active,
.login-btn:focus-visible {
	transform: scale(0.97);
	opacity: 0.92;
	outline: none;
	box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.25), 0 6px 24px rgba(26, 115, 232, 0.35);
}

.login-btn[disabled] {
	opacity: 0.5;
	transform: none;
}

/* 错误提示 */
.error-msg {
	margin-top: 20px;
	text-align: center;
	color: #e53935;
	font-size: 20px;
	font-weight: 500;
}

/* 底部版权 */
.footer-text {
	position: absolute;
	bottom: 24px;
	font-size: 16px;
	color: #bbb;
	letter-spacing: 1px;
}

/* ===== TV 4K 超大屏适配 ===== */
@media (min-width: 3000px) {
	.form-card {
		width: 580px;
		padding: 72px 64px 60px;
	}
	.form-title { font-size: 46px; }
	.input-field {
		height: 72px;
		line-height: 72px;
		font-size: 26px;
	}
	.login-btn {
		height: 76px;
		line-height: 76px;
		font-size: 30px;
	}
	.brand-title { font-size: 64px; }
	.logo-icon {
		width: 120px;
		height: 120px;
	}
	.logo-letter { font-size: 64px; }
}

/* ===== 小屏兜底 ===== */
@media (max-width: 1024px) {
	.brand-panel { flex: 0 0 32%; }
	.form-card { width: 380px; padding: 40px 36px 36px; }
	.form-title { font-size: 30px; }
	.input-field { height: 52px; line-height: 52px; font-size: 18px; }
	.login-btn { height: 54px; line-height: 54px; font-size: 22px; }
}
</style>
