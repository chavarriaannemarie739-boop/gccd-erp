<script>
export default {
	onLaunch() {
		console.log('App Launch')
		// 直接从 index 加载大屏 WebView，跳过登录页
		uni.reLaunch({
			url: '/pages/index/index'
		})
	},
	onShow() {
		console.log('App Show')
	},
	onHide() {
		console.log('App Hide')
	}
}
</script>

<style>
/* 全局重置 - TV 横屏 */
page {
	width: 100vw;
	height: 100vh;
	overflow: hidden;
	margin: 0;
	padding: 0;
	background-color: #e8edf3;
	font-family: 'Microsoft YaHei', 'PingFang SC', -apple-system, 'Helvetica Neue', sans-serif;
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
}

/* 所有元素 box-border */
*,
*::before,
*::after {
	box-sizing: border-box;
}

/* 滚动条隐藏 */
::-webkit-scrollbar {
	display: none;
}

/* 选中高亮 - TV 遥控器焦点 */
:focus-visible {
	outline: 3px solid #1a73e8;
	outline-offset: 2px;
	border-radius: 4px;
}

/* toast 大小适配 */
.uni-toast {
	font-size: 24px !important;
	padding: 20px 40px !important;
	border-radius: 16px !important;
}
</style>
