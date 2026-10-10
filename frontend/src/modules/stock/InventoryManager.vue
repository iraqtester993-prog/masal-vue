<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';

import {computed, onBeforeUnmount, onMounted, reactive, ref, watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createStockApi} from './stock-api.js';
import {downloadText, requestKey, stockStatus} from './stock-model.js';
const props = defineProps({batchId:{type:Number,required:true},inspectOnly:Boolean,cities:{type:Array,default:() => []}});
const emit = defineEmits(['changed','close','error','withdrawals','busy']);
const {session} = usePortal(), api = createStockApi(session.api), writer = new AbortController();
const state = reactive({batch:null, cards:[],meta:{page:1,last_page:1,total:0},query:'',status:'',busy:false,saving:false,error:'',success:''});
const chosen = ref([]), action = ref(''), reason = ref(''), preview = ref(null), adjustments = ref([]), restoreId = ref(''), secretCopy = ref(false), password = ref('');
const metadata = reactive({city:'',supplier:'',notes:''});
const canRestore = computed(() => session.state.identity?.account.type === 'system' && session.state.identity?.membership.kind === 'owner' && session.can('inventory.restore'));
const restores = computed(() => adjustments.value.filter(row => row.batch_id === props.batchId && row.kind === 'cancellation' && row.status === 'cancelled'));
let reader, revision = 0, timer, signature = '', operationKey = '', withdrawalRecord = null;
function failure(cause) {if (cause.name !== 'AbortError') {state.error = Object.values(cause.errors || {}).flat().join(' ') || cause.message; emit('error',cause);}}
async function load(page = 1) {
  reader?.abort(); reader = new AbortController(); const expected = ++revision; state.busy = true;
  try {const [batch,cards] = await Promise.all([api.get('batches',props.batchId,reader.signal),api.cards(props.batchId,{page,per_page:20,query:state.query,status:state.status},reader.signal)]); if (expected !== revision) return; state.batch = batch.data; state.cards = cards.data; state.meta = cards.meta; Object.assign(metadata,{city:batch.data.city,supplier:batch.data.supplier,notes:batch.data.notes || ''});}
  catch (cause) {if (expected === revision) failure(cause);} finally {if (expected === revision) state.busy = false;}
}
async function select(mode = '') {
  if (state.busy || state.saving) return; state.busy = true; state.error = '';
  try {chosen.value = (await api.selection(props.batchId,mode ? {mode} : {query:state.query,status:state.status},writer.signal)).data.ids;}
  catch (cause) {failure(cause);} finally {state.busy = false;}
}
async function chooseAction() {
  preview.value = null; state.error = ''; state.success = ''; withdrawalRecord = null; password.value = ''; signature = ''; operationKey = '';
  if (action.value === 'restore') {
    try {const response = await api.list('adjustments',{account_id:state.batch.account_id,batch_id:props.batchId,status:'cancelled',per_page:100},writer.signal); adjustments.value = response.data; restoreId.value = restores.value[0]?.id || '';}
    catch (cause) {failure(cause);}
  }
}
async function review() {
  state.error = '';
  if (!reason.value.trim()) {state.error = 'سبب الإجراء مطلوب.'; return;}
  if (['damage','copy','return'].includes(action.value) && !chosen.value.length) {state.error = 'حدد البطاقات أولًا.'; return;}
  if (action.value === 'copy' && secretCopy.value && password.value.length < 12) {state.error = 'كلمة مرور الملف يجب أن تحتوي 12 حرفًا على الأقل.'; return;}
  if (action.value === 'restore' && !restoreId.value) {state.error = 'اختر عملية إلغاء قابلة للاسترجاع.'; return;}
  state.saving = true;
  try {preview.value = (await api.previewAction(props.batchId,{version:state.batch.version,action:action.value,reason:reason.value.trim(),...(['damage','copy','return'].includes(action.value) ? {card_ids:[...chosen.value]}:{}),...(action.value === 'copy' ? {secrets:secretCopy.value}:{}),...(action.value === 'edit' ? {...metadata}:{}),...(action.value === 'restore' ? {adjustment_id:Number(restoreId.value)}:{})},writer.signal)).data;}
  catch (cause) {failure(cause);} finally {state.saving = false;}
}
async function confirm() {
  if (state.saving || !preview.value) return; state.saving = true; state.error = '';
  const base = {version:state.batch.version,reason:reason.value.trim()}, keySignature = JSON.stringify([props.batchId,action.value,base,[...chosen.value],metadata,restoreId.value,secretCopy.value]);
  if (keySignature !== signature) {signature = keySignature; operationKey = requestKey();}
  try {
    if (action.value === 'copy') {
      const payload = (await api.copy(props.batchId,{...base,card_ids:[...chosen.value],secrets:secretCopy.value},writer.signal)).data;
      if (secretCopy.value) {const {encryptFile} = await import('../../shared/files/encrypted-file.js'); downloadText(`masal-copy-${props.batchId}.encrypted.json`,JSON.stringify(await encryptFile(JSON.stringify(payload),password.value)),'application/json');}
      else {const cell = value => '"' + String(value ?? '').replace(/^[=+@\-]/,"'$&").replaceAll('"','""') + '"'; downloadText(`masal-copy-${props.batchId}.csv`,'\uFEFF' + [['Serial','Expiry','Status'],...payload.cards.map(card => [card.serial,card.expiry,stockStatus(card.status)])].map(row => row.map(cell).join(',')).join('\r\n'));}
    } else if (action.value === 'damage') await api.claim(props.batchId,{...base,card_ids:[...chosen.value],idempotency_key:operationKey},writer.signal);
    else if (action.value === 'return') {
      if (!withdrawalRecord) withdrawalRecord = (await api.withdrawal({...base,batch_id:props.batchId,card_ids:[...chosen.value],idempotency_key:operationKey},writer.signal)).data;
      if (session.state.identity?.account.type === 'system' && session.state.identity?.membership.kind === 'owner' && session.can('exports.approve') && session.can('exports.encrypt') && session.can('data.pin')) {
        if (withdrawalRecord.status === 'pending') withdrawalRecord = (await api.reviewWithdrawal(withdrawalRecord.id,{version:withdrawalRecord.version,decision:'approve',reason:reason.value.trim(),hours:24,idempotency_key:`${operationKey}:approve`},writer.signal)).data;
        const result = (await api.downloadWithdrawal(withdrawalRecord.id,{version:withdrawalRecord.version,reason:reason.value.trim(),idempotency_key:`${operationKey}:download`},writer.signal)).data;
        const {workbook} = await import('../../shared/files/excel-export.js'); downloadText(`supplier-return-${withdrawalRecord.id}.xlsx`,workbook(result.cards.map(card => ({Serial:card.serial,PIN:card.pin,Expiry:card.expiry,...(card.cvc ? {CVC:card.cvc}:{}),...(card.reference ? {Reference:card.reference}:{})}))));
      }
      emit('withdrawals');
    } else await api.batchAction(props.batchId,{...base,action:action.value,idempotency_key:operationKey,...(action.value === 'edit' ? {...metadata}:{}),...(action.value === 'restore' ? {adjustment_id:Number(restoreId.value)}:{})},writer.signal);
    state.success = action.value === 'copy' ? 'تم تنزيل النسخة.' : 'تم تنفيذ الإجراء.'; password.value = ''; chosen.value = []; preview.value = null; action.value = ''; withdrawalRecord = null; emit('changed'); await load(state.meta.page);
  } catch (cause) {failure(cause);} finally {state.saving = false;}
}
watch(() => [state.query,state.status], () => {clearTimeout(timer); timer = setTimeout(() => load(),250);});
watch(() => state.saving, busy => emit('busy',busy), {flush:'sync'});
onMounted(() => load());
onBeforeUnmount(() => {clearTimeout(timer); reader?.abort(); writer.abort(); state.cards = []; password.value = '';});
</script>
<template>
  <section v-if="state.batch" class="inventory-manager" :aria-busy="state.busy || state.saving">
    <div class="inventory-summary"><b>{{state.batch.product_name}}</b><span>{{state.batch.account_name}}</span><span>{{state.batch.quantity}} بطاقة · {{state.batch.available_count}} متاحة</span></div>
    <div class="inventory-controls"><input v-model="state.query" :disabled="state.saving" placeholder="بحث بالسيريال" aria-label="بحث البطاقات"><select v-model="state.status" :disabled="state.saving" aria-label="حالة البطاقة"><option value="">جميع الحالات</option><option v-for="value in ['Available','Sold','Quarantined','Exported','Cancelled by Reversal','Compensated','Replaced','Written Off','Reserved']" :key="value" :value="value">{{stockStatus(value)}}</option></select><template v-if="!inspectOnly"><button type="button" class="btn small" :disabled="state.busy || state.saving" @click="select()">تحديد نتائج البحث</button><button type="button" class="btn small" :disabled="state.busy || state.saving" @click="select('remaining')">تحديد المتبقي</button><span>{{chosen.length}} محددة</span></template></div>
    <TablePanel class="tablewrap"><table><thead><tr><th>{{inspectOnly ? 'معرف داخلي' : 'تحديد'}}</th><th>Serial</th><th v-if="inspectOnly">PIN</th><th>الانتهاء</th><th>الحالة</th></tr></thead><tbody><tr v-for="card in state.cards" :key="card.id"><td v-if="inspectOnly">{{card.id}}</td><td v-else><input v-model="chosen" type="checkbox" :value="card.id" :disabled="state.saving" :aria-label="'تحديد ' + card.serial"></td><td dir="ltr">{{card.serial}}</td><td v-if="inspectOnly">••••••••</td><td>{{card.expiry}}</td><td><span class="badge settlement-status">{{card.claim_id && card.status === 'Quarantined' ? 'تالف — قيد المعالجة' : stockStatus(card.status)}}</span></td></tr></tbody></table></TablePanel>
    <p v-if="!state.cards.length && !state.busy" class="empty">لا توجد نتائج</p><div class="actions"><button type="button" class="btn small" :disabled="state.busy || state.saving || state.meta.page <= 1" @click="load(state.meta.page-1)">السابق</button><span>{{state.meta.page}} / {{state.meta.last_page}}</span><button type="button" class="btn small" :disabled="state.busy || state.saving || state.meta.page >= state.meta.last_page" @click="load(state.meta.page+1)">التالي</button></div>
    <template v-if="!inspectOnly"><fieldset class="stock-fieldset" :disabled="state.saving"><div class="inventory-controls"><details><summary>إجراءات أخرى</summary><label>الإجراء<select v-model="action" @change="chooseAction"><option value="">اختر الإجراء</option><option v-if="session.can('inventory.edit')" value="edit">تعديل بيانات الطلبية</option><option v-if="session.can('claims.create')" value="damage">تعليم المحدد كتالف</option><option v-if="session.can('inventory.export')" value="copy">تصدير كشف دون رموز</option><option v-if="session.can('inventory.cancel')" value="cancel">إلغاء المتبقي من الطلبية</option><option v-if="session.can('inventory.quarantine')" value="quarantine">إيقاف البيع</option><option v-if="session.can('inventory.quarantine')" value="resume">إعادة تفعيل</option><option v-if="canRestore" value="restore">استرجاع الملغي</option></select></label></details><button v-if="session.can('exports.view') && session.can('exports.request')" type="button" class="btn" @click="action = 'return'; chooseAction()">إرجاع للمزود</button></div>
      <template v-if="action && !preview"><div v-if="action === 'edit'" class="formgrid"><label>المحافظة<select v-model="metadata.city"><option v-for="city in cities" :key="city" :value="city">{{city}}</option></select></label><label>المصدر<input v-model="metadata.supplier"></label><label>ملاحظات<textarea v-model="metadata.notes"></textarea></label></div><label v-if="action === 'restore'">عملية الإلغاء<select v-model="restoreId"><option value="">اختر عملية الإلغاء</option><option v-for="row in restores" :key="row.id" :value="row.id">{{row.id}} · {{row.quantity}} بطاقة</option></select></label><template v-if="action === 'copy'"><label v-if="session.can('exports.encrypt') && session.can('data.pin')" class="inline-check"><input v-model="secretCopy" type="checkbox">نسخة مشفرة مع رموز البطاقات</label><label v-if="secretCopy">كلمة مرور الملف<input v-model="password" type="password" autocomplete="new-password" minlength="12"></label></template><label>سبب الإجراء<textarea v-model="reason"></textarea></label><div class="formfoot"><button type="button" class="btn primary" @click="review">معاينة الإجراء</button></div></template>
      <div v-if="preview" class="notice"><b>{{action === 'return' ? 'إرجاع للمزود' : 'تأكيد الإجراء'}}</b><p>{{preview.quantity}} بطاقة</p><p v-if="['damage','cancel','return'].includes(action)">الرصيد التشغيلي المسحوب: {{preview.debit ?? '••••'}} {{preview.currency === 'IQD' ? 'د.ع' : '$'}}. لا يوجد إرجاع نقدي تلقائي.</p><p v-if="action === 'copy'">نسخة فقط؛ لا يتغير رصيد المخزون أو حالة البطاقات.</p><div class="actions"><button type="button" class="btn" @click="preview = null">رجوع</button><button type="button" class="btn primary" @click="confirm">{{state.saving ? 'جارٍ التنفيذ…' : action === 'return' && session.state.identity?.account.type !== 'system' ? 'إرسال للاعتماد' : 'تأكيد الإجراء'}}</button></div></div>
    </fieldset></template><p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p><p v-if="state.success" class="notice" role="status">{{state.success}}</p>
  </section><p v-else-if="state.error" class="notice warn" role="alert">{{state.error}}</p><p v-else class="read-loading help" role="status">جارٍ تحميل تفاصيل المخزون…</p>
</template>
