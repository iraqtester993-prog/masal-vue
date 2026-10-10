<script setup>
import {usePreferencesTranslator} from "../preferences/preferences-state.js";
const uiLabel=usePreferencesTranslator();
import { computed,reactive,ref,onMounted,onBeforeUnmount,onServerPrefetch } from 'vue';
import { usePortal } from '../auth/session.js';
import { navIconPath } from '../../layouts/navigation.js';
import { createReportApi } from './report-api.js';
import { emptyFilters,groupCards,tr,errorText,formatNumber } from './report-model.js';
import { exportReport } from './report-export.js';
import ReportSettings from './ReportSettings.vue';
import ReportDetails from './ReportDetails.vue';
import './reports-parity.css';
const {session}=usePortal(),api=createReportApi(session.api);
const filters=reactive(emptyFilters()),options=reactive({accounts:[],products:[],providers:[],cities:[],currencies:['IQD'],groups:[],sections:[]}),sections=ref([]),group=ref(''),settings=ref(false),detail=ref(null),busy=ref(false),exportBusy=ref(false),error=ref('');
let controller,exportController,revision=0,alive=true;
const groups=computed(()=>options.groups.map(g=>({...g,sections:sections.value.filter(s=>g.prefixes.includes(s.id.split('-')[0])&&(filters.kind==='all'||s.id.split('-')[0]===filters.kind))})).filter(g=>g.sections.length));
const selectedGroup=computed(()=>groups.value.find(g=>g.id===group.value));
const cards=computed(()=>selectedGroup.value?groupCards(selectedGroup.value,selectedGroup.value.sections):[]);
async function load(initial=false){controller?.abort();controller=new AbortController();const expected=++revision;busy.value=true;error.value='';
  try{if(initial){const result=await api.options(controller.signal);if(!alive||expected!==revision)return;Object.assign(options,result.data);sections.value=options.sections.map(s=>({...s,available:true,count:null}));}if(group.value&&!selectedGroup.value)group.value='';if(group.value){const ids=selectedGroup.value?.sections.map(s=>s.id)||[];if(ids.length){const response=await api.summary({...filters,section_ids:ids},controller.signal);if(alive&&expected===revision){const received=new Map(response.data.sections.map(s=>[s.id,s]));sections.value=sections.value.map(s=>received.get(s.id)||s);}}}}
  catch(failure){if(failure.name!=='AbortError'&&alive&&expected===revision)error.value=errorText(failure);}
  finally{if(alive&&expected===revision)busy.value=false;}
}
function selectGroup(id){group.value=id;load();}
function apply(next){Object.assign(filters,next);settings.value=false;detail.value=null;load();}
function open(section){if(section.available)detail.value=section;else error.value=section.reason;}
async function download({parameters,print}={parameters:{...filters,section_ids:(selectedGroup.value?.sections||sections.value).map(s=>s.id)},print:false}){
  if(exportBusy.value)return;exportController?.abort();exportController=new AbortController();const expected=revision,actor=session.state.identity?.user?.id;exportBusy.value=true;error.value='';
  try{await exportReport(api,parameters,print,exportController.signal,()=>alive&&expected===revision&&session.state.identity?.user?.id===actor);}
  catch(failure){if(alive&&failure.name!=='AbortError')error.value=errorText(failure);}
  finally{if(alive)exportBusy.value=false;}
}
onMounted(()=>load(true));onServerPrefetch(()=>load(true));onBeforeUnmount(()=>{alive=false;revision++;controller?.abort();exportController?.abort();});
</script>
<template><div class="reports-workspace"><div class="card report-export-bar report-unified-toolbar"><div class="report-toolbar-title"><h2 v-if="selectedGroup">{{tr(selectedGroup.title)}}</h2><span v-else class="help">التقارير حسب الفترة والجهة المختارة · {{sections.length}} أقسام التقرير</span></div><div class="actions"><button v-if="selectedGroup" class="btn" @click="selectGroup('')">رجوع إلى المجموعات</button><button class="btn primary" :disabled="busy" @click="settings=true">إعداد التقرير</button><button v-if="session.can('reports.export')" class="btn" :disabled="busy||exportBusy||!sections.length" @click="download()">تنزيل التقرير المنسق · Excel</button><button v-if="session.can('reports.print')" class="btn" :disabled="busy||exportBusy||!sections.length" @click="download({parameters:{...filters,section_ids:(selectedGroup?.sections||sections).map(s=>s.id)},print:true})">طباعة / حفظ PDF</button></div></div><div v-if="error" class="notice warn" role="alert">{{error}} <button class="btn small" @click="load(!options.sections.length)">إعادة المحاولة</button></div><p v-if="busy||exportBusy" class="help" :class="{'read-loading':!exportBusy}" role="status">{{exportBusy?'جارٍ إعداد جميع السجلات المطابقة…':'جارٍ تحميل التقارير…'}}</p><div v-if="!selectedGroup" class="report-group-grid"><article v-for="g in groups" :key="g.id" class="card report-group-card" :class="'report-group-'+g.id" role="button" tabindex="0" @click="selectGroup(g.id)" @keydown.enter="selectGroup(g.id)" @keydown.space.prevent="selectGroup(g.id)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path :d="navIconPath(g.icon)"/></svg><strong>{{tr(g.title)}}</strong><span>{{tr(g.note)}}</span><small>{{g.sections.length}} تقارير</small><button class="btn small report-group-action" type="button" @click.stop="selectGroup(g.id)">عرض التفاصيل <span aria-hidden="true">←</span></button></article></div><section v-else id="report-group-content" class="report-related" :class="'report-group-'+selectedGroup.id" :aria-label="tr(selectedGroup.title)"><div class="report-related-grid"><article v-for="section in cards" :key="section.id+section.filter" class="card report-related-card report-tile"><div class="report-tile-heading"><span class="report-tile-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path :d="navIconPath(selectedGroup.icon)"/></svg></span><h3>{{tr(section.title)}}</h3></div><p v-if="!section.available" class="help">{{uiLabel(section.reason)}}</p><div class="report-tile-footer"><div class="report-tile-count"><strong>{{formatNumber(section.count)}}</strong><small>{{section.available?'سجل':'غير متاح'}}</small></div><button class="btn small report-tile-open" :disabled="!section.available" :aria-label="'عرض التفاصيل — '+tr(section.title)" @click="open(section)"><span>عرض التفاصيل</span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 12H5m6-6-6 6 6 6"/></svg></button></div></article></div></section><div v-if="!groups.length&&!busy" class="card empty">لا توجد أقسام تقارير مسموحة لهذا الحساب</div><ReportSettings v-if="settings" :filters="filters" :options="options" @close="settings=false" @apply="apply"/><ReportDetails v-if="detail" :key="detail.id+detail.filter" :section="detail" :filters="filters" :api="api" @close="detail=null" @export="download" @open="id=>{const next=sections.find(s=>s.id===id);if(next)detail={...next,filter:''}}"/></div></template>

