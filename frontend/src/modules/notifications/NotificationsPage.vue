<script setup>
import {computed,onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';
import {useRouter} from 'vue-router';
import {usePortal} from '../auth/session.js';
import {createPagedResource} from '../accounts/paged-resource.js';
import PrivateAttachment from '../support/PrivateAttachment.vue';
import CatalogModal from '../catalog/CatalogModal.vue';
import NoticeDetails from './NoticeDetails.vue';
import {attachmentUrl,audienceLabels,changedNotifications,createMutationKey,downloadCsv,errorText,searchUsers,time} from '../support/communications.js';
import {createNotificationsApi} from './notifications-api.js';
import '../finance/finance-parity.css';
import '../catalog/catalog.css';
import '../support/communications.css';

const {session} = usePortal(), router = useRouter(), api = createNotificationsApi(session.api);
const state = reactive({users:[],modes:[],mode:'all',search:'',query:'',filter:'all',locale:'ar',loadingOptions:session.can('notifications.send'),sending:false,exporting:false,error:'',success:''});
const form = reactive({title:'',body:'',translations:{en:{title:'',body:''},ckb:{title:'',body:''}},image:null});
const selected = ref([]), attachmentBusy = ref(false), reading = ref(null), detailNotice = ref(null), picker = ref(null), choices = computed(() => searchUsers(state.users,state.search));
const writer = new AbortController(), createKey = createMutationKey(); let optionsReader, timer, alive = true;
async function failure(cause) {
  if (!alive || cause.name === 'AbortError') return '';
  state.error = errorText(cause);
  if ([401,403].includes(cause.status)) {await session.refresh(); if (!session.state.identity) await router.replace({name:'login'});}
  return state.error;
}
const notices = createPagedResource((parameters,signal) => api.list(parameters,signal),failure), list = notices.state;
function filters(page = 1) {return {page,per_page:20,query:state.query,filter:state.filter,locale:state.locale};}
function load(page = 1) {return notices.load(filters(page));}
async function loadOptions() {
  if (!session.can('notifications.send')) return;
  optionsReader?.abort(); optionsReader = new AbortController(); state.loadingOptions = true;
  try {const response = await api.options(optionsReader.signal); if (alive) {state.users = response.data.users; state.modes = response.data.modes;}}
  catch (cause) {await failure(cause);} finally {if (alive) state.loadingOptions = false;}
}
async function send() {
  if (state.sending || state.loadingOptions || attachmentBusy.value) return;
  state.sending = true; state.error = ''; state.success = '';
  const payload = {title:form.title.trim(),body:form.body.trim(),mode:state.mode,...(state.mode === 'custom' ? {user_ids:[...selected.value]}:{}),...(form.image ? {attachment_id:form.image.id}:{})};
  if (session.can('notifications.translations')) {
    payload.translations = {};
    for (const language of ['en','ckb']) {const pair = form.translations[language]; if (pair.title.trim() || pair.body.trim()) payload.translations[language] = {title:pair.title.trim(),body:pair.body.trim()};}
  }
  try {
    const response = await api.create({...payload,idempotency_key:createKey.for(payload)},writer.signal); if (!alive) return;
    form.title = ''; form.body = ''; form.image = null; form.translations = {en:{title:'',body:''},ckb:{title:'',body:''}}; selected.value = []; state.search = ''; state.mode = 'all'; createKey.clear();
    state.success = `تم إرسال الإشعار إلى ${response.data.count} مستخدم.`; changedNotifications(); await load();
  } catch (cause) {await failure(cause);} finally {if (alive) state.sending = false;}
}
async function openNotice(notice) {
  if (reading.value !== null) return;
  reading.value = notice.id; state.error = '';
  try {
    if (!notice.mine && !notice.read) {await api.read(notice.id,writer.signal); if (!alive) return; notice.read = true; changedNotifications();}
    let destination = notice.page;
    if (notice.can_open && destination === 'accounts') {
      const account = (await session.api.account(notice.entity_id,writer.signal)).data;
      destination = account.type === 'pos' ? 'pos' : 'agents';
    }
    if (notice.can_open && destination && router.hasRoute(destination)) {
      const query = notice.entity_id ? {[notice.page === 'support' ? 'ticket':'notice_entity']:String(notice.entity_id)} : {};
      await router.push({name:destination,query});
    } else {detailNotice.value = {...notice}; if (state.filter === 'unread') await load(list.meta.current_page);}
  } catch (cause) {await failure(cause);} finally {if (alive) reading.value = null;}
}
async function exportNotices() {
  if (state.exporting) return; state.exporting = true; state.error = '';
  try {const response = await api.export(filters(),writer.signal); if (alive) {downloadCsv(response.data); state.success = `تم تصدير ${response.data.count} إشعار.`;}}
  catch (cause) {await failure(cause);} finally {if (alive) state.exporting = false;}
}
function dismissPicker(event) {event.currentTarget.open = false; event.currentTarget.querySelector('summary')?.focus();}
function outsidePicker(event) {if (picker.value?.open && !picker.value.contains(event.target)) picker.value.open = false;}
watch(() => [state.query,state.filter,state.locale], () => {clearTimeout(timer); timer = setTimeout(() => load(),250);});
onMounted(() => {document.addEventListener('pointerdown',outsidePicker); return Promise.all([load(),loadOptions()]);});
onBeforeUnmount(() => {alive = false; document.removeEventListener('pointerdown',outsidePicker); clearTimeout(timer); writer.abort(); optionsReader?.abort(); notices.dispose();});
</script>
<template>
  <div class="finance-workspace communication-workspace notifications-module">
    <p v-if="state.error || list.error" class="notice warn" role="alert">{{state.error || list.error}}</p><p v-if="state.success" class="notice" role="status">{{state.success}}</p>
    <div v-if="session.can('notifications.send')" class="card"><form @submit.prevent="send"><fieldset class="communication-fieldset" :disabled="state.sending || state.loadingOptions"><div class="formgrid"><label>المستخدمين<select v-model="state.mode" aria-label="المستخدمين"><option v-for="mode in state.modes" :key="mode" :value="mode">{{audienceLabels[mode]}}</option></select></label></div><details v-if="state.mode === 'custom'" ref="picker" class="notice-picker" @keydown.esc.prevent="dismissPicker"><summary>{{selected.length ? 'تم تحديد ' + selected.length + ' مستخدم' : 'اختر المستخدمين'}}</summary><div class="notice-dropdown"><input v-model="state.search" placeholder="بحث بالاسم أو البريد" aria-label="بحث المستخدمين"><div class="notice-users"><label v-for="user in choices" :key="user.id"><input v-model="selected" type="checkbox" :value="user.id">{{user.name}}</label><p v-if="!choices.length">لا توجد نتائج</p></div></div></details><div class="notice-language-grid"><fieldset><legend>العربية</legend><label>العنوان<input v-model="form.title" required dir="rtl" maxlength="200" aria-label="عنوان الإشعار العربي"></label><label>النص<textarea v-model="form.body" required dir="rtl" maxlength="10000" aria-label="نص الإشعار العربي"></textarea></label></fieldset><fieldset v-for="language in [{id:'en',name:'English'},{id:'ckb',name:'کوردی'}]" :key="language.id" :disabled="!session.can('notifications.translations')"><legend translate="no">{{language.name}}</legend><label>العنوان<input v-model="form.translations[language.id].title" :dir="language.id === 'en' ? 'ltr':'rtl'" maxlength="200" :aria-label="language.name + ' title'"></label><label>النص<textarea v-model="form.translations[language.id].body" :dir="language.id === 'en' ? 'ltr':'rtl'" maxlength="10000" :aria-label="language.name + ' text'"></textarea></label></fieldset></div><p v-if="!form.translations.en.title || !form.translations.en.body || !form.translations.ckb.title || !form.translations.ckb.body" class="help">الترجمة غير المكتملة لا تُرسل. عند ترك لغة فارغة بالكامل، يظهر النص العربي بدلًا منها.</p><PrivateAttachment v-if="session.can('notifications.attach')" v-model="form.image" kind="notification" :disabled="state.sending" @busy="attachmentBusy = $event" @error="failure"/><div class="formfoot"><button class="btn primary" :disabled="state.sending || state.loadingOptions || attachmentBusy">{{state.sending ? 'جارٍ الإرسال…' : 'إرسال'}}</button></div></fieldset></form></div>
    <div class="card notifications-list" style="margin-top:20px"><div class="notice-toolbar"><input v-model="state.query" type="search" placeholder="بحث الإشعارات" aria-label="بحث الإشعارات"><select v-model="state.filter" aria-label="عرض الإشعارات"><option value="all">الكل</option><option value="unread">غير المقروءة</option></select><select v-model="state.locale" aria-label="لغة محتوى الإشعارات"><option value="ar">العربية</option><option value="en">English</option><option value="ckb">کوردی</option></select><button type="button" class="btn small" :disabled="list.loading" @click="load(list.meta.current_page)">تحديث</button><button v-if="session.can('notifications.export')" type="button" class="btn small" :disabled="state.exporting" @click="exportNotices">تصدير</button></div><div v-for="notice in list.rows" :key="notice.id" class="rowline"><div><b translate="no" dir="auto">{{notice.title}}</b><p translate="no" dir="auto" style="white-space:pre-wrap">{{notice.body}}</p><img v-if="notice.attachment_id" :src="attachmentUrl(notice.attachment_id)" class="message-image" alt="صورة الإشعار" loading="lazy"><small>{{time(notice.time)}} · {{notice.mine ? 'صادر' : 'وارد'}}</small></div><button type="button" class="btn small" :disabled="reading !== null" @click="openNotice(notice)">{{notice.page === 'support' && notice.can_open ? 'فتح الرسالة' : notice.read ? 'مقروء' : 'عرض'}}</button></div><p v-if="list.loading" class="read-loading help" role="status">جارٍ تحميل الإشعارات…</p><div v-else-if="!list.rows.length" class="empty">لا توجد إشعارات</div><div v-if="list.meta.last_page > 1" class="actions"><button type="button" class="btn small" :disabled="list.loading || list.meta.current_page <= 1" @click="load(list.meta.current_page-1)">السابق</button><span>{{list.meta.current_page}} / {{list.meta.last_page}}</span><button type="button" class="btn small" :disabled="list.loading || list.meta.current_page >= list.meta.last_page" @click="load(list.meta.current_page+1)">التالي</button></div></div>
    <CatalogModal v-if="detailNotice" title="الإشعار" section="الإشعارات" content-class="finance-modal communication-workspace" @close="detailNotice = null"><NoticeDetails :notice="detailNotice"/><div class="formfoot"><button type="button" class="btn" @click="detailNotice = null">إغلاق</button></div></CatalogModal>
  </div>
</template>
