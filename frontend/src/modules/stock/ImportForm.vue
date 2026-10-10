<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';

import {computed, onBeforeUnmount, reactive, ref, watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createStockApi} from './stock-api.js';
import {parseSheets} from './order-parser.js';
import {delimited} from '../../shared/files/import-reader.js';
import {amountText, blankOrder, importedLine, mapColumns, matchedProduct, orderPayload, requestKey} from './stock-model.js';
import {vMoney} from '../finance/money-input.js';
import OrderLines from './OrderLines.vue';
const props = defineProps({options:{type:Object,required:true}, correction:Object});
const emit = defineEmits(['saved','records','error','reset']);
const {session} = usePortal(), api = createStockApi(session.api), controller = new AbortController();
const isAdmin = computed(() => session.state.identity?.account.type === 'system');
const canSubmit = computed(() => session.can('import.preview') && session.can('data.pin'));
const draft = reactive(blankOrder(props.options.accounts.length === 1 ? props.options.accounts[0].id : ''));
const step = ref(0), busy = ref(false), preview = ref(null), exclude = ref(false), error = ref(''), text = ref(''), fallback = ref(''), done = ref(null), filesInput = ref(null);
let submitKey = '', submitted = null;
const products = computed(() => props.options.products.filter(product => product.provider_id === Number(draft.provider_id)));
const sources = computed(() => props.options.sources.filter(source => source.provider_id === Number(draft.provider_id) && source.network_account_id === Number(draft.account_id)));
const accountName = computed(() => props.options.accounts.find(account => account.id === Number(draft.account_id))?.name || '—');
const blocked = computed(() => busy.value || !canSubmit.value);
function changed() {preview.value = null; exclude.value = false; submitKey = ''; submitted = null;}
watch(draft, changed, {deep:true});
watch(() => props.correction, order => {
  if (!order?.draft) return;
  Object.assign(draft, JSON.parse(JSON.stringify(order.draft))); step.value = 0; error.value = ''; preview.value = null; done.value = null;
}, {immediate:true});
onBeforeUnmount(() => {controller.abort(); draft.lines = []; preview.value = null; text.value = '';});
function fail(cause) {if (cause.name === 'AbortError') return; error.value = Object.values(cause.errors || {}).flat().join(' ') || cause.message; emit('error', cause);}
function providerChanged() {draft.source_id = ''; fallback.value = ''; for (const line of draft.lines) line.product_id = matchedProduct(products.value, draft.provider_id, line.category_code)?.id || '';}
function accountChanged() {draft.source_id = '';}
function append(parsed) {
  if (draft.lines.reduce((sum,line) => sum + line.rows.length,0) + parsed.reduce((sum,line) => sum + line.rows.length,0) > 50000) throw Error('الحد الأقصى 50,000 بطاقة لكل طلبية.');
  if (draft.lines.length + parsed.length > 200) throw Error('الحد الأقصى 200 ملف أو ورقة لكل طلبية.');
  draft.lines.push(...parsed.map(line => importedLine(line, products.value, draft.provider_id, fallback.value)));
}
async function read(event) {
  const files = [...event.target.files]; event.target.value = ''; if (blocked.value) return;
  busy.value = true; error.value = '';
  try {
    for (const file of files) {
      if (controller.signal.aborted) return;
      if (file.size > 15000000 || !/\.(txt|csv|tsv|xlsx)$/i.test(file.name)) throw Error('اختر ملف TXT أو CSV أو Excel بحجم لا يتجاوز 15 ميغابايت.');
      const response = await api.read(draft.account_id, file, controller.signal); append(parseSheets(response.data.sheets));
    }
  } catch (cause) {fail(cause);} finally {busy.value = false;}
}
function paste() {if (blocked.value) return; error.value = ''; try {append(parseSheets([{name:'بيانات ملصوقة',rows:delimited(text.value)}])); text.value = '';} catch (cause) {fail(cause);}}
function next() {
  error.value = '';
  if (step.value === 0 && (!draft.account_id || !draft.provider_id || !draft.source_id || !draft.city || !Number.isInteger(Number(draft.category_count)) || draft.category_count < 1)) {error.value = 'حدد عدد الفئات والوكيل والشركة والمصدر والمحافظة.'; return;}
  if (step.value === 1 && !draft.lines.length) {error.value = 'ارفع ملفات الفئات أو أضف بيانات البطاقات أولًا.'; return;}
  step.value++;
}
async function validate() {
  if (blocked.value) return; busy.value = true; error.value = '';
  try {preview.value = (await api.preview(orderPayload(draft, props.options.products),controller.signal)).data; submitKey = requestKey(); submitted = null;}
  catch (cause) {fail(cause);} finally {busy.value = false;}
}
async function send(approve) {
  if (blocked.value || !preview.value || preview.value.lines.some(line => !line.accepted) || (preview.value.rejected && !exclude.value)) return;
  busy.value = true; error.value = '';
  try {
    const payload = {...preview.value.draft, preview_hash:preview.value.preview_hash, exclude_rejected:exclude.value, idempotency_key:submitKey};
    if (!submitted) submitted = (props.correction ? await api.resubmit(props.correction.id, {...payload,version:props.correction.version},controller.signal) : await api.submit(payload,controller.signal)).data;
    if (approve && submitted.status === 'pending') submitted = (await api.review(submitted.id,{version:submitted.version,decision:'approve',idempotency_key:`${submitKey}:approve`},controller.signal)).data;
    done.value = {id:submitted.id,status:submitted.status,quantity:submitted.quantity,rejected:submitted.rejected,summary:submitted.summary}; draft.lines = []; preview.value = null; text.value = ''; emit('saved', done.value); submitted = null;
  } catch (cause) {fail(cause);} finally {busy.value = false;}
}
function restart() {emit('reset'); Object.assign(draft, blankOrder(props.options.accounts.length === 1 ? props.options.accounts[0].id : '')); step.value = 0; done.value = null; error.value = ''; fallback.value = ''; text.value = '';}
</script>
<template>
  <section class="card multi-orders">
    <template v-if="done"><h3>{{done.status === 'approved' ? 'تم اعتماد الطلبية' : 'أرسلت الطلبية للإدارة'}}</h3><div class="order-metrics"><span>{{done.id}}</span><span>{{done.summary.lines.length}} ملف</span><span>{{done.quantity}} بطاقة صالحة</span><span>{{done.rejected}} مستبعدة</span></div><div class="formfoot"><button type="button" class="btn primary" @click="restart">طلبية جديدة</button><button type="button" class="btn" @click="emit('records')">سجل الطلبيات</button></div></template>
    <template v-else>
      <div class="steps"><div v-for="(title,index) in ['بيانات الطلبية','ملفات الفئات','المعاينة والإرسال']" :key="title" class="step" :class="{active:step === index}">{{index+1}}. {{title}}</div></div>
      <p v-if="!canSubmit" class="notice warn">إنشاء الطلبية وقراءة ملفات البطاقات يتطلبان صلاحية المعاينة وعرض رموز البطاقات.</p>
      <fieldset :disabled="blocked" class="stock-fieldset">
        <div v-show="step === 0" class="formgrid">
          <label>عدد الفئات<input v-model="draft.category_count" type="number" min="1" max="200" step="1" aria-label="عدد الفئات"></label>
          <label>الوكيل الرئيسي<input v-if="!isAdmin && options.accounts.length === 1" :value="accountName" readonly><select v-else v-model="draft.account_id" :disabled="!!correction" aria-label="وكيل الطلبية" @change="accountChanged"><option value="">اختر الوكيل</option><option v-for="account in options.accounts" :key="account.id" :value="account.id">{{account.name}}</option></select></label>
          <label>الشركة<select v-model="draft.provider_id" aria-label="شركة الطلبية" @change="providerChanged"><option value="">اختر الشركة</option><option v-for="provider in options.providers" :key="provider.id" :value="provider.id">{{provider.name}}</option></select></label>
          <label>الفئة (اختياري)<select v-model="fallback" aria-label="فئة الطلبية (اختياري)"><option value="">تحديد تلقائي من الملف</option><option v-for="product in products" :key="product.id" :value="product.id">{{product.name}}</option></select></label>
          <label>المصدر<select v-model="draft.source_id" aria-label="مصدر الطلبية"><option value="">اختر المصدر</option><option v-for="source in sources" :key="source.id" :value="source.id">{{source.name}}</option></select></label>
          <label>المحافظة<select v-model="draft.city" aria-label="محافظة الطلبية"><option value="">اختر المحافظة</option><option v-for="city in options.cities" :key="city" :value="city">{{city}}</option></select></label>
        </div>
        <div v-show="step === 1"><div class="order-upload actions"><button type="button" class="btn primary" @click="filesInput.click()">رفع ملفات الفئات</button><input ref="filesInput" hidden type="file" multiple accept=".txt,.csv,.xlsx" @change="read"><span>{{draft.category_count}} فئة · {{draft.lines.length}} ملف / ورقة</span><span v-if="busy">جارٍ قراءة الملفات…</span></div><ul class="order-upload-list"><li v-for="line in draft.lines" :key="line.key"><span>{{line.name}}</span><button type="button" class="btn small" @click="draft.lines = draft.lines.filter(row => row.key !== line.key)">إزالة</button></li></ul><details><summary>إدخال نص بدل ملف</summary><textarea v-model="text" rows="4" dir="auto" aria-label="بيانات البطاقات"></textarea><button type="button" class="btn small" :disabled="!text.trim()" @click="paste">إضافة البيانات</button></details></div>
        <div v-if="step === 2">
          <template v-if="!preview"><TablePanel class="tablewrap"><table class="order-preview-edit"><thead><tr><th>الملف</th><th>الفئة حسب المرجع</th><th>فئة المخزون</th><th>البطاقات</th><th>تكلفة البطاقة · د.ع</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="line in draft.lines" :key="line.key"><td>{{line.name}}</td><td>{{line.reference_label || line.category_code || 'غير محددة'}}</td><td><select v-model="line.product_id" :aria-label="'فئة الملف ' + line.name"><option value="">اختر فئة هذا الملف</option><option v-for="product in products" :key="product.id" :value="product.id">{{product.name}}</option></select><details v-if="line.rawRows"><summary>تحديد أعمدة الملف</summary><label v-for="field in ['serial','pin','expiry']" :key="field">{{field}}<select v-model="line.columnMap[field]" :aria-label="field + ' ' + line.name" @change="mapColumns(line)"><option value="">غير موجود</option><option v-for="(cell,index) in line.rawRows[0].cells" :key="index" :value="String(index)">عمود {{index+1}} · {{cell}}</option></select></label></details><label>تاريخ الانتهاء الافتراضي<input v-model="line.default_expiry" type="date"></label></td><td>{{line.rows.length}}</td><td><input v-model="line.cost" v-money inputmode="decimal" aria-label="تكلفة البطاقة"><small>{{products.find(product => product.id === Number(line.product_id))?.currency || 'IQD'}}</small><details><summary>مصاريف الملف</summary><input v-model="line.expenses" v-money inputmode="decimal" aria-label="مصاريف الملف"></details></td><td><button type="button" class="btn small" @click="draft.lines = draft.lines.filter(row => row.key !== line.key)">إزالة</button></td></tr></tbody></table></TablePanel></template>
          <template v-else><div class="order-metrics"><span>{{accountName}} · {{draft.city}}</span><span>{{preview.quantity}} صالحة</span><span>{{preview.rejected}} مرفوضة</span><b>{{amountText(preview.amounts)}}</b><button type="button" class="btn small" @click="changed">تعديل بيانات المعاينة</button></div><OrderLines :lines="preview.lines" :draft-lines="preview.draft.lines" :can-export="session.can('data.pin') && session.can('import.preview')"/><div v-if="preview.lines.some(line => !line.accepted)" class="notice warn">يوجد ملف بلا بطاقات صالحة؛ راجع أسباب الرفض ثم صححه أو أزله.</div><label v-if="preview.rejected" class="order-exclude"><input v-model="exclude" type="checkbox">استبعاد {{preview.rejected}} بطاقة مرفوضة واعتماد {{preview.quantity}} بطاقة صالحة فقط</label></template>
        </div>
      </fieldset>
      <div v-if="error" class="notice warn" role="alert">{{error}}</div><div class="formfoot"><button type="button" class="btn" :disabled="busy || step === 0" @click="step--; changed(); error = ''">السابق</button><button v-if="step < 2" type="button" class="btn primary" :disabled="blocked" @click="next">{{step === 1 ? 'معاينة الملفات' : 'التالي'}}</button><button v-else-if="!preview" type="button" class="btn primary" :disabled="blocked || !draft.lines.length" @click="validate">فحص الملفات</button><button v-else type="button" class="btn primary" :disabled="blocked || preview.lines.some(line => !line.accepted) || (preview.rejected && !exclude)" @click="send(isAdmin && session.can('import.approve'))">{{isAdmin && session.can('import.approve') ? 'اعتماد الطلبية' : 'إرسال للإدارة'}}</button></div>
    </template>
  </section>
</template>
