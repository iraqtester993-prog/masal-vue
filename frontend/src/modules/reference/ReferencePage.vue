<script setup>
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';


import {computed, onBeforeUnmount, onMounted, reactive, ref, watch} from 'vue';

import {useRoute, useRouter} from 'vue-router';

import {usePortal} from '../auth/session.js';

import CatalogModal from '../catalog/CatalogModal.vue';

import {createReferenceApi, referenceDraft, referenceKind, referencePayload, referencePermission} from './reference-api.js';

import '../catalog/catalog.css';

import './reference.css';

const {session} = usePortal(), route = useRoute(), router = useRouter(), api = createReferenceApi(session.api);

const kind = computed(() => referenceKind(route.name));

const permission = computed(() => referencePermission(kind.value));

const labels = {governorates:'المحافظات', sources:'المصادر', 'pos-types':'أنواع نقاط البيع', representatives:'المندوبون'};

const addLabels = {sources:'إضافة مصدر', 'pos-types':'نوع نقطة بيع', representatives:'مندوب جديد'};

const state = reactive({rows:[], meta:{current_page:1,last_page:1,total:0}, options:{providers:[],agents:[],networks:[],pos_types:[]}, q:'', network:'', busy:false, saving:false, error:'', success:''});

const pageSize=ref(10),networkOpen=ref(false);watch(pageSize,()=>load());

const draft = ref(null), photoRows = ref([]), deletedPhotos = ref([]);

let readController, revision = 0, timer;

const writeController = new AbortController();

const can = (verb, row = null) => {

  if (!session.can(`${permission.value}.${verb}`)) return false;

  if (kind.value === 'sources' && verb !== 'view') return (state.options.writable_network_ids || []).includes(Number(row?.network_account_id || state.network || session.state.identity?.account.id)) || (verb === 'create' && session.state.identity?.account.type === 'system' && state.options.writable_network_ids?.length > 0);

  return true;

};

const networks = computed(() => state.options.networks || []);

const agents = computed(() => state.options.agents || []);

async function failure(error) {

  if (error.name === 'AbortError') return;

  if (error.status === 401 || error.status === 403) { await session.refresh(); if (!session.state.identity) await router.replace({name:'login'}); }

  state.error = Object.values(error.errors || {}).flat().join(' ') || error.message || 'تعذر إكمال الطلب.';

}

async function load(page = 1, withOptions = false) {

  readController?.abort(); readController = new AbortController(); const expected = ++revision, signal = readController.signal;

  state.busy = true; state.error = '';

  try {

    const requestKind = kind.value;

    const [response, options] = await Promise.all([api.list(requestKind, {query:state.q, page, per_page:pageSize.value, ...(requestKind === 'sources' && state.network ? {network_account_id:state.network} : {})}, signal), withOptions && requestKind !== 'governorates' ? api.options(state.network, signal) : Promise.resolve(null)]);

    if (expected !== revision) return;

    state.rows = response.data.map(row => ({...row, active:row.active ?? row.status === 'active'})); state.meta = {...response.meta,current_page:response.meta.current_page ?? response.meta.page};

    if (options) state.options = options.data;

  } catch (error) { if (expected === revision) await failure(error); }

  finally { if (expected === revision) state.busy = false; }

}

function close() {

  if (state.saving) return;

  for (const photo of photoRows.value) if (photo.temporary) URL.revokeObjectURL(photo.url);

  draft.value = null; photoRows.value = []; deletedPhotos.value = [];

}

function edit(row = null) {

  if (!can(row ? 'edit' : 'create', row)) return;

  close(); if(kind.value==='sources' && !row && session.state.identity?.account.type==='system')networkOpen.value=true; state.error = ''; state.success = ''; draft.value = referenceDraft(kind.value, row);

  photoRows.value = (row?.photos || []).map(photo => ({...photo}));

  if (row?.provider_id && !state.options.providers.some(item => item.id === row.provider_id)) state.options.providers.push({id:row.provider_id,name:row.provider_name,status:'disabled'});

  if (row?.agent_account_id && !state.options.agents.some(item => item.id === row.agent_account_id)) state.options.agents.push({id:row.agent_account_id,name:row.agent_name,status:'disabled'});

}

