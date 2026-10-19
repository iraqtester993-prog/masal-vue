<script setup>
import AppSelect from '../../shared/components/AppSelect.vue';
import AccountPicker from './AccountPicker.vue';
import TablePanel from '../../shared/components/TablePanel.vue';
import FundingAmountPicker from './FundingAmountPicker.vue';


import { vMoney } from './money-input.js';

import {computed,inject,onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';

import {amount,decimal,errorText,financeKey,minor,money,positive,statuses,sum,time} from './finance-model.js';

import FinancePagination from './FinancePagination.vue';

const vm=inject(financeKey), tab=ref('history'), value=ref(''), note=ref(''), to=ref(''), reference=ref(''), directConfirm=ref(false), filter=ref(''), recordType=ref('requests'), detail=ref(null), review=reactive({}), policyOpen=ref(false), policyDraft=reactive({daily_limit:1,amounts:'',recovery_hours:0});

const requests=vm.resource('funding-requests'), incoming=vm.resource('funding-requests'), transfers=vm.resource('transfers');

const requestKey=vm.key(), directKey=vm.key(), reviewKeys=new Map(), cancelKeys=new Map();
const directReading=ref(false),preparedDirect=ref(null);

const parent=computed(()=>vm.service.value==='topup'?vm.options.topup_funder_account_id:vm.options.parent_account_id), pointRequester=computed(()=>vm.identity.value?.account.type==='pos');
const funderName=computed(()=>vm.service.value==='topup'?(vm.options.topup_funder_account_name||'الوكيل الرئيسي'):(vm.options.parent_account_name||vm.name(parent.value)));
const fundingTargets=computed(()=>vm.service.value==='topup'?vm.options.accounts.filter(row=>row.type==='pos'):vm.children.value);

const dailyUsed=computed(()=>vm.options.counts?.today_requests), dailyRemaining=computed(()=>dailyUsed.value===undefined?null:Math.max(0,(vm.options.funding_policy?.daily_limit ?? 0)-dailyUsed.value));

const available=computed(()=>vm.walletAmount(vm.ownId.value,vm.service.value,vm.currency.value));

const tabs=computed(()=>[

  ...(!vm.admin.value && vm.ownVisible.value && parent.value && (vm.service.value!=='topup'||pointRequester.value) && vm.can('wallets.request')?[{id:'request',name:'طلب تمويل'}]:[]),

  ...(vm.can('wallets.approve')?[{id:'incoming',name:'طلبات واردة'}]:[]),

  ...(!vm.admin.value && vm.ownVisible.value && vm.hasChildren.value && (vm.service.value!=='topup'||vm.identity.value?.account.type==='main_agent') && vm.can('wallets.transfer')?[{id:'direct',name:'تمويل مباشر'}]:[]),

  {id:'history',name:'سجل التمويل'},

]);

function loadRequests(page=1,options){if(vm.emptyScope()){requests.dispose();return;}return requests.load(vm.recordParameters({status:filter.value,per_page:25,page}),options);}

function loadIncoming(page=1,options){if(!vm.can('wallets.approve'))return;if(vm.emptyScope()){incoming.dispose();return;}return incoming.load(vm.recordParameters({status:'pending',direction:'incoming',per_page:25,page}),options);}

function loadTransfers(page=1,options){if(vm.emptyScope()){transfers.dispose();return;}return transfers.load(vm.recordParameters({per_page:25,page}),options);}
const fundingHistory=computed(()=>recordType.value==='requests'?requests:transfers);
function fundingStatusClass(status){return status==='pending'?'warn':['approved','posted'].includes(status)?'good':status==='rejected'?'danger':'neutral';}
const unregisterRefresh=vm.registerRefresh?.(()=>Promise.all([loadRequests(requests.state.meta.current_page,{fresh:true}),loadIncoming(incoming.state.meta.current_page,{fresh:true}),loadTransfers(transfers.state.meta.current_page,{fresh:true})]));
onBeforeUnmount(()=>unregisterRefresh?.());

async function refresh(){await vm.refresh();await Promise.all([loadRequests(),loadIncoming(),loadTransfers()]);}

watch([vm.service,vm.currency],()=>{value.value='';directConfirm.value=false;preparedDirect.value=null;detail.value=null;if(!tabs.value.some(row=>row.id===tab.value))tab.value=tabs.value[0]?.id||'history';loadRequests();loadIncoming();loadTransfers();});

watch(vm.filters,()=>{detail.value=null;loadRequests();loadIncoming();loadTransfers();},{deep:true});

watch([value,to,reference],()=>{directConfirm.value=false;preparedDirect.value=null;});

watch(filter,()=>loadRequests());

watch(recordType,()=>{detail.value=null;});

onMounted(()=>{tab.value=tabs.value[0]?.id || 'history';loadRequests();loadIncoming();loadTransfers();});

function payloadAmount(){if(!vm.service.value)throw new Error('اختر محفظة من كروت المحافظ.');return amount(value.value);}

async function requestFunding(){try{if(vm.service.value==='topup'&&!pointRequester.value)throw new Error('رصيد Topup للوكيل يُمنح من تخصيص الإدارة.');const result=await vm.execute({service:vm.service.value,currency:vm.currency.value,amount:payloadAmount(),purpose:note.value.trim() || null},requestKey,body=>vm.api.create('funding-requests',body),'تم إرسال طلب التمويل.');if(result){value.value='';note.value='';await refresh();tab.value='history';}}catch(failed){vm.error.value=errorText(failed);}}

async function direct(){
 if(vm.busy.value||directReading.value)return;
 try{
  const payload={from_account_id:vm.ownId.value,to_account_id:Number(to.value),service:vm.service.value,currency:vm.currency.value,amount:payloadAmount(),reference:reference.value.trim()};
  if(!to.value||!fundingTargets.value.some(row=>Number(row.id)===payload.to_account_id))throw new Error('اختر تابعًا متاحًا.');
  if(vm.admin.value&&payload.service==='topup')throw new Error('رصيد Topup للوكيل يُمنح من تخصيص الإدارة.');
  if(payload.service==='topup'&&vm.identity.value?.account.type!=='main_agent')throw new Error('تمويل Topup من الوكيل الرئيسي إلى نقطة بيع تابعة فقط.');
  if(!directConfirm.value||JSON.stringify(preparedDirect.value)!==JSON.stringify(payload)){
   directConfirm.value=false;preparedDirect.value=null;directReading.value=true;
   const rows=await vm.loadWallets(undefined,[payload.from_account_id,payload.to_account_id],{fresh:true});
   const current={from_account_id:vm.ownId.value,to_account_id:Number(to.value),service:vm.service.value,currency:vm.currency.value,amount:payloadAmount(),reference:reference.value.trim()};
   if(JSON.stringify(current)!==JSON.stringify(payload))return;
   if(!Array.isArray(rows))throw new Error('تعذر تحديث رصيد التمويل؛ أعد المحاولة.');
   const funded=sum(rows.filter(row=>Number(row.account_id)===payload.from_account_id&&row.service===payload.service&&row.currency===payload.currency).map(row=>row.available));
   if(minor(payload.amount)>minor(funded))throw new Error('الرصيد المتاح لا يكفي.');
   preparedDirect.value=Object.freeze(payload);directConfirm.value=true;return;
  }
  const result=await vm.execute(preparedDirect.value,directKey,body=>vm.api.create('transfers',body),'تم تنفيذ التمويل.');
  if(result){value.value='';to.value='';reference.value='';directConfirm.value=false;preparedDirect.value=null;await refresh();}
 }catch(failed){vm.error.value=errorText(failed);}finally{directReading.value=false;}
}

async function batches(row,page=1){const draft=review[row.id];draft.loading=true;draft.error='';try{const result=await vm.api.list('funding-requests/'+row.id+'/batches',{page});draft.batches=result.data;draft.meta=result.meta;}catch(failure){draft.error=errorText(failure);}finally{draft.loading=false;}}
function edit(row){review[row.id] ??= {batch:'',reference:'',reason:'',batches:[],meta:{current_page:1,last_page:1},loading:false,error:''};if(vm.admin.value&&row.service==='voucher')void batches(row);}

async function decide(row,decision){if(decision==='approve'&&vm.admin.value&&row.service==='topup'){vm.error.value='رصيد Topup للوكيل يُمنح من تخصيص الإدارة.';return;}const draft=review[row.id];let key=reviewKeys.get(row.id);if(!key){key=vm.key();reviewKeys.set(row.id,key);}const payload={version:row.version,decision,...(decision==='approve'?{reference:draft.reference.trim(),...(vm.admin.value && row.service==='voucher'?{stock_batch_id:Number(draft.batch)}:{})}:{reason:draft.reason.trim()})};const result=await vm.execute(payload,key,body=>vm.api.action('funding-requests',row.id,'review',body),decision==='approve'?'تم اعتماد طلب التمويل.':'تم رفض طلب التمويل.');if(result){delete review[row.id];await refresh();}}

async function cancel(row){let key=cancelKeys.get(row.id);if(!key){key=vm.key();cancelKeys.set(row.id,key);}const result=await vm.execute({version:row.version},key,body=>vm.api.action('funding-requests',row.id,'cancel',body),'تم إلغاء طلب التمويل.');if(result){detail.value=null;await refresh();}}

function showPolicy(){const policy=vm.options.funding_policy;if(!policy)return;Object.assign(policyDraft,{daily_limit:policy.daily_limit,amounts:policy.amounts.join('\n'),recovery_hours:policy.recovery_hours});policyOpen.value=!policyOpen.value;}

async function savePolicy(){try{const amounts=policyDraft.amounts.split(/[\n;]+/).map(raw=>raw.trim()).filter(Boolean).map(raw=>amount(raw));if(new Set(amounts).size!==amounts.length)throw new Error('يوجد مبلغ مكرر.');const result=await vm.savePolicy({version:vm.options.funding_policy.version,daily_limit:Number(policyDraft.daily_limit),amounts,...(vm.can('security.fundingRecovery')?{recovery_hours:Number(policyDraft.recovery_hours)}:{})});if(result){policyOpen.value=false;await refresh();}}catch(failed){vm.error.value=errorText(failed);}}

function afterTransfer(){try{return available.value===null?'—':money(decimal(minor(available.value)-minor(value.value || '0')));}catch{return '—';}}

</script>

<template><section class="simple-wallets"><div class="card wallet-funding-content"><div class="wallet-funding-body"><p v-if="!pointRequester && (tab==='request' || tab==='direct')" class="caption">محفظة التمويل: {{vm.service.value?vm.serviceName(vm.service.value):'اختر محفظة من كروت المحافظ'}}</p><div class="tabs"><button v-for="item in tabs" :key="item.id" :class="{active:tab===item.id}" @click="tab=item.id;vm.error.value='';directConfirm=false">{{item.name}}<span v-if="item.id==='incoming' && vm.options.counts?.pending_incoming" class="badge">{{vm.options.counts.pending_incoming}}</span></button></div>

  <form v-if="tab==='request'" @submit.prevent="requestFunding"><div class="formgrid funding-request-fields"><label>إلى الجهة الأعلى<input :value="funderName" readonly></label><label v-if="pointRequester">المبلغ المطلوب<FundingAmountPicker v-model="value" :amounts="vm.options.funding_policy?.amounts || []" :currency="vm.currencyLabel(vm.currency.value)" :disabled="vm.busy.value"/></label><label v-else>المبلغ المطلوب<input v-model="value" type="text" inputmode="decimal" v-money required></label><label>ملاحظة اختيارية<input v-model="note" maxlength="1000"></label></div><p v-if="!pointRequester && vm.service.value==='voucher' && vm.options.parent_account_type==='system'" class="help">رصيد البطاقات يجهّز بطلبية مخزون معتمدة بنفس القيمة.</p><div v-if="pointRequester" class="funding-daily-status"><span>طلبات اليوم: {{dailyUsed ?? '—'}} / {{vm.options.funding_policy?.daily_limit ?? '—'}}</span><span>المتبقي اليوم: {{dailyRemaining ?? '—'}}</span></div><p v-if="pointRequester && !(vm.options.funding_policy?.amounts.length)" class="notice warn">طلبات التمويل غير متاحة حاليًا؛ لم تعتمد الإدارة مبالغ للطلبات.</p><p v-else-if="pointRequester && dailyRemaining===0" class="notice warn">وصلت إلى عدد الطلبات المسموح اليوم؛ يمكنك الطلب غدًا بتوقيت بغداد.</p><div class="actions"><button class="btn primary" :disabled="vm.busy.value || !vm.service.value || !positive(value) || (pointRequester && (dailyRemaining===0 || !vm.options.funding_policy?.amounts.includes(value)))">إرسال طلب التمويل</button></div></form>

  <form v-if="tab==='direct'" @submit.prevent="direct"><div class="formgrid"><label>المستفيد<AccountPicker v-model="to" :vm="vm" :parameters="{...(vm.service.value==='topup'?{ancestor_id:vm.ownId.value,exclude_account_id:vm.ownId.value,account_types:['pos']}:{parent_id:vm.ownId.value}),account_status:'active'}" label="المستفيد" :clearable="false"/></label><label>مبلغ التمويل<input v-model="value" type="text" inputmode="decimal" v-money required></label><label>مرجع التمويل / التحصيل<input v-model="reference" required maxlength="200"></label></div><p>المتاح: {{money(available)}} {{vm.currencyLabel(vm.currency.value)}} · المتبقي بعد التحويل: {{afterTransfer()}} {{vm.currencyLabel(vm.currency.value)}}</p><p v-if="directConfirm" class="notice">تأكيد تحويل {{money(value)}} {{vm.currencyLabel(vm.currency.value)}} إلى {{vm.name(to)}} من {{vm.serviceName(vm.service.value)}}.</p><button class="btn primary" :disabled="vm.busy.value || directReading || !to || !positive(value) || !reference.trim() || !vm.service.value">{{directReading?'جارٍ التحقق من رصيد التمويل…':directConfirm?'تأكيد التمويل':'تمويل'}}</button></form>


  <div v-if="tab==='incoming'"><p v-if="incoming.state.error" class="notice warn" role="alert">{{incoming.state.error}}</p><p v-if="incoming.state.loading" class="read-loading caption">جارٍ تحميل الطلبات…</p><article v-for="row in incoming.state.rows" :key="row.id" class="wallet-request"><div class="rowline"><strong>{{row.to_account_name || vm.name(row.to_account_id)}}</strong><strong>{{money(row.amount)}} {{vm.currencyLabel(row.currency)}} · {{vm.serviceName(row.service)}}</strong><span class="badge" :class="fundingStatusClass(row.status)">{{statuses[row.status] || row.status}}</span></div><p v-if="row.purpose">{{row.purpose}}</p><small>{{time(row.created_at)}} · {{row.id}}</small><div v-if="vm.admin.value && row.service==='voucher'" class="notice">الرصيد يُضاف عند اعتماد الطلبية. ربطها هنا لا يضيف رصيدًا ثانيًا.</div><div v-if="vm.admin.value && row.service==='topup'" class="notice">رصيد Topup للوكيل يُمنح من تخصيص الإدارة.</div><div v-if="!vm.admin.value" class="caption">رصيدك المتاح لهذه المحفظة: {{money(vm.walletAmount(vm.ownId.value,row.service,row.currency))}} {{vm.currencyLabel(row.currency)}}</div><button v-if="!review[row.id]" class="btn" @click="edit(row)">مراجعة الطلب</button><div v-else class="wallet-request-review"><label v-if="vm.admin.value && row.service==='voucher'">الطلبية المعتمدة<AppSelect v-model="review[row.id].batch" :disabled="review[row.id].loading"><option value="">{{review[row.id].loading?'جارٍ تحميل الطلبيات…':'اختر طلبية مطابقة للوكيل والمبلغ'}}</option><option v-for="batch in review[row.id].batches" :key="batch.id" :value="batch.id">{{batch.label}}</option></AppSelect><small v-if="!review[row.id].loading&&!review[row.id].batches.length">لا توجد طلبية معتمدة مطابقة لهذا الطلب وغير مرتبطة بتمويل آخر.</small><small v-if="review[row.id].error" role="alert">{{review[row.id].error}}</small><FinancePagination v-if="review[row.id].meta.last_page>1" :meta="review[row.id].meta" :busy="review[row.id].loading" @page="batches(row,$event)"/></label><label>مرجع التمويل<input v-model="review[row.id].reference" maxlength="200" placeholder="رقم التحويل أو السند"></label><div class="actions"><button v-if="!(vm.admin.value && row.service==='topup')" class="btn primary" :disabled="vm.busy.value || !review[row.id].reference.trim() || (vm.admin.value && row.service==='voucher' && !review[row.id].batch)" @click="decide(row,'approve')">{{vm.admin.value && row.service==='voucher'?'اعتماد وربط الطلبية':'موافقة وتمويل'}}</button></div><label>سبب الرفض<input v-model="review[row.id].reason" maxlength="1000"></label><button class="btn danger" :disabled="vm.busy.value || !review[row.id].reason.trim()" @click="decide(row,'reject')">رفض الطلب</button></div></article><p v-if="!incoming.state.rows.length && !incoming.state.loading" class="empty">لا توجد طلبات واردة بانتظار الإجراء</p><FinancePagination v-if="incoming.state.meta.total>0" :meta="incoming.state.meta" :busy="incoming.state.loading" @page="loadIncoming" /></div>

  <div v-if="tab==='history'"><div class="wallet-history-filter"><label>السجل<AppSelect v-model="recordType"><option value="requests">طلبات التمويل</option><option value="transfers">التحويلات المنفذة</option></AppSelect></label><label v-if="recordType==='requests'">حالة الطلب<AppSelect v-model="filter"><option value="">كل الحالات</option><option v-for="state in ['pending','approved','rejected','cancelled']" :key="state" :value="state">{{statuses[state]}}</option></AppSelect></label></div><p v-if="(recordType==='requests'?requests:transfers).state.error" class="notice warn" role="alert">{{(recordType==='requests'?requests:transfers).state.error}}</p><p v-if="(recordType==='requests'?requests:transfers).state.loading" class="read-loading caption">جارٍ تحميل السجل…</p><div v-if="pointRequester" class="pos-funding-records">
    <article v-for="row in fundingHistory.state.rows" :key="row.id" class="pos-funding-record">
     <header><span>{{recordType==='requests'?'طلب تمويل':'تحويل منفذ'}} <b class="mono">#{{row.id}}</b></span><span class="badge" :class="recordType==='requests'?fundingStatusClass(row.status):'good'">{{recordType==='requests'?statuses[row.status]:'منفذ'}}</span></header>
     <strong class="pos-funding-record-amount mono">{{money(row.amount)}} <small>{{vm.currencyLabel(row.currency)}}</small></strong>
     <dl><div><dt>المحفظة</dt><dd>{{vm.serviceName(row.service)}}<small v-if="row.legacy_service">{{row.source_label||'رصيد سابق قيد المطابقة'}}</small></dd></div><div><dt>الجهة الممولة</dt><dd>{{row.from_account_name || vm.name(row.from_account_id)}}</dd></div><div><dt>المستفيد</dt><dd>{{row.to_account_name || vm.name(row.to_account_id)}}</dd></div><div><dt>التاريخ والوقت</dt><dd>{{time(row.created_at)}}</dd></div></dl>
     <footer><button type="button" class="btn small" :aria-expanded="detail?.id===row.id" @click="detail=detail?.id===row.id?null:row">التفاصيل</button><button v-if="recordType==='requests' && row.to_account_id===vm.ownId.value && row.status==='pending' && vm.can('wallets.request')" type="button" class="btn small" :disabled="vm.busy.value" @click="cancel(row)">إلغاء الطلب</button></footer>
    </article><p v-if="!fundingHistory.state.rows.length&&!fundingHistory.state.loading" class="empty">لا توجد سجلات تمويل مطابقة</p>
   </div><TablePanel v-else class="tablewrap"><table><thead><tr><th>التاريخ والوقت</th><th>الجهة الممولة</th><th>المستفيد</th><th>المحفظة</th><th>المبلغ</th><th>الحالة</th><th>التفاصيل</th></tr></thead><tbody><tr v-for="row in (recordType==='requests'?requests:transfers).state.rows" :key="row.id"><td>{{time(row.created_at)}}</td><td>{{row.from_account_name || vm.name(row.from_account_id)}}</td><td>{{row.to_account_name || vm.name(row.to_account_id)}}</td><td>{{vm.serviceName(row.service)}}<small v-if="row.legacy_service">{{row.source_label||'رصيد سابق قيد المطابقة'}}</small></td><td>{{money(row.amount)}} {{vm.currencyLabel(row.currency)}}</td><td><span class="badge" :class="recordType==='requests'?fundingStatusClass(row.status):'good'">{{recordType==='requests'?statuses[row.status]:'منفذ'}}</span></td><td><button type="button" class="btn small" :aria-expanded="detail?.id===row.id" @click="detail=detail?.id===row.id?null:row">التفاصيل</button><button v-if="recordType==='requests' && row.to_account_id===vm.ownId.value && row.status==='pending' && vm.can('wallets.request')" class="btn small" :disabled="vm.busy.value" @click="cancel(row)">إلغاء الطلب</button></td></tr><tr v-if="!(recordType==='requests'?requests:transfers).state.rows.length && !(recordType==='requests'?requests:transfers).state.loading"><td colspan="7" class="empty">لا توجد عمليات</td></tr></tbody></table></TablePanel><FinancePagination v-if="recordType==='requests'" :meta="requests.state.meta" :busy="requests.state.loading" @page="loadRequests" /><FinancePagination v-else :meta="transfers.state.meta" :busy="transfers.state.loading" @page="loadTransfers" />

    <section v-if="detail" class="funding-record-detail"><div class="funding-settings-heading"><h4>تفاصيل {{recordType==='requests'?'طلب التمويل':'عملية التحويل'}} · {{detail.id}}</h4><button type="button" class="btn small" @click="detail=null">إغلاق التفاصيل</button></div><dl class="account-fields"><div><dt>المستفيد</dt><dd>{{detail.to_account_name || vm.name(detail.to_account_id)}}</dd></div><div><dt>الجهة الممولة</dt><dd>{{detail.from_account_name || vm.name(detail.from_account_id)}}</dd></div><div><dt>{{recordType==='requests'?'المبلغ المطلوب':'مبلغ التحويل'}}</dt><dd>{{money(detail.amount)}} {{vm.currencyLabel(detail.currency)}}</dd></div><div><dt>المبلغ المنفذ</dt><dd>{{recordType==='transfers' || detail.status==='approved'?money(detail.amount):'—'}}</dd></div><div><dt>{{recordType==='requests'?'وقت الطلب':'وقت التحويل'}}</dt><dd>{{time(detail.created_at)}}</dd></div><div><dt>مقدم الطلب</dt><dd>{{detail.creator_name || (detail.creator_id?'#'+detail.creator_id:'—')}}</dd></div><div><dt>الحالة</dt><dd>{{recordType==='requests'?statuses[detail.status]:'منفذ'}}</dd></div><div v-if="recordType==='requests'"><dt>وقت الموافقة أو الرفض</dt><dd>{{time(detail.reviewed_at)}}</dd></div><div><dt>منفذ الإجراء</dt><dd>{{detail.reviewer_name || (detail.reviewer_id?'#'+detail.reviewer_id:'—')}}</dd></div><div><dt>المحفظة</dt><dd>{{vm.serviceName(detail.service)}}</dd></div><div><dt>سبب الرفض أو الملاحظة</dt><dd>{{detail.reason || detail.purpose || '—'}}</dd></div><div><dt>مرجع التنفيذ</dt><dd>{{detail.reference || detail.transaction_id || detail.stock_batch_id || '—'}}</dd></div><div v-if="recordType==='transfers'"><dt>المبلغ المسترجع</dt><dd>{{money(detail.recovered_amount)}}</dd></div></dl></section>

  </div>

  <div v-if="tab==='history' && vm.admin.value && vm.ownVisible.value && vm.can('security.fundingRequests')" class="funding-settings"><button type="button" class="btn" @click="showPolicy">إعدادات طلبات تمويل نقاط البيع</button><form v-if="policyOpen" @submit.prevent="savePolicy"><div class="formgrid"><label>عدد الطلبات المسموح يوميًا<input lang="en" v-model="policyDraft.daily_limit" type="number" min="1" max="100" required></label><label>مبالغ طلبات التمويل<textarea v-model="policyDraft.amounts" rows="6" required placeholder="مبلغ واحد في كل سطر"></textarea></label></div><div class="actions"><button class="btn primary" :disabled="vm.busy.value">حفظ الإعدادات</button><button type="button" class="btn" :disabled="vm.busy.value" @click="policyOpen=false">إلغاء</button></div></form></div>

  

</div></div></section></template>

