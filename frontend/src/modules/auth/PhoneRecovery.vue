<script setup>
import { ref } from 'vue';
import { usePortal } from './session.js';
import FinanceDialog from '../finance/FinanceDialog.vue';
import '../finance/finance-parity.css';
const emit = defineEmits(['close', 'complete']);
const { session } = usePortal();
const phone = ref(''), code = ref(''), password = ref(''), confirmation = ref('');
const challenge = ref(''), busy = ref(false), error = ref(''), testMode = ref(false);
async function submit() {
  if (busy.value) return;
  busy.value = true; error.value = '';
  try {
    if (!challenge.value) {
      const response = await session.api.mutate('/auth/recovery/phone', 'POST', { phone: phone.value });
      challenge.value = response.data.challenge;
      testMode.value = response.data.test_mode === true;
    } else {
      await session.api.mutate('/auth/recovery/reset', 'POST', { challenge: challenge.value, code: code.value, password: password.value, password_confirmation: confirmation.value });
      password.value = ''; confirmation.value = '';
      emit('complete');
    }
  } catch (failure) {
    error.value = failure.status === 503 ? 'خدمة استعادة كلمة المرور عبر الهاتف لم تُفعّل بعد.'
      : failure.status === 429 ? 'المحاولات كثيرة. انتظر قليلًا ثم حاول مجددًا.'
      : Object.values(failure.errors || {}).flat()[0] || failure.message;
  } finally { busy.value = false; }
}
function restart() { challenge.value = ''; code.value = ''; password.value = ''; confirmation.value = ''; error.value = ''; }
</script>
<template>
  <FinanceDialog :open="true" title="استعادة كلمة المرور" :busy="busy" @close="emit('close')">
    <form class="phone-recovery-form" @submit.prevent="submit">
      <p>أدخل رقم الهاتف المسجل بحسابك.</p>
      <label>رقم الهاتف<input v-model="phone" type="tel" autocomplete="tel" inputmode="tel" dir="ltr" maxlength="40" required :disabled="busy || !!challenge" /></label>
      <template v-if="challenge">
        <p v-if="testMode" role="status">وضع التجربة: رمز التحقق 123456. لا يتم إرسال رسالة SMS حاليًا.</p>
        <label>رمز التحقق<input v-model="code" autocomplete="one-time-code" inputmode="numeric" dir="ltr" pattern="[0-9]{6}" maxlength="6" required :disabled="busy" /></label>
        <label>كلمة المرور الجديدة<input v-model="password" type="password" autocomplete="new-password" minlength="9" maxlength="128" required :disabled="busy" /></label>
        <label>تأكيد كلمة المرور<input v-model="confirmation" type="password" autocomplete="new-password" minlength="9" maxlength="128" required :disabled="busy" /></label>
      </template>
      <p v-if="error" class="login-error" role="alert">{{ error }}</p>
      <div class="phone-recovery-actions"><button class="btn primary" type="submit" :disabled="busy">{{ busy ? 'جارٍ التحقق…' : challenge ? 'تغيير كلمة المرور' : 'متابعة' }}</button><button v-if="challenge" class="btn" type="button" :disabled="busy" @click="restart">طلب رمز جديد</button></div>
    </form>
  </FinanceDialog>
</template>
<style scoped>
.phone-recovery-form{display:grid;gap:16px}.phone-recovery-form label{display:grid;gap:8px}.phone-recovery-form input{width:100%;box-sizing:border-box;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text)}.phone-recovery-actions{display:flex;flex-wrap:wrap;gap:10px}
</style>
