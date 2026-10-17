<script setup>
import {computed,onBeforeUnmount,onMounted,ref} from 'vue';
import {usePortal} from './session.js';
import AccountModal from '../accounts/AccountModal.vue';
import FormField from '../accounts/FormField.vue';
import PhoneRecovery from './PhoneRecovery.vue';
import {bootstrapPasswordRecoveryDevice,createRecoveryDeviceAttempt,newPasswordError} from './password-reset.js';
import '../accounts/accounts.css';
const props=defineProps({open:Boolean,login:String}),emit=defineEmits(['close','complete']);
const {session,portal}=usePortal();
const recovery=ref({status:'none'}),identifier=ref(props.login||''),password=ref(''),confirmation=ref('');
const busy=ref(false),error=ref(''),errors=ref({}),phoneMode=ref(false),dismissedVersion=ref(null);
const automaticallyOpen=ref(false);
const controller=new AbortController();let pending=null,timer,generation=0,focusRequested=false;
const deviceAttempt=createRecoveryDeviceAttempt(()=>bootstrapPasswordRecoveryDevice(session.api,{signal:controller.signal}));
const approved=computed(()=>recovery.value.status==='approved'&&Number.isSafeInteger(recovery.value.version));
const visible=computed(()=>props.open||automaticallyOpen.value);
function acceptStatus(value){recovery.value=value;if(value.status==='approved'&&dismissedVersion.value!==value.version)automaticallyOpen.value=true;}
async function refresh(){
  if(busy.value)return;
  if(typeof document!=='undefined'&&document.visibilityState==='hidden')return;
  if(pending)return pending;
  const expected=generation;
  pending=(async()=>{
    try{
      const response=await session.api.passwordRecoveryStatus(controller.signal),value=response?.data;
      if(expected!==generation||!['none','pending','approved','expired','completed'].includes(value?.status))return;
      if(Number.isSafeInteger(recovery.value.version)&&Number.isSafeInteger(value.version)&&value.version<recovery.value.version)return;
      acceptStatus(value);
      const focus=focusRequested;focusRequested=false;
      if(value.status==='none'&&portal.id==='pos'){
        const bound=await deviceAttempt.run({focus});if(expected===generation&&bound&&['pending','approved'].includes(bound.status))acceptStatus(bound);
      }
    }catch(failure){if(visible.value&&failure.name!=='AbortError')error.value=failure.message;}
    finally{pending=null;}
  })();return pending;
}
async function request(){
  if(busy.value||!identifier.value.trim())return;busy.value=true;error.value='';errors.value={};generation++;
  try{
    const response=await session.api.requestPasswordRecovery({login:identifier.value.trim()},controller.signal);
    recovery.value=response.data;dismissedVersion.value=null;
  }catch(failure){if(failure.name!=='AbortError')error.value=Object.values(failure.errors||{}).flat()[0]||failure.message;}
  finally{busy.value=false;}
}
async function save(){
  if(busy.value||!approved.value)return;error.value=newPasswordError(password.value,confirmation.value);errors.value={};if(error.value)return;
  busy.value=true;generation++;let checkStatus=false;
  try{
    await session.api.completePasswordRecovery({version:recovery.value.version,password:password.value,password_confirmation:confirmation.value},controller.signal);
    password.value='';confirmation.value='';recovery.value={status:'completed',version:recovery.value.version+1};dismissedVersion.value=null;automaticallyOpen.value=false;emit('complete');
  }catch(failure){if(failure.name!=='AbortError'){errors.value=failure.errors||{};error.value=Object.values(errors.value).flat()[0]||failure.message;checkStatus=[409,410].includes(failure.status);}}
  finally{busy.value=false;if(checkStatus)void refresh();}
}
function close(){if(busy.value)return;if(approved.value)dismissedVersion.value=recovery.value.version;automaticallyOpen.value=false;password.value='';confirmation.value='';emit('close');}
function focused(){focusRequested=true;void refresh();}
onMounted(()=>{void refresh();timer=setInterval(refresh,5000);window.addEventListener('focus',focused);document.addEventListener('visibilitychange',focused);});
onBeforeUnmount(()=>{generation++;deviceAttempt.dispose();controller.abort();clearInterval(timer);window.removeEventListener('focus',focused);document.removeEventListener('visibilitychange',focused);password.value='';confirmation.value='';});
</script>
<template>
  <PhoneRecovery v-if="phoneMode" @close="phoneMode=false" @complete="phoneMode=false;emit('complete')"/>
  <AccountModal v-else-if="visible" :title="approved?'تعيين كلمة مرور جديدة':'استعادة الدخول'" section="حماية حسابك" :busy="busy" @close="close">
    <form v-if="approved" @submit.prevent="save">
      <p class="help">وافق المسؤول على تغيير كلمة المرور لهذا الجهاز. اختر كلمة جديدة وأكدها، ثم سجل الدخول بها.</p>
      <fieldset class="formgrid" :disabled="busy"><FormField v-model="password" name="recovery-password" label="كلمة المرور الجديدة" type="password" required :minlength="9" :maxlength="72" full :error="errors.password"/><FormField v-model="confirmation" name="recovery-confirmation" label="تأكيد كلمة المرور" type="password" required :minlength="9" :maxlength="72" full :error="errors.password_confirmation"/></fieldset>
      <p v-if="error" class="notice notice-error" role="alert">{{error}}</p><footer class="formfoot"><button class="btn primary" :disabled="busy">{{busy?'جارٍ الحفظ…':'حفظ كلمة المرور'}}</button></footer>
    </form>
    <template v-else>
      <p class="help">تواصل مع وكيلك أو المدير واطلب إعادة تعيين كلمة المرور. ستظهر هنا خانات الكلمة الجديدة بعد موافقته على هذا الجهاز.</p>
      <p v-if="recovery.trusted" class="notice" role="status">هذا الجهاز معروف للنظام. لا تحتاج رابطًا أو رمزًا؛ انتظر موافقة المسؤول.</p>
      <p v-else-if="recovery.status==='pending'" class="notice" role="status">طلب هذا الجهاز بانتظار موافقة المسؤول. مرجع الطلب: <b dir="ltr">{{recovery.request_label}}</b></p>
      <form v-else @submit.prevent="request"><p v-if="recovery.status==='expired'" class="help">انتهت صلاحية الطلب. يمكنك إنشاء طلب جديد لهذا الجهاز.</p><FormField v-model="identifier" name="recovery-login" label="اسم مستخدم تسجيل الدخول" required :maxlength="190"/><footer class="formfoot"><button class="btn primary" :disabled="busy||!identifier.trim()">{{busy?'جارٍ الإرسال…':'طلب الاستعادة لهذا الجهاز'}}</button></footer></form>
      <p v-if="error" class="notice notice-error" role="alert">{{error}}</p>
      <footer class="formfoot"><button type="button" class="btn" :disabled="busy" @click="close">إغلاق</button><button type="button" class="btn" :disabled="busy" @click="phoneMode=true">الاستعادة عبر الهاتف</button></footer>
    </template>
  </AccountModal>
</template>
