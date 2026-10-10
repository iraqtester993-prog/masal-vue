<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';


import { vMoney } from './money-input.js';

import {computed,inject,onMounted,reactive,ref,watch} from 'vue';

import {amount,decimal,errorText,financeKey,minor,money,positive,statuses,time} from './finance-model.js';

import FinancePagination from './FinancePagination.vue';

const props=defineProps({quickDeposit:Boolean});

const vm=inject(financeKey), tab=ref('history'), value=ref(''), note=ref(''), to=ref(''), reference=ref(''), directConfirm=ref(false), filter=ref(''), recordType=ref('requests'), detail=ref(null), review=reactive({}), policyOpen=ref(false), policyDraft=reactive({daily_limit:1,amounts:'',recovery_hours:0});

const requests=vm.resource('funding-requests'), incoming=vm.resource('funding-requests'), transfers=vm.resource('transfers');

const requestKey=vm.key(), directKey=vm.key(), depositKey=vm.key(), reviewKeys=new Map(), cancelKeys=new Map();

const parent=computed(()=>vm.options.parent_account_id), pointRequester=computed(()=>vm.identity.value?.account.type==='pos');

const dailyUsed=computed(()=>vm.options.counts?.today_requests), dailyRemaining=computed(()=>dailyUsed.value===undefined?null:Math.max(0,(vm.options.funding_policy?.daily_limit ?? 0)-dailyUsed.value));

const available=computed(()=>vm.walletAmount(vm.ownId.value,vm.service.value,vm.currency.value));

const tabs=computed(()=>[

  ...(!vm.admin.value && vm.ownVisible.value && parent.value && vm.can('wallets.request')?[{id:'request',name:'طلب تمويل'}]:[]),

  ...(vm.can('wallets.approve')?[{id:'incoming',name:'طلبات واردة'}]:[]),

  ...(!vm.admin.value && vm.ownVisible.value && vm.children.value.length && vm.can('wallets.transfer')?[{id:'direct',name:'تمويل مباشر'}]:[]),

  ...(vm.admin.value && vm.ownVisible.value && vm.service.value!=='voucher' && vm.can('wallets.deposit')?[{id:'deposit',name:'إيداع'}]:[]),

  {id:'history',name:'سجل التمويل'},

]);

function loadRequests(page=1){if(vm.emptyScope()){requests.dispose();return;}return requests.load(vm.recordParameters({status:filter.value,per_page:25,page}));}

function loadIncoming(page=1){if(!vm.can('wallets.approve'))return;if(vm.emptyScope()){incoming.dispose();return;}return incoming.load(vm.recordParameters({status:'pending',direction:'incoming',per_page:25,page}));}

function loadTransfers(page=1){if(vm.emptyScope()){transfers.dispose();return;}return transfers.load(vm.recordParameters({per_page:25,page}));}

async function refresh(){await vm.refresh();await Promise.all([loadRequests(),loadIncoming(),loadTransfers()]);}

watch([vm.service,vm.currency],()=>{value.value='';directConfirm.value=false;detail.value=null;loadRequests();loadIncoming();loadTransfers();});

watch(vm.filters,()=>{detail.value=null;loadRequests();loadIncoming();loadTransfers();},{deep:true});

watch([value,to,reference],()=>{directConfirm.value=false;});

watch(filter,()=>loadRequests());

watch(recordType,()=>{detail.value=null;});

function quickDeposit(){if(props.quickDeposit && tabs.value.some(row=>row.id==='deposit'))tab.value='deposit';}

watch(()=>props.quickDeposit,quickDeposit);

onMounted(()=>{tab.value=tabs.value[0]?.id || 'history';quickDeposit();loadRequests();loadIncoming();loadTransfers();});

function payloadAmount(){if(!vm.service.value)throw new Error('اختر محفظة من كروت المحافظ.');return amount(value.value);}

async function requestFunding(){try{const result=await vm.execute({service:vm.service.value,currency:vm.currency.value,amount:payloadAmount(),purpose:note.value.trim() || null},requestKey,body=>vm.api.create('funding-requests',body),'تم إرسال طلب التمويل.');if(result){value.value='';note.value='';await refresh();tab.value='history';}}catch(failed){vm.error.value=errorText(failed);}}

