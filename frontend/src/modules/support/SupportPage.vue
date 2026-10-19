<script setup>
import AppSelect from '../../shared/components/AppSelect.vue';

import {computed,nextTick,onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';

import {useRoute,useRouter} from 'vue-router';

import {usePortal} from '../auth/session.js';

import {createPagedResource} from '../accounts/paged-resource.js';

import PrivateAttachment from './PrivateAttachment.vue';

import SupportPhoneLinks from './SupportPhoneLinks.vue';

import SupportConversation from './SupportConversation.vue';
import SiteInquiryInbox from './SiteInquiryInbox.vue';

import {createSupportApi} from './support-api.js';
import {useRouteRefresh} from '../../shared/route-refresh.js';

import {changedNotifications,createMutationKey,errorText,searchUsers,ticketStatuses,time} from './communications.js';

import '../finance/finance-parity.css';

import './communications.css';

import './support.css';



const {session} = usePortal(), api = createSupportApi(session.api), router = useRouter(), route = useRoute();

const state = reactive({options:{identity:null,recipients:[],broadcast_users:[],can_edit_phones:false},query:'',filter:'all',error:'',success:'',loadingOptions:true,loadingDetail:false,saving:false,phoneSaving:false,phoneVersion:0,phones:[],counterpart:{id:null,name:'',phones:[]}});

const form = reactive({recipient:'',title:'',description:'',image:null}), audience = ref(session.can('support.broadcast') ? 'all' : 'direct'), search = ref(''), selectedUsers = ref([]), attachmentBusy = ref(false);

const composeTitle=ref(null),composeOpen=ref(false);
const isPos=computed(()=>session.state.identity?.account.type==='pos');
const siteInboxAvailable=computed(()=>session.state.identity?.account.type==='system'&&session.can('support.view'));
const inboxTab=computed(()=>route.query.tab==='phones'?'phones':route.query.tab==='site'&&siteInboxAvailable.value?'site':'tickets');
function changeTab(tab){if(state.saving||state.phoneSaving)return;const {ticket,quick,...query}=route.query;composeOpen.value=false;void router.replace({query:{...query,tab}});}

function quickCompose(){if(route.query.quick==='support' && session.can('support.create')){if(inboxTab.value!=='tickets')void router.replace({query:{...route.query,tab:'tickets'}});composeOpen.value=true;selected.value=null;nextTick(()=>composeTitle.value?.focus());}}

watch(()=>route.query.quick,quickCompose);

const selected = ref(null), reply = ref(''), stream = ref(null), phoneDraft = ref([]);

const choices = computed(() => searchUsers(state.options.broadcast_users,search.value));

const writer = new AbortController(), createKey = createMutationKey(), replyKey = createMutationKey(), statusKey = createMutationKey(), phoneKey = createMutationKey();

let detailReader, optionsReader, detailRevision = 0, alive = true, filterTimer;

async function failure(cause) {

  if (cause.name === 'AbortError' || !alive) return '';

  const message = errorText(cause); state.error = message;

  if ([401,403].includes(cause.status)) {await session.refresh(); if (!session.state.identity) await router.replace({name:'login'});}

  return message;

}

const threads = createPagedResource((parameters,signal) => api.tickets(parameters,signal),failure);

const list = threads.state;

function load(page = 1) {return threads.load({page,per_page:20,query:state.query,filter:state.filter});}

async function loadOptions() {

  optionsReader?.abort(); optionsReader = new AbortController(); state.loadingOptions = true;

  try {

    const options = await api.options(optionsReader.signal); if (!alive) return; state.options = options.data;

    const own = await api.phones(options.data.identity.id,optionsReader.signal); if (!alive) return;

    state.phoneVersion = own.data.version; state.phones = own.data.phones; phoneDraft.value = own.data.phones.map(row => ({...row}));

    if (!session.can('support.broadcast')) audience.value = 'direct';

  } catch (cause) {await failure(cause);} finally {if (alive) state.loadingOptions = false;}

}

async function markRead(ticket,signal) {

  if (!ticket.unread_count) return;

  await api.read(ticket.id,signal); changedNotifications(); await load(list.meta.current_page);

}

async function openTicket(id,{read=true} = {}) {

  if (state.saving) return;

  detailReader?.abort(); detailReader = new AbortController(); const expected = ++detailRevision, own = detailReader;

  const previous = selected.value, pendingReply = previous?.id === id ? reply.value : '';

  if (previous?.id !== id) {selected.value = null; state.counterpart = {id:null,name:'',phones:[]}; replyKey.clear();}
  state.loadingDetail = true; state.error = ''; reply.value = pendingReply;

  try {

    const response = await api.ticket(id,own.signal); if (expected !== detailRevision || !alive) return;

    selected.value = response.data;

    const ownAccount = session.state.identity?.account.id;

    const counterpartId = response.data.recipient_id === ownAccount ? response.data.origin_id : response.data.recipient_id;

    const other = response.data.participants.find(account => account.id === counterpartId);

    if (pendingReply.trim() && response.data.version !== previous.version && response.data.last_message?.mine && response.data.last_message?.body === pendingReply.trim()) {reply.value = ''; replyKey.clear();}

    if (other) {const phones = await api.phones(other.id,own.signal); if (expected !== detailRevision || !alive) return; state.counterpart = {id:other.id,name:other.name,phones:phones.data.phones};}

    await nextTick(); stream.value?.scrollToEnd();

    if (read) {await markRead(response.data,own.signal); if (expected === detailRevision && selected.value?.id === response.data.id) selected.value.unread_count = 0;}

  } catch (cause) {if (expected === detailRevision) {if ([403,404].includes(cause.status)) selected.value = null; await failure(cause);}}

  finally {if (expected === detailRevision && alive) state.loadingDetail = false;}

}

async function chooseTicket(row) {

  if (state.saving) return;
  composeOpen.value = false;

  if (String(route.query.ticket || '') === String(row.id)) await openTicket(row.id);

  else await router.replace({query:{...route.query,tab:'tickets',ticket:String(row.id)}});

}

function backToThreads(){
  if(state.saving)return;
  detailRevision++;detailReader?.abort();selected.value=null;state.loadingDetail=false;composeOpen.value=false;reply.value='';replyKey.clear();
  const {ticket,quick,...query}=route.query;
  void router.replace({query});
}

async function saveTicket() {

  if (state.saving || attachmentBusy.value || state.loadingOptions) return;

  state.error = ''; state.success = ''; state.saving = true;

  const payload = {title:form.title.trim(),description:form.description.trim(),...(form.image ? {attachment_id:form.image.id}:{})};

  const direct = audience.value === 'direct';

  if (direct) payload.recipient_id = Number(form.recipient);

  else {payload.mode = audience.value; if (audience.value === 'custom') payload.user_ids = [...selectedUsers.value];}

  try {

    const idempotency_key = createKey.for({direct,...payload});

    const response = await api[direct ? 'create':'broadcast']({...payload,idempotency_key},writer.signal); if (!alive) return;

    form.title = ''; form.description = ''; form.image = null; selectedUsers.value = []; createKey.clear();

    state.success = direct ? 'تم إرسال الرسالة.' : `تم إرسال الرسالة إلى ${response.data.count} مستخدم.`;

    changedNotifications(); await load();

    state.saving = false;

    if (direct) await chooseTicket(response.data);

  } catch (cause) {await failure(cause);} finally {if (alive) state.saving = false;}

}

async function sendReply() {

  if (!selected.value || state.saving || !reply.value.trim()) return;

  state.error = ''; state.success = ''; state.saving = true;

  const ticket = selected.value, payload = {body:reply.value.trim(),version:ticket.version};

  try {

    const response = await api.reply(ticket.id,{...payload,idempotency_key:replyKey.for({id:ticket.id,...payload})},writer.signal); if (!alive) return;

    selected.value = response.data; reply.value = ''; replyKey.clear(); state.success = 'تم إرسال الرد.';

    changedNotifications(); await load(list.meta.current_page); await nextTick(); stream.value?.scrollToEnd();

  } catch (cause) {await failure(cause); if (cause.status === 409) state.error += ' حدّث المحادثة وراجع الرد قبل إعادة الإرسال.';}

  finally {if (alive) state.saving = false;}

}

async function changeStatus(action) {

  if (!selected.value || state.saving) return;

  state.error = ''; state.success = ''; state.saving = true;

  const ticket = selected.value, payload = {action,version:ticket.version};

  try {

    const response = await api.status(ticket.id,{...payload,idempotency_key:statusKey.for({id:ticket.id,...payload})},writer.signal); if (!alive) return;

    selected.value = response.data; statusKey.clear(); state.success = action === 'close' ? 'تم إغلاق المحادثة.' : 'تم تصعيد المحادثة.';

    changedNotifications(); await load(list.meta.current_page);

  } catch (cause) {await failure(cause);} finally {if (alive) state.saving = false;}

}

async function savePhones() {

  if (state.phoneSaving || state.loadingOptions) return;

  state.phoneSaving = true; state.error = ''; state.success = '';

  const payload = {phones:phoneDraft.value.map(row => ({label:row.label.trim(),number:row.number.trim()})),version:state.phoneVersion};

  try {

    const response = await api.savePhones(state.options.identity.id,{...payload,idempotency_key:phoneKey.for(payload)},writer.signal); if (!alive) return;

    state.phoneVersion = response.data.version; state.phones = response.data.phones; phoneDraft.value = response.data.phones.map(row => ({...row})); phoneKey.clear(); state.success = 'تم حفظ أرقام الدعم.';

  } catch (cause) {await failure(cause);} finally {if (alive) state.phoneSaving = false;}

}

async function refresh() {await load(list.meta.current_page); if (selected.value && !state.saving) await openTicket(selected.value.id);}

watch(() => [state.query,state.filter], () => {clearTimeout(filterTimer); filterTimer = setTimeout(() => load(),250);});

watch(() => route.query.ticket, id => {if (/^[1-9]\d*$/.test(String(id || ''))) openTicket(Number(id)); else {detailRevision++; detailReader?.abort(); selected.value = null; state.loadingDetail = false;}});

useRouteRefresh(async()=>{state.error='';await load(list.meta.current_page);if(selected.value)await openTicket(selected.value.id,{read:false});return !state.error&&!list.error;},{canRefresh:()=>!state.saving&&!state.phoneSaving&&!attachmentBusy.value&&!composeOpen.value});
onMounted(async () => {await Promise.all([loadOptions(),load()]); if (alive && /^[1-9]\d*$/.test(String(route.query.ticket || ''))) await openTicket(Number(route.query.ticket)); quickCompose();});

onBeforeUnmount(() => {alive = false; detailRevision++; clearTimeout(filterTimer); writer.abort(); detailReader?.abort(); optionsReader?.abort(); threads.dispose();});

</script>

<template>

  <div class="finance-workspace communication-workspace support-module support-full-page" :class="{'pos-support-chat':isPos&&(selected||state.loadingDetail),'pos-support-compose':isPos&&composeOpen}">

    <p v-if="state.error || list.error" class="notice warn" role="alert">{{state.error || list.error}}</p><p v-if="state.success" class="notice" role="status">{{state.success}}</p>

    <div class="support-workspace">
      <button v-if="isPos&&(selected||state.loadingDetail||composeOpen)" type="button" class="btn pos-support-back" :disabled="state.saving" @click="backToThreads"><span aria-hidden="true">‹</span> المحادثات</button>

      <nav class="support-page-tabs" role="tablist" aria-label="أقسام الدعم الفني"><button id="support-tickets-tab" type="button" role="tab" class="btn" :aria-selected="inboxTab==='tickets'" aria-controls="support-tickets-panel" :class="{primary:inboxTab==='tickets'}" @click="changeTab('tickets')">المحادثات</button><button id="support-phones-tab" type="button" role="tab" class="btn" :aria-selected="inboxTab==='phones'" aria-controls="support-phones-panel" :class="{primary:inboxTab==='phones'}" @click="changeTab('phones')">أرقام الدعم</button><button v-if="siteInboxAvailable" type="button" role="tab" class="btn" :aria-selected="inboxTab==='site'" :class="{primary:inboxTab==='site'}" @click="changeTab('site')">رسائل موقع الشركة <span class="badge">{{state.options.site_inquiries_count||0}}</span></button></nav>
      <SiteInquiryInbox v-if="siteInboxAvailable && inboxTab==='site'" @count="state.options.site_inquiries_count=$event"/>
      <section v-if="inboxTab==='phones'" id="support-phones-panel" class="support-contact-bar support-phones-panel" role="tabpanel" aria-labelledby="support-phones-tab">

        <p v-if="state.loadingOptions" class="read-loading help" role="status">جارٍ تحميل أرقام الدعم…</p>
        <SupportPhoneLinks v-if="state.phones.length" :name="state.options.identity?.name||''" :phones="state.phones"/>
        <SupportPhoneLinks v-for="contact in state.options.recipients" :key="contact.id" :name="contact.name" :phones="contact.phones"/>
        <SupportPhoneLinks v-if="state.counterpart.id&&!state.options.recipients.some(contact=>contact.id===state.counterpart.id)" :name="state.counterpart.name" :phones="state.counterpart.phones"/>
        <p v-if="!state.loadingOptions&&!state.phones.length&&!state.options.recipients.some(contact=>contact.phones?.length)&&!state.counterpart.phones.length" class="empty">لا توجد أرقام دعم مسجلة حاليًا.</p>

        <details v-if="state.options.can_edit_phones"><summary>إدارة أرقام الدعم الخاصة بي</summary><form @submit.prevent="savePhones"><fieldset class="communication-fieldset" :disabled="state.phoneSaving || state.loadingOptions"><div v-for="(row,index) in phoneDraft" :key="index" class="support-phone-edit"><span class="support-phone-order">{{index + 1}}</span><input v-model="row.label" required maxlength="60" :aria-label="'اسم رقم الدعم ' + (index + 1)" placeholder="اسم الرقم"><input lang="en" v-model="row.number" type="tel" inputmode="numeric" minlength="11" maxlength="11" pattern="07[78][0-9]{8}" required :aria-label="'رقم الدعم ' + (index + 1)" dir="ltr" placeholder="077xxxxxxxx أو 078xxxxxxxx" title="11 رقمًا تبدأ بـ077 أو 078"><button type="button" class="btn small" @click="phoneDraft.splice(index,1)">حذف</button></div><div class="actions"><button type="button" class="btn" :disabled="phoneDraft.length >= 5" @click="phoneDraft.push({label:'الدعم الفني',number:''})">+ إضافة رقم دعم</button><button class="btn primary">{{state.phoneSaving ? 'جارٍ الحفظ…' : 'حفظ الأرقام'}}</button></div></fieldset></form></details>

      </section>

      <div v-show="inboxTab==='tickets'" id="support-tickets-panel" role="tabpanel" aria-labelledby="support-tickets-tab">
      <section class="support-inbox">

        <div class="support-section-heading"><h2 v-if="!isPos">المحادثات</h2><span class="badge">{{list.meta.unread_total ?? 0}} غير مقروءة</span></div>

        <div class="support-chat-layout">

          <aside class="support-chat-list"><input v-model="state.query" type="search" placeholder="بحث بالعنوان أو الطرف" aria-label="بحث محادثات الدعم"><div class="actions"><button type="button" class="btn small" :class="{primary:state.filter === 'all'}" @click="state.filter = 'all'">الكل</button><button type="button" class="btn small" :class="{primary:state.filter === 'unread'}" @click="state.filter = 'unread'">غير المقروءة</button><button type="button" class="btn small" :disabled="list.loading || state.saving" @click="refresh">تحديث</button></div>

            <button v-for="ticket in list.rows" :key="ticket.id" type="button" class="support-thread support-chat-item" :class="{unread:ticket.unread_count > 0,selected:selected?.id === ticket.id}" :disabled="state.saving" @click="chooseTicket(ticket)"><span class="support-chat-item-head"><b>{{ticket.title}}</b><span v-if="ticket.unread_count" class="support-unread-dot">{{ticket.unread_count}}</span></span><span>{{ticket.origin_name}} ← {{ticket.recipient_name}}</span><small class="support-snippet">{{ticket.last_message?.body || ticket.description}}</small><span class="support-chat-item-head"><small>{{time(ticket.last_message_at)}}</small><small>{{ticketStatuses[ticket.status] || ticket.status}}</small></span></button>

            <p v-if="list.loading" class="read-loading help" role="status">جارٍ تحميل المحادثات…</p><p v-else-if="!list.rows.length" class="empty">لا توجد محادثات مطابقة</p><div v-if="list.meta.last_page > 1" class="actions"><button type="button" class="btn small" :disabled="list.loading || list.meta.current_page <= 1" @click="load(list.meta.current_page-1)">السابق</button><span>{{list.meta.current_page}} / {{list.meta.last_page}}</span><button type="button" class="btn small" :disabled="list.loading || list.meta.current_page >= list.meta.last_page" @click="load(list.meta.current_page+1)">التالي</button></div>

          </aside>

          <SupportConversation ref="stream" v-model="reply" :ticket="selected" :counterpart="state.counterpart" :busy="state.saving" :loading="state.loadingDetail" @reply="sendReply" @status="changeStatus" @refresh="openTicket(selected.id)"/>

        </div>

      </section>

      <button v-if="isPos&&session.can('support.create')" type="button" class="btn primary pos-compose-toggle" :aria-expanded="composeOpen" @click="composeOpen=!composeOpen">{{composeOpen?'إغلاق الرسالة الجديدة':'رسالة جديدة'}}</button>
      <form v-if="session.can('support.create')&&(!isPos||composeOpen)" class="support-composer" @submit.prevent="saveTicket"><fieldset class="communication-fieldset" :disabled="state.saving || state.loadingOptions"><div class="support-section-heading"><h2 v-if="!isPos">رسالة جديدة</h2><span v-if="!isPos" class="caption">تواصل مع المستلم وتابع الردود هنا</span></div><div class="support-compose-grid"><label v-if="session.can('support.broadcast')">المستخدمين<AppSelect v-model="audience" aria-label="مستخدمو رسالة الدعم"><option value="all">عام لكل المستخدمين ضمن نطاقي</option><option v-if="state.options.identity?.type === 'system'" value="agents">الوكلاء الرئيسيون</option><option value="branches">الفروع</option><option value="custom">مخصص</option><option value="direct">مستلم مباشر</option></AppSelect></label><div v-if="audience === 'custom'"><input v-model="search" placeholder="بحث المستخدمين" aria-label="بحث مستلمي الدعم"><div class="scope-options"><label v-for="user in choices" :key="user.id"><input v-model="selectedUsers" type="checkbox" :value="user.id">{{user.name}}</label><p v-if="!choices.length">لا توجد نتائج</p></div></div><label v-if="audience === 'direct'">المرسل إليه<AppSelect v-model="form.recipient" required><option value="" disabled>اختر المستلم</option><option v-for="account in state.options.recipients" :key="account.id" :value="account.id">{{account.name}}</option></AppSelect></label><label>العنوان<input ref="composeTitle" v-model="form.title" required maxlength="200"></label><label class="support-message-field">محتوى الرسالة<textarea v-model="form.description" required maxlength="10000"></textarea></label></div><PrivateAttachment v-if="session.can('support.attach')" v-model="form.image" kind="support" :disabled="state.saving" @busy="attachmentBusy = $event" @error="failure"/><div class="formfoot"><button class="btn primary" :disabled="state.saving || attachmentBusy || state.loadingOptions">{{state.saving ? 'جارٍ الإرسال…' : 'إرسال الرسالة'}}</button></div></fieldset></form>

      </div>
    </div>

  </div>

</template>

