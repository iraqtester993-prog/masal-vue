<script setup>
import { computed,nextTick,onBeforeUnmount,ref,watch } from 'vue';
import CatalogModal from '../catalog/CatalogModal.vue';
import ReceiptCard from './ReceiptCard.vue';
import {rechargeContent} from './recharge-qr.js';
import PrintPolicySummary from './PrintPolicySummary.vue';
import { canRequestReprint,downloadBlob,pendingAttempt,saleStatuses } from './sales-model.js';
import {printResultPayload} from './print-confirmation.js';
import {usePrinterRoute} from '../../shared/printer-route.js';
import './receipt-app.css';
const {printRoute}=usePrinterRoute();
import { requiresNativePrinter as hasNativePrinter,prepareNativeReceipt,printPreparedNativeReceipt,nativeRequest,isNativeReceiptLimit,nativeReceiptLimitMessage } from '../../shared/native-print.js';
const props = defineProps({vm:{type:Object,required:true}}),emit = defineEmits(['changed','reprint']);
const copyNotice=ref('');
const pinCopyNotice=ref(''),pinCopyBusy=ref(false);
const selectedOutcome = ref(null),failureReason = ref(''),localBusy = ref(false),wait = ref(0),delivery = ref(false),reference = ref(''),channel = ref('استلام مباشر'),password = ref(''),nativePrintBlocked=ref(false);
const printKey = props.vm.key(),resultKey = props.vm.key(),retryKey = props.vm.key(),deliveryKey = props.vm.key(),copyKey=props.vm.key(); let interval,deadline = 0;
const receipt = computed(()=>props.vm.receipt.value),sale = computed(()=>receipt.value?.sale),busy = computed(()=>localBusy.value || props.vm.state.busy);
const pos = computed(()=>props.vm.pos?.value || props.vm.identity?.value?.account?.type==='pos');
const displayReceipt = computed(()=>pos.value ? {...receipt.value,design:{...receipt.value.design,width:58}} : receipt.value);
const unavailableRechargeQr = computed(()=>receipt.value?.design?.qr_enabled && (receipt.value.cards||[]).some(card=>rechargeContent(card.fields?.pin,receipt.value.design.recharge_template)===null));
const ready = computed(()=>sale.value && (sale.value.status === 'Print Requested' || sale.value.status === 'Reprint Requested' && receipt.value.reprint_approved));
const canCopy=computed(()=>pos.value&&sale.value?.issued_at&&!sale.value.print_pending&&['Printed','Reprinted','Print Failed','Delivered'].includes(sale.value.status)&&props.vm.can('sell.print')&&props.vm.can('sell.receipt')&&props.vm.can('data.pin'));
const copyablePins=computed(()=>pos.value&&sale.value?.issued_at&&props.vm.can('sell.receipt')&&props.vm.can('data.pin')?(receipt.value?.cards||[]).filter(card=>typeof card.fields?.pin==='string'&&card.fields.pin.length>0):[]);
const receiptState=computed(()=>{
 if(sale.value?.print_pending)return {tone:'pending',title:'بانتظار تأكيد نتيجة الطباعة',hint:'تحقق من الورقة ثم سجّل النتيجة الفعلية أدناه.',icon:'M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6Z'};
 const states={
  Printed:{tone:'success',title:'تمت الطباعة',hint:'تم تسجيل نجاح الطباعة لهذه العملية.'},
  Reprinted:{tone:'success',title:'أُعيدت طباعة الوصل',hint:'نفس البطاقات، دون بيع أو خصم جديد.'},
  Delivered:{tone:'success',title:'تم التسليم',hint:'تم تسجيل تسليم هذه العملية.'},
  'Print Failed':{tone:'failed',title:'لم تكتمل الطباعة',hint:'الوصل محفوظ. أعد طباعة نفس البطاقات دون بيع جديد.'},
  'Print Requested':{tone:'ready',title:'الوصل جاهز للطباعة',hint:'راجع بيانات الوصل ثم ابدأ الطباعة.'},
  'Reprint Requested':{tone:'pending',title:ready.value?'إعادة الطباعة متاحة':'بانتظار إذن إعادة الطباعة',hint:ready.value?'اطبع نفس الوصل دون إصدار بطاقات جديدة.':'سيظهر إجراء الطباعة بعد موافقة المسؤول.'},
  Reserved:{tone:'pending',title:'العملية محجوزة',hint:'لم يكتمل إصدار هذه العملية.'},
  Cancelled:{tone:'neutral',title:'العملية ملغاة',hint:''},
 };
 const state=states[sale.value?.status]||{tone:'neutral',title:saleStatuses[sale.value?.status]||'تفاصيل العملية',hint:''};
 return {...state,icon:state.tone==='success'?'m5 12 4 4L19 6':state.tone==='failed'?'m7 7 10 10M17 7 7 17':state.tone==='ready'?'M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6Z':'M12 8v5m0 3h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z'};
});
const attempt = computed(()=>pendingAttempt(sale.value));
const blockedPrint = computed(()=>receipt.value?.pending_print && Number(receipt.value.pending_print.id)!==Number(sale.value?.id) ? receipt.value.pending_print : null);
const canDeliver = computed(()=>props.vm.can('sell.deliver') && sale.value?.issued_at && !sale.value.print_pending && !['Reserved','Cancelled','Delivered','Reprint Requested'].includes(sale.value.status));
watch(()=>receipt.value,()=>{selectedOutcome.value=null;failureReason.value='';delivery.value=false;password.value='';reference.value='';nativePrintBlocked.value=false;copyNotice.value='';pinCopyNotice.value='';deadline=Date.now()+(receipt.value?.print_wait_seconds || 0)*1000;wait.value=Math.max(0,Math.ceil((deadline-Date.now())/1000));clearInterval(interval);interval=setInterval(()=>wait.value=Math.max(0,Math.ceil((deadline-Date.now())/1000)),1000);});
async function copyPin(card){
 if(busy.value||pinCopyBusy.value||!props.vm.can('sell.receipt')||!props.vm.can('data.pin')||!copyablePins.value.includes(card))return;
 const openedReceipt=receipt.value,index=copyablePins.value.indexOf(card)+1;
 pinCopyBusy.value=true;pinCopyNotice.value='';
 try{
  if(typeof navigator==='undefined'||!navigator.clipboard?.writeText)throw Error('النسخ غير متاح على هذا الجهاز. يمكنك تحديد PIN من الوصل ونسخه.');
  await navigator.clipboard.writeText(card.fields.pin);
  if(receipt.value===openedReceipt)pinCopyNotice.value=`تم نسخ PIN للبطاقة ${index} كنص.`;
 }catch{if(receipt.value===openedReceipt)pinCopyNotice.value='تعذر نسخ PIN. يمكنك تحديد الرقم من الوصل ونسخه.';}
 finally{pinCopyBusy.value=false;}
}
function merge(data) {
  receipt.value.sale={...sale.value,...data};
  if(data.print_pending)receipt.value.pending_print={id:data.id,product_name:sale.value.product_name,quantity:sale.value.quantity};
  else if(Number(receipt.value.pending_print?.id)===Number(data.id))receipt.value.pending_print=null;
  props.vm.state.options.pending_print=receipt.value.pending_print || null;emit('changed');
}
async function preparePrintReceipt(){
  await nextTick();const element=document.querySelector('.sales-receipt-dialog .receipt');
  if(!element)throw Error('الوصل غير جاهز للطباعة.');
  return {element,blocks:hasNativePrinter()?await prepareNativeReceipt(element):null};
}
async function print({prepared,browser=false}={}) {
  if(!ready.value || sale.value.print_pending || blockedPrint.value || busy.value || wait.value)return;
  localBusy.value=true;nativePrintBlocked.value=false;let started=false;
  try {
    prepared ||= await preparePrintReceipt();const {element,blocks}=prepared;
    const result = await props.vm.execute({version:sale.value.version},printKey,(payload,signal)=>props.vm.api.action(sale.value.id,'print/start',payload,signal),'بدأت محاولة الطباعة؛ سجل نتيجتها بعد خروج الورقة.');
    if(!result)return;
    started=true;merge(result);selectedOutcome.value=null;failureReason.value='';deadline=Date.now()+(receipt.value.print_policy?.interval_seconds || 0)*1000;
    if(hasNativePrinter()) {
      const jobId=`sale-${sale.value.id}-${pendingAttempt(sale.value)}`;
      props.vm.state.notice=browser?(await nativeRequest({action:'browserPrint',jobId,blocks})).message:await printPreparedNativeReceipt(blocks,jobId);
      return;
    }
    await Promise.all([...element.querySelectorAll('img')].map(image=>image.decode?.().catch(()=>null)));
    const width=pos.value?58:Math.max(58,Math.min(100,Number(receipt.value.design.width)||80)),height=Math.max(100,Math.ceil(element.scrollHeight*25.4/96*1.15)+12);
    let style=document.getElementById('sales-paper-size'); if(!style){style=document.createElement('style');style.id='sales-paper-size';document.head.appendChild(style);}
    style.textContent=`@page masal-receipt { size: ${width}mm ${height}mm; margin: 4mm; }`;
    window.print();
  } catch(error) {
    nativePrintBlocked.value=isNativeReceiptLimit(error);
    props.vm.state.error=nativePrintBlocked.value?nativeReceiptLimitMessage:hasNativePrinter()||!started?error.message:'لم تُفتح نافذة الطباعة. المحاولة ما زالت معلقة؛ سجل النتيجة الفعلية أدناه.';
    if(started&&error.printState==='not_sent') {
      // Only a native response proving no dispatch permits automatic failure recording.
      const updated=await props.vm.execute({version:sale.value.version,attempt_id:Number(pendingAttempt(sale.value)),success:false,reason:String(nativePrintBlocked.value?nativeReceiptLimitMessage:error.message).slice(0,1000)},resultKey,(payload,signal)=>props.vm.api.action(sale.value.id,'print/result',payload,signal),'لم يُرسل الوصل للطابعة. أصلح الاتصال وأعد طباعة نفس العملية دون خصم جديد.');
      if(updated)merge(updated);
    }
  }
  finally {localBusy.value=false;}
}
async function copyReceipt(browser=false){
 if(!canCopy.value||busy.value)return;
 localBusy.value=true;props.vm.state.error='';copyNotice.value='';
 try{
  const {element,blocks}=await preparePrintReceipt();
  const authorization=await props.vm.execute({},copyKey,(payload,signal)=>props.vm.api.action(sale.value.id,'print/copy',payload,signal),'تم تجهيز نسخة من نفس الوصل دون بيع أو خصم جديد.');
  if(!authorization)return;
  if(hasNativePrinter()){
   const jobId=`digital-stock-copy-${sale.value.id}-${authorization.copy_id}`;
   props.vm.state.notice=browser?(await nativeRequest({action:'browserPrint',jobId,blocks})).message:await printPreparedNativeReceipt(blocks,jobId);
  }else{
   await Promise.all([...element.querySelectorAll('img')].map(image=>image.decode?.().catch(()=>null)));
   window.print();
  }
  copyNotice.value=hasNativePrinter()?props.vm.state.notice:'فُتحت نافذة الطباعة لنسخة من الوصل الأصلي؛ لا بيع أو خصم جديد.';
 }catch(error){props.vm.state.error=error.message;}finally{localBusy.value=false;}
}
async function result() {
  if(!attempt.value || busy.value || !sale.value.print_pending)return;
  let payload;
  try {payload=printResultPayload(sale.value,selectedOutcome.value,failureReason.value);}catch(error){props.vm.state.error=error.message;return;}
  const updated=await props.vm.execute(payload,resultKey,(value,signal)=>props.vm.api.action(sale.value.id,'print/result',value,signal),payload.success?'تم تسجيل نجاح الطباعة.':'تم تسجيل الفشل وسببه؛ البطاقة نفسها محفوظة لإعادة الطباعة.');
  if(updated){merge(updated);selectedOutcome.value=null;failureReason.value='';}
}
async function retry() {
  if(busy.value)return;let prepared;
  if(hasNativePrinter()){
    localBusy.value=true;nativePrintBlocked.value=false;
    try{prepared=await preparePrintReceipt();}
    catch(error){nativePrintBlocked.value=isNativeReceiptLimit(error);props.vm.state.error=nativePrintBlocked.value?nativeReceiptLimitMessage:error.message;return;}
    finally{localBusy.value=false;}
  }
  const updated=await props.vm.execute({version:sale.value.version},retryKey,(payload,signal)=>props.vm.api.action(sale.value.id,'print/retry',payload,signal),'إعادة طباعة نفس الوصل دون خصم جديد.');if(updated){merge(updated);await nextTick();await print({prepared});}
}
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
  <CatalogModal v-if="receipt" title="وصل العملية" section="البيع والطباعة" content-class="sales-modal sales-receipt-dialog pos-receipt-dialog" body-class="receipt-dialog-body" :busy="busy" @close="close">
    <div v-if="blockedPrint" class="notice warn no-print" role="alert">توجد محاولة طباعة معلقة للعملية #{{blockedPrint.id}} · {{blockedPrint.product_name}}. افتحها وحدد هل خرجت الورقة قبل بدء طباعة أخرى.<button v-if="vm.can('sell.receipt')" type="button" class="btn primary" :disabled="busy || vm.receiptLoading?.value" @click="vm.openReceipt(blockedPrint)">فتح العملية المعلقة</button></div>
    <header v-if="pos" class="receipt-app-state no-print" :data-tone="receiptState.tone" role="status" aria-live="polite"><span class="receipt-app-state-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="receiptState.icon"/></svg></span><h3>{{receiptState.title}}</h3><p v-if="receiptState.hint">{{receiptState.hint}}</p><div class="receipt-app-meta"><span>عملية <b class="mono">#{{sale.id}}</b></span><span v-if="sale.quantity">{{sale.quantity}} بطاقة</span></div></header>
    <div v-else class="receipt-operation-strip no-print"><span class="badge">{{saleStatuses[sale.status] || sale.status}}</span><span>عملية <b class="mono">#{{sale.id}}</b></span><span v-if="sale.quantity">{{sale.quantity}} بطاقة</span></div>
    <p v-if="unavailableRechargeQr" class="notice warn no-print receipt-qr-unavailable" role="status">تعذر إنشاء QR لبعض رموز البطاقات بسبب تنسيق غير مدعوم. راجع بيانات البطاقة وصيغة التعبئة.</p>
    <div class="receipt-app-paper"><ReceiptCard :receipt="displayReceipt"/></div>
    <div v-if="copyablePins.length" class="receipt-pin-copy no-print" aria-label="نسخ PIN كنص"><span>نسخ رمز البطاقة كنص</span><div><button v-for="(card,index) in copyablePins" :key="card.id" type="button" class="btn small" :aria-label="'نسخ PIN للبطاقة '+(index+1)" :disabled="busy||pinCopyBusy" @click="copyPin(card)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M8 8h12v13H8ZM16 8V3H3v13h5"/></svg>نسخ PIN<span v-if="copyablePins.length>1">{{index+1}}</span></button></div><p v-if="pinCopyNotice" role="status">{{pinCopyNotice}}</p></div>
    <PrintPolicySummary v-if="!pos" :policy="receipt.print_policy" :daily="receipt.daily_print" :sale="sale" :wait="wait" :can-retry="vm.can('sell.reprint') && vm.can('sell.print')" :busy="busy" @retry="retry"/>
    <p v-else-if="wait > 0 && ready && !sale.print_pending" class="receipt-print-hint no-print" role="status">الطباعة متاحة بعد {{wait}} ثانية</p>
    <p v-if="sale.status === 'Reprint Requested' && !ready" class="notice no-print">بانتظار إذن إعادة الطباعة من المسؤول.</p>
    <section v-if="sale.print_pending && vm.can('sell.result')" class="receipt-result-panel no-print" aria-label="تأكيد نتيجة الطباعة">
      <div class="receipt-result-heading"><b>هل خرج الوصل كاملًا؟</b><small v-if="!pos">اختر النتيجة بعد خروج الورقة.</small></div>
      <div class="receipt-result-options" role="group" aria-label="نتيجة الطباعة">
        <button type="button" aria-label="تمت الطباعة" class="btn receipt-result-success" :class="{selected:selectedOutcome === true}" :aria-pressed="selectedOutcome === true" :disabled="busy || !attempt" @click="selectedOutcome=true">تمت الطباعة</button>
        <button type="button" aria-label="فشلت الطباعة" class="btn receipt-result-failure" :class="{selected:selectedOutcome === false}" :aria-pressed="selectedOutcome === false" :disabled="busy || !attempt" @click="selectedOutcome=false">فشلت الطباعة</button>
      </div>
      <label v-if="selectedOutcome === false" class="receipt-failure-reason">سبب الفشل<textarea v-model="failureReason" required maxlength="1000" rows="2" placeholder="مثل: نفاد الورق أو طباعة جزء من الوصل"></textarea></label>
      <button type="button" class="btn primary receipt-result-confirm" :disabled="busy || !attempt || selectedOutcome === null || selectedOutcome === false && !failureReason.trim()" @click="result">تأكيد النتيجة</button>
    </section>
    <div v-if="sale.status === 'Print Failed'" class="notice warn no-print"><b>لم تكتمل الطباعة</b><p>{{sale.failure_reason}}</p><small v-if="pos">الوصل محفوظ؛ يمكنك طباعة نسخة من نفس البطاقات دون بيع أو خصم جديد.</small></div>
    <p v-if="['Printed','Reprinted'].includes(sale.status)" class="receipt-print-hint no-print" role="status">{{sale.status==='Reprinted' ? 'تمت إعادة طباعة نفس الوصل دون بيع أو خصم جديد.':'تم تسجيل نجاح الطباعة.'}}</p>
    <p v-if="vm.state.error" class="notice warn no-print" role="alert">{{vm.state.error}}</p>
    <p v-if="copyNotice" class="notice no-print" role="status">{{copyNotice}}</p>
    <p v-if="nativePrintBlocked" class="notice warn no-print">افتح نقطة البيع بمتصفح الهاتف، ثم افتح نفس الوصل من سجل العمليات واطبعه دون إصدار بيع جديد.</p>
    <div class="formfoot receipt-primary-actions no-print">
      <button v-if="canCopy" type="button" class="btn primary" :disabled="busy" @click="copyReceipt(false)">{{printRoute==='direct'?'إعادة طباعة مباشرة':'إعادة طباعة بالمتصفح'}}</button>
      <button v-if="canCopy&&printRoute==='direct'" type="button" class="btn" :disabled="busy" @click="copyReceipt(true)">إعادة طباعة بالمتصفح</button>
      <button v-if="ready && vm.can('sell.print') && !sale.print_pending" type="button" class="btn primary" :disabled="busy || !!blockedPrint || wait > 0" @click="print">{{printRoute==='direct' ? 'طباعة مباشرة':'طباعة المتصفح'}}</button>
      <button v-if="ready && printRoute==='direct' && vm.can('sell.print') && !sale.print_pending" type="button" class="btn" :disabled="busy || !!blockedPrint || wait > 0" @click="print({browser:true})">طباعة بالمتصفح</button>
      <button v-if="!pos&&canRequestReprint(sale,vm.identity?.value,vm.can)" type="button" class="btn" :disabled="busy" @click="$emit('reprint',sale)">طلب إذن إعادة الطباعة</button>
      <button v-if="canDeliver" type="button" class="btn" :disabled="busy" @click="delivery=!delivery">تسجيل التسليم</button>
      <button v-if="!pos" type="button" class="btn" :disabled="busy" @click="close">إغلاق الوصل</button>
    </div>
    <form v-if="delivery" class="no-print" @submit.prevent="deliver"><div class="formgrid"><label>طريقة التسليم<select v-model="channel"><option>استلام مباشر</option><option>ملف مشفر</option></select></label><label>مرجع التسليم<input v-model="reference" required maxlength="200"></label><label v-if="channel === 'ملف مشفر'">كلمة مرور الملف<input v-model="password" type="password" minlength="12" autocomplete="new-password" required></label></div><div class="formfoot"><button class="btn primary" :disabled="busy || !reference.trim()">{{channel === 'ملف مشفر' ? 'تنزيل الملف المشفر وتسجيل التسليم':'تأكيد التسليم'}}</button></div></form>
  </CatalogModal>
</template>
