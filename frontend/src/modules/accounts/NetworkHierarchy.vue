<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';


import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';

import { usePortal } from '../auth/session.js';

import { createFinanceApi, allPages } from '../finance/finance-api.js';

import { money } from '../finance/finance-model.js';

import { accountTypeLabel } from '../../app/portal-config.js';

import NetworkOutline from './NetworkOutline.vue';

import AccountRowActions from './AccountRowActions.vue';

import './network-original.css';

const props=defineProps({mode:String, query:String, type:String, revision:Number});

const emit=defineEmits(['action']);

const {session}=usePortal(), rows=ref([]), balances=ref([]), loading=ref(false), error=ref(''), expanded=reactive({}), opened=ref(null), points=ref(false), activeRoot=ref(null), drillQuery=ref(''), drillPage=ref(1), drillSize=ref(10);

let controller, sequence=0;

async function load(){

  controller?.abort(); controller=new AbortController(); const expected=++sequence; loading.value=true; error.value='';

  try {

    const accounts=[]; let page=1;

    while(true){ const result=await session.api.accounts({page,per_page:100},controller.signal); accounts.push(...result.data); if(page>=(result.meta?.last_page||1))break; if(++page>500)throw Error('الشبكة كبيرة جدًا لعرضها كاملة.'); }

    const wallets=session.can('wallets.view')?await allPages(createFinanceApi(session.api),'wallets',{service:'voucher',currency:'IQD'},controller.signal):[];

    if(expected===sequence){rows.value=accounts.filter(row=>row.type!=='system');balances.value=wallets;}

  }catch(cause){if(cause.name!=='AbortError'&&expected===sequence)error.value=cause.message;}

  finally{if(expected===sequence)loading.value=false;}

}

const balance=id=>balances.value.find(row=>Number(row.account_id)===Number(id))?.balance ?? null;

const children=id=>rows.value.filter(row=>Number(row.parent_id??row.parent?.id)===Number(id));

function node(row,seen=new Set()){

  if(seen.has(row.id))return null;

  const next=new Set([...seen,row.id]);

  return {record:row,color:row.color||({main_agent:'#0891b2',sub_agent:'#8b5cf6',sub_branch:'#059669'}[row.type]),nestedSub:row.type==='sub_branch',balance:balance(row.id),children:children(row.id).filter(child=>child.type!=='pos').map(child=>node(child,next)).filter(Boolean),points:children(row.id).filter(child=>child.type==='pos').map(child=>({...child,balance:balance(child.id)}))};

}

const roots=computed(()=>rows.value.filter(row=>row.type!=='pos'&&!rows.value.some(parent=>parent.id===Number(row.parent_id??row.parent?.id))));

function matches(row){return (!props.type||row.type===props.type)&&(!props.query||`${row.name} ${row.city||''}`.includes(props.query));}

function matchesNode(value){return matches(value.record)||value.children.some(matchesNode)||value.points.some(matches);}

const forest=computed(()=>roots.value.map(row=>node(row)).filter(value=>value&&matchesNode(value)));

function descendants(id, seen=new Set()) { if(seen.has(id))return []; const next=new Set([...seen,id]); return children(id).flatMap(row=>[row,...descendants(row.id,next)]); }

const drilled=computed(()=>opened.value?(points.value?descendants(opened.value.id).filter(row=>row.type==='pos'):children(opened.value.id).filter(row=>row.type!=='pos')):[]);

const drillPath=computed(()=>{const path=[];let row=opened.value;const seen=new Set();while(row&&!seen.has(row.id)){path.unshift(row);seen.add(row.id);if(row.id===activeRoot.value)break;row=rows.value.find(value=>value.id===Number(row.parent_id??row.parent?.id));}return path;});

const matchedDrill=computed(()=>drilled.value.filter(row=>`${row.name} ${row.city||''} ${row.phone||''}`.includes(drillQuery.value)));

const drillPages=computed(()=>Math.max(1,Math.ceil(matchedDrill.value.length/drillSize.value)));

const shownDrill=computed(()=>matchedDrill.value.slice((drillPage.value-1)*drillSize.value,drillPage.value*drillSize.value));

watch([opened,points,drillQuery,drillSize],()=>drillPage.value=1);

function openRoot(row,showPoints){activeRoot.value=row.id;opened.value=row;points.value=showPoints;drillQuery.value='';}

watch(()=>props.revision,load); onMounted(load);

onBeforeUnmount(()=>{sequence++;controller?.abort();});

</script>

