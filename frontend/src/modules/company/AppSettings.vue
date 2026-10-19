<script setup>
import {computed,nextTick,onBeforeUnmount,onMounted,ref,watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {useRouteRefresh} from '../../shared/route-refresh.js';
const {session}=usePortal(),data=ref(null),busy=ref(false),loading=ref(false),error=ref(''),notice=ref('');
function permitted(permission,capability){
 const identity=session.state.identity;
 const scope=identity?.capabilities?.system_scope;
 return identity?.account?.type==='system'&&session.can(permission)&&(scope===true||scope===undefined&&identity.membership?.kind==='owner')&&identity.capabilities?.[capability]!==false;
}
const canSettings=computed(()=>permitted('app.settings','app_settings')),canPrinters=computed(()=>permitted('printers.manage','printers_manage'));
const allowed=computed(()=>canSettings.value||canPrinters.value);
const selectedTab=ref('settings');
const tabs=computed(()=>[
 ...(canSettings.value?[{id:'settings',label:'إعدادات تطبيق دنانير'}]:[]),
 ...(canPrinters.value?[{id:'printers',label:'الطابعات المدعومة'}]:[]),
]);
const activeTab=computed(()=>tabs.value.some(tab=>tab.id===selectedTab.value)?selectedTab.value:tabs.value[0]?.id);
function moveTab(event,index){
 let next;
 const direction=event.currentTarget?.closest?.('[dir]')?.dir||globalThis.document?.documentElement?.dir||'rtl';
 if(event.key==='Home')next=0;
 else if(event.key==='End')next=tabs.value.length-1;
 else if(['ArrowLeft','ArrowRight'].includes(event.key))next=(index+(event.key===(direction==='rtl'?'ArrowLeft':'ArrowRight')?1:-1)+tabs.value.length)%tabs.value.length;
 else return;
 event.preventDefault();selectedTab.value=tabs.value[next].id;
 const list=event.currentTarget?.parentElement;
 void nextTick(()=>list?.querySelectorAll('[role="tab"]')[next]?.focus());
}
const scope=computed(()=>`${session.state.identity?.user?.id}:${session.state.identity?.account?.id}:${canSettings.value}:${canPrinters.value}`);
const saved=ref(''),fields=['name','about','terms','privacy'];
let alive=true,mounted=false,revision=0,reader,writer=new AbortController();
function configPayload(config,{includePrinters=true}={}){
 const result={};
 if(canSettings.value)for(const field of fields)result[field]=config[field];
 if(includePrinters&&canPrinters.value)result.printers=config.printers.map(({id,name,profile,match,enabled})=>({id,name,profile,match,enabled:enabled!==false&&enabled!==0}));
 return result;
}
function readData(value){
 if(!Number.isInteger(value?.version)||value.version<1||!value.config||typeof value.config!=='object')throw Error('تعذر قراءة إعدادات التطبيق. أعد المحاولة.');
 if(canSettings.value&&fields.some(field=>typeof value.config[field]!=='string'&&!(field!=='name'&&value.config[field]===null)))throw Error('تعذر قراءة حقول إعدادات التطبيق.');
 if(canPrinters.value&&(!Array.isArray(value.config.printers)||value.config.printers.some(row=>!row||['id','name','profile','match'].some(field=>typeof row[field]!=='string'))))throw Error('تعذر قراءة إعدادات الطابعات.');
 const config=configPayload(value.config);
 if(canSettings.value)for(const field of fields)config[field]??='';
 return {version:value.version,config};
}
function current(expected,actor){return alive&&revision===expected&&scope.value===actor&&allowed.value;}
function denied(cause){
 if(![401,403].includes(cause.status))return;
 data.value=null;saved.value='';session.refresh?.({background:true})?.catch?.(()=>{});
}
async function load({preserve=false,signal}={}){
 if(!alive||!allowed.value||busy.value||signal?.aborted)return false;
 reader?.abort();const controller=new AbortController();reader=controller;
 const expected=++revision,actor=scope.value,before=JSON.stringify(data.value),abort=()=>controller.abort();
 signal?.addEventListener('abort',abort,{once:true});loading.value=true;error.value='';
 try{
  const response=await session.api.request('/company/app-config?manage=1',{signal:controller.signal,fresh:true});
  if(!current(expected,actor)||controller.signal.aborted||preserve&&JSON.stringify(data.value)!==before)return false;
  data.value=readData(response.data);saved.value=JSON.stringify(data.value);return true;
 }catch(cause){if(current(expected,actor)&&cause.name!=='AbortError'){denied(cause);error.value=cause.message;}return false;}
 finally{signal?.removeEventListener('abort',abort);if(current(expected,actor))loading.value=false;}
}
async function save(){
 if(!alive||!canSettings.value||activeTab.value!=='settings'||!data.value||busy.value||loading.value)return;
 const expected=revision,actor=scope.value,payload={version:data.value.version,config:configPayload(data.value.config,{includePrinters:false})};
 busy.value=true;error.value='';notice.value='';
 try{
  const response=await session.api.mutate('/company/app-config','PUT',payload,writer.signal);
  if(!current(expected,actor))return;
  data.value=readData(response.data);saved.value=JSON.stringify(data.value);notice.value='تم حفظ إعدادات تطبيق دنانير.';
 }catch(cause){if(current(expected,actor)&&cause.name!=='AbortError'){denied(cause);error.value=cause.message;}}
 finally{if(current(expected,actor))busy.value=false;}
}
watch(scope,()=>{revision++;reader?.abort();writer.abort();writer=new AbortController();data.value=null;saved.value='';error.value='';notice.value='';busy.value=false;loading.value=false;if(mounted&&allowed.value)void load();});
onMounted(()=>{mounted=true;void load();});
onBeforeUnmount(()=>{alive=false;revision++;reader?.abort();writer.abort();});
useRouteRefresh(({signal})=>load({preserve:true,signal}),{canRefresh:()=>allowed.value&&!busy.value&&!loading.value&&!!data.value&&JSON.stringify(data.value)===saved.value});
</script>
<template>
 <section v-if="allowed" class="card app-settings">
  <div class="tabs app-settings-tabs" role="tablist" aria-label="إعدادات التطبيق والطابعات">
   <button v-for="(tab,index) in tabs" :id="'app-settings-tab-'+tab.id" :key="tab.id" type="button" role="tab" :class="{active:activeTab===tab.id}" :aria-selected="activeTab===tab.id" :aria-controls="'app-settings-panel-'+tab.id" :tabindex="activeTab===tab.id?0:-1" @click="selectedTab=tab.id" @keydown="moveTab($event,index)">{{tab.label}}</button>
  </div>
  <p v-if="loading" class="read-loading" role="status">جارٍ تحميل الإعدادات…</p>
  <p v-if="error" class="notice warn" role="alert">{{error}}</p>
  <p v-if="notice" class="notice" role="status">{{notice}}</p>
  <section v-if="activeTab==='settings'&&canSettings" id="app-settings-panel-settings" role="tabpanel" aria-labelledby="app-settings-tab-settings" tabindex="0">
   <form v-if="data" @submit.prevent="save">
    <fieldset :disabled="busy||loading" class="formgrid">
     <label>اسم الشركة في التطبيق<input v-model="data.config.name" required maxlength="120"></label>
     <label>عن شركة دنانير<textarea v-model="data.config.about" maxlength="10000" rows="3"></textarea></label>
     <label>الشروط والأحكام<textarea v-model="data.config.terms" maxlength="20000" rows="4"></textarea></label>
     <label>سياسة الخصوصية<textarea v-model="data.config.privacy" maxlength="20000" rows="4"></textarea></label>
    </fieldset>
    <footer class="formfoot"><button class="btn primary" :disabled="busy||loading">{{busy?'جارٍ الحفظ…':'حفظ إعدادات التطبيق'}}</button></footer>
   </form>
  </section>
  <section v-if="activeTab==='printers'&&canPrinters" id="app-settings-panel-printers" role="tabpanel" aria-labelledby="app-settings-tab-printers" tabindex="0">
   <template v-if="data">
    <h3 id="app-printer-title">الطابعات المدعومة · 58 و80 ملم</h3>
    <div v-if="data.config.printers.length" class="tablewrap app-printer-table-wrap" role="region" aria-labelledby="app-printer-title" tabindex="0">
     <table class="app-printer-table" aria-labelledby="app-printer-title">
      <thead><tr><th scope="col">اسم العرض</th><th scope="col">المحرك</th><th scope="col">عبارة المطابقة</th><th scope="col">الحالة</th></tr></thead>
      <tbody>
       <tr v-for="printer in data.config.printers" :key="printer.id">
        <th scope="row">{{printer.name}}</th>
        <td><bdi dir="ltr">{{printer.profile==='GOOJPRT'?'GOOJPRT / MTP-2':printer.profile}}</bdi></td>
        <td><bdi dir="ltr">{{printer.match}}</bdi></td>
        <td><span class="status-badge app-printer-status" :class="{'status-inactive':!printer.enabled}">{{printer.enabled?'مفعّل':'معطّل'}}</span></td>
       </tr>
      </tbody>
     </table>
    </div>
    <p v-if="!data.config.printers.length" class="empty">لا توجد طابعات مدعومة.</p>
   </template>
  </section>
 </section>
</template>
<style scoped>
.app-settings{margin-block:18px;min-width:0}
.app-settings-tabs{margin-bottom:18px}
.app-settings-tabs>button{min-height:44px}
.app-settings textarea{width:100%}
.app-settings fieldset{border:0;padding:0}
.app-printer-table-wrap{margin-top:14px;border:1px solid var(--line);border-radius:12px;background:var(--panel)}
.app-printer-table{table-layout:fixed;min-width:620px}
.app-settings .app-printer-table :is(th,td){padding:14px 16px;vertical-align:middle;text-align:start;white-space:normal;overflow-wrap:anywhere;line-height:1.8}
.app-printer-table thead th{font-weight:700;color:var(--muted)}
.app-printer-table thead th:nth-child(1){width:28%}
.app-printer-table thead th:nth-child(2){width:25%}
.app-printer-table thead th:nth-child(3){width:31%}
.app-printer-table thead th:nth-child(4){width:16%}
.app-printer-table tbody th{background:transparent;font-size:13px;font-weight:700;color:var(--ink)}
.app-printer-table tbody td{color:var(--text)}
.app-printer-table tbody tr:nth-child(even){background:var(--raised)}
.app-printer-table :is(th,td)+:is(th,td){border-inline-start:1px solid var(--soft-line)}
.app-printer-table tbody tr:last-child :is(th,td){border-bottom:0}
.app-settings .app-printer-table :is(th,td):last-child{text-align:center}
.app-printer-status{display:inline-flex;justify-content:center;min-width:64px;white-space:nowrap;font-weight:700}
@media(max-width:650px){.app-settings .app-printer-table :is(th,td){padding:12px}}
</style>
