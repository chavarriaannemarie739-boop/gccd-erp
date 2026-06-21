<template>
  <vue-particles id="tsparticles" :options="particlesOptions" />
  <div
    class="login-container"
    :style="{
      backgroundImage: 'url(' + login_bg_url + ')',
      backgroundColor: login_bg_color
    }"
  >
    <!--<el-row class="mt-4">-->
    <!--  <el-col :span="1" :offset="23">-->
    <!--    <lang-select class="cursor-pointer mr-4 mt-[3px]" />-->
    <!--    <theme-select class="cursor-pointer" />-->
    <!--  </el-col>-->
    <!--</el-row>-->
    <el-form ref="ref" class="login-form" :model="model" :rules="rules">
      <div class="login-logo" :style="{ backgroundColor: login_bg_color }">
        <!--<el-image v-if="logo_url" :src="logo_url" style="height: 108px">-->
        <!--  <template #error>-->
        <!--    <svg-icon icon-class="picture" />-->
        <!--  </template>-->
        <!--</el-image>-->
        <!--<div v-else style="height: 115px"></div>-->
      </div>
      <div class="login-title">
        <h3 class="login-title-name">{{ system_name }}</h3>
      </div>
      <el-form-item prop="username">
        <el-input v-model="model.username" :placeholder="$t('login.username')" clearable>
          <template #prefix>
            <svg-icon icon-class="user" />
          </template>
        </el-input>
      </el-form-item>
      <el-form-item prop="password">
        <el-input
          v-model="model.password"
          type="password"
          :placeholder="$t('login.password')"
          clearable
          show-password
        >
          <template #prefix>
            <svg-icon icon-class="lock" />
          </template>
        </el-input>
      </el-form-item>
      <el-form-item v-if="Number(captcha_switch) === 1 && captcha_src" prop="captcha_code">
        <el-col :span="13">
          <el-input
            v-model="model.captcha_code"
            :placeholder="$t('login.captchaCode')"
            autocomplete="off"
            clearable
          >
            <template #prefix>
              <svg-icon icon-class="picture" />
            </template>
          </el-input>
        </el-col>
        <el-col :span="11">
          <el-image
            class="login-captcha"
            :style="{ height: captchaHeight }"
            :src="captcha_src"
            fit="fill"
            :alt="$t('login.captchaCode')"
            :title="$t('login.Click to refresh the captcha code')"
            @click="captcha"
          />
        </el-col>
      </el-form-item>
      <aj-captcha
        v-if="Number(captcha_switch) === 1 && Number(captcha_mode) === 2"
        ref="ajcaptcha"
        mode="pop"
        :captcha-type="captcha_type"
        @success="ajcaptchaSuccess"
      />
      <el-button
        v-if="Number(captcha_switch) === 1 && Number(captcha_mode) === 2"
        :loading="loading"
        type="primary"
        class="login-bottom"
        @click="ajcaptchaShow"
      >
        {{ $t('login.login') }}
      </el-button>
      <el-button
        v-else
        :loading="loading"
        type="primary"
        class="login-bottom"
        @click.prevent="handleLogin"
      >
        {{ $t('login.login') }}
      </el-button>
    </el-form>
  </div>
</template>

