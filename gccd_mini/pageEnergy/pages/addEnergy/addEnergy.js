const util = require("../../../utils/util.js")
import Toast from "@vant/weapp/toast/toast"

Page({
  data: {
    statusBarHeight: 0,
    loading: false,
    submitting: false,
    showSubmitSuccessDialog: false,
    showDeviceSelect: false,
    showClassSelect: false,
    deviceList: [], // 设备列表
    selectedDevice: null, // 选中的设备
    classOptions: [
      {
        text: "白班",
        value: "0",
      },
      {
        text: "夜班",
        value: "1",
      },
    ],
    formData: {
      power_num: "", // 电表数
      gas_num: "", // 气表数
      class: "0", // 班次，默认白班
      remark: "", // 备注
      device_code: "", // 设备编码
      power_num_south: "", // 南电表数（双电表设备）
      power_num_north: "", // 北电表数（双电表设备）
      phosphating: "", // 酸洗车间：磷化吨数
      saponification: "", // 酸洗车间：皂化吨数
      alkali: "", // 酸洗车间：碱吨数
    },
    isDualMeterDevice: false,
    isGasReadonly: true,
    /** 设备为「酸洗车间」时显示磷化/皂化/碱 */
    isAcidWashWorkshop: false,
  },

  gasReadonlyDevices: ["球化南1", "球化北", "穿孔40机", "穿孔50机"],

  // 返回上一页
  navigateBack() {
    wx.navigateBack({
      delta: 1,
    })
  },

  // 生命周期函数
  onLoad(options) {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({
          statusBarHeight: res.statusBarHeight,
        })
      },
    })
    // 获取设备列表
    this.getDeviceList()
  },

  // 获取设备列表
  getDeviceList() {
    Toast.loading({
      message: "加载中...",
      forbidClick: true,
      duration: 0,
    })

    util
      .request(
        "admin/repair.Device/list",
        {
          limit: 1000,
          page: 1,
        },
        "POST",
      )
      .then((res) => {
        Toast.clear()
        if (res.data.code === 200) {
          this.setData(
            {
              deviceList: res.data.data.list || [],
            },
            () => {
              console.log(this.data.deviceList)
            },
          )
        } else {
          Toast.fail(res.data.msg || "获取设备列表失败")
        }
      })
      .catch((err) => {
        Toast.clear()
        Toast.fail("接口请求失败")
        console.error(err)
      })
  },

  // 显示设备选择器
  showDevicePicker() {
    if (this.data.deviceList.length === 0) {
      Toast("暂无设备数据")
      return
    }
    this.setData({
      showDeviceSelect: true,
    })
  },

  // 设备选择确认（picker 在部分模式下 detail.value 为单列对象，多列时为数组，需统一成设备对象）
  onDeviceConfirm(event) {
    const detail = event.detail || {}
    let value = detail.value
    if (Array.isArray(value)) {
      value = value[0]
    }
    if (!value || typeof value !== "object") {
      Toast.fail("请选择设备")
      return
    }
    const deviceName = value.device_name
    const isAcidWash = deviceName === "酸洗车间"
    const updates = {
      selectedDevice: value,
      "formData.device_code": value.device_code || "",
      showDeviceSelect: false,
      isDualMeterDevice: ["穿孔40机", "穿孔50机"].includes(deviceName),
      isGasReadonly: this.gasReadonlyDevices.includes(deviceName),
      isAcidWashWorkshop: isAcidWash,
    }
    if (!isAcidWash) {
      updates["formData.phosphating"] = ""
      updates["formData.saponification"] = ""
      updates["formData.alkali"] = ""
    }
    this.setData(updates, () => {
      this.getGasNumber(deviceName)
    })
  },

  // 根据设备名称获取气表数
  getGasNumber(deviceName) {
    Toast.loading({
      message: "获取气表数中...",
      forbidClick: true,
      duration: 0,
    })

    util
      .request(
        "admin/production.ProductReport/getPower",
        {
          device_name: deviceName,
        },
        "POST",
      )
      .then((res) => {
        Toast.clear()
        if (res.data.code === 200) {
          console.log(res.data.data)
          this.setData({
            "formData.gas_num": res.data.data || "",
          })
        } else {
          Toast.fail(res.data.msg || "获取气表数失败")
          // 获取失败时清空气表数
          this.setData({
            "formData.gas_num": "",
          })
        }
      })
      .catch((err) => {
        Toast.clear()
        Toast.fail("接口请求失败")
        console.error(err)
        // 获取失败时清空气表数
        this.setData({
          "formData.gas_num": "",
        })
      })
  },

  // 关闭设备选择器
  onDeviceSelectClose() {
    this.setData({
      showDeviceSelect: false,
    })
  },

  // 显示班次选择器
  showClassPicker() {
    this.setData({
      showClassSelect: true,
    })
  },

  // 班次选择确认
  onClassConfirm(event) {
    const { value } = event.detail
    this.setData({
      "formData.class": value.value,
      showClassSelect: false,
    })
  },

  // 关闭班次选择器
  onClassSelectClose() {
    this.setData({
      showClassSelect: false,
    })
  },

  // 输入框变化
  onInputChange(event) {
    const { field } = event.currentTarget.dataset
    if (field === "gas_num" && this.data.isGasReadonly) {
      return
    }
    const value = event.detail
    const acidTonFields = ["phosphating", "saponification", "alkali"]
    if (acidTonFields.includes(field)) {
      const isValid = /^\d*\.?\d*$/.test(value) || value === ""
      if (!isValid) {
        wx.showToast({
          title: "请输入有效的数字（如 10 或 10.5）",
          icon: "none",
          duration: 2000,
        })
        return
      }
    }
    this.setData({
      [`formData.${field}`]: value,
    })
  },

  // 验证表单
  validateForm() {
    if (!this.data.selectedDevice) {
      Toast.fail("请选择设备")
      return false
    }

    if (this.data.isDualMeterDevice) {
      // 南北电表设备，验证南北电表数
      if (!this.data.formData.power_num_south || isNaN(this.data.formData.power_num_south)) {
        Toast.fail("请输入有效的南电表数")
        return false
      }
      if (!this.data.formData.power_num_north || isNaN(this.data.formData.power_num_north)) {
        Toast.fail("请输入有效的北电表数")
        return false
      }
    } else {
      // 普通设备，验证单个电表数
      if (!this.data.formData.power_num || isNaN(this.data.formData.power_num)) {
        Toast.fail("请输入有效的电表数")
        return false
      }
    }

    // 气表数现在由系统获取，不需要验证
    // if (!this.data.formData.gas_num || isNaN(this.data.formData.gas_num)) {
    //     Toast.fail('请输入有效的气表数');
    //     return false;
    // }

    if (!this.data.formData.class) {
      Toast.fail("请选择班次")
      return false
    }

    return true
  },

  // 提交能耗数据
  onSubmitSuccessConfirm() {
    this._submitLock = false
    this.setData({
      submitting: false,
      showSubmitSuccessDialog: false,
    })
    wx.navigateBack({
      delta: 1,
    })
  },

  onSubmitSuccessDialogClose() {
    this._submitLock = false
    this.setData({
      showSubmitSuccessDialog: false,
      submitting: false,
    })
  },

  submitEnergy() {
    if (this._submitLock || this.data.submitting) {
      return
    }
    if (!this.validateForm()) {
      return
    }

    this._submitLock = true
    this.setData({
      loading: true,
      submitting: true,
    })
    wx.showLoading({
      title: "提交中...",
      mask: true,
    })

    let params
    if (this.data.isDualMeterDevice) {
      // 南北电表设备，构造包含两个方向的数组
      params = {
        list: [
          {
            device_code: this.data.formData.device_code,
            power_num: this.data.formData.power_num_south,
            gas_num: this.data.formData.gas_num,
            class: this.data.formData.class,
            remark: this.data.formData.remark + "(南)",
            direction: "南",
          },
          {
            device_code: this.data.formData.device_code,
            power_num: this.data.formData.power_num_north,
            gas_num: this.data.formData.gas_num,
            class: this.data.formData.class,
            remark: this.data.formData.remark + "(北)",
            direction: "北",
          },
        ],
      }
    } else {
      // 普通设备，使用 list: [{}] 传递
      const row = {
        power_num: this.data.formData.power_num,
        gas_num: this.data.formData.gas_num,
        class: this.data.formData.class,
        remark: this.data.formData.remark,
        device_code: this.data.formData.device_code,
        routing_code: 1,
        processes_code: 1,
      }
      if (this.data.isAcidWashWorkshop) {
        row.phosphating = this.data.formData.phosphating
        row.saponification = this.data.formData.saponification
        row.alkali = this.data.formData.alkali
      }
      params = {
        list: [row],
      }
    }

    console.log(params)

    util
      .request("admin/production.ProductReport/reportpower", params, "POST")
      .then((res) => {
        if (res.data.code === 200) {
          this.setData({
            loading: false,
            showSubmitSuccessDialog: true,
          })
        } else {
          this._submitLock = false
          Toast.fail(res.data.msg || "上报失败")
          this.setData({
            loading: false,
            submitting: false,
          })
        }
      })
      .catch((err) => {
        this._submitLock = false
        Toast.fail(
          "接口请求失败，若网络不稳定可能已提交成功，请到列表核对后再试",
        )
        console.error(err)
        this.setData({
          loading: false,
          submitting: false,
        })
      })
      .finally(() => {
        wx.hideLoading()
      })
  },
})
