<script setup>
import {usePreferencesTranslator} from '../preferences/preferences-state.js';
const t=usePreferencesTranslator();
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';

import { computed,ref,onMounted,onBeforeUnmount,onServerPrefetch,watch } from 'vue';
import { useRouter } from 'vue-router';
import { usePortal } from '../auth/session.js';
import ReportModal from './ReportModal.vue';
import { reportCell,tr,errorText,formatNumber } from './report-model.js';
import { reportDestination,reportColumnHelp,reportSourceNotes } from './report-navigation.js';
const props=defineProps({section:{type:Object,required:true},filters:Object,api:Object});const emit=defineEmits(['close','export','open']);
const {session}=usePortal(),router=useRouter();
const rows=ref([]),columns=ref(props.section.columns??[]),hidden=ref([]),q=ref(''),sort=ref(''),direction=ref('desc'),page=ref(1),pageSize=ref(20),meta=ref({total:0,last_page:1}),busy=ref(false),error=ref(''),selected=ref(null),selectionBusy=ref(false);
let controller,detailController,revision=0,timer,alive=true;
const visible=computed(()=>columns.value.filter(c=>!hidden.value.includes(c.key)));
const parameters=computed(()=>({...props.filters,detail_filter:props.section.filter||'',q:q.value,sort:sort.value,direction:sort.value?direction.value:'',page:page.value,per_page:pageSize.value}));
const noColumns=computed(()=>!visible.value.length);
async function load(){
  controller?.abort();controller=new AbortController();const expected=++revision;busy.value=true;error.value='';selected.value=null;
  try {const response=await props.api.rows(props.section.id,parameters.value,controller.signal);if(!alive||expected!==revision)return;rows.value=response.data;columns.value=response.columns;meta.value=response.meta;}
  catch(failure){if(failure.name!=='AbortError'&&alive&&expected===revision)error.value=errorText(failure);}
  finally{if(alive&&expected===revision)busy.value=false;}
}
function search(){clearTimeout(timer);page.value=1;timer=setTimeout(load,300);}
function sortBy(column){if(column.source_available===false)return;direction.value=sort.value===column.key&&direction.value==='asc'?'desc':'asc';sort.value=column.key;page.value=1;load();}
function toggleColumn(key){hidden.value=hidden.value.includes(key)?hidden.value.filter(k=>k!==key):[...hidden.value,key];}
function jump(number){page.value=number;load();}
async function selectRow(row){detailController?.abort();detailController=new AbortController();selectionBusy.value=true;error.value='';const expected=revision;
  try{const response=await props.api.row(props.section.id,row.row_key,parameters.value,detailController.signal);if(alive&&expected===revision)selected.value=response;}
  catch(failure){if(alive&&failure.name!=='AbortError')error.value=errorText(failure);}
  finally{if(alive)selectionBusy.value=false;}
}
function exportRows(print){emit('export',{parameters:{...parameters.value,section_ids:[props.section.id],visible_columns:visible.value.map(c=>c.key)},print});}
const destination=computed(()=>reportDestination(props.section.id,router.getRoutes(),session.state.identity,session.can,props.filters));
const sourceNotes=computed(()=>reportSourceNotes(columns.value));
onMounted(load);onServerPrefetch(load);watch(pageSize,()=>{page.value=1;load();});onBeforeUnmount(()=>{alive=false;revision++;clearTimeout(timer);controller?.abort();detailController?.abort();});
</script>
<template><ReportModal :title="tr(section.title)" wide @close="emit('close')"><div class="report-detail-dialog" data-no-pagination><FilterBar class="report-table-tools toolbar"><input v-model="q" placeholder="بحث داخل التقرير" aria-label="بحث داخل التقرير" @input="search"/><details class="column-picker"><summary class="btn">الأعمدة</summary><div><label v-for="c in columns" :key="c.key" class="inline-check"><input type="checkbox" :checked="!hidden.includes(c.key)" @change="toggleColumn(c.key)"/>{{tr(c.label)}}</label></div></details><span class="badge">{{formatNumber(meta.total)}} سجل</span><span class="badge">{{section.snapshot?'الوضع الحالي':'حركات الفترة'}}</span><select v-model.number="pageSize" aria-label="عدد السجلات في الصفحة"><option :value="20">20</option><option :value="50">50</option><option :value="100">100</option></select><button v-if="session.can('reports.export')" class="btn" :disabled="noColumns||busy" @click="exportRows(false)">تصدير Excel</button><button v-if="session.can('reports.print')" class="btn" :disabled="noColumns||busy" @click="exportRows(true)">طباعة / حفظ PDF</button></FilterBar><p v-if="section.note" class="help">{{tr(section.note)}}</p><p v-for="note in sourceNotes" :key="note" class="caption">{{t(note)}}</p><div v-if="error" class="notice warn" role="alert">{{error}}</div><p v-if="busy||selectionBusy" role="status" class="read-loading help">جارٍ تحميل البيانات…</p><section v-if="selected" class="report-record-panel" aria-label="تفاصيل السجل"><div class="cardhead"><h3>تفاصيل السجل</h3><button class="btn small" @click="selected=null">إغلاق التفاصيل</button></div><dl class="report-record-fields"><div v-for="c in selected.columns" :key="c.key"><dt>{{tr(c.label)}}</dt><dd>{{c.label_type?t(reportCell(selected.data[c.key],c,selected.data)):reportCell(selected.data[c.key],c,selected.data)}}</dd></div></dl><section v-for="related in selected.related||[]" :key="related.id" class="report-linked"><div class="cardhead"><h4>{{tr(related.title)}}</h4><span class="badge">{{formatNumber(related.total)}} سجل</span></div><TablePanel :start="(meta.current_page-1)*pageSize+1" class="tablewrap"><table><thead><tr><th v-for="c in related.columns" :key="c.key">{{tr(c.label)}}</th></tr></thead><tbody><tr v-for="row in related.rows" :key="row.row_key"><td v-for="c in related.columns" :key="c.key">{{c.label_type?t(reportCell(row[c.key],c,row)):reportCell(row[c.key],c,row)}}</td></tr></tbody></table></TablePanel><p v-if="related.total>20" class="caption">عرض أول 20 سجلًا مرتبطًا.</p><button v-if="related.total>20" class="btn small" @click="emit('open',related.id)">فتح التقرير الكامل</button></section></section><TablePanel :start="(meta.current_page-1)*pageSize+1" class="tablewrap"><table><thead><tr><th v-for="c in visible" :key="c.key"><button class="table-sort" :disabled="c.source_available===false" :title="t(reportColumnHelp(c))||undefined" @click="sortBy(c)">{{tr(c.label)}} {{sort===c.key?(direction==='asc'?'↑':'↓'):''}}</button></th><th>التفاصيل</th></tr></thead><tbody><tr v-for="row in rows" :key="row.row_key"><td v-for="c in visible" :key="c.key"><span :class="{badge:c.key==='status'||c.key==='active'}">{{c.label_type?t(reportCell(row[c.key],c,row)):reportCell(row[c.key],c,row)}}</span></td><td><button class="btn small" :disabled="selectionBusy" @click="selectRow(row)">عرض التفاصيل</button></td></tr><tr v-if="!rows.length&&!busy"><td :colspan="visible.length+1" class="empty">لا توجد سجلات مطابقة.</td></tr></tbody></table></TablePanel><div class="table-pagination"><div>الصفحة {{page}} من {{meta.last_page}} · {{formatNumber(meta.total)}} سجل</div><div class="actions"><button class="btn small" :disabled="page<=1||busy" @click="jump(page-1)">السابق</button><button class="btn small" :disabled="page>=meta.last_page||busy" @click="jump(page+1)">التالي</button></div></div><div class="formfoot"><button class="btn" @click="emit('close')">إغلاق</button><button v-if="destination" class="btn primary" @click="router.push(destination.to);emit('close')">الانتقال إلى الصفحة</button></div></div></ReportModal></template>