async function addPhotos(event) {

  const files = [...event.target.files]; event.target.value = '';

  for (const file of files) {

    if (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size > 700000) {state.error = 'اختر صورة PNG أو JPG أو WEBP بحجم لا يتجاوز 700 كيلوبايت.'; continue;}

    const url = URL.createObjectURL(file);

    photoRows.value.push({temporary:true, file, url, name:file.name});

  }

}

function removePhoto(photo) {

  if (photo.temporary) URL.revokeObjectURL(photo.url); else deletedPhotos.value.push(photo.id);

  photoRows.value = photoRows.value.filter(row => row !== photo);

}

async function save() {

  if (state.saving) return;

  state.saving = true; state.error = ''; state.success = '';

  try {

    let saved = (await api.save(kind.value, draft.value.id, referencePayload(kind.value, draft.value, state.network || draft.value.network_account_id), writeController.signal)).data;

    draft.value.id = saved.id; draft.value.version = saved.version;

    if (kind.value === 'representatives') {

      for (const id of [...deletedPhotos.value]) {saved = (await api.deletePhoto(saved.id, id, saved.version, writeController.signal)).data; deletedPhotos.value = deletedPhotos.value.filter(value => value !== id); draft.value.version = saved.version;}

      for (const photo of photoRows.value.filter(row => row.temporary)) {saved = (await api.uploadPhoto(saved.id, saved.version, photo.file, writeController.signal)).data; draft.value.version = saved.version; URL.revokeObjectURL(photo.url); photo.temporary = false; photoRows.value = [...(saved.photos || []).map(row => ({...row})), ...photoRows.value.filter(row => row.temporary)];}

    }

    state.saving = false; close(); state.success = 'تم حفظ البيانات.'; await load(state.meta.current_page, true);

  } catch (error) { await failure(error); }

  finally { state.saving = false; }

}

async function toggle(row) {

  if (state.saving) return;

  state.saving = true; state.error = ''; state.success = '';

  try { await api.status(kind.value, row, writeController.signal); state.success = 'تم تحديث الحالة.'; await load(state.meta.current_page); }

  catch (error) { await failure(error); } finally { state.saving = false; }

}

async function exportRows() {

  if (state.saving) return;

  state.saving = true; state.error = '';

  try {

    const result = await api.export(kind.value, {query:state.q}, writeController.signal);

    const url = URL.createObjectURL(new Blob([result.data.csv], {type:'text/csv;charset=utf-8'}));

    const link = document.createElement('a'); link.href = url; link.download = result.data.filename;

    document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);

  } catch (error) { await failure(error); } finally { state.saving = false; }

}

watch(() => state.q, () => {clearTimeout(timer); timer = setTimeout(() => load(), 220);});

watch(() => state.network, () => {close(); load(1, true);});

watch(kind, () => {close(); state.q = ''; state.network = ''; state.success = ''; load(1, true);});

watch(() => route.query.new, value => { if (value === '1' && !state.busy) edit(); });

onMounted(async () => {await load(1, true); if (route.query.new === '1') edit();});

onBeforeUnmount(() => {clearTimeout(timer); readController?.abort(); writeController.abort(); state.saving = false; close();});

</script>



