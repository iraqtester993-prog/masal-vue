<script setup>
import CatalogModal from '../catalog/CatalogModal.vue';
import TablePanel from '../../shared/components/TablePanel.vue';

import {ref,onMounted,onBeforeUnmount} from 'vue';
import {RouterLink} from 'vue-router';
import {usePortal} from '../auth/session.js';
import {useListNavigation} from '../../shared/api/list-navigation.js';
import {useRouteRefresh} from '../../shared/route-refresh.js';
import {money,errorText} from '../finance/finance-model.js';
import FinancePagination from '../finance/FinancePagination.vue';
import './digital.css';
const {session}=usePortal(),controller=new AbortController();
const navigation=useListNavigation(session.api,'topup-categories');
const rows=ref([]),q=ref(navigation.saved?.q||''),loading=ref(false),error=ref(''),meta=ref({current_page:1,last_page:1,total:0});
const pathFor=page=>'/topup/categories?'+new URLSearchParams({page,q:q.value});
let displayPath=pathFor(navigation.saved?.page||1);
function hydrate(path){const cached=session.api.peekRequest?.(path);if(Array.isArray(cached?.data)){rows.value=cached.data;meta.value=cached.meta;return cached;}return null;}
hydrate(displayPath);
const names={topup:'تعبئة رصيد',bundle:'باقة',bill:'تسديد فاتورة'};
const editing=ref(null),image=ref(null),saving=ref(false);
async function saveImage(){if(!image.value||saving.value)return;saving.value=true;error.value='';try{const body=new FormData();body.set('version',String(editing.value.image_version));body.set('image',image.value);await session.api.mutate('/topup/categories/'+editing.value.id+'/image','POST',body,controller.signal);editing.value=null;image.value=null;await load(meta.value.current_page,{fresh:true});}catch(e){error.value=errorText(e);}finally{saving.value=false;}}
let revision=0;
async function load(page=1,{fresh=false}={}){
 const current=++revision,path=pathFor(page),cached=hydrate(path);
 if(path!==displayPath&&!cached){rows.value=[];meta.value={current_page:page,last_page:page,total:0};}
 displayPath=path;loading.value=fresh||!cached;error.value='';
 try{const result=await session.api.request(path,{signal:controller.signal,fresh});if(current===revision){rows.value=result.data;meta.value=result.meta;void navigation.restoreScroll();}}
 catch(e){if(current===revision&&e.name!=='AbortError')error.value=errorText(e);}
 finally{if(current===revision)loading.value=false;}
}
useRouteRefresh(async()=>{await load(meta.value.current_page,{fresh:true});return !error.value;},{canRefresh:()=>!saving.value&&!editing.value});
onMounted(()=>{if(rows.value.length)void navigation.restoreScroll();return load(navigation.saved?.page||1);});onBeforeUnmount(()=>{navigation.remember({q:q.value,page:meta.value.current_page});revision++;controller.abort();});
</script>
<template><section class="digital-services topup-categories" data-no-pagination><section class="card"><div class="toolbar"><h3>Topup — فئات شركة آسياسيل</h3><RouterLink class="btn" to="/digital">ربط التوكن واستعلام الشركة</RouterLink><RouterLink class="btn" to="/topup/allocation">تخصيص الفئات للوكلاء</RouterLink></div></section><p v-if="error" class="notice warn" role="alert">{{error}}</p><section class="card"><form class="toolbar" @submit.prevent="load()"><input v-model="q" placeholder="بحث باسم الفئة أو الباقة" aria-label="بحث الفئات" maxlength="160"><button class="btn" :disabled="loading">بحث</button><button type="button" class="btn" :disabled="loading" @click="load(meta.current_page,{fresh:true})">تحديث</button></form><TablePanel class="tablewrap"><table><thead><tr><th>صورة الفئة</th><th>مالك التوكن</th><th>الفئة / الباقة</th><th>الخدمة</th><th>معرف فئة الشركة</th><th>سعر الشركة · د.ع</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td><img v-if="row.image_url" :src="row.image_url" alt="" style="width:48px;height:48px;object-fit:contain"><button v-if="session.can('topup.categories')&&row.image_version" type="button" class="btn small" @click="editing=row;image=null">تعديل الصورة</button></td><td>{{row.main_account_name}}</td><td>{{row.name}}</td><td>{{names[row.type]}}</td><td dir="ltr">{{row.remote_id}}</td><td>{{money(row.price)}}</td></tr><tr v-if="!rows.length"><td colspan="6" class="empty">{{loading?'جارٍ التحميل…':'لا توجد فئات من الشركة؛ استعلم بالتوكن من إعدادات الربط. الرصيد وحده لا يتيح التعبئة.'}}</td></tr></tbody></table></TablePanel><FinancePagination :meta="meta" :busy="loading" @page="load"/></section><CatalogModal v-if="editing" title="صورة فئة Topup" section="الفئات" :busy="saving" @close="editing=null;image=null"><strong>{{editing.name}}</strong><form @submit.prevent="saveImage"><label>صورة الفئة<input type="file" accept="image/png,image/jpeg" required :disabled="saving" @change="image=$event.target.files?.[0]||null"></label><p v-if="error" class="notice warn" role="alert">{{error}}</p><div class="formfoot"><button class="btn primary" :disabled="saving||!image">حفظ الصورة</button><button type="button" class="btn" :disabled="saving" @click="editing=null;image=null">إلغاء</button></div></form></CatalogModal></section></template>
