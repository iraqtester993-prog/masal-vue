<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';

import {ref,onMounted,onBeforeUnmount} from 'vue';
import {usePortal} from '../auth/session.js';
import {money,errorText} from '../finance/finance-model.js';
import FinancePagination from '../finance/FinancePagination.vue';
import './digital.css';
const {session}=usePortal(),controller=new AbortController();
const rows=ref([]),q=ref(''),loading=ref(false),error=ref(''),meta=ref({current_page:1,last_page:1,total:0});
const names={topup:'تعبئة رصيد',bundle:'باقة',bill:'تسديد فاتورة'};
let revision=0;
async function load(page=1){const current=++revision;loading.value=true;error.value='';try{const result=await session.api.request('/topup/categories?'+new URLSearchParams({page,q:q.value}),{signal:controller.signal});if(current===revision){rows.value=result.data;meta.value=result.meta;}}catch(e){if(current===revision&&e.name!=='AbortError')error.value=errorText(e);}finally{if(current===revision)loading.value=false;}}
onMounted(()=>load());onBeforeUnmount(()=>{revision++;controller.abort();});
</script>
<template><section class="digital-services topup-categories" data-no-pagination><section class="card"><div class="toolbar"><h3>Topup — فئات شركة آسياسيل</h3><a class="btn" href="/digital">ربط التوكن واستعلام الشركة</a><a class="btn" href="/topup/allocation">تخصيص الفئات للوكلاء</a></div><p class="help">الفئات مجلوبة من الشركة بالتوكن الخاص بكل وكيل رئيسي. التعبئة تستخدم معرف فئة الشركة، وتُصرف من رصيد الوكيل المشترك.</p></section><p v-if="error" class="notice warn" role="alert">{{error}}</p><section class="card"><form class="toolbar" @submit.prevent="load()"><input v-model="q" placeholder="بحث باسم الفئة أو الباقة" aria-label="بحث الفئات" maxlength="160"><button class="btn" :disabled="loading">بحث</button><button type="button" class="btn" :disabled="loading" @click="load(meta.current_page)">تحديث</button></form><TablePanel class="tablewrap"><table><thead><tr><th>الوكيل الرئيسي</th><th>الفئة / الباقة</th><th>الخدمة</th><th>معرف فئة الشركة</th><th>سعر الشركة · د.ع</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td>{{row.main_account_name}}</td><td>{{row.name}}</td><td>{{names[row.type]}}</td><td dir="ltr">{{row.remote_id}}</td><td>{{money(row.price)}}</td></tr><tr v-if="!rows.length"><td colspan="5" class="empty">{{loading?'جارٍ التحميل…':'لا توجد فئات من الشركة؛ استعلم بالتوكن من إعدادات الربط. الرصيد وحده لا يتيح التعبئة.'}}</td></tr></tbody></table></TablePanel><FinancePagination :meta="meta" :busy="loading" @page="load"/></section></section></template>
