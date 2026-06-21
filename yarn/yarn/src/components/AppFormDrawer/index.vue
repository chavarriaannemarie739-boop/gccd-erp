<template>
  <el-drawer
    class="app-form-drawer"
    :model-value="modelValue"
    direction="rtl"
    :size="size"
    :title="title"
    :close-on-click-modal="false"
    :destroy-on-close="destroyOnClose"
    v-bind="$attrs"
    @update:model-value="onUpdate"
  >
    <slot />
  </el-drawer>
</template>

<script>
/**
 * 统一「侧拉大抽屉」交互：标题/正文留白与出库列表新增一致。
 * v-model 绑定是否显示；内容放在默认插槽。
 */
export default {
  name: 'AppFormDrawer',
  inheritAttrs: false,
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    title: {
      type: String,
      default: ''
    },
    size: {
      type: String,
      default: '92%'
    },
    destroyOnClose: {
      type: Boolean,
      default: true
    }
  },
  emits: ['update:modelValue'],
  methods: {
    onUpdate(val) {
      this.$emit('update:modelValue', val)
    }
  }
}
</script>