<template>

  <p class="read-loading" v-if="loading" role="status">جارٍ تحميل الشبكة…</p><p v-if="error" class="notice warn" role="alert">{{error}} <button class="btn" @click="load">إعادة المحاولة</button></p>

  <template v-if="!loading && !error">

    <div v-if="mode==='compact'" class="network-overview"><div class="network-legend"><span>● وكيل رئيسي</span><span>● وكيل فرعي</span><span>● نقطة بيع</span></div><ul class="branch-list-forest"><NetworkOutline v-for="entry in forest" :key="entry.record.id" :node="entry" :expanded="expanded" @toggle="id=>expanded[id]=expanded[id]===false" /></ul></div>

    <div v-else class="network-drill"><p class="caption">الفروع المباشرة ونقاط البيع بكامل شبكة الحساب · يظهر الوكيل المباشر بجانب كل نقطة</p>

      <section v-for="entry in forest" :key="entry.record.id" class="drill-root" :style="{'--agent-color':entry.color}"><div class="drill-account"><div class="drill-name"><span class="badge">{{accountTypeLabel(entry.record.type)}}</span><h3>{{entry.record.name}}</h3><small>{{entry.record.city||'—'}}</small></div><div class="drill-balance"><small>رصيد البطاقات</small><strong>{{money(entry.balance)}} <small>د.ع</small></strong></div><div class="actions"><button class="btn" @click="openRoot(entry.record,false)">الفروع ({{entry.children.length}})</button><button class="btn" @click="openRoot(entry.record,true)">نقاط الشبكة ({{entry.record.network_pos_count??descendants(entry.record.id).filter(row=>row.type==='pos').length}})</button><AccountRowActions :account="entry.record" compact tree @details="emit('action','details',$event)" @edit="emit('action','edit',$event)" @categories="emit('action','categories',$event)" @permissions="emit('action','permissions',$event)" @archive="emit('action','archive',$event)" @status="emit('action','status',$event)" /></div></div>      <div v-if="opened && activeRoot===entry.record.id" class="drill-panel"><div class="drill-path"><div><button v-for="item in drillPath" class="btn small" @click="opened=item">{{item.name}}</button></div><button class="btn small" :disabled="drillPath.length<2" @click="opened=drillPath[drillPath.length-2]">رجوع</button><button class="btn small" @click="opened=null">إغلاق</button></div><div class="tabs"><button :class="{active:!points}" @click="points=false">الفروع</button><button :class="{active:points}" @click="points=true">نقاط الشبكة</button></div><div class="drill-tools"><input v-model="drillQuery" type="search" placeholder="بحث بالاسم أو المحافظة أو الهاتف"><select v-model.number="drillSize" aria-label="عدد التابعين"><option v-for="size in [10,25,50,100]" :value="size">{{size}}</option></select></div><TablePanel class="tablewrap"><table><thead><tr><th>الاسم</th><th>النوع</th><th>رصيد البطاقات</th><th>{{points?'الوكيل المباشر':'الفروع / النقاط'}}</th><th>الإجراء</th></tr></thead><tbody><tr v-for="row in shownDrill" :key="row.id"><td>{{row.name}}</td><td>{{accountTypeLabel(row.type)}}</td><td>{{money(balance(row.id))}} د.ع</td><td>{{points ? row.parent?.name || '—' : children(row.id).filter(child=>child.type!=='pos').length+' / '+descendants(row.id).filter(child=>child.type==='pos').length}}</td><td><button v-if="row.type!=='pos'" class="btn small" @click="opened=row">الفروع</button><button class="btn small" @click="emit('action','details',row)">التفاصيل</button><button v-if="session.can('account.create') && row.type!=='pos'" class="btn small" @click="emit('action','create',row)">إنشاء حساب تابع</button></td></tr></tbody></table></TablePanel><p v-if="!matchedDrill.length" class="empty">لا توجد حسابات مطابقة</p><div class="table-pagination-footer"><button class="btn small" :disabled="drillPage<=1" @click="drillPage--">السابق</button><span>الصفحة {{drillPage}} من {{drillPages}}</span><button class="btn small" :disabled="drillPage>=drillPages" @click="drillPage++">التالي</button></div></div></section>



    </div><p v-if="!forest.length" class="empty">لا توجد حسابات مطابقة</p>

  </template>

</template>

<style scoped>

.network-legend{display:flex;gap:14px;border:1px solid var(--line);border-radius:24px;padding:10px 16px;width:max-content;margin:0 0 20px}.network-legend span:nth-child(1){color:#168baf}.network-legend span:nth-child(2){color:#8b5cf6}.network-legend span:nth-child(3){color:#059669}.drill-balance{display:grid;gap:6px;min-width:160px}.drill-balance strong{font-size:20px}.drill-account>.actions{margin-inline-start:auto}.drill-path{display:flex;justify-content:space-between}.drill-root{margin-bottom:16px}

.drill-account :deep(.actions>.actions){display:contents}.drill-account :deep(.btn){font-size:11px;min-height:34px;padding:6px 10px}

</style>

