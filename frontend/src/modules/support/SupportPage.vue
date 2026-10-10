<script setup>

import {computed,nextTick,onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';

import {useRoute,useRouter} from 'vue-router';

import {usePortal} from '../auth/session.js';

import {createPagedResource} from '../accounts/paged-resource.js';

import PrivateAttachment from './PrivateAttachment.vue';

import SupportPhoneLinks from './SupportPhoneLinks.vue';

import SupportConversation from './SupportConversation.vue';
import SiteInquiryInbox from './SiteInquiryInbox.vue';

import {createSupportApi} from './support-api.js';

import {changedNotifications,createMutationKey,errorText,searchUsers,ticketStatuses,time} from './communications.js';

import '../finance/finance-parity.css';

import './communications.css';

import './support.css';



const {session} = usePortal(), api = createSupportApi(session.api), router = useRouter(), route = useRoute();

const state = reactive({options:{identity:null,recipients:[],broadcast_users:[],can_edit_phones:false},query:'',filter:'all',error:'',success:'',loadingOptions:true,loadingDetail:false,saving:false,phoneSaving:false,phoneVersion:0,phones:[],counterpart:{name:'',phones:[]}});

const form = reactive({recipient:'',title:'',description:'',image:null}), audience = ref(session.can('support.broadcast') ? 'all' : 'direct'), search = ref(''), selectedUsers = ref([]), attachmentBusy = ref(false);

const composeTitle=ref(null);
const siteInboxAvailable=computed(()=>session.state.identity?.account.type==='system'&&session.can('support.view'));
const inboxTab=ref(route.query.tab==='site'?'site':'tickets');

function quickCompose(){if(route.query.quick==='support' && session.can('support.create')){selected.value=null;nextTick(()=>composeTitle.value?.focus());}}

watch(()=>route.query.quick,quickCompose);

const selected = ref(null), reply = ref(''), stream = ref(null), phoneDraft = ref([]);

const choices = computed(() => searchUsers(state.options.broadcast_users,search.value));

const composerRecipient = computed(() => state.options.recipients.find(row => row.id === Number(form.recipient)) || state.options.recipients[0]);

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

  selected.value = null; state.counterpart = {name:'',phones:[]}; state.loadingDetail = true; state.error = ''; reply.value = pendingReply; if (previous?.id !== id) replyKey.clear();

  try {

    const response = await api.ticket(id,own.signal); if (expected !== detailRevision || !alive) return;

    selected.value = response.data;

    const ownAccount = session.state.identity?.account.id;

    const counterpartId = response.data.recipient_id === ownAccount ? response.data.origin_id : response.data.recipient_id;

    const other = response.data.participants.find(account => account.id === counterpartId);

    if (pendingReply.trim() && response.data.version !== previous.version && response.data.last_message?.mine && response.data.last_message?.body === pendingReply.trim()) {reply.value = ''; replyKey.clear();}

    if (other) {const phones = await api.phones(other.id,own.signal); if (expected !== detailRevision || !alive) return; state.counterpart = {name:other.name,phones:phones.data.phones};}

    await nextTick(); stream.value?.scrollToEnd();

    if (read) {await markRead(response.data,own.signal); if (expected === detailRevision && selected.value?.id === response.data.id) selected.value.unread_count = 0;}

  } catch (cause) {if (expected === detailRevision) {if ([403,404].includes(cause.status)) selected.value = null; await failure(cause);}}

  finally {if (expected === detailRevision && alive) state.loadingDetail = false;}

}

