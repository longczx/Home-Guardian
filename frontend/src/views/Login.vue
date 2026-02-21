<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import {
  NCard,
  NForm,
  NFormItem,
  NInput,
  NButton,
  NSpace,
  useMessage,
  type FormRules,
  type FormInst,
} from 'naive-ui'
import { useAuthStore } from '@/stores/auth'
import { loginApi } from '@/api/auth'
import { useAppStore } from '@/stores/app'

const router = useRouter()
const message = useMessage()
const authStore = useAuthStore()
const appStore = useAppStore()

const formRef = ref<FormInst | null>(null)
const loading = ref(false)

const form = reactive({
  username: '',
  password: '',
})

const rules: FormRules = {
  username: { required: true, message: '请输入用户名', trigger: 'blur' },
  password: { required: true, message: '请输入密码', trigger: 'blur' },
}

async function handleLogin() {
  try {
    await formRef.value?.validate()
  } catch {
    return
  }

  loading.value = true
  try {
    const res = await loginApi(form)
    authStore.setTokens(res.data.access_token, res.data.refresh_token)
    message.success('登录成功')
    router.push('/')
  } catch (err: any) {
    message.error(err.response?.data?.message || '登录失败')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div
    class="min-h-screen flex items-center justify-center p-4"
    :style="{ background: appStore.isDark ? '#101014' : '#f0f2f5' }"
  >
    <div class="w-full max-w-[400px]">
      <div class="text-center mb-8">
        <div class="flex items-center justify-center gap-3 mb-3">
          <div
            class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl"
            style="background: linear-gradient(135deg, #18a058, #2080f0)"
          >
            🏠
          </div>
          <h1 class="text-3xl font-bold" :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }">
            Home Guardian
          </h1>
        </div>
        <p :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.45)' }">
          智能家居守护平台
        </p>
      </div>

      <NCard
        :style="{
          backgroundColor: appStore.cardBg,
          backdropFilter: 'blur(12px)',
          borderRadius: '12px',
          border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.08)',
        }"
      >
        <NForm ref="formRef" :model="form" :rules="rules" size="large">
          <NFormItem path="username" label="用户名">
            <NInput
              v-model:value="form.username"
              placeholder="请输入用户名"
              @keyup.enter="handleLogin"
            />
          </NFormItem>
          <NFormItem path="password" label="密码">
            <NInput
              v-model:value="form.password"
              type="password"
              show-password-on="click"
              placeholder="请输入密码"
              @keyup.enter="handleLogin"
            />
          </NFormItem>
          <NButton
            type="primary"
            block
            strong
            :loading="loading"
            @click="handleLogin"
            class="mt-2"
          >
            登 录
          </NButton>
        </NForm>
      </NCard>
    </div>
  </div>
</template>
