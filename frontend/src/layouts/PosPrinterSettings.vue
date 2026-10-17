<script setup>
import {computed,onBeforeUnmount,onMounted,ref} from 'vue';
import {usePortal} from '../modules/auth/session.js';
import CatalogModal from '../modules/catalog/CatalogModal.vue';
import {nativeRequest,getNativePrinterCapabilities,openNativePrinterTest,selectPrinterChoice,scanNativePrinters,pairNativePrinter} from '../shared/native-print.js';
import {enabledPrinters,printerChoices,mergePrinterDevices} from '../shared/printer-registry.js';
import './pos-about.css';

const emit=defineEmits(['close']);
const {session}=usePortal(),printers=ref([]),devices=ref([]),capability=ref(null),busy=ref(false),error=ref(''),notice=ref(''),selected=ref(''),deviceNotice=ref(''),discovering=ref(false),pairingAddress=ref('');
let alive=true,controller;
const choices=computed(()=>printerChoices(capability.value||{},devices.value,printers.value));
const directChoices=computed(()=>choices.value.filter(row=>row.route==='direct'));
const browserChoices=computed(()=>choices.value.filter(row=>row.route==='browser'));
const directSelection=computed(()=>directChoices.value.some(row=>row.key===selected.value)?selected.value:'');
const browserSelection=computed(()=>browserChoices.value.some(row=>row.key===selected.value)?selected.value:'');
const discoverySupported=computed(()=>capability.value?.bluetoothDiscoveryVersion===1);
const radioBusy=computed(()=>discovering.value||!!pairingAddress.value);
async function perform(work){
 if(busy.value)return;busy.value=true;error.value='';notice.value='';
 try{await work();}catch(e){if(alive){if(e.name==='AbortError')notice.value='تم إيقاف متابعة البلوتوث. إذا بقيت نافذة إقران Android، يمكنك إلغاؤها من النافذة.';else error.value=e.message;}}
 finally{if(alive)busy.value=false;}
}
async function readRegistry(){const response=await session.api.request('/company/app-config',{fresh:true});if(!alive)return;printers.value=response.data.config.printers||[];await nativeRequest({action:'printerRegistry',printers:enabledPrinters(printers.value)},{timeoutMs:5000});}
async function readCapability(){const value=await getNativePrinterCapabilities();if(alive)capability.value=value;}
async function readDevices(){
 deviceNotice.value='';
 try{
  const result=await nativeRequest({action:'printerDevices'},{timeoutMs:5000});if(!alive)return;
  devices.value=mergePrinterDevices(devices.value,result.devices||[]);
  if(result.permissionRequired)deviceNotice.value='اضغط بحث ووافق على إذن الأجهزة القريبة لعرض الطابعات.';
  else if(result.enabled===false)deviceNotice.value='البلوتوث مطفأ. اضغط تشغيل البلوتوث والبحث.';
  else if(!devices.value.length)deviceNotice.value='لا توجد أجهزة مقترنة؛ ابحث عن الطابعة وهي قريبة وقابلة للاكتشاف.';
 }catch(e){if(alive)deviceNotice.value=e.message;}
}
function restoreSelection(){
 if(capability.value?.printRoute==='browser')selected.value='browser';
 else if(capability.value?.printerMode!=='bluetooth'&&choices.value.some(row=>row.kind==='internal'))selected.value='internal';
 else{const matches=choices.value.filter(row=>row.kind==='bluetooth'&&row.name===capability.value?.printerName);selected.value=matches.length===1?matches[0].key:'';}
}
async function initialize(){await readCapability();try{await readRegistry();await readCapability();}catch(e){if(alive)error.value=e.message;}await readDevices();if(alive)restoreSelection();}
function refresh(){if(document.visibilityState==='visible'&&!radioBusy.value)perform(initialize);}
async function save(){
 await perform(async()=>{
  const choice=choices.value.find(row=>row.key===selected.value);if(!choice||choice.unavailable)throw Error('اختر طابعة مقترنة ومتاحة أولًا.');
  if(choice.kind!=='browser'){await readRegistry();await readCapability();const current=choices.value.find(row=>row.key===choice.key);if(!current||current.unavailable)throw Error('هذه الطابعة لم تعد متاحة.');notice.value=await selectPrinterChoice(current);}
  else notice.value=await selectPrinterChoice(choice);
  await readCapability();window.dispatchEvent(new Event('dananeer:printer-change'));
 });
}
function discoveryProgress(value,{replace=false}={}){
 if(!alive)return;
 devices.value=mergePrinterDevices(replace?[]:devices.value,value.devices||[]);
 deviceNotice.value=value.message||'';
}
async function search(){
 if(busy.value)return;
 controller=new AbortController();discovering.value=true;
 try{await perform(async()=>{const result=await scanNativePrinters({signal:controller.signal,onProgress:value=>discoveryProgress(value,{replace:true})});discoveryProgress(result,{replace:true});if(alive)notice.value=result.message;});}
 finally{if(alive)discovering.value=false;controller=null;}
}
async function pair(device){
 if(busy.value||device.bonded)return;
 controller=new AbortController();pairingAddress.value=device.address;
 try{await perform(async()=>{const result=await pairNativePrinter(device.address,{signal:controller.signal,onProgress:discoveryProgress});discoveryProgress(result);await readDevices();if(alive)notice.value=result.message;});}
 finally{if(alive)pairingAddress.value='';controller=null;}
}
function selectDevice(device){if(busy.value||!device.bonded)return;const choice=choices.value.find(row=>row.address===device.address);if(choice&&!choice.unavailable){selected.value=choice.key;notice.value=choice.route==='direct'?'تم اختيار الطابعة؛ اضغط حفظ الاختيار.':'هذا الجهاز يستخدم نافذة طباعة النظام؛ اضغط حفظ الاختيار.';error.value='';}}
function cancelDiscovery(){controller?.abort();}
function close(){cancelDiscovery();emit('close');}
async function testPrinter(){await perform(async()=>{await readCapability();if(capability.value.printRoute!=='browser'){await readRegistry();await readCapability();}if(capability.value.printRoute==='browser'){await nativeRequest({action:'browserPrint',jobId:'digital-printer-test-'+crypto.randomUUID(),blocks:[{text:'دنانير — اختبار الطابعة'},{text:'اختبار فقط — لا توجد عملية بيع أو خصم'},{text:'نهاية الاختبار — END'}]});notice.value='فُتحت نافذة الطباعة؛ اختر الطابعة وتحقق من الورقة.';}else await openNativePrinterTest();});}
onMounted(()=>{perform(initialize);document.addEventListener('visibilitychange',refresh);});
onBeforeUnmount(()=>{alive=false;cancelDiscovery();document.removeEventListener('visibilitychange',refresh);});
</script>

