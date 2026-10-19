<script setup>
import {computed,onBeforeUnmount,onMounted,ref} from 'vue';
import AccountModal from './AccountModal.vue';
import FormField from './FormField.vue';
import {useAccountAccess} from './use-account-access.js';
import {canResetAccountPassword} from '../auth/password-reset.js';
const props=defineProps({account:Object,staff:Object}),emit=defineEmits(['close','saved']);
const {session,handleFailure}=useAccountAccess();
const reason=ref(''),busy=ref(false),error=ref(''),errors=ref({}),complete=ref(false);
const accountState=ref(null),staffState=ref(null),requestId=ref('auto'),ready=ref(false),automaticRequestId=ref(null),approvedRequest=ref(null);
const current=computed(()=>props.staff?staffState.value:accountState.value),requests=computed(()=>current.value?.password_reset_requests||[]);
const trustedRequests=computed(()=>requests.value.filter(value=>value.trusted===true));
const automaticRequest=computed(()=>trustedRequests.value.length===1&&trustedRequests.value[0].id===automaticRequestId.value?trustedRequests.value[0]:null);
const allowed=computed(()=>props.staff?!!staffState.value&&session.can('staff.password_reset'):canResetAccountPassword(session.state.identity,accountState.value));
const staffPath=()=>`/accounts/${props.account.id}/staff/${props.staff.id}/password-reset`;
const selectionError=computed(()=>{
  if(!ready.value)return '';
  if(requestId.value!=='auto')return requests.value.some(value=>value.id===requestId.value)?'':'طلب الجهاز المختار لم يعد متاحًا. حدّث الطلبات واختر طلبًا جديدًا.';
  if(trustedRequests.value.length>1)return 'توجد عدة أجهزة معروفة. اختر طلب الجهاز المقصود قبل التأكيد.';
  if(automaticRequestId.value&&!automaticRequest.value)return 'طلب الجهاز المعروف تغيّر أو لم يعد متاحًا. اختر طلب الجهاز المقصود من جديد.';
  return requests.value.length&&!automaticRequest.value?'اختر طلب الجهاز المقصود لاعتماد الاستعادة على هذا الجهاز.':'';
});
const selectDevice=computed(()=>requests.value.length>0||requestId.value!=='auto'||!!automaticRequestId.value);
const devices=computed(()=>{
  const options=[{value:'auto',label:automaticRequest.value?`الجهاز المعروف تلقائيًا — ${automaticRequest.value.request_label}`:selectDevice.value?'اختر طلب الجهاز المقصود':'الجلسات المسجلة فقط'},...requests.value.map(value=>({value:value.id,label:`${value.device_label||'جهاز'} — ${value.request_label}${value.trusted?' — معروف':' — طلب جديد'}`}))];
  if(requestId.value!=='auto'&&!requests.value.some(value=>value.id===requestId.value))options.push({value:requestId.value,label:'الطلب المختار لم يعد متاحًا'});
  return options;
});
const controller=new AbortController();
let alive=true,loaded=false;
async function readRequests(){
  const response=await session.api.request(props.staff?staffPath():`/accounts/${props.account.id}`,{signal:controller.signal,fresh:true});
  if(!alive)return;
  if(props.staff)staffState.value=response.data;else accountState.value=response.data;
  if(!loaded){automaticRequestId.value=trustedRequests.value.length===1?trustedRequests.value[0].id:null;loaded=true;}
  ready.value=true;
}
async function refreshRequests(){
  if(busy.value||complete.value)return;busy.value=true;error.value='';errors.value={};
  try{await readRequests();}catch(failure){if(failure.name!=='AbortError'){ready.value=false;error.value=await handleFailure(failure);}}
  finally{if(alive)busy.value=false;}
}
onMounted(refreshRequests);
async function save(){
  if(busy.value||complete.value||!ready.value||!allowed.value)return;
  if(!reason.value.trim()){error.value='أدخل سبب إعادة تعيين كلمة المرور.';return;}
  const expectedRequestId=requestId.value;
  busy.value=true;error.value='';errors.value={};
  try{
    ready.value=false;await readRequests();if(!alive)return;
    if(!allowed.value)throw Error('إعادة تعيين كلمة المرور لم تعد متاحة لهذا الحساب.');
    if(requestId.value!==expectedRequestId)throw Error('تغيّر اختيار الجهاز. راجع الطلب ثم أكّد من جديد.');
    if(selectionError.value){errors.value={request_id:selectionError.value};error.value=selectionError.value;return;}
    const approved=requestId.value==='auto'?automaticRequest.value:requests.value.find(value=>value.id===requestId.value);
    const payload={version:current.value.version,reason:reason.value.trim(),...(approved?{request_id:approved.id}:{})};
    if(props.staff)await session.api.mutate(staffPath(),'POST',payload,controller.signal);else await session.api.resetAccountPassword(props.account.id,payload,controller.signal);
    if(alive){approvedRequest.value=approved?{request_label:approved.request_label,device_label:approved.device_label}:null;complete.value=true;}
  }catch(failure){if(failure.name!=='AbortError'){errors.value=failure.errors||{};error.value=Object.values(errors.value).flat()[0]||await handleFailure(failure);}}
  finally{if(alive)busy.value=false;}
}
onBeforeUnmount(()=>{alive=false;controller.abort();});
</script>
<template>
  <AccountModal title="إعادة تعيين كلمة المرور" :busy="busy" @close="complete?emit('saved'):emit('close')">
    <template v-if="complete"><p class="notice" role="status">تم طلب تغيير كلمة المرور. سيختار صاحب الحساب كلمة جديدة ويؤكدها داخل التطبيق.</p><p v-if="approvedRequest" class="help">تم اعتماد طلب الجهاز: {{approvedRequest.device_label||'جهاز'}} — <b dir="ltr">{{approvedRequest.request_label}}</b>. الموافقة لهذا الجهاز فقط؛ افتح الاستعادة عليه لاختيار كلمة جديدة.</p><p v-else class="help">إذا كان صاحب الحساب خارج التطبيق، أنشئ طلب استعادة من جهازه ثم اختر مرجع ذلك الطلب للموافقة عليه.</p><p class="help">كلمة المرور لا تُرسل برسالة نصية أو بريد إلكتروني.</p><footer class="formfoot"><button type="button" class="btn primary" @click="emit('saved')">إغلاق</button></footer></template>
    <form v-else @submit.prevent="save">
      <p class="help">سيُطلب من صاحب حساب «{{staff?.name||account.name}}» إدخال كلمة مرور جديدة وتأكيدها داخل التطبيق.</p>
      <p class="help">الموافقة مرتبطة بالجهاز المختار. كلمة المرور لا تُرسل برسالة نصية أو بريد إلكتروني.</p>
      <button type="button" class="btn small" :disabled="busy" @click="refreshRequests">تحديث طلبات الأجهزة</button>
      <p class="help">بعد إنشاء طلب استعادة جديد من جهاز صاحب الحساب، حدّث الطلبات واختر مرجعه.</p>
      <fieldset class="formgrid" :disabled="busy||!ready||!allowed"><FormField v-if="selectDevice" v-model="requestId" name="password-reset-device" label="الجهاز المطلوب" :options="devices" full hint="للجهاز الجديد اختر طلبه بعد التأكد من صاحب الحساب." :error="errors.request_id||selectionError"/><FormField v-model="reason" name="password-reset-reason" label="سبب إعادة التعيين" type="textarea" required :minlength="3" :maxlength="500" full :error="errors.reason"/></fieldset>
      <p v-if="error" class="notice notice-error" role="alert">{{error}}</p>
      <footer class="formfoot"><button type="button" class="btn" :disabled="busy" @click="emit('close')">إلغاء</button><button class="btn primary" :disabled="busy||!ready||!allowed||!reason.trim()||!!selectionError">{{busy?'جارٍ التأكيد…':'تأكيد إعادة التعيين'}}</button></footer>
    </form>
  </AccountModal>
</template>