async function chooseTicket(row) {

  if (state.saving) return;

  if (String(route.query.ticket || '') === String(row.id)) await openTicket(row.id);

  else await router.replace({query:{...route.query,ticket:String(row.id)}});

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

onMounted(async () => {await Promise.all([loadOptions(),load()]); if (alive && /^[1-9]\d*$/.test(String(route.query.ticket || ''))) await openTicket(Number(route.query.ticket)); quickCompose();});

onBeforeUnmount(() => {alive = false; detailRevision++; clearTimeout(filterTimer); writer.abort(); detailReader?.abort(); optionsReader?.abort(); threads.dispose();});

</script>

<template>

  <div class="finance-workspace communication-workspace support-module">

    <p v-if="state.error || list.error" class="notice warn" role="alert">{{state.error || list.error}}</p><p v-if="state.success" class="notice" role="status">{{state.success}}</p>

    <div class="card workspace-card support-workspace">

      <nav v-if="siteInboxAvailable" class="site-inquiry-tabs" aria-label="مصدر رسائل الدعم"><button type="button" class="btn" :class="{primary:inboxTab==='tickets'}" @click="inboxTab='tickets'">محادثات النظام</button><button type="button" class="btn" :class="{primary:inboxTab==='site'}" @click="inboxTab='site'">رسائل موقع الشركة <span class="badge">{{state.options.site_inquiries_count||0}}</span></button></nav>
      <SiteInquiryInbox v-if="siteInboxAvailable && inboxTab==='site'" @count="state.options.site_inquiries_count=$event"/>
      <div v-show="!siteInboxAvailable || inboxTab==='tickets'">
      <section class="support-contact-bar">

        <SupportPhoneLinks v-if="!selected && composerRecipient" :name="composerRecipient.name" :phones="composerRecipient.phones"/>

        <details v-if="state.options.can_edit_phones"><summary>إدارة أرقام الدعم الخاصة بي</summary><form @submit.prevent="savePhones"><fieldset class="communication-fieldset" :disabled="state.phoneSaving || state.loadingOptions"><div v-for="(row,index) in phoneDraft" :key="index" class="support-phone-edit"><span class="support-phone-order">{{index + 1}}</span><input v-model="row.label" required maxlength="60" :aria-label="'اسم رقم الدعم ' + (index + 1)" placeholder="اسم الرقم"><input v-model="row.number" type="tel" inputmode="numeric" minlength="11" maxlength="11" pattern="07[78][0-9]{8}" required :aria-label="'رقم الدعم ' + (index + 1)" dir="ltr" placeholder="077xxxxxxxx أو 078xxxxxxxx" title="11 رقمًا تبدأ بـ077 أو 078"><button type="button" class="btn small" @click="phoneDraft.splice(index,1)">حذف</button></div><div class="actions"><button type="button" class="btn" :disabled="phoneDraft.length >= 5" @click="phoneDraft.push({label:'الدعم الفني',number:''})">+ إضافة رقم دعم</button><button class="btn primary">{{state.phoneSaving ? 'جارٍ الحفظ…' : 'حفظ الأرقام'}}</button></div></fieldset></form></details>

      </section>

      <section class="support-inbox">

        <div class="support-section-heading"><h2>المحادثات</h2><span class="badge">{{list.meta.unread_total ?? 0}} غير مقروءة</span></div>

        <div class="support-chat-layout">

          <aside class="support-chat-list"><input v-model="state.query" type="search" placeholder="بحث بالعنوان أو الطرف" aria-label="بحث محادثات الدعم"><div class="actions"><button type="button" class="btn small" :class="{primary:state.filter === 'all'}" @click="state.filter = 'all'">الكل</button><button type="button" class="btn small" :class="{primary:state.filter === 'unread'}" @click="state.filter = 'unread'">غير المقروءة</button><button type="button" class="btn small" :disabled="list.loading || state.saving" @click="refresh">تحديث</button></div>

            <button v-for="ticket in list.rows" :key="ticket.id" type="button" class="support-thread support-chat-item" :class="{unread:ticket.unread_count > 0,selected:selected?.id === ticket.id}" :disabled="state.saving" @click="chooseTicket(ticket)"><span class="support-chat-item-head"><b>{{ticket.title}}</b><span v-if="ticket.unread_count" class="support-unread-dot">{{ticket.unread_count}}</span></span><span>{{ticket.origin_name}} ← {{ticket.recipient_name}}</span><small class="support-snippet">{{ticket.last_message?.body || ticket.description}}</small><span class="support-chat-item-head"><small>{{time(ticket.last_message_at)}}</small><small>{{ticketStatuses[ticket.status] || ticket.status}}</small></span></button>

            <p v-if="list.loading" class="read-loading help" role="status">جارٍ تحميل المحادثات…</p><p v-else-if="!list.rows.length" class="empty">لا توجد محادثات مطابقة</p><div v-if="list.meta.last_page > 1" class="actions"><button type="button" class="btn small" :disabled="list.loading || list.meta.current_page <= 1" @click="load(list.meta.current_page-1)">السابق</button><span>{{list.meta.current_page}} / {{list.meta.last_page}}</span><button type="button" class="btn small" :disabled="list.loading || list.meta.current_page >= list.meta.last_page" @click="load(list.meta.current_page+1)">التالي</button></div>

          </aside>

          <SupportConversation ref="stream" v-model="reply" :ticket="selected" :counterpart="state.counterpart" :busy="state.saving" :loading="state.loadingDetail" @reply="sendReply" @status="changeStatus" @refresh="openTicket(selected.id)"/>

        </div>

      </section>

      <form v-if="session.can('support.create')" class="support-composer" @submit.prevent="saveTicket"><fieldset class="communication-fieldset" :disabled="state.saving || state.loadingOptions"><div class="support-section-heading"><h2>رسالة جديدة</h2><span class="caption">تواصل مع المستلم وتابع الردود هنا</span></div><div class="support-compose-grid"><label v-if="session.can('support.broadcast')">المستخدمين<select v-model="audience" aria-label="مستخدمو رسالة الدعم"><option value="all">عام لكل المستخدمين ضمن نطاقي</option><option v-if="state.options.identity?.type === 'system'" value="agents">الوكلاء الرئيسيون</option><option value="branches">الفروع</option><option value="custom">مخصص</option><option value="direct">مستلم مباشر</option></select></label><div v-if="audience === 'custom'"><input v-model="search" placeholder="بحث المستخدمين" aria-label="بحث مستلمي الدعم"><div class="scope-options"><label v-for="user in choices" :key="user.id"><input v-model="selectedUsers" type="checkbox" :value="user.id">{{user.name}}</label><p v-if="!choices.length">لا توجد نتائج</p></div></div><label v-if="audience === 'direct'">المرسل إليه<select v-model="form.recipient" required><option value="" disabled>اختر المستلم</option><option v-for="account in state.options.recipients" :key="account.id" :value="account.id">{{account.name}}</option></select></label><label>العنوان<input ref="composeTitle" v-model="form.title" required maxlength="200"></label><label class="support-message-field">محتوى الرسالة<textarea v-model="form.description" required maxlength="10000"></textarea></label></div><PrivateAttachment v-if="session.can('support.attach')" v-model="form.image" kind="support" :disabled="state.saving" @busy="attachmentBusy = $event" @error="failure"/><div class="formfoot"><button class="btn primary" :disabled="state.saving || attachmentBusy || state.loadingOptions">{{state.saving ? 'جارٍ الإرسال…' : 'إرسال الرسالة'}}</button></div></fieldset></form>

      </div>
    </div>

  </div>

</template>