<script>
import defaultSettings from '@/settings'
import LangSelect from '@/components/LangSelect/index.vue'
import ThemeSelect from '@/components/ThemeSelect/index.vue'
import AjCaptcha from '@/components/AjCaptcha/index.vue'
import { captcha, setting } from '@/api/system/login'
import { useAppStore, useAppStoreHook } from '@/store/modules/app'
import { useSettingsStoreHook } from '@/store/modules/settings'
import { useUserStoreHook } from '@/store/modules/user'
import { delNotice } from '@/utils/settings'
export default {
  name: 'SystemLogin',
  // components: { LangSelect, ThemeSelect, AjCaptcha },
  data() {
    return {
      particlesOptions: {
        background: {
          color: {
            value: ['#242645']
          }
        },
        fullScreen: {
          enable: false
        },
        opacity: {
          value: 1,
          random: true
        },
        fpsLimit: 60,
        interactivity: {
          events: {
            onClick: {
              enable: false,
              mode: 'push' // 可用的click模式有: "push", "remove", "repulse", "bubble"。
            },
            onHover: {
              enable: true,
              mode: 'grab', // 可用的hover模式有: "grab", "repulse", "bubble"。
              parallax: { enable: false, force: 60, smooth: 10 }
            },
            resize: true
          },
          modes: {
            bubble: {
              distance: 400,
              duration: 2,
              opacity: 0.8,
              size: 40
            },
            push: {
              quantity: 4
            },
            repulse: {
              distance: 200,
              duration: 0.4
            }
          }
        },
        //粒子
        particles: {
          color: {
            value: '#FFF',
            animation: {
              enable: true,
              speed: 190,
              sync: true
            }
          },
          //线条
          links: {
            color: '#fff', //线条颜色
            distance: 150, //线条长度
            enable: true, //是否有线条
            opacity: 0.5, //线条透明度
            width: '0.4' //线条宽度
          },
          move: {
            direction: 'none',
            enable: true,
            outModes: 'bounce',
            random: false,
            speed: 0.5, //粒子速度
            straight: false
          },
          number: {
            density: {
              enable: true,
              area: 800
            },
            value: 160 //粒子数量
          },
          opacity: {
            value: 0.2 //透明度
          },
          shape: {
            type: 'circle' // 可用的粒子外观类型有："circle","edge","triangle", "polygon","star"
          },
          size: {
            random: true,
            value: { min: 1, max: 7 }
          }
        },
        detectRetina: true
      },
      name: '登录',
      loading: false,
      redirect: undefined,
      otherQuery: {},
      captcha_switch: 0,
      captcha_mode: 1,
      captcha_type: 'blockPuzzle',
      captcha_src: '',
      login_bg_url: '',
      login_bg_color: '',
      logo_url: '',
      system_name: 'yylAdmin',
      model: {
        username: '',
        password: '',
        captcha_id: '',
        captcha_code: '',
        ajcaptcha: {}
      }
    }
  },
  computed: {
    captchaHeight() {
      const appStore = useAppStoreHook()
      if (appStore.size == 'large') {
        return '40px'
      } else if (appStore.size == 'small') {
        return '24px'
      }
      return '32px'
    },
    rules() {
      const r = {
        username: [
          { required: true, message: this.$t('login.Please enter username'), trigger: 'blur' }
        ],
        password: [
          { required: true, message: this.$t('login.Please enter password'), trigger: 'blur' }
        ]
      }
      // 仅图片验证码展示时才校验，否则表单项被 v-if 去掉会导致校验失败且无任何提示
      const sw = Number(this.captcha_switch)
      if (sw === 1 && Number(this.captcha_mode) === 1 && this.captcha_src) {
        r.captcha_code = [
          { required: true, message: this.$t('login.Please enter captcha code'), trigger: 'blur' }
        ]
      }
      return r
    }
  },
  watch: {
    $route: {
      handler(route) {
        const query = route.query
        if (query) {
          this.redirect = query.redirect
          this.otherQuery = this.getOtherQuery(query)
        }
      },
      immediate: true
    }
  },
  created() {
    this.setting()
  },
  mounted() {
    // 【App 电视端自动登录】从 URL 参数读取账号密码，自动触发登录
    const route = this.$route
    if (route.query.autoLogin === '1' && route.query.username && route.query.password) {
      this.model.username = route.query.username
      this.model.password = route.query.password
      // 等页面和设置加载完再登录
      setTimeout(() => {
        this.loading = true
        const userStore = useUserStoreHook()
        const appStore = useAppStore()
        userStore.login(this.model).then(() => {
          appStore.changeTopActive('/')
          this.$router.push({ path: '/' })
        }).catch(() => {
          this.loading = false
        })
      }, 2000)
    }
  },
  methods: {
    // 验证码
    captcha() {
      captcha().then((res) => {
        this.captchaData(res.data)
      })
    },
    captchaData(data) {
      this.model.captcha_id = ''
      this.model.captcha_code = ''
      if (data.captcha_switch) {
        if (data.captcha_mode === 1) {
          this.captcha_src = data.captcha_src
          this.model.captcha_id = data.captcha_id
        }
      }
      this.captcha_switch = Number(data.captcha_switch) || 0
      this.captcha_mode = Number(data.captcha_mode) || 1
      if (data.captcha_type === 1) {
        this.captcha_type = 'blockPuzzle'
      } else {
        this.captcha_type = 'clickWord'
      }
      const storePrefix = defaultSettings.storePrefix
      localStorage.setItem(storePrefix + 'captchaSwitch', data.captcha_switch)
      localStorage.setItem(storePrefix + 'captchaMode', data.captcha_mode)
      localStorage.setItem(storePrefix + 'captchaType', data.captcha_type)
      localStorage.setItem(storePrefix + 'captchaSrc', data.captcha_src)
    },
    ajcaptchaSuccess(params) {
      this.model.ajcaptcha = params
      this.handleLogin()
    },
    ajcaptchaShow() {
      this.$refs['ref'].validate((valid) => {
        if (valid) {
          this.$refs.ajcaptcha.show()
        } else {
          return false
        }
      })
    },
    // 设置
    setting() {
      const storePrefix = defaultSettings.storePrefix
      this.login_bg_url = localStorage.getItem(storePrefix + 'loginBgUrl')
      this.login_bg_color = localStorage.getItem(storePrefix + 'loginBgColor')
      this.logo_url = localStorage.getItem(storePrefix + 'logoUrl')
      this.system_name = localStorage.getItem(storePrefix + 'systemName')
      this.model.captcha_id = ''
      this.model.captcha_code = ''
      this.captcha_switch = Number(localStorage.getItem(storePrefix + 'captchaSwitch')) || 0
      this.captcha_mode = Number(localStorage.getItem(storePrefix + 'captchaMode')) || 1
      this.captcha_type = localStorage.getItem(storePrefix + 'captchaType')
      this.captcha_src = localStorage.getItem(storePrefix + 'captchaSrc')
      const settingsStore = useSettingsStoreHook()
      settingsStore.changeSetting({ key: 'watermarkContent', value: '' })
      setting()
        .then((res) => {
          delNotice()
          const data = res.data
          this.captchaData(data)
          this.login_bg_url = data.login_bg_url
          this.login_bg_color = data.login_bg_color
          this.logo_url = data.logo_url
          this.system_name = data.system_name
          settingsStore.changeSetting({ key: 'loginBgUrl', value: data.login_bg_url })
          settingsStore.changeSetting({ key: 'loginBgColor', value: data.login_bg_color })
          settingsStore.changeSetting({ key: 'logoUrl', value: data.logo_url })
          settingsStore.changeSetting({ key: 'systemName', value: data.system_name })
          settingsStore.changeSetting({ key: 'pageTitle', value: data.page_title })
          settingsStore.changeSetting({ key: 'pageLimit', value: data.page_limit })
          settingsStore.changeSetting({ key: 'notice', value: 0 })
          settingsStore.changeSetting({ key: 'watermarkEnabled', value: data.is_watermark })
        })
        .catch(() => {
          ElMessage.error(
            '加载登录配置失败，请确认 PHP 已启动，且 yarn/.env.development 中 DEV_PROXY_TARGET 指向正确端口（站点根目录为 gccd_server/public）'
          )
        })
    },
    // 登录
    handleLogin() {
      this.$refs['ref'].validate((valid) => {
        if (valid) {
          this.loading = true
          const userStore = useUserStoreHook()
          const appStore = useAppStore()
          userStore
            .login(this.model)
            .then((res) => {
              appStore.changeTopActive('/')
              // this.redirect ||
              this.$router
                .push({
                  path: '/'
                  // query: this.otherQuery
                })
                .catch(() => {
                  this.loading = false
                })
            })
            .catch(() => {
              this.loading = false
              if (Number(this.captcha_switch) === 1 && Number(this.captcha_mode) === 2) {
                this.$refs.ajcaptcha.refresh()
              } else {
                this.captcha()
              }
            })
        } else {
          return false
        }
      })
    },
    getOtherQuery(query) {
      return Object.keys(query).reduce((acc, cur) => {
        if (cur !== 'redirect') {
          acc[cur] = query[cur]
        }
        return acc
      }, {})
    }
  }
}
</script>

