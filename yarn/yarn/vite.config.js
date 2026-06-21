import vue from '@vitejs/plugin-vue'
import legacy from '@vitejs/plugin-legacy'
import { defineConfig, loadEnv } from 'vite'
import { resolve } from 'path'
import UnoCSS from 'unocss/vite'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import Icons from 'unplugin-icons/vite'
import IconsResolver from 'unplugin-icons/resolver'
import { createSvgIconsPlugin } from 'vite-plugin-svg-icons'

const pathSrc = resolve(__dirname, 'src')
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd())
  const envAll = loadEnv(mode, process.cwd(), '')
  const isDvMode = mode.startsWith('dv')
  const devProxyTarget =
    envAll.DEV_PROXY_TARGET || envAll.VITE_DEV_PROXY_TARGET || 'http://127.0.0.1:9526'
  const devPort = isDvMode ? 9528 : 9527
  return {
    base: env.VITE_APP_BASE,
    resolve: {
      alias: {
        '@': pathSrc,
        'vue-i18n': 'vue-i18n/dist/vue-i18n.cjs.js'
      }
    },
    css: {
      // CSS 预处理器
      preprocessorOptions: {
        // 定义全局 SCSS 变量
        scss: {
          api: 'modern-compiler',
          additionalData: `@use "@/styles/variables.scss" as *;`
        }
      }
    },
    server: {
      host: '0.0.0.0',
      port: devPort,
      /** 占坑时直接报错，避免静默改用 9528/9529 导致书签失效 */
      strictPort: true,
      open: isDvMode ? '/entries/dv/index.html' : true,
      /** 通过 http://gaochi.com:9527 访问时需放行 Host（与本机 hosts 中域名一致） */
      allowedHosts: ['gaochi.com', '.gaochi.com', 'localhost'],
      // 开发时接口走同源 /admin，由这里转发到本机 PHP（站点根目录需指向 gccd_server/public）
      proxy: {
        '/admin': {
          target: devProxyTarget,
          changeOrigin: true,
          rewrite: (path) => '/index.php' + path
        }
      }
    },
    plugins: [
      vue(),
      // 车间电视（荣耀等）自带 WebView 内核老旧，dv 大屏需打兼容包（nomodule + 폴리필）
      ...(isDvMode
        ? [
            legacy({
              targets: ['Android >= 5', 'Chrome >= 49'],
              additionalLegacyPolyfills: ['regenerator-runtime/runtime'],
              renderLegacyChunks: true,
              modernPolyfills: true
            })
          ]
        : []),
      ...(isDvMode
        ? []
        : [
            UnoCSS(),
            AutoImport({
              imports: ['vue', 'vue-router', 'vue-i18n', 'pinia', '@vueuse/core'],
              resolvers: [ElementPlusResolver(), IconsResolver({})],
              vueTemplate: true,
              dts: false
            }),
            Components({
              resolvers: [ElementPlusResolver(), IconsResolver({ enabledCollections: ['ep'] })],
              dirs: ['src/components', 'src/**/components'],
              dts: false
            }),
            Icons({
              autoInstall: true
            }),
            createSvgIconsPlugin({
              iconDirs: [resolve(pathSrc, 'assets/icons')],
              symbolId: 'icon-[dir]-[name]'
            })
          ])
    ],
    // 预加载项目必需的组件
    optimizeDeps: {
      include: [
        'vue',
        'vue-router',
        'vue-i18n',
        'pinia',
        'axios',
        'crypto-js',
        'nprogress',
        'path-to-regexp',
        'path-browserify',
        'echarts/core',
        'echarts/charts',
        'echarts/components',
        'echarts/renderers',
        'element-plus'
      ]
    },
    // 构建配置
    build: {
      outDir: env.VITE_APP_OUT_DIR,
      chunkSizeWarningLimit: 2000, // 消除打包大小超过500kb警告
      minify: 'terser', // Vite 2.6.x 以上需要配置 minify: "terser", terserOptions 才能生效
      terserOptions: {
        compress: {
          keep_infinity: true, // 防止 Infinity 被压缩成 1/0，这可能会导致 Chrome 上的性能问题
          drop_console: true, // 生产环境去除 console
          drop_debugger: true // 生产环境去除 debugger
        },
        format: {
          comments: false // 删除注释
        }
      },
      rollupOptions: {
        input: isDvMode ? resolve(__dirname, 'entries/dv/index.html') : resolve(__dirname, 'index.html'),
        output: {
          // 用于从入口点创建的块的打包输出格式[name]表示文件名,[hash]表示该文件内容hash值
          entryFileNames: 'js/[name].[hash].js',
          // 用于命名代码拆分时创建的共享块的输出命名
          chunkFileNames: 'js/[name].[hash].js',
          // 用于输出静态资源的命名，[ext]表示文件扩展名
          assetFileNames: (assetInfo) => {
            const info = assetInfo.name.split('.')
            let extType = info[info.length - 1]
            if (/\.(mp4|webm|ogg|mp3|wav|flac|aac)(\?.*)?$/i.test(assetInfo.name)) {
              extType = 'media'
            } else if (/\.(png|jpe?g|gif|svg)(\?.*)?$/.test(assetInfo.name)) {
              extType = 'img'
            } else if (/\.(woff2?|eot|ttf|otf)(\?.*)?$/i.test(assetInfo.name)) {
              extType = 'fonts'
            }
            return `${extType}/[name].[hash].[ext]`
          }
        }
      }
    }
  }
})