async function direct(){try{const transferred=payloadAmount();if(!to.value || !vm.children.value.some(row=>Number(row.id)===Number(to.value)))throw new Error('اختر تابعًا متاحًا.');if(available.value===null || minor(transferred)>minor(available.value))throw new Error('الرصيد المتاح لا يكفي.');if(!directConfirm.value){directConfirm.value=true;return;}const result=await vm.execute({from_account_id:vm.ownId.value,to_account_id:Number(to.value),service:vm.service.value,currency:vm.currency.value,amount:transferred,reference:reference.value.trim()},directKey,body=>vm.api.create('transfers',body),'تم تنفيذ التمويل.');if(result){value.value='';to.value='';reference.value='';directConfirm.value=false;await refresh();}}catch(failed){vm.error.value=errorText(failed);}}

async function deposit(){try{const result=await vm.execute({account_id:Number(to.value),service:vm.service.value,currency:vm.currency.value,amount:payloadAmount(),reference:reference.value.trim()},depositKey,body=>vm.api.create('deposits',body),'تم تسجيل الإيداع.');if(result){value.value='';to.value='';reference.value='';await refresh();}}catch(failed){vm.error.value=errorText(failed);}}

function edit(row){review[row.id] ??= {batch:'',reference:'',reason:''};}

async function decide(row,decision){const draft=review[row.id];let key=reviewKeys.get(row.id);if(!key){key=vm.key();reviewKeys.set(row.id,key);}const payload={version:row.version,decision,...(decision==='approve'?{reference:draft.reference.trim(),...(vm.admin.value && row.service==='voucher'?{stock_batch_id:Number(draft.batch)}:{})}:{reason:draft.reason.trim()})};const result=await vm.execute(payload,key,body=>vm.api.action('funding-requests',row.id,'review',body),decision==='approve'?'تم اعتماد طلب التمويل.':'تم رفض طلب التمويل.');if(result){delete review[row.id];await refresh();}}

async function cancel(row){let key=cancelKeys.get(row.id);if(!key){key=vm.key();cancelKeys.set(row.id,key);}const result=await vm.execute({version:row.version},key,body=>vm.api.action('funding-requests',row.id,'cancel',body),'تم إلغاء طلب التمويل.');if(result){detail.value=null;await refresh();}}

function showPolicy(){const policy=vm.options.funding_policy;if(!policy)return;Object.assign(policyDraft,{daily_limit:policy.daily_limit,amounts:policy.amounts.join('\n'),recovery_hours:policy.recovery_hours});policyOpen.value=!policyOpen.value;}

async function savePolicy(){try{const amounts=policyDraft.amounts.split(/[\n;]+/).map(raw=>raw.trim()).filter(Boolean).map(raw=>amount(raw));if(new Set(amounts).size!==amounts.length)throw new Error('يوجد مبلغ مكرر.');const result=await vm.savePolicy({version:vm.options.funding_policy.version,daily_limit:Number(policyDraft.daily_limit),amounts,...(vm.can('security.fundingRecovery')?{recovery_hours:Number(policyDraft.recovery_hours)}:{})});if(result){policyOpen.value=false;await refresh();}}catch(failed){vm.error.value=errorText(failed);}}

function afterTransfer(){try{return available.value===null?'—':money(decimal(minor(available.value)-minor(value.value || '0')));}catch{return '—';}}

</script>

