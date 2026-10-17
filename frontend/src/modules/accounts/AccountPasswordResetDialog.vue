<script setup>
import {computed,onBeforeUnmount,ref} from 'vue';
import AccountModal from './AccountModal.vue';
import FormField from './FormField.vue';
import {useAccountAccess} from './use-account-access.js';
import {canResetAccountPassword} from '../auth/password-reset.js';
const props=defineProps({account:Object}),emit=defineEmits(['close','saved']);
const {session,handleFailure}=useAccountAccess();
const reason=ref(''),busy=ref(false),error=ref(''),errors=ref({}),complete=ref(false);
const requestId=ref('auto'),requests=computed(()=>props.account.password_reset_requests||[]);
const selectDevice=computed(()=>requests.value.length>1||requests.value.some(value=>value.trusted!==true));
const devices=computed(()=>[{value:'auto',label:requests.value.some(value=>value.trusted)?'الجهاز المعروف تلقائيًا':'الجلسات المسجلة فقط'},...requests.value.map(value=>({value:value.id,label:`${value.device_label||'جهاز'} — ${value.request_label}${value.trusted?' — معروف':' — طلب جديد'}`}))]);
const controller=new AbortController();
async function save(){
  if(busy.value||complete.value||!canResetAccountPassword(session.state.identity,props.account))return;
  if(!reason.value.trim()){error.value='أدخل سبب إعادة تعيين كلمة المرور.';return;}
  busy.value=true;error.value='';errors.value={};
  try{
    await session.api.resetAccountPassword(props.account.id,{version:props.account.version,reason:reason.value.trim(),...(requestId.value!=='auto'?{request_id:requestId.value}:{})},controller.signal);
    complete.value=true;
  }catch(failure){if(failure.name!=='AbortError'){errors.value=failure.errors||{};error.value=Object.values(errors.value).flat()[0]||await handleFailure(failure);}}
  finally{busy.value=false;}
}
onBeforeUnmount(()=>controller.abort());
</script>
<template>
  <AccountModal title="إعادة تعيين كلمة المرور" :busy="busy" @close="complete?emit('saved'):emit('close')">
    <template v-if="complete"><p class="notice" role="status">تم طلب تغيير كلمة المرور. سيختار صاحب الحساب كلمة جديدة ويؤكدها داخل التطبيق.</p><footer class="formfoot"><button type="button" class="btn primary" @click="emit('saved')">إغلاق</button></footer></template>
    <form v-else @submit.prevent="save">
      <p class="help">سيُطلب من صاحب حساب «{{account.name}}» إدخال كلمة مرور جديدة وتأكيدها داخل التطبيق.</p>
      <fieldset class="formgrid" :disabled="busy"><FormField v-if="selectDevice" v-model="requestId" name="password-reset-device" label="الجهاز المطلوب" :options="devices" full hint="للجهاز الجديد اختر طلبه بعد التأكد من صاحب الحساب." :error="errors.request_id"/><FormField v-model="reason" name="password-reset-reason" label="سبب إعادة التعيين" type="textarea" required :minlength="3" :maxlength="500" full :error="errors.reason"/></fieldset>
      <p v-if="error" class="notice notice-error" role="alert">{{error}}</p>
      <footer class="formfoot"><button type="button" class="btn" :disabled="busy" @click="emit('close')">إلغاء</button><button class="btn primary" :disabled="busy||!reason.trim()">{{busy?'جارٍ التأكيد…':'تأكيد إعادة التعيين'}}</button></footer>
    </form>
  </AccountModal>
</template>
