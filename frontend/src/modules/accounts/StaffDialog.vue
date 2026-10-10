<script setup>
import {computed,onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';
import {useAccountAccess} from './use-account-access.js';
import {staffPayload} from './account-model.js';
import CreatedLoginDetails from './CreatedLoginDetails.vue';import {createdLoginDetails} from './login-details.js';
import AccountModal from './AccountModal.vue';import FormField from './FormField.vue';import ParentPicker from './ParentPicker.vue';
const props=defineProps({account:Object,staff:Object});const emit=defineEmits(['close','saved']);const {session,handleFailure}=useAccountAccess();
const form=reactive({name:props.staff?.name || '',email:props.staff?.email || '',permission_profile_id:String(props.staff?.permission_profile_id || ''),scope_roots:[...(props.staff?.scope_roots || [props.account.id])],include_descendants:props.staff?.include_descendants ?? true,notes:props.staff?.notes || ''});
const scopeNames=reactive(Object.fromEntries([props.account,...(props.staff?.scope_accounts || [])].map((account)=>[account.id,account.name])));
const loginDetails=ref(null);
const password=ref(''),confirmation=ref(''),showPassword=ref(false),reason=ref(''),profiles=ref([]),profileQuery=ref(''),profilePage=ref(1),profileLastPage=ref(1),selectedRoot=ref(props.account),busy=ref(false),error=ref(''),errors=ref({});
const profileOptions=computed(()=>[{value:'',label:'اختر نوع الصلاحية'},...(props.staff?.permission_profile_id && !profiles.value.some((profile)=>profile.id===props.staff.permission_profile_id)?[{value:String(props.staff.permission_profile_id),label:props.staff.permission_profile_name || 'نوع الصلاحية الحالي'}]:[]),...profiles.value.map((profile)=>({value:String(profile.id),label:profile.name}))]);
const controller=new AbortController();let timer,profileController;
function clear(){password.value='';confirmation.value='';}
function close(){if(!busy.value){clear();const created=!!loginDetails.value;loginDetails.value=null;emit(created?'saved':'close');}}
async function loadProfiles(page=1){profileController?.abort();profileController=new AbortController();const own=profileController;try{const result=await session.api.profiles(props.account.id,{q:profileQuery.value,page,per_page:25},own.signal);if(profileController!==own)return;profiles.value=result.data.filter((profile)=>profile.status==='active');profilePage.value=result.meta.current_page;profileLastPage.value=result.meta.last_page;}catch(failure){if(failure.name!=='AbortError' && profileController===own)error.value=await handleFailure(failure);}}
function addRoot(){scopeNames[selectedRoot.value.id]=selectedRoot.value.name;if(!form.scope_roots.includes(selectedRoot.value.id))form.scope_roots.push(selectedRoot.value.id);}
async function save(){
  if(busy.value)return;error.value='';errors.value={};
  if(!props.staff)confirmation.value=password.value;
  busy.value=true;
  try{
    const payload=staffPayload(form,{staff:props.staff,canRole:session.can('staff.role'),canScope:session.can('staff.scope'),password:password.value,confirmation:confirmation.value,reason:reason.value});
    if(props.staff)await session.api.updateStaff(props.account.id,props.staff.id,payload,controller.signal);else {const result=await session.api.createStaff(props.account.id,payload,controller.signal);loginDetails.value=createdLoginDetails(props.account.type,result.data?.user?.login || result.data?.user?.email || result.data?.login || result.data?.email || payload.email,password.value);}
    clear();if(props.staff)emit('saved');
  }catch(failure){if(failure.name!=='AbortError'){clear();errors.value=failure.errors || {};error.value=await handleFailure(failure);}}
  finally{busy.value=false;}
}
watch(profileQuery,()=>{clearTimeout(timer);timer=setTimeout(()=>loadProfiles(),250);});
onMounted(()=>loadProfiles());
onBeforeUnmount(()=>{clearTimeout(timer);profileController?.abort();controller.abort();clear();loginDetails.value=null;});
</script>
<template><AccountModal :title="staff?'تعديل الموظف':'إضافة موظف'" section="مستخدمو النظام" class="staff-original-modal" :busy="busy" @close="close"><p v-if="error" class="notice notice-error" role="alert">{{error}}</p><CreatedLoginDetails v-if="loginDetails" :details="loginDetails" @close="close"/><form v-else @submit.prevent="save"><fieldset class="formgrid staff-form" :disabled="busy"><FormField v-model="form.notes" name="staff-notes" label="ملاحظات (اختياري)" type="textarea" :maxlength="2000" full :error="errors.notes"/><FormField v-model="form.name" name="staff-name" label="اسم الموظف" required :maxlength="120" :error="errors.name"/><FormField v-model="form.email" name="staff-email" label="البريد الإلكتروني" type="email" required :maxlength="190" :error="errors.email"/><div v-if="!staff" class="form-field"><label for="staff-password">كلمة المرور</label><div class="staff-password-row"><input id="staff-password" v-model="password" :type="showPassword?'text':'password'" required minlength="9" autocomplete="new-password" placeholder="9 خانات على الأقل؛ الحروف اختيارية."><button type="button" class="btn small" @click="showPassword=!showPassword">{{showPassword?'إخفاء':'إظهار'}}</button></div><small v-if="errors.password" class="field-error">{{errors.password.join(' ')}}</small></div><FormField v-model="form.permission_profile_id" name="staff-profile" label="نوع الصلاحية" :disabled="!!staff && !session.can('staff.role')" required :options="profileOptions" :error="errors.permission_profile_id"/><div v-if="profileLastPage>1" class="form-field full"><div class="actions"><button type="button" class="btn" :disabled="profilePage<=1" @click="loadProfiles(profilePage-1)">السابق</button><small>{{profilePage}} / {{profileLastPage}}</small><button type="button" class="btn" :disabled="profilePage>=profileLastPage" @click="loadProfiles(profilePage+1)">التالي</button></div></div></fieldset>
<details class="staff-extra"><summary>خيارات النطاق والبحث عن الصلاحيات</summary><input v-model="profileQuery" type="search" placeholder="بحث عن نوع الصلاحية"><fieldset class="scope-section" :disabled="busy || (!!staff && !session.can('staff.scope'))"><h3>نطاق الوصول</h3><ParentPicker v-model="selectedRoot" all-accounts :ancestor-id="account.id" label="الحسابات المكلف بها" :disabled="busy"/><button type="button" class="btn" :disabled="busy" @click="addRoot">إضافة إلى النطاق</button><div class="scope-options"><label v-for="id in form.scope_roots" :key="id"><input type="checkbox" checked :disabled="busy" @change="form.scope_roots=form.scope_roots.filter((entry)=>entry!==id)"/><span>{{scopeNames[id] || `الحساب #${id}`}}</span></label></div><label class="scope-options"><input v-model="form.include_descendants" type="checkbox" :disabled="busy"/>يشمل التابعين للحسابات المحددة</label><p v-if="errors.scope_roots" class="field-error">اختر نطاقًا متاحًا داخل شجرتك.</p></fieldset></details>
<FormField v-if="staff" v-model="reason" name="staff-reason" label="سبب التعديل" type="textarea" required :maxlength="500" :error="errors.reason"/><footer class="formfoot"><button type="button" class="btn" :disabled="busy" @click="close">إلغاء</button><button class="btn primary" :disabled="busy || !form.permission_profile_id">{{busy?'جارٍ الحفظ…':'حفظ الموظف'}}</button></footer></form></AccountModal></template>