<template><section class="simple-wallets"><div class="card wallet-funding-content"><div class="wallet-funding-body"><p v-if="tab==='request' || tab==='direct' || tab==='deposit'" class="caption">محفظة التمويل: {{vm.service.value?vm.serviceName(vm.service.value):'اختر محفظة من كروت المحافظ'}}</p><div class="tabs"><button v-for="item in tabs" :key="item.id" :class="{active:tab===item.id}" @click="tab=item.id;vm.error.value='';directConfirm=false">{{item.name}}<span v-if="item.id==='incoming' && vm.options.counts?.pending_incoming" class="badge">{{vm.options.counts.pending_incoming}}</span></button></div>

  <form v-if="tab==='request'" @submit.prevent="requestFunding"><div class="formgrid funding-request-fields"><label>إلى الجهة الأعلى<input :value="vm.options.parent_account_name || vm.name(parent)" readonly></label><label v-if="pointRequester">المبلغ المطلوب<select v-model="value" required aria-label="مبلغ طلب التمويل"><option value="" disabled>اختر مبلغ التمويل</option><option v-for="entry in vm.options.funding_policy?.amounts || []" :key="entry" :value="entry">{{money(entry)}} {{vm.currencyLabel(vm.currency.value)}}</option></select></label><label v-else>المبلغ المطلوب<input v-model="value" type="text" inputmode="decimal" v-money required></label><label>ملاحظة اختيارية<input v-model="note" maxlength="1000"></label></div><p v-if="vm.service.value==='voucher' && vm.options.parent_account_type==='system'" class="help">رصيد البطاقات يجهّز بطلبية مخزون معتمدة بنفس القيمة.</p><div v-if="pointRequester" class="funding-daily-status"><span>طلبات اليوم: {{dailyUsed ?? '—'}} / {{vm.options.funding_policy?.daily_limit ?? '—'}}</span><span>المتبقي اليوم: {{dailyRemaining ?? '—'}}</span></div><p v-if="pointRequester && !(vm.options.funding_policy?.amounts.length)" class="notice warn">طلبات التمويل غير متاحة حاليًا؛ لم تعتمد الإدارة مبالغ للطلبات.</p><p v-else-if="pointRequester && dailyRemaining===0" class="notice warn">وصلت إلى عدد الطلبات المسموح اليوم؛ يمكنك الطلب غدًا بتوقيت بغداد.</p><div class="actions"><button class="btn primary" :disabled="vm.busy.value || !vm.service.value || !positive(value) || (pointRequester && (dailyRemaining===0 || !vm.options.funding_policy?.amounts.includes(value)))">إرسال طلب التمويل</button></div></form>

  <form v-if="tab==='direct'" @submit.prevent="direct"><div class="formgrid"><label>المستفيد<select v-model="to" required aria-label="المستفيد"><option value="">اختر تابعًا</option><option v-for="row in vm.children.value" :key="row.id" :value="row.id">{{row.name}}</option></select></label><label>مبلغ التمويل<input v-model="value" type="text" inputmode="decimal" v-money required></label><label>مرجع الإيداع / التحصيل<input v-model="reference" required maxlength="200"></label></div><p>المتاح: {{money(available)}} {{vm.currencyLabel(vm.currency.value)}} · المتبقي بعد التحويل: {{afterTransfer()}} {{vm.currencyLabel(vm.currency.value)}}</p><p v-if="directConfirm" class="notice">تأكيد تحويل {{money(value)}} {{vm.currencyLabel(vm.currency.value)}} إلى {{vm.name(to)}} من {{vm.serviceName(vm.service.value)}}.</p><button class="btn primary" :disabled="vm.busy.value || !to || !positive(value) || !reference.trim() || !vm.service.value">{{directConfirm?'تأكيد التمويل':'تمويل'}}</button></form>

  <form v-if="tab==='deposit'" @submit.prevent="deposit"><div class="formgrid"><label>المستفيد<select v-model="to" required aria-label="حساب الإيداع"><option value="">اختر الحساب</option><option v-for="row in vm.options.accounts" :key="row.id" :value="row.id">{{row.name}}</option></select></label><label>مبلغ الإيداع<input v-model="value" type="text" inputmode="decimal" v-money required></label><label>مرجع الإيداع<input v-model="reference" required maxlength="200" placeholder="رقم الإيداع أو السند"></label></div><p v-if="vm.service.value==='voucher'" class="notice warn">رصيد البطاقات يُضاف من طلبية مخزون معتمدة.</p><div class="actions"><button class="btn primary" :disabled="vm.busy.value || !to || !positive(value) || !reference.trim() || !vm.service.value || vm.service.value==='voucher'">تسجيل الإيداع</button></div></form>

  <div v-if="tab==='incoming'"><p v-if="incoming.state.error" class="notice warn" role="alert">{{incoming.state.error}}</p><p v-if="incoming.state.loading" class="read-loading caption">جارٍ تحميل الطلبات…</p><article v-for="row in incoming.state.rows" :key="row.id" class="wallet-request"><div class="rowline"><strong>{{row.to_account_name || vm.name(row.to_account_id)}}</strong><strong>{{money(row.amount)}} {{vm.currencyLabel(row.currency)}} · {{vm.serviceName(row.service)}}</strong><span class="badge">{{statuses[row.status] || row.status}}</span></div><p v-if="row.purpose">{{row.purpose}}</p><small>{{time(row.created_at)}} · {{row.id}}</small><div v-if="vm.admin.value && row.service==='voucher'" class="notice">الرصيد يُضاف عند اعتماد الطلبية. ربطها هنا لا يضيف رصيدًا ثانيًا.</div><div v-if="!vm.admin.value" class="caption">رصيدك المتاح لهذه المحفظة: {{money(vm.walletAmount(vm.ownId.value,row.service,row.currency))}} {{vm.currencyLabel(row.currency)}}</div><button v-if="!review[row.id]" class="btn" @click="edit(row)">مراجعة الطلب</button><div v-else><label v-if="vm.admin.value && row.service==='voucher'">الطلبية المعتمدة<select v-if="vm.options.approved_stock_batches" v-model="review[row.id].batch"><option value="">اختر طلبية بنفس القيمة</option><option v-for="batch in vm.options.approved_stock_batches.filter(entry=>entry.account_id===row.to_account_id && entry.amount===row.amount && entry.currency===row.currency)" :key="batch.id" :value="batch.id">{{batch.label || '#'+batch.id}}</option></select><input v-else v-model="review[row.id].batch" type="number" min="1" step="1" placeholder="رقم الطلبية المعتمدة"></label><label>مرجع الإيداع<input v-model="review[row.id].reference" maxlength="200" placeholder="رقم الإيداع أو السند"></label><div class="actions"><button class="btn primary" :disabled="vm.busy.value || !review[row.id].reference.trim() || (vm.admin.value && row.service==='voucher' && !review[row.id].batch)" @click="decide(row,'approve')">{{vm.admin.value && row.service==='voucher'?'اعتماد وربط الطلبية':'موافقة وتمويل'}}</button></div><label>سبب الرفض<input v-model="review[row.id].reason" maxlength="1000"></label><button class="btn danger" :disabled="vm.busy.value || !review[row.id].reason.trim()" @click="decide(row,'reject')">رفض الطلب</button></div></article><p v-if="!incoming.state.rows.length && !incoming.state.loading" class="empty">لا توجد طلبات واردة بانتظار الإجراء</p><FinancePagination v-if="incoming.state.meta.total>0" :meta="incoming.state.meta" :busy="incoming.state.loading" @page="loadIncoming" /></div>

  <div v-if="tab==='history'"><div class="wallet-history-filter"><label>السجل<select v-model="recordType"><option value="requests">طلبات التمويل</option><option value="transfers">التحويلات المنفذة</option></select></label><label v-if="recordType==='requests'">حالة الطلب<select v-model="filter"><option value="">كل الحالات</option><option v-for="state in ['pending','approved','rejected','cancelled']" :key="state" :value="state">{{statuses[state]}}</option></select></label></div><p v-if="(recordType==='requests'?requests:transfers).state.error" class="notice warn" role="alert">{{(recordType==='requests'?requests:transfers).state.error}}</p><p v-if="(recordType==='requests'?requests:transfers).state.loading" class="read-loading caption">جارٍ تحميل السجل…</p><TablePanel class="tablewrap"><table><thead><tr><th>التاريخ</th><th>الجهة الأعلى / الممول</th><th>المستفيد</th><th>المحفظة</th><th>المبلغ</th><th>الحالة</th><th>التفاصيل</th></tr></thead><tbody><tr v-for="row in (recordType==='requests'?requests:transfers).state.rows" :key="row.id"><td>{{time(row.created_at)}}</td><td>{{row.from_account_name || vm.name(row.from_account_id)}}</td><td>{{row.to_account_name || vm.name(row.to_account_id)}}</td><td>{{vm.serviceName(row.service)}}</td><td>{{money(row.amount)}} {{vm.currencyLabel(row.currency)}}</td><td><span class="badge">{{recordType==='requests'?statuses[row.status]:'منفذ'}}</span></td><td><button type="button" class="btn small" :aria-expanded="detail?.id===row.id" @click="detail=detail?.id===row.id?null:row">التفاصيل</button><button v-if="recordType==='requests' && row.to_account_id===vm.ownId.value && row.status==='pending' && vm.can('wallets.request')" class="btn small" :disabled="vm.busy.value" @click="cancel(row)">إلغاء الطلب</button></td></tr><tr v-if="!(recordType==='requests'?requests:transfers).state.rows.length && !(recordType==='requests'?requests:transfers).state.loading"><td colspan="7" class="empty">لا توجد عمليات</td></tr></tbody></table></TablePanel><FinancePagination v-if="recordType==='requests'" :meta="requests.state.meta" :busy="requests.state.loading" @page="loadRequests" /><FinancePagination v-else :meta="transfers.state.meta" :busy="transfers.state.loading" @page="loadTransfers" />

    <section v-if="detail" class="funding-record-detail"><div class="funding-settings-heading"><h4>تفاصيل {{recordType==='requests'?'طلب التمويل':'عملية التحويل'}} · {{detail.id}}</h4><button type="button" class="btn small" @click="detail=null">إغلاق التفاصيل</button></div><dl class="account-fields"><div><dt>المستفيد</dt><dd>{{detail.to_account_name || vm.name(detail.to_account_id)}}</dd></div><div><dt>الجهة الممولة</dt><dd>{{detail.from_account_name || vm.name(detail.from_account_id)}}</dd></div><div><dt>المبلغ المطلوب</dt><dd>{{money(detail.amount)}} {{vm.currencyLabel(detail.currency)}}</dd></div><div><dt>المبلغ المنفذ</dt><dd>{{recordType==='transfers' || detail.status==='approved'?money(detail.amount):'—'}}</dd></div><div><dt>وقت الطلب</dt><dd>{{time(detail.created_at)}}</dd></div><div><dt>مقدم الطلب</dt><dd>{{detail.creator_name || (detail.creator_id?'#'+detail.creator_id:'—')}}</dd></div><div><dt>الحالة</dt><dd>{{recordType==='requests'?statuses[detail.status]:'منفذ'}}</dd></div><div><dt>وقت الموافقة أو الرفض</dt><dd>{{time(detail.reviewed_at)}}</dd></div><div><dt>منفذ الإجراء</dt><dd>{{detail.reviewer_name || (detail.reviewer_id?'#'+detail.reviewer_id:'—')}}</dd></div><div><dt>المحفظة</dt><dd>{{vm.serviceName(detail.service)}}</dd></div><div><dt>سبب الرفض أو الملاحظة</dt><dd>{{detail.reason || detail.purpose || '—'}}</dd></div><div><dt>مرجع التنفيذ</dt><dd>{{detail.reference || detail.transaction_id || detail.stock_batch_id || '—'}}</dd></div><div v-if="recordType==='transfers'"><dt>المبلغ المسترجع</dt><dd>{{money(detail.recovered_amount)}}</dd></div></dl></section>

  </div>

  <div v-if="tab==='history' && vm.admin.value && vm.ownVisible.value && vm.can('security.fundingRequests')" class="funding-settings"><button type="button" class="btn" @click="showPolicy">إعدادات طلبات تمويل نقاط البيع</button><form v-if="policyOpen" @submit.prevent="savePolicy"><div class="formgrid"><label>عدد الطلبات المسموح يوميًا<input v-model="policyDraft.daily_limit" type="number" min="1" max="100" required></label><label>مبالغ طلبات التمويل<textarea v-model="policyDraft.amounts" rows="6" required placeholder="مبلغ واحد في كل سطر"></textarea></label><label v-if="vm.can('security.fundingRecovery')">المهلة · ساعات<input v-model="policyDraft.recovery_hours" type="number" min="1" max="720" required></label></div><div class="actions"><button class="btn primary" :disabled="vm.busy.value">حفظ الإعدادات</button><button type="button" class="btn" :disabled="vm.busy.value" @click="policyOpen=false">إلغاء</button></div></form></div>

  <p v-if="vm.error.value" class="notice warn" role="alert">{{vm.error.value}}</p>

</div></div></section></template>

