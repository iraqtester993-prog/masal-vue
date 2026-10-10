<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { accountTypeLabel } from '../../app/portal-config.js';
import { childTypes } from './child-types.js';
import { accountDraft, accountPayload } from './account-model.js';
import { useAccountAccess } from './use-account-access.js';
import AccountModal from './AccountModal.vue';
import CreatedLoginDetails from './CreatedLoginDetails.vue';
import {createdLoginDetails} from './login-details.js';
import FormField from './FormField.vue';
import ParentPicker from './ParentPicker.vue';
import {createReferenceApi} from '../reference/reference-api.js';
import PosReferenceFields from '../reference/PosReferenceFields.vue';
import './accounts.css';
const AccountAttachments = defineAsyncComponent(() => import('./AccountAttachments.vue'));
const props = defineProps({parent:Object,account:Object,kind:{type:String,default:'agents'}});
const emit = defineEmits(['close','created','saved','changed']);
const {session,handleFailure} = useAccountAccess();
const parent = ref(props.parent || session.state.identity.account);
const type = ref(props.account?.type || (props.kind === 'pos' ? 'pos' : childTypes(parent.value).find((entry) => entry !== 'pos') || ''));
const form = reactive(accountDraft(props.account));
const createdAccount=ref(null),loginDetails=ref(null);
const login=ref(props.account?.owner_user?.login || props.account?.owner_user?.email || ''),loginReason=ref('');
const canLogin=computed(()=>session.state.identity?.account.type==='system' && session.state.identity?.membership.kind==='owner' && session.can('account.login'));
const loginChanged=computed(()=>login.value.trim().toLowerCase()!==String(props.account?.owner_user?.login || props.account?.owner_user?.email || '').trim().toLowerCase());
const email = ref(''), password = ref(''), confirmation = ref('');
const busy = ref(false), loading = ref(false), errors = ref({}), error = ref(''), cities = ref([]);
const accountVersion = ref(props.account?.version), mediaBusy = ref(false), mediaConflict = ref(false);
const controller = new AbortController();
const referenceApi = createReferenceApi(session.api), reference = reactive({pos_type_id:null,representative_ids:[]}), referenceOptions = ref({pos_types:[],available_representatives:[],selected_representatives:[]}), referenceReady = ref(false);
let referenceRevision = 0;
async function loadReference() {
  if (!isPos.value || !parent.value?.id) return;
  const expected = ++referenceRevision; loading.value = true; referenceReady.value = false;
  try {
    const result = props.account ? await referenceApi.getProfile(props.account.id,controller.signal) : await referenceApi.posOptions(parent.value.id,controller.signal);
    if (expected !== referenceRevision) return;
    referenceOptions.value = result.data;
    referenceReady.value = true;
    reference.pos_type_id = result.data.pos_type_id || null; reference.representative_ids = [...(result.data.representative_ids || [])];
    if (props.account) accountVersion.value = result.data.version;
  } catch (failure) { if (expected === referenceRevision && failure.name !== 'AbortError') error.value = await handleFailure(failure); }
  finally { if (expected === referenceRevision) loading.value = false; }
}
const isPos = computed(() => type.value === 'pos');
const types = computed(() => childTypes(parent.value).filter((entry) => props.kind === 'pos' ? entry === 'pos' : entry !== 'pos').map((value) => ({value,label:accountTypeLabel(value)})));
const title = computed(() => props.account ? `تعديل ${isPos.value ? 'نقطة البيع' : 'الوكيل'}` : isPos.value ? 'إضافة نقطة بيع' : 'وكيل جديد');
watch(parent, () => { if (!types.value.some((entry) => entry.value === type.value)) type.value = types.value[0]?.value || ''; });
watch(() => [parent.value?.id,type.value], () => loadReference());
function clear() { password.value = ''; confirmation.value = ''; }
function close() { if (!busy.value && !mediaBusy.value) { clear(); loginDetails.value=null; if(createdAccount.value)emit('created',createdAccount.value);else emit('close'); } }
async function submit() {
  if (busy.value || mediaBusy.value || mediaConflict.value || (isPos.value && !referenceReady.value)) return;
  error.value = ''; errors.value = {};
  if (!props.account && password.value !== confirmation.value) { errors.value = {'user.password_confirmation':['تأكيد كلمة المرور غير مطابق.']}; return; }
  busy.value = true;
  try {
    const payload = accountPayload(form, {account:props.account && {...props.account,version:accountVersion.value},parent:parent.value,type:type.value,email:email.value,password:password.value,confirmation:confirmation.value,login:login.value,loginReason:loginReason.value,canLogin:canLogin.value,canDevice:session.can('pos.device'),canLocation:session.can('pos.location'),reference:referenceOptions.value.pos_types ? reference : null,canType:session.can('pos.type'),canRepresentatives:session.can('pos.representatives')});
    const result = props.account ? await session.api.updateAccount(props.account.id,payload,controller.signal) : await session.api.createAccount(payload,controller.signal);
    if(props.account){clear();emit('saved',result.data.account || result.data);}else{createdAccount.value=result.data.account || result.data;loginDetails.value=createdLoginDetails(createdAccount.value.type || type.value,result.data.user?.login || result.data.user?.email || payload.user.email,password.value);clear();}
  } catch (failure) {
    if (failure.name !== 'AbortError') { clear(); errors.value = failure.errors || {}; error.value = await handleFailure(failure); }
  } finally { busy.value = false; }
}
onMounted(async () => {
  loading.value = true;
  try { const result = await session.api.accountOptions(controller.signal); cities.value = result.data.cities; await loadReference(); }
  catch (failure) { if (failure.name !== 'AbortError') error.value = await handleFailure(failure); }
  finally { loading.value = false; }
});
onBeforeUnmount(() => { controller.abort(); clear(); loginDetails.value=null;createdAccount.value=null; });
</script>
<template>
  <AccountModal :title="title" :busy="busy || mediaBusy" :section="isPos ? 'نقاط البيع' : 'الوكلاء'" @close="close">
    <p v-if="error" class="notice notice-error" role="alert">{{error}}</p>
    <CreatedLoginDetails v-if="loginDetails" :details="loginDetails" @close="close"/>
    <form v-else @submit.prevent="submit">
      <fieldset class="formgrid" :disabled="busy || loading || mediaBusy">
        <FormField v-model="form.name" name="name" :label="isPos ? 'الاسم التجاري' : 'اسم الوكيل'" required :maxlength="190" :error="errors.name"/>
        <FormField v-if="!isPos" v-model="type" name="type" label="النوع" :options="types" :disabled="!!account" required :error="errors.type"/>
        <FormField v-if="isPos" v-model="form.owner_name" name="owner_name" label="اسم صاحب المكتب" required :maxlength="190" :error="errors.owner_name"/>
        <div class="form-field full">
          <ParentPicker v-if="!account" v-model="parent" :label="isPos ? 'الوكيل التابع' : 'الوكيل الأعلى'" :disabled="busy"/>
          <template v-else><label>{{isPos ? 'الوكيل التابع' : 'الوكيل الأعلى'}}</label><input :value="account.parent?.name || parent.name" readonly aria-readonly="true"/><small>تبعية الحساب ثابتة في هذا النموذج.</small></template>
          <span v-if="errors.parent_id" class="field-error">الجهة الأعلى غير متاحة لهذا النوع.</span>
        </div>
        <FormField v-model="form.city" name="city" label="المحافظة" required :disabled="isPos && !!account && !session.can('pos.location')" :options="[{value:'',label:'اختر المحافظة'},...cities]" :error="errors.city"/>
        <FormField v-model="form.phone" name="phone" :label="isPos ? 'الهاتف' : 'رقم الهاتف'" type="tel" required :maxlength="30" :error="errors.phone"/>
        <template v-if="isPos">
          <FormField v-model="form.address" name="address" label="المدينة والعنوان" required :disabled="!!account && !session.can('pos.location')" :maxlength="500" :error="errors.address"/>
          <div class="form-field full"><label>تقييد البيع بالرقم التسلسلي للجهاز</label><button type="button" class="device-lock-switch" role="switch" aria-label="تقييد البيع بالرقم التسلسلي للجهاز" :aria-checked="form.device_lock_enabled" :disabled="!!account&&!session.can('pos.device')" @click="form.device_lock_enabled=!form.device_lock_enabled"><span class="device-lock-track" aria-hidden="true"><span/></span><span>{{form.device_lock_enabled?'تشغيل':'إطفاء'}}</span></button><small>{{form.device_lock_enabled?'يلزم الرقم التسلسلي المعتمد عند البيع.':'يمكن للنقطة البيع من أي جهاز بعد تسجيل الدخول.'}}</small><span v-if="errors.device_lock_enabled" class="field-error">{{errors.device_lock_enabled.join(' ')}}</span></div><FormField v-model="form.serial" name="serial" label="الرقم التسلسلي للجهاز" :required="form.device_lock_enabled" :disabled="!!account && !session.can('pos.device')" :maxlength="190" :error="errors.serial"/>
          <FormField v-model="form.device_model" name="device_model" label="موديل الجهاز" required :disabled="!!account && !session.can('pos.device')" :maxlength="190" :error="errors.device_model"/>
          <FormField v-model="form.app_version" name="app_version" label="إصدار التطبيق" required :disabled="!!account && !session.can('pos.device')" :maxlength="190" :error="errors.app_version"/>
          <PosReferenceFields v-model:type-id="reference.pos_type_id" v-model:representative-ids="reference.representative_ids" :options="referenceOptions" :disabled="busy || loading" :can-type="session.can('pos.type')" :can-representatives="session.can('pos.representatives')" :errors="errors" />
        </template>
        <template v-else>
          <FormField v-model="form.support" name="support" label="معلومات الدعم" :maxlength="500" :error="errors.support"/>
          <FormField v-model="form.color" name="color" label="لون الوكيل" type="color" required :error="errors.color"/>
        </template>
        <FormField v-model="form.notes" name="notes" label="ملاحظات (اختياري)" type="textarea" :maxlength="2000" full :error="errors.notes"/>
      </fieldset>
      <section v-if="!account" class="network-login-fields">
        <h3>حساب الدخول</h3>
        <fieldset class="formgrid" :disabled="busy || loading">
          <FormField v-model="email" name="user.email" label="البريد الإلكتروني للدخول" type="email" required :maxlength="190" :error="errors['user.email'] || errors['user.login']"/>
          <FormField v-model="password" name="user.password" label="كلمة مرور الحساب" type="password" required :minlength="9" hint="9 خانات على الأقل؛ الحروف اختيارية." :error="errors['user.password']"/>
          <FormField v-model="confirmation" name="user.password_confirmation" label="تأكيد كلمة المرور" type="password" required :minlength="9" :error="errors['user.password_confirmation']"/>
        </fieldset>
      </section>
      <section v-else-if="account.owner_user" class="network-login-fields">
        <h3>حساب الدخول</h3><fieldset class="formgrid" :disabled="busy || loading"><FormField v-model="login" name="login" label="بريد أو اسم مستخدم تسجيل الدخول" :disabled="!canLogin" required :maxlength="190" :error="errors.login"/><FormField v-if="canLogin && loginChanged" v-model="loginReason" name="login-reason" label="سبب تعديل حساب الدخول" required :maxlength="500" :error="errors.reason"/></fieldset>
      </section>
      <AccountAttachments v-if="account && session.can('account.attachments.view')" :account="account" :disabled="busy" @version="accountVersion=$event" @changed="emit('changed',$event)" @busy="mediaBusy=$event" @conflict="mediaConflict=true"/>
      <p v-else-if="!account" class="muted-note">يمكن إضافة الصورة والمستمسكات من تفاصيل الحساب بعد حفظه.</p>
      <p v-if="!account && !types.some((entry)=>entry.value===type)" class="help">اختر وكيلاً أعلى يسمح بإنشاء هذا النوع من الحساب.</p>
      <footer class="formfoot"><button type="button" class="btn" :disabled="busy || mediaBusy" @click="close">إلغاء</button><button class="btn primary" :disabled="busy || loading || mediaBusy || mediaConflict || (isPos && !referenceReady) || (!account && !types.some((entry)=>entry.value===type))">{{busy ? 'جارٍ الحفظ…' : 'حفظ البيانات'}}</button></footer>
    </form>
  </AccountModal>
</template>