<template>
 <CatalogModal title="إعدادات الطابعة" content-class="pos-info-dialog pos-printer-dialog" :busy="busy&&!radioBusy" @close="close">
  <div class="pos-info-body pos-printer-body">
   <p v-if="error" class="notice warn" role="alert">{{error}}</p><p v-if="notice" class="notice" role="status">{{notice}}</p>
   <div class="pos-printer-current">
    <span class="pos-printer-saved-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6Z"/><path d="M17 11h1"/></svg></span>
    <div><small>الطابعة المحفوظة</small><strong dir="auto">{{capability?.printerName||'لم يتم الاختيار بعد'}}</strong><span>{{capability?.printRoute==='direct'?'طباعة مباشرة':'طباعة المتصفح والنظام'}}</span></div>
    <span v-if="capability?.printerName" class="pos-printer-saved-check" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg></span>
   </div>
   <section class="pos-printer-section">
    <h3>الطباعة المباشرة</h3>
    <label class="pos-printer-picker"><span class="pos-printer-sr">اسم الطابعة</span><select :value="directSelection" :disabled="busy" aria-label="طابعات الطباعة المباشرة" @change="selected=$event.target.value"><option value="" disabled>اختر الطابعة</option><option v-for="row in directChoices" :key="row.key" :value="row.key" :disabled="row.unavailable">{{row.registryName||row.name}}</option></select></label>
   </section>
   <section class="pos-printer-section">
    <h3>طباعة المتصفح والنظام</h3>
    <label class="pos-printer-picker"><span class="pos-printer-sr">اسم الطابعة</span><select :value="browserSelection" :disabled="busy" aria-label="طابعات المتصفح والنظام" @change="selected=$event.target.value"><option value="" disabled>اختر الطباعة بالمتصفح</option><option v-for="row in browserChoices" :key="row.key" :value="row.key" :disabled="row.unavailable">{{row.name}}</option></select></label>
   </section>
   <div class="pos-printer-simple-actions"><button type="button" class="btn primary" :disabled="busy||!selected||choices.find(row=>row.key===selected)?.unavailable" @click="save"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>حفظ الاختيار</button><button type="button" class="btn" :disabled="busy||!capability" @click="testPrinter"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6Z"/></svg>اختبار الطابعة</button></div>
   <section class="pos-printer-section pos-printer-bluetooth">
    <div class="pos-printer-section-heading"><h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m7 7 10 10-5 4V3l5 4L7 17"/></svg>أجهزة البلوتوث</h3><button v-if="radioBusy" type="button" class="btn small" @click="cancelDiscovery">إيقاف</button><button v-else type="button" class="btn pos-printer-search" :disabled="busy||!discoverySupported" @click="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg>{{discovering?'جارٍ البحث…':'تشغيل البلوتوث والبحث'}}</button></div>
    <p v-if="capability&&!discoverySupported" class="notice warn" role="status">البحث والإقران داخل التطبيق يحتاجان تحديث تطبيق دنانير إلى 1.4.2 أو أحدث. تبقى الأجهزة المقترنة والطباعة بالمتصفح متاحة.</p>
    <p v-if="deviceNotice" class="help" role="status" aria-live="polite">{{deviceNotice}}</p>
    <ul v-if="devices.length" class="pos-printer-devices">
     <li v-for="device in devices" :key="device.address">
      <span class="pos-printer-device-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6Z"/></svg></span>
      <div><strong dir="auto">{{device.name}}</strong><small :title="device.address"><i v-if="device.bonded" aria-hidden="true"></i>{{device.bonded?'مقترن':device.bonding?'بانتظار الإقران':'غير مقترن'}} · {{choices.find(row=>row.address===device.address)?.route==='direct'?'طباعة مباشرة':'طباعة النظام'}}</small></div>
      <button v-if="!device.bonded" type="button" class="btn small" :disabled="busy||!discoverySupported" :aria-label="'إقران '+device.name" @click="pair(device)">{{pairingAddress===device.address?'جارٍ الإقران…':'إقران'}}</button>
      <button v-else type="button" class="btn small" :class="{primary:selected==='bluetooth:'+device.address}" :disabled="busy" :aria-label="'اختيار '+device.name" @click="selectDevice(device)">اختيار</button>
     </li>
    </ul>
   </section>
   <p v-if="busy&&!radioBusy" class="help" role="status">جارٍ قراءة إعدادات الطابعة…</p>
  </div>
 </CatalogModal>
