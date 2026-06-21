/**
 * WebView Token 注入脚本
 * 
 * 问题说明：
 * uni-app 的 web-view 组件加载 PC 端页面时，PC 前端的 localStorage 中没有 AdminToken，
 * 会被重定向到登录页。
 * 
 * 解决方案：
 * 在 Dashboard 的 Vue 前端代码中（如果有权修改的话），添加以下逻辑来从 URL 读取 token：
 * 
 * 方案 A（推荐）- 修改 PC 前端路由守卫：
 * 在 PC 端前端项目的路由守卫（router.beforeEach）中，添加从 URL 读取 AdminToken 的逻辑：
 * 
 * ```javascript
 * // 在 PC 端项目的 router/index.js 或 permission.js 中
 * router.beforeEach((to, from, next) => {
 *   // 从 URL 参数读取 AdminToken（APP端传入）
 *   const urlToken = to.query.AdminToken
 *   if (urlToken) {
 *     localStorage.setItem('AdminToken', urlToken)
 *   }
 *   
 *   const token = localStorage.getItem('AdminToken')
 *   if (token) {
 *     next()
 *   } else {
 *     next('/login')
 *   }
 * })
 * ```
 * 
 * 方案 B - 如果不想改 PC 前端代码：
 * 可以让 WebView 加载一个中间页，这个中间页负责：
 * 1. 从 URL 读取 token
 * 2. 写入 localStorage
 * 3. 再跳转到 Dashboard
 * 
 * 中间页模板见下方 bridge.html
 */

// 如果使用方案 B，将下面的 HTML 部署到服务器上
const BRIDGE_HTML = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>正在跳转...</title>
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: #f5f5f5;
            font-family: -apple-system, sans-serif;
        }
        .loading {
            text-align: center;
            color: #666;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e0e0e0;
            border-top: 4px solid #1a73e8;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="loading">
        <div class="spinner"></div>
        <p>正在进入系统...</p>
    </div>
    <script>
        // 从 URL 读取 AdminToken
        const params = new URLSearchParams(window.location.search);
        const token = params.get('AdminToken');
        const targetUrl = params.get('redirect') || '/admin/#/dashboard';
        
        if (token) {
            // 写入 localStorage（与 PC 前端同域，所以 localStorage 是共享的）
            localStorage.setItem('AdminToken', token);
            
            // 跳转到 Dashboard
            setTimeout(function() {
                window.location.href = targetUrl;
            }, 500);
        } else {
            document.querySelector('.loading p').textContent = 'Token 缺失，请重新登录';
        }
    </script>
</body>
</html>
`;

module.exports = {
    BRIDGE_HTML
}
