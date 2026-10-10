<script setup>
import { computed,nextTick,onBeforeUnmount,ref,watch } from 'vue';
import CatalogModal from '../catalog/CatalogModal.vue';
import ReceiptCard from './ReceiptCard.vue';
import PrintPolicySummary from './PrintPolicySummary.vue';
import { canRequestReprint,downloadBlob,pendingAttempt,saleStatuses } from './sales-model.js';
const props = defineProps({vm:{type:Object,required:true}}),emit = defineEmits(['changed','reprint']);
const failureEditing = ref(false),failureReason = ref(''),localBusy = ref(false),wait = ref(0),delivery = ref(false),reference = ref(''),channel = ref('استلام مباشر'),password = ref('');
const printKey = props.vm.key(),resultKey = props.vm.key(),retryKey = props.vm.key(),deliveryKey = props.vm.key(); let interval,deadline = 0;
const receipt = computed(()=>props.vm.receipt.value),sale = computed(()=>receipt.value?.sale),busy = computed(()=>localBusy.value || props.vm.state.busy);
const ready = computed(()=>sale.value && (sale.value.status === 'Print Requested' || sale.value.status === 'Reprint Requested' && receipt.value.reprint_approved));
const attempt = computed(()=>pendingAttempt(sale.value));
const canDeliver = computed(()=>props.vm.can('sell.deliver') && sale.value?.issued_at && !sale.value.print_pending && !['Reserved','Cancelled','Delivered','Reprint Requested'].includes(sale.value.status));
watch(()=>receipt.value,()=>{failureEditing.value=false;failureReason.value='';delivery.value=false;password.value='';reference.value='';deadline=Date.now()+(receipt.value?.print_wait_seconds || 0)*1000;wait.value=Math.max(0,Math.ceil((deadline-Date.now())/1000));clearInterval(interval);interval=setInterval(()=>wait.value=Math.max(0,Math.ceil((deadline-Date.now())/1000)),1000);});
function merge(data) {receipt.value.sale={...sale.value,...data}; emit('changed');}
async function print() {
  if(!ready.value || sale.value.print_pending || busy.value || wait.value)return;
  const result = await props.vm.execute({version:sale.value.version},printKey,(payload,signal)=>props.vm.api.action(sale.value.id,'print/start',payload,signal),'بدأت محاولة الطباعة؛ سجل نتيجتها بعد خروج الورقة.');
  if(!result)return;
  merge(result);deadline=Date.now()+(receipt.value.print_policy?.interval_seconds || 0)*1000;
  localBusy.value=true;
  try {
    await nextTick(); const element=document.querySelector('.sales-receipt-dialog .receipt');
    await Promise.all([...element.querySelectorAll('img')].map(image=>image.decode?.().catch(()=>null)));
    const width=Math.max(58,Math.min(100,Number(receipt.value.design.width)||80)),height=Math.max(100,Math.ceil(element.scrollHeight*25.4/96*1.15)+12);
    let style=document.getElementById('sales-paper-size'); if(!style){style=document.createElement('style');style.id='sales-paper-size';document.head.appendChild(style);}
    style.textContent=`@page masal-receipt { size: ${width}mm ${height}mm; margin: 4mm; }`;
    window.print();
  } catch {props.vm.state.error='لم تُفتح نافذة الطباعة. المحاولة ما زالت معلقة؛ سجل النتيجة الفعلية أدناه.';}
  finally {localBusy.value=false;}
}
async function result(success) {
  if(!attempt.value || busy.value || !sale.value.print_pending)return;
  const updated=await props.vm.execute({version:sale.value.version,attempt_id:Number(attempt.value),success,...(!success?{reason:failureReason.value.trim()}: {})},resultKey,(payload,signal)=>props.vm.api.action(sale.value.id,'print/result',payload,signal),success?'تم تسجيل نجاح الطباعة.':'تم تسجيل الفشل وسببه؛ البطاقة نفسها محفوظة لإعادة الطباعة.');
  if(updated){merge(updated);failureEditing.value=false;}
}
async function retry() {const updated=await props.vm.execute({version:sale.value.version},retryKey,(payload,signal)=>props.vm.api.action(sale.value.id,'print/retry',payload,signal),'العملية جاهزة لإعادة محاولة الطباعة دون خصم جديد.');if(updated)merge(updated);}
async function deliver() {
  if(busy.value || !reference.value.trim())return;
  if(channel.value === 'ملف مشفر') {
    localBusy.value=true;
    try {const {encryptFile}=await import('../../shared/files/encrypted-file.js');const encrypted=await encryptFile(JSON.stringify({sale_id:sale.value.id,cards:receipt.value.cards}),password.value); downloadBlob(new Blob([JSON.stringify(encrypted)],{type:'application/json'}),`sale-${sale.value.id}.encrypted.json`);}
    catch(error){props.vm.state.error=error.message;return;} finally{localBusy.value=false;password.value='';}
  }
  const updated=await props.vm.execute({version:sale.value.version,channel:channel.value,reference:reference.value.trim()},deliveryKey,(payload,signal)=>props.vm.api.action(sale.value.id,'deliver',payload,signal),'تم تسجيل التسليم.');if(updated){merge(updated);delivery.value=false;}
}
function close(){if(!busy.value){password.value='';props.vm.clearReceipt();}}
onBeforeUnmount(()=>{clearInterval(interval);document.getElementById('sales-paper-size')?.remove();password.value='';});
</script>
<template>
  <CatalogModal v-if="receipt" title="وصل العملية" section="البيع والطباعة" content-class="sales-modal sales-receipt-dialog" :busy="busy" @close="close"><ReceiptCard :receipt="receipt"/><PrintPolicySummary :policy="receipt.print_policy" :daily="receipt.daily_print" :sale="sale" :wait="wait" :can-retry="vm.can('sell.reprint') && vm.can('sell.print')" :busy="busy" @retry="retry"/><div class="notice no-print" style="margin-top:18px">الحالة: {{saleStatuses[sale.status] || sale.status}}{{['Print Requested','Reprint Requested'].includes(sale.status) ? '. نافذة الطباعة لا تؤكد خروج الورقة؛ سجل النتيجة أدناه.':'. تم تسجيل نتيجة الطباعة.'}}</div><div v-if="sale.status === 'Reprint Requested' && !ready" class="notice no-print">بانتظار موافقة الأعلى؛ لا يمكن إعادة الطباعة أو تسجيل نتيجتها قبل الاعتماد.</div><div v-if="failureEditing && sale.print_pending" class="no-print"><label>سبب فشل الطباعة<textarea v-model="failureReason" required maxlength="1000"></textarea></label><button type="button" class="btn danger" :disabled="busy || !failureReason.trim()" @click="result(false)">تسجيل فشل الطباعة</button></div><div v-if="sale.status === 'Print Failed'" class="notice no-print"><span>{{sale.failure_reason}}</span><button v-if="vm.can('sell.reprint')" type="button" class="btn" :disabled="busy" @click="$emit('reprint',sale)">رفع طلب معالجة</button></div><p v-if="vm.state.error" class="notice warn no-print" role="alert">{{vm.state.error}}</p><div class="formfoot no-print"><button v-if="['Printed','Reprinted'].includes(sale.status) && canRequestReprint(sale,vm.identity.value,vm.can)" type="button" class="btn" :disabled="busy" @click="$emit('reprint',sale)">طلب إذن إعادة الطباعة</button><button v-if="ready && vm.can('sell.print')" type="button" class="btn" :disabled="busy || sale.print_pending || wait > 0" @click="print">طباعة المتصفح</button><button v-if="sale.print_pending && vm.can('sell.result')" type="button" class="btn danger" :disabled="busy || !attempt" @click="failureEditing=!failureEditing">فشلت الطباعة</button><button v-if="sale.print_pending && vm.can('sell.result')" type="button" class="btn primary" :disabled="busy || !attempt" @click="result(true)">تأكيد نجاح الطباعة</button><button v-if="canDeliver" type="button" class="btn" :disabled="busy" @click="delivery=!delivery">تسجيل التسليم</button></div><form v-if="delivery" class="no-print" @submit.prevent="deliver"><div class="formgrid"><label>طريقة التسليم<select v-model="channel"><option>استلام مباشر</option><option>ملف مشفر</option></select></label><label>مرجع التسليم<input v-model="reference" required maxlength="200"></label><label v-if="channel === 'ملف مشفر'">كلمة مرور الملف<input v-model="password" type="password" minlength="12" autocomplete="new-password" required></label></div><div class="formfoot"><button class="btn primary" :disabled="busy || !reference.trim()">{{channel === 'ملف مشفر' ? 'تنزيل الملف المشفر وتسجيل التسليم':'تأكيد التسليم'}}</button></div></form></CatalogModal>
</template>
