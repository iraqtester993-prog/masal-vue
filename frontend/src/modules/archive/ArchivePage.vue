<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';

import {onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createPagedResource} from '../accounts/paged-resource.js';
import {createOperationsApi} from '../security/operations-api.js';
import {accountTypes,errorText,time} from '../finance/finance-model.js';
import '../finance/finance-parity.css';
const {session} = usePortal(),api=createOperationsApi(session.api),query=ref(''),record=ref(null),state=reactive({error:'',loadingDetail:false});
let reader,revision=0,alive=true,timer;
async function failure(cause) {if(cause.name==='AbortError'||!alive)return '';state.error=errorText(cause);if([401,403].includes(cause.status))await session.refresh();return state.error;}
const listing=createPagedResource((parameters,signal)=>api.archives(parameters,signal),failure),list=listing.state;
function load(page=1){return listing.load({page,per_page:20,query:query.value});}
async function open(id){reader?.abort();reader=new AbortController();const expected=++revision;state.error='';state.loadingDetail=true;record.value=null;try{const response=await api.archive(id,reader.signal);if(alive&&revision===expected)record.value=response.data;}catch(cause){await failure(cause);}finally{if(alive&&revision===expected)state.loadingDetail=false;}}
function close(){revision++;reader?.abort();record.value=null;state.loadingDetail=false;}
function imageUrl(url){const base=(import.meta.env.VITE_API_BASE_URL||'/api/v1').replace(/\/$/,'');return base.replace(/\/api\/v1$/,'')+url;}
watch(query,()=>{clearTimeout(timer);timer=setTimeout(()=>load(),250);});onMounted(()=>{if(session.can('agents.archiveView'))load();});onBeforeUnmount(()=>{alive=false;reader?.abort();listing.dispose();clearTimeout(timer);});
</script>

<template>
  <section class="finance-workspace"><section class="card"><div class="cardhead"><h2>أرشيف المحذوفات</h2><span class="badge">{{list.meta.total}}</span></div><p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p><input type="search" v-model="query" placeholder="بحث بالاسم أو إيميل الدخول" aria-label="بحث المحذوفات"><TablePanel class="tablewrap"><table><thead><tr><th>الحساب</th><th>النوع</th><th>تاريخ الأرشفة</th><th>المنفّذ</th><th>الإجراء</th></tr></thead><tbody><tr v-for="row in list.rows" :key="row.id"><td>{{row.name}}</td><td>{{accountTypes[row.type]}}</td><td>{{time(row.time)}}</td><td>{{row.actor_name}}</td><td><button class="btn small" @click="open(row.id)">معاينة</button></td></tr></tbody></table></TablePanel><p v-if="!list.rows.length" class="empty">{{list.loading?'جارٍ التحميل…':'لا توجد حسابات مؤرشفة'}}</p><div v-if="list.meta.last_page>1" class="actions"><button class="btn" :disabled="list.loading||list.meta.current_page<=1" @click="load(list.meta.current_page-1)">السابق</button><span>{{list.meta.current_page}} / {{list.meta.last_page}}</span><button class="btn" :disabled="list.loading||list.meta.current_page>=list.meta.last_page" @click="load(list.meta.current_page+1)">التالي</button></div>
    <p class="read-loading" v-if="state.loadingDetail" role="status">جارٍ تحميل المعاينة…</p><section v-if="record" class="card" style="margin-top:18px"><div class="cardhead"><h3>{{record.name}}</h3><button class="btn small" @click="close">إغلاق المعاينة</button></div><img v-if="record.image_url" :src="imageUrl(record.image_url)" style="max-width:120px;max-height:120px" alt="صورة الحساب المؤرشف"><dl class="account-fields"><div><dt>معرّف الحساب</dt><dd>{{record.account_id}}</dd></div><div><dt>الجهة الأعلى</dt><dd>{{record.before?.parent_name||'—'}}</dd></div><div><dt>المحافظة</dt><dd>{{record.before?.city||'—'}}</dd></div><div><dt>الهاتف</dt><dd>{{record.before?.phone||'—'}}</dd></div><div><dt>العنوان</dt><dd>{{record.before?.address||'—'}}</dd></div><div><dt>سبب الأرشفة</dt><dd>{{record.reason}}</dd></div><div v-for="user in record.users" :key="user.id"><dt>{{user.name}} — إيميل الدخول</dt><dd>{{user.email||'—'}}</dd></div></dl><div class="actions"><span class="badge">العمليات السابقة: {{record.sales_count}}</span><span class="badge">القيود المالية: {{record.ledger_count}}</span></div></section>
  </section></section>
</template>