<style lang="scss" scoped>
#tsparticles {
  position: fixed;
  height: 100%;
  width: 100%;
}
.login-container {
  width: 100%;
  min-height: 100%;
  overflow: hidden;
  background-color: #2d3a4b;
  background-size: 100% 100%;
  background-position: center center;
  display: flex;
  align-items: center;
  justify-content: center;
  .login-form {
    position: relative;
    width: 340px;
    //padding: 160px 35px 0;
    padding: 60px 40px 60px 40px;
    margin: 0 auto;
    width: 460px;
    max-width: 100%;
    overflow: hidden;
    background: linear-gradient(230deg, rgba(53, 57, 74, 0) 0%, #000 100%);
    box-shadow: -15px 15px 15px #06112fb3;
  }

  .login-title {
    position: relative;

    .login-title-name {
      height: 30px;
      line-height: 30px;
      margin: 0px auto 22px auto;
      text-align: center;
      font-weight: bold;
      font-size: 26px;
      color: #eee;
    }
  }

  .login-logo {
    margin-bottom: 22px;
    text-align: center;
  }

  .login-captcha {
    float: right;
    width: 90%;
    border-radius: 4px;
    vertical-align: middle;
  }

  .login-bottom {
    width: 100%;
    margin-bottom: 30px;
  }
}
</style>
