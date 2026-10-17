<script setup>
import {computed,onBeforeUnmount,ref} from 'vue';
import {useRouter} from 'vue-router';
import {usePortal} from './session.js';
import {landingRoute} from '../../router/access.js';
import {newPasswordError} from './password-reset.js';
import {posBrandStyle} from '../../layouts/pos-brand.js';
import FormField from '../accounts/FormField.vue';
import '../accounts/accounts.css';
const {session}=usePortal(),router=useRouter();
const password=ref(''),confirmation=ref(''),busy=ref(false),error=ref(''),errors=ref({});
const controller=new AbortController();
const brandStyle=computed(()=>posBrandStyle(session.state.identity?.brand?.color||session.state.identity?.account?.color));
async function save(){
  if(busy.value)return;
  error.value=newPasswordError(password.value,confirmation.value);errors.value={};if(error.value)return;
  busy.value=true;
  try{
    await session.completePasswordReset(password.value,confirmation.value,session.state.identity.password_reset.version,controller.signal);
    password.value='';confirmation.value='';
    await router.replace(landingRoute(router,session));
  }catch(failure){
    if(failure.name!=='AbortError'){
      errors.value=failure.errors||{};error.value=Object.values(errors.value).flat()[0]||failure.message;
      if(failure.status===409)await session.refresh();
    }
  }finally{busy.value=false;}
}
async function signOut(){if(busy.value)return;busy.value=true;try{await session.logout();await router.replace({name:'login'});}catch(failure){error.value=failure.message;}finally{busy.value=false;}}
onBeforeUnmount(()=>{controller.abort();password.value='';confirmation.value='';});
</script>
<template>
  <main class="password-change-page account-workspace" :class="{'pos-password-change':session.state.identity?.account.type==='pos'}" :style="brandStyle" dir="rtl">
    <section class="card password-change-card" aria-labelledby="password-change-title">
      <div class="dialog-heading-main"><span class="dialog-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg></span><div><span class="dialog-section">حماية حسابك</span><h1 id="password-change-title">تعيين كلمة مرور جديدة</h1></div></div>
      <p class="help">طلب المسؤول تغيير كلمة مرور حسابك. أدخل كلمة جديدة وأكدها للمتابعة.</p>
      <form @submit.prevent="save">
        <fieldset class="formgrid" :disabled="busy"><FormField v-model="password" name="required-password" label="كلمة المرور الجديدة" type="password" required :minlength="9" :maxlength="72" full hint="9 خانات على الأقل؛ الحروف اختيارية." :error="errors.password"/><FormField v-model="confirmation" name="required-confirmation" label="تأكيد كلمة المرور" type="password" required :minlength="9" :maxlength="72" full :error="errors.password_confirmation"/></fieldset>
        <p v-if="error" class="notice notice-error" role="alert">{{error}}</p>
        <footer class="formfoot"><button class="btn primary" :disabled="busy">{{busy?'جارٍ الحفظ…':'حفظ كلمة المرور والمتابعة'}}</button><button type="button" class="btn" :disabled="busy" @click="signOut">تسجيل الخروج</button></footer>
      </form>
    </section>
  </main>
</template>
<style scoped>
.password-change-page{min-height:100dvh;display:grid;place-items:center;padding:24px 16px;background:var(--raised);color:var(--ink)}.password-change-page .password-change-card{width:min(100%,520px);padding:28px;display:grid;gap:20px;border-radius:22px}.password-change-card h1{font-size:21px}.password-change-card .dialog-heading-main{display:flex;align-items:center;gap:14px}.password-change-card .dialog-icon{width:46px;height:46px;display:grid;place-items:center;background:var(--pos-soft-light);border-radius:14px;color:var(--pos-link-light)}.password-change-card svg{width:25px;height:25px}.password-change-card .dialog-section,.password-change-card .help{color:var(--muted);font-size:13px}.password-change-card .formgrid{display:grid;gap:16px;border:0;padding:0;margin:0}.password-change-card :deep(.form-field){display:grid;gap:7px}.password-change-card :deep(small){color:var(--muted);font-size:12px}.password-change-card .formfoot{margin-top:18px;display:flex;gap:10px;flex-wrap:wrap}.password-change-card .btn.primary{background:var(--pos-brand);border-color:var(--pos-brand);color:var(--pos-brand-ink)}@media(max-width:480px){.password-change-page .password-change-card{padding:22px 18px}.password-change-card .formfoot{display:grid}.password-change-card .formfoot .btn{width:100%}}
.pos-password-change{--panel:#fff;--raised:#F5F7F6;--ink:#173239;--text:#173239;--muted:#627A80;--line:#D9E4E7}:global(html[data-theme='dark']) .pos-password-change{--panel:#142329;--raised:#0D171B;--ink:#ECF2F3;--text:#C4D4D8;--muted:#8DA4AC;--line:#29414A}:global(html[data-theme='dark']) .password-change-card .dialog-icon{background:var(--pos-soft-dark);color:var(--pos-link-dark)}
</style>
