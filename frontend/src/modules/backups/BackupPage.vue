<script setup>
import TablePanel from "../../shared/components/TablePanel.vue";
import {computed,nextTick,onBeforeUnmount,onMounted,onServerPrefetch,reactive,ref,watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createBackupApi} from './backup-api.js';
import {bytes,date,fileForPreview,manifestCounts,restorePayload,statuses,terminalJob} from './backup-model.js';
import './backup.css';
const {session}=usePortal(),api=createBackupApi(session.api);
const backups=ref([]),capabilities=reactive({prepare:false,switch:false,reason:''}),preview=ref(null),job=ref(null);
const loading=ref(false),busy=ref(''),error=ref(''),notice=ref(''),password=ref(''),reviewDialog=ref(null),reviewOpen=ref(false);
const eligible=computed(()=>session.state.identity?.account?.type==='system'&&session.state.identity?.membership?.kind==='owner');
const can=(key)=>eligible.value&&session.can(key);
const counts=computed(()=>manifestCounts(preview.value?.manifest));
let controller,revision=0,disposed=false,jobTimer;
function fail(failure){return Object.values(failure?.errors??{}).flat().join(' ')||failure?.message||'تعذر إكمال الطلب.';}
function request(){controller?.abort();controller=new AbortController();return{signal:controller.signal,version:++revision};}
async function load(){
  if(!can('backup.view'))return;
  const current=request();loading.value=true;error.value='';
  try{const result=await api.list(current.signal);if(current.version!==revision||disposed)return;backups.value=result.data;Object.assign(capabilities,result.capabilities);if(Array.isArray(result.restore_jobs)&&result.restore_jobs.length){job.value=result.restore_jobs[0];clearTimeout(jobTimer);if(typeof window!=='undefined')pollJob();}}
  catch(failure){if(current.version===revision&&!disposed)error.value=fail(failure);}
  finally{if(current.version===revision)loading.value=false;}
}
async function create(){
  if(busy.value||!can('backup.create'))return;
  const current=request();busy.value='create';error.value='';notice.value='';
  try{const result=await api.create(current.signal);if(current.version!==revision||disposed)return;if(result.data?.status!=='completed')throw new Error('لم تكتمل النسخة على السيرفر.');backups.value=[result.data,...backups.value].slice(0,30);notice.value='اكتملت النسخة الاحتياطية الموقعة على السيرفر.';}
  catch(failure){if(current.version===revision&&!disposed)error.value=fail(failure);}
  finally{if(current.version===revision)busy.value='';}
}
async function inspect(source){
  if(busy.value||!can('backup.restore'))return;
  const current=request();busy.value='preview';error.value='';notice.value='';preview.value=null;password.value='';
  try{const result=await api.preview(source,current.signal);if(current.version!==revision||disposed)return;preview.value=result.data;reviewOpen.value=true;await nextTick();reviewDialog.value?.showModal();}
  catch(failure){if(current.version===revision&&!disposed)error.value=fail(failure);}
  finally{if(current.version===revision)busy.value='';}
}
async function prepareRestore(event){
  const file=event.target.files?.[0];event.target.value='';if(!file)return;
  try{await inspect(fileForPreview(file));}catch(failure){error.value=fail(failure);}
}
function closeReview(){if(busy.value)return;reviewOpen.value=false;password.value='';reviewDialog.value?.close();}
async function restore(){
  if(busy.value||!can('backup.restore')||!can('backup.create')||!capabilities.prepare||!capabilities.switch)return;
  let payload;try{payload=restorePayload(preview.value,password.value);}catch(failure){error.value=fail(failure);return;}
  const current=request();busy.value='restore';error.value='';
  try{const result=await api.restore(payload,current.signal);if(current.version!==revision||disposed)return;job.value=result.data;password.value='';busy.value='';closeReview();await pollJob();}
  catch(failure){if(current.version===revision&&!disposed)error.value=fail(failure);}
  finally{payload.current_password='';if(current.version===revision)busy.value='';}
}
async function pollJob(){
  if(disposed||!job.value||terminalJob(job.value.status)||!can('backup.restore'))return;
  const captured=job.value.id;
  try{const result=await api.job(captured);if(disposed||job.value?.id!==captured)return;job.value=result.data;if(job.value.status==='completed'){notice.value='اكتمل استرجاع النسخة والتحويل إلى القاعدة المراجعة.';return;}if(terminalJob(job.value.status)){error.value=job.value.failure||'فشل الاسترجاع؛ راجع حالة العملية.';return;}}
  catch(failure){if(!disposed)error.value=fail(failure);if([401,403].includes(failure.status)){if(!disposed&&session.refresh)await session.refresh();return;}}
  if(!disposed&&!terminalJob(job.value?.status))jobTimer=setTimeout(pollJob,5000);
}
watch(()=>session.state.identity?.user?.id,()=>{controller?.abort();++revision;backups.value=[];preview.value=null;job.value=null;password.value='';busy.value='';reviewDialog.value?.close();reviewOpen.value=false;clearTimeout(jobTimer);if(!disposed)load();});
onMounted(load);onServerPrefetch(load);
onBeforeUnmount(()=>{disposed=true;++revision;controller?.abort();clearTimeout(jobTimer);password.value='';reviewDialog.value?.close();});
</script>
<template>
  <section class="backup-workspace" dir="rtl">
    <p v-if="error" class="backup-error" role="alert">{{error}}</p><p v-if="notice" class="backup-notice" role="status">{{notice}}</p>
    <template v-if="eligible">
      <div class="two">
        <div class="card"><button class="btn primary" type="button" :disabled="!!busy||!can('backup.create')" @click="create">{{busy==='create'?'جارٍ النسخ الاحتياطي…':'نسخ احتياطي'}}</button></div>
        <div class="card"><input type="file" accept=".json" aria-label="اختيار نسخة احتياطية من الحاسبة" :disabled="!!busy||!can('backup.restore')" @change="prepareRestore"><p class="help">نسخة Laravel موقعة، بحد أقصى 15 ميغابايت للرفع من الحاسبة.</p></div>
      </div>
      <p v-if="loading" class="read-loading help" role="status">جارٍ تحميل النسخ المحفوظة…</p>
      <p v-if="busy==='preview'" class="help" role="status">جارٍ فحص توقيع النسخة وعلاقاتها داخل قاعدة مستقلة…</p>
      <div v-if="job" class="card backup-job" role="status"><h2>حالة الاسترجاع</h2><p>{{statuses[job.status]||job.status}}</p><p v-if="job.status==='queued'" class="help">الطلب مسجل، وينتظر تنفيذ عامل الاسترجاع على السيرفر.</p><p v-if="job.failure" class="backup-error">{{job.failure}}</p><code>{{job.id}}</code><p v-if="job.safety_backup_id" class="help">حُفظت نسخة كاملة للنظام الحالي قبل التحويل.</p></div>
      <div class="card backup-list"><div class="backup-heading"><h2>النسخ المحفوظة على السيرفر</h2><button type="button" class="btn small" :disabled="!!busy" @click="load">تحديث</button></div>
        <p v-if="!loading&&!backups.length" class="help">لا توجد نسخة مسجلة بعد.</p>
        <TablePanel v-if="backups.length" class="backup-table-wrap"><table><thead><tr><th>التاريخ</th><th>الحالة</th><th>الحجم</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="item in backups" :key="item.id"><td>{{date(item.created_at)}}</td><td>{{statuses[item.status]||item.status}}<p v-if="item.failure" class="help">{{item.failure}}</p></td><td class="mono">{{bytes(item.bytes)}}</td><td><div class="backup-actions"><a v-if="item.status==='completed'&&can('backup.view')" class="btn small" :href="'/api/v1/backups/'+encodeURIComponent(item.id)+'/download'">تنزيل النسخة</a><button v-if="item.status==='completed'&&can('backup.restore')" type="button" class="btn small" :disabled="!!busy" @click="inspect(item.id)">فحص للاسترجاع</button></div></td></tr></tbody></table></TablePanel>
      </div>
      <Teleport to="body"><dialog v-if="reviewOpen&&preview" ref="reviewDialog" class="backup-review" dir="rtl" aria-label="مراجعة استرجاع النسخة" @cancel.prevent="closeReview">
        <div class="backup-heading"><h2>مراجعة استرجاع النسخة</h2><button class="btn small" type="button" :disabled="!!busy" @click="closeReview">إغلاق</button></div>
        <p class="help">الاسترجاع ينشئ قاعدة جديدة، ويفحصها ثم يحوّل النظام إليها. تُحفظ نسخة كاملة من الحالة الحالية قبل التحويل.</p>
        <dl><dt>الحسابات</dt><dd class="mono">{{counts.accounts??'—'}}</dd><dt>البطاقات</dt><dd class="mono">{{counts.cards??'—'}}</dd><dt>المرفقات</dt><dd class="mono">{{counts.files??'—'}}</dd><dt>إجمالي السجلات</dt><dd class="mono">{{counts.rows??'—'}}</dd><dt>فحص العلاقات</dt><dd>{{preview.reference_verified?'اكتمل داخل قاعدة مستقلة':'لم يكتمل؛ يحتاج بيئة فحص على السيرفر'}}</dd><dt>انتهاء المعاينة</dt><dd>{{date(preview.expires_at)}}</dd></dl>
        <p v-if="!capabilities.prepare||!capabilities.switch" class="help">{{capabilities.reason||'الاسترجاع يحتاج ربط إنشاء قاعدة مستقلة والتحويل الآمن على الاستضافة.'}}</p>
        <p v-if="!can('backup.create')" class="help">الاسترجاع يحتاج صلاحية إنشاء النسخ لحفظ نسخة الأمان الحالية.</p><form @submit.prevent="restore"><label>كلمة المرور الحالية<input v-model="password" type="password" autocomplete="current-password" required maxlength="255" :disabled="!!busy||!can('backup.create')||!capabilities.prepare||!capabilities.switch||!preview.reference_verified"></label><div class="backup-actions"><button class="btn primary" type="submit" :disabled="!!busy||!can('backup.create')||!capabilities.prepare||!capabilities.switch||!preview.reference_verified">{{busy==='restore'?'جارٍ تسجيل طلب الاسترجاع…':'تأكيد الاسترجاع إلى النسخة المراجعة'}}</button><button class="btn" type="button" :disabled="!!busy" @click="closeReview">إلغاء</button></div></form><p v-if="error" class="backup-error" role="alert">{{error}}</p>
      </dialog></Teleport>
    </template>
    <p v-else class="help">النسخ والاسترجاع متاحان لمالك حساب إدارة النظام فقط.</p>
  </section>
</template>
