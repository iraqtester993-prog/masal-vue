<script setup>
import {usePreferencesTranslator} from "../preferences/preferences-state.js";
const uiLabel=usePreferencesTranslator();
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';

import { computed,ref } from 'vue';
import { money,time,providerNames } from './digital-model.js';
import DigitalExcludedCatalog from './DigitalExcludedCatalog.vue';
import { exportCategories } from './category-export.js';
const exporting=ref(false),exportError=ref('');
async function download(pdf){exporting.value=true;exportError.value='';try{await exportCategories(props.connection,pdf);}catch(error){exportError.value=error.message||'تعذر التصدير.';}finally{exporting.value=false;}}
const props=defineProps({connection:{type:Object,required:true}}),emit=defineEmits(['close']);
const query=ref(''),type=ref('');
const types={topup:'تعبئة رصيد',bundle:'باقة',bill:'فاتورة',voucher:'بطاقة'};
const counts=computed(()=>Object.entries(types).map(([key,name])=>({key,name,count:props.connection.offers.filter(row=>row.type===key).length})).filter(row=>row.count));
const hasCost=computed(()=>props.connection.offers.some(row=>row.cost!==undefined));
const rows=computed(()=>props.connection.offers.filter(row=>(!type.value||row.type===type.value)&&(!query.value.trim()||[row.remote_name,row.name,row.remote_id].some(value=>String(value||'').toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())))));
</script>
<template><section class="card digital-category-details" aria-label="تفاصيل فئات الشركة"><div class="toolbar"><h3>فئات {{providerNames[connection.provider]}} · {{connection.account_name}}</h3><button class="btn small" :disabled="exporting" @click="download(false)">تصدير Excel</button><button class="btn small" :disabled="exporting" @click="download(true)">PDF / طباعة</button><button class="btn small" @click="emit('close')">إغلاق التفاصيل</button></div><p class="help">الفئات المرتبطة: {{connection.offers.length}}<template v-if="connection.catalog_updated_at"> · آخر جلب: {{time(connection.catalog_updated_at)}}</template></p><div class="digital-status-summary"><span class="badge">الإجمالي: {{connection.offers.length}} فئة</span><span v-for="item in counts" :key="item.key" class="badge">{{uiLabel(item.name)}}: {{item.count}}</span></div><FilterBar class="toolbar"><label>بحث بالفئة<input v-model="query" type="search" placeholder="اسم الفئة أو معرّف الشركة"></label><label>نوع الفئة<select v-model="type"><option value="">كل الأنواع</option><option v-for="(name,key) in types" :key="key" :value="key">{{name}}</option></select></label></FilterBar><TablePanel class="tablewrap"><table><thead><tr><th>اسم الفئة لدى الشركة</th><th>النوع</th><th>معرّف الشركة</th><th v-if="hasCost">سعر الشركة · د.ع</th><th>سعر البيع · د.ع</th><th>الحالة</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td>{{row.remote_name||row.name}}<small v-if="row.province">{{row.province}}</small></td><td>{{types[row.type]||'غير محدد'}}</td><td><span dir="ltr">{{row.remote_id||'—'}}</span></td><td v-if="hasCost">{{row.cost==null?'—':money(row.cost)}}</td><td>{{money(row.retail)}}</td><td>{{row.active?'مفعّلة للبيع':'معطّلة'}}</td></tr><tr v-if="!rows.length"><td :colspan="hasCost?6:5" class="empty">{{connection.offers.length?'لا توجد فئات مطابقة للبحث':'لا توجد فئات مرتبطة. اجلب فئات الشركة أولًا.'}}</td></tr></tbody></table></TablePanel><p class="caption">عدد الفئات المعروضة: {{rows.length}} · التصدير يشمل جميع الفئات والمستبعدات، وليس نتائج البحث فقط. لحفظ PDF اختر «حفظ بتنسيق PDF» من نافذة الطباعة.</p><p v-if="exportError" class="notice warn">{{exportError}}</p><DigitalExcludedCatalog v-if="connection.provider==='topup'&&Object.hasOwn(connection,'excluded_catalog')" :records="connection.excluded_catalog??null"/></section></template>