<template>

  <section class="reference-module catalog-workspace" :aria-label="labels[kind]">

    <p v-if="state.error && !draft" class="notice warn" role="alert">{{state.error}}</p>

    <p v-if="state.success" class="notice success" role="status">{{state.success}}</p>

    <FilterBar v-if="kind === 'sources' && networkOpen && session.state.identity?.account.type === 'system'" class="toolbar reference-network">

      <label>الوكيل الرئيسي <select v-model="state.network"><option value="">جميع الوكلاء</option><option v-for="network in networks" :key="network.id" :value="network.id">{{network.name}}</option></select></label>

    </FilterBar>

    <div class="card" :class="{'workspace-card':!['sources','governorates'].includes(kind)}">

      <FilterBar class="toolbar" :class="{'sources-original-toolbar':kind==='sources'}">

        <button v-if="kind !== 'governorates' && can('create')" class="btn primary" :disabled="state.saving || state.busy" @click="edit()">{{addLabels[kind]}}</button>

        <button v-if="['pos-types','representatives'].includes(kind) && can('export')" class="btn" :disabled="state.saving" @click="exportRows">تصدير</button>

        <input v-model="state.q" :placeholder="kind === 'governorates' ? 'بحث عن محافظة' : kind === 'sources' ? 'بحث باسم المصدر أو الشركة' : 'بحث بالاسم أو المحافظة…'" :aria-label="`بحث ${labels[kind]}`" :style="kind==='sources'?'flex:1;max-width:none':'max-width:300px'" />

        <span v-if="kind === 'governorates'">{{state.rows.filter(row => row.active).length}} محافظة مفعلة</span><span v-else-if="kind!=='sources'" class="caption">{{state.meta.total}} سجل</span><template v-if="kind==='sources'"><select v-model.number="pageSize" aria-label="عدد المصادر في الصفحة"><option v-for="n in [10,25,50,100]" :value="n">{{n}}</option></select><small>{{state.meta.total?(state.meta.current_page-1)*pageSize+1:0}}–{{Math.min(state.meta.current_page*pageSize,state.meta.total)}} من {{state.meta.total}}</small><button v-if="session.state.identity?.account.type==='system'" class="btn small" aria-label="فلترة الوكيل الرئيسي" @click="networkOpen=!networkOpen">⋯</button></template>

      </FilterBar>

      <form v-if="draft && kind === 'sources'" class="control-inline-form" @submit.prevent="save">

        <div class="formgrid"><label>اسم المصدر<input v-model="draft.name" required maxlength="150" :disabled="state.saving" /></label><label>الشركة<select v-model="draft.provider_id" required :disabled="state.saving"><option value="" disabled>اختر الشركة</option><option v-for="provider in state.options.providers" :key="provider.id" :value="provider.id">{{provider.name}}</option></select></label></div>

        <p v-if="session.state.identity?.account.type === 'system' && !draft.id && !state.network" class="help">اختر الوكيل الرئيسي للمصدر.</p>

        <p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p>

        <div class="formfoot"><button class="btn primary" :disabled="state.saving || (session.state.identity?.account.type === 'system' && !draft.id && !state.network)">حفظ المصدر</button><button class="btn" type="button" :disabled="state.saving" @click="close">إلغاء</button></div>

      </form>

      <p v-if="state.busy" class="read-loading help" role="status">جارٍ تحميل البيانات…</p>

      <TablePanel :start="(state.meta.current_page-1)*pageSize+1" class="tablewrap"><table><thead><tr>

        <template v-if="kind === 'governorates'"><th>المحافظة</th><th>الوكلاء الرئيسيون</th><th>الوكلاء الفرعيون</th><th>نقاط البيع</th><th>الحالة</th><th>الإجراء</th></template>

        <template v-else-if="kind === 'sources'"><th>اسم المصدر</th><th>الشركة</th><th>الحالة</th><th>الإجراءات</th></template>

        <template v-else-if="kind === 'representatives'"><th>المعرف</th><th>اسم المندوب</th><th>رقم الهاتف</th><th>العنوان</th><th>الوكيل</th><th>الحالة</th><th>الإجراءات</th></template>

        <template v-else><th>المعرف</th><th>النوع</th><th>الحالة</th><th>الإجراءات</th></template>

      </tr></thead><tbody><tr v-for="row in state.rows" :key="row.id">

        <template v-if="kind === 'governorates'"><td>{{row.name}}</td><td>{{row.main_agents_count}}</td><td>{{row.sub_agents_count}}</td><td>{{row.pos_count}}</td></template>

        <template v-else-if="kind === 'sources'"><td>{{row.name}}</td><td>{{row.provider_name}}</td></template>

        <template v-else-if="kind === 'representatives'"><td class="mono">{{row.id}}</td><td>{{row.name || '—'}}</td><td>{{row.phone || '—'}}</td><td>{{row.address || '—'}}</td><td>{{row.agent_name}}</td></template>

        <template v-else><td class="mono">{{row.id}}</td><td>{{row.name}}</td></template>

        <td><span class="badge" :class="{neutral:!row.active}">{{kind === 'governorates' ? (row.active ? 'مفعلة':'معطلة') : kind === 'sources' ? (row.active ? 'مفعل':'معطل') : (row.active ? 'مفعل':'موقوف')}}</span></td>

        <td><div class="actions"><button v-if="kind !== 'governorates' && can('edit', row)" class="btn small" :disabled="state.saving" @click="edit(row)">تعديل</button><button v-if="can('toggle', row)" class="btn small" :disabled="state.saving" @click="toggle(row)">{{row.active ? (kind === 'governorates' || kind === 'sources' ? 'تعطيل':'إيقاف') : 'تفعيل'}}</button></div></td>

      </tr></tbody></table></TablePanel>

      <div v-if="!state.busy && !state.rows.length" class="empty">{{kind === 'sources' ? 'لا توجد مصادر':'لا توجد بيانات في نطاق حسابك'}}</div>

      <div v-if="kind==='sources' || state.meta.last_page > 1" class="reference-pagination"><button class="btn small" :disabled="state.busy || state.meta.current_page <= 1" @click="load(state.meta.current_page-1)">السابق</button><span>الصفحة {{state.meta.current_page}} من {{state.meta.last_page}}</span><button class="btn small" :disabled="state.busy || state.meta.current_page >= state.meta.last_page" @click="load(state.meta.current_page+1)">التالي</button></div>

    </div>

    <CatalogModal v-if="draft && kind !== 'sources'" :title="draft.id ? 'تعديل البيانات' : addLabels[kind]" :busy="state.saving" @close="close">

      <form @submit.prevent="save"><div class="formgrid">

        <template v-if="kind === 'representatives'"><label>اسم المندوب<input v-model="draft.name" maxlength="150" :disabled="state.saving" /></label><label>رقم الهاتف<input v-model="draft.phone" type="tel" maxlength="40" :disabled="state.saving" /></label><label>العنوان<input v-model="draft.address" maxlength="500" :disabled="state.saving" /></label><label>الوكيل<select v-model="draft.agent_account_id" required :disabled="state.saving"><option value="" disabled>اختر الوكيل</option><option v-for="agent in agents" :key="agent.id" :value="agent.id">{{agent.name}}</option></select></label></template>

        <template v-else><label>اسم نوع نقطة البيع<input v-model="draft.name" required maxlength="150" :disabled="state.saving" /></label><label>الحالة<select v-model="draft.status" :disabled="state.saving"><option value="active">مفعل</option><option value="disabled">موقوف</option></select></label></template>

      </div>

      <section v-if="kind === 'representatives' && session.can('representatives.images')" class="pos-related-editor"><div class="cardhead"><h3>صور المندوب</h3><span class="help">اختياري ويمكن إضافة أكثر من صورة</span></div><label class="btn small">إضافة صور<input type="file" accept="image/png,image/jpeg,image/webp" multiple hidden :disabled="state.saving" @change="addPhotos" /></label><div class="document-previews"><span v-for="photo in photoRows" :key="photo.id || photo.url" class="document-thumb"><img :src="photo.url" alt="صورة المندوب" /><button type="button" :disabled="state.saving" aria-label="حذف الصورة" @click="removePhoto(photo)">×</button></span></div></section>

      <p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p><div class="formfoot"><button type="button" class="btn" :disabled="state.saving" @click="close">إلغاء</button><button class="btn primary" :disabled="state.saving">{{state.saving ? 'جارٍ الحفظ…':'حفظ البيانات ✓'}}</button></div></form>

    </CatalogModal>

  </section>

</template>