</template>

<style scoped>
.pos-printer-body{display:flex;flex-direction:column;gap:22px;background:var(--canvas)}
.pos-printer-body>p{margin:0}
.pos-printer-body .pos-printer-current{display:flex;align-items:center;justify-items:normal;text-align:start;gap:13px;min-height:102px;margin:0;padding:17px 16px;border-radius:20px;background:var(--accent-soft);border:1px solid var(--line)}
.pos-printer-current>div{display:grid;gap:3px;flex:1;min-width:0}
.pos-printer-current small{font-size:12px;color:var(--muted)}
.pos-printer-current strong{font-size:20px;line-height:1.4;overflow-wrap:anywhere;max-width:100%;width:fit-content;color:var(--ink)}
.pos-printer-current>div>span{font-size:10px;color:var(--muted)}
.pos-printer-saved-icon{display:grid;place-items:center;flex:none;width:48px;height:48px;background:var(--panel);color:var(--info-accent);border:1px solid var(--line);border-radius:15px}
.pos-printer-saved-icon svg{width:25px;height:25px}
.pos-printer-saved-check{display:grid;place-items:center;flex:none;width:25px;height:25px;border-radius:50%;background:var(--panel);color:var(--info-accent)}
.pos-printer-saved-check svg{width:17px;height:17px}
.pos-printer-section{margin:0;padding:0;border:0;min-width:0}
.pos-printer-section h3{font-size:14px;margin:0 0 10px}
.pos-printer-section-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.pos-printer-section-heading h3{display:flex;align-items:center;gap:7px;margin:0}
.pos-printer-section-heading h3 svg{width:19px;height:19px;color:var(--info-accent);flex:none}
.pos-printer-picker{display:block;position:relative;margin:0;min-width:0;color:var(--ink)}
.pos-printer-picker::after{content:"";position:absolute;inset-inline-end:16px;top:20px;width:6px;height:6px;border-right:1px solid var(--muted);border-bottom:1px solid var(--muted);transform:rotate(45deg);pointer-events:none}
.pos-printer-picker select{appearance:none;width:100%;min-width:0;min-height:49px;font-family:inherit;font-size:14px;background-color:var(--panel);color:var(--ink);border:1px solid var(--line);border-radius:13px;padding:10px 14px;padding-inline-end:40px}
.pos-printer-body .pos-printer-simple-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:0}
.pos-printer-simple-actions>.btn{display:flex;align-items:center;justify-content:center;gap:7px;min-width:0;min-height:46px;padding:8px;font-size:12px;border-radius:13px}
.pos-printer-simple-actions svg{width:17px;height:17px;flex:none}
.pos-printer-search{display:flex;align-items:center;gap:6px;min-height:44px;font-size:11px;padding:7px 10px;border-radius:12px}
.pos-printer-search svg{width:16px;height:16px;flex:none}
.pos-printer-devices{display:grid;gap:0;list-style:none;margin:14px 0 0;padding:0;background:var(--panel);border:1px solid var(--line);border-radius:17px;overflow:hidden}
.pos-printer-devices li{display:flex;align-items:center;gap:11px;padding:14px 12px;min-height:83px;min-width:0}
.pos-printer-devices li+li{border-top:1px solid var(--line)}
.pos-printer-device-mark{display:grid;place-items:center;flex:none;width:35px;height:39px;border-radius:11px;background:var(--canvas);color:var(--muted)}
.pos-printer-device-mark svg{width:20px;height:20px}
.pos-printer-devices li>div{display:grid;gap:5px;min-width:0;flex:1}
.pos-printer-devices strong{font-size:13px;overflow-wrap:anywhere;line-height:1.5}
.pos-printer-devices small{display:flex;align-items:center;flex-wrap:wrap;gap:5px;font-size:10px;color:var(--muted);line-height:1.5;overflow-wrap:anywhere}
.pos-printer-devices small i{height:5px;width:5px;border-radius:50%;background:var(--info-accent)}
.pos-printer-devices button{flex:none;min-height:44px;min-width:59px;padding-inline:10px;font-size:12px;border-radius:11px}
.pos-printer-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:359px){.pos-printer-simple-actions>.btn{font-size:11px}.pos-printer-devices li{gap:8px;padding-inline:10px}.pos-printer-device-mark{width:29px}.pos-printer-search{font-size:10px}}
</style>
