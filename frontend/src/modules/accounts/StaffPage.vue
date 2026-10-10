<script setup>
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';


import {defineAsyncComponent,onMounted,onBeforeUnmount,ref,watch} from 'vue';import {createPagedResource} from './paged-resource.js';import {useAccountAccess} from './use-account-access.js';import {initialAccountContext} from './account-model.js';import './accounts.css';

const StaffDialog=defineAsyncComponent(()=>import('./StaffDialog.vue'));const ActionDialog=defineAsyncComponent(()=>import('./ActionDialog.vue'));

const AccountModal=defineAsyncComponent(()=>import('./AccountModal.vue'));

const SelfProfileDialog=defineAsyncComponent(()=>import('./SelfProfileDialog.vue'));

const {session,handleFailure,router}=useAccountAccess();const account=ref(session.state.identity.account),query=ref(''),modal=ref(null),success=ref('');const resource=createPagedResource((params,signal)=>session.api.staff(account.value.id,params,signal),handleFailure);const state=resource.state;let timer;const controller=new AbortController();const ready=ref(false);

const size=ref(10),counts=ref({total:0,disabled:0});

let countRead;

async function load(page=1){await resource.load({q:query.value,page,per_page:size.value});if(query.value||state.error)return;countRead?.abort();countRead=new AbortController();const own=countRead;try{const result=await session.api.staff(account.value.id,{status:'disabled',page:1,per_page:1},own.signal);if(countRead===own){counts.value={total:state.meta.total,disabled:result.meta.total};}}catch(failure){if(failure.name!=='AbortError')state.error=await handleFailure(failure);}}



watch(size,()=>load());

async function exportStaff(){try{const {workbook}=await import('../../shared/files/excel-export.js');const url=URL.createObjectURL(workbook(state.rows.map(row=>({'اسم الموظف':row.name,'البريد الإلكتروني':row.email||'','الحالة':row.status}))));const link=document.createElement('a');link.href=url;link.download='users.xlsx';link.click();setTimeout(()=>URL.revokeObjectURL(url),0);}catch(e){state.error=await handleFailure(e);}}

function statusAction(payload,signal){const staff=modal.value.staff;return session.api.setStaffStatus(account.value.id,staff.id,{version:staff.version,status:staff.status==='active'?'disabled':'active',...payload},signal);}

async function saved(){modal.value=null;success.value='تم حفظ بيانات الموظف.';await session.refresh();if(!session.state.identity)return router.replace({name:'login'});await load(state.meta.current_page);}

watch(query,()=>{clearTimeout(timer);timer=setTimeout(()=>load(),250);});watch(account,()=>{modal.value=null;success.value='';if(ready.value)load();});onMounted(async()=>{try{account.value=await initialAccountContext(session.state.identity,session.api,controller.signal);await load();ready.value=true;}catch(failure){if(failure.name!=='AbortError')state.error=await handleFailure(failure);}});onBeforeUnmount(()=>{clearTimeout(timer);controller.abort();countRead?.abort();resource.dispose();});

</script>

<template><div class="account-workspace"><p v-if="success" class="notice notice-success" role="status">{{success}}</p><div class="card workspace-card users-workspace"><div class="users-summary"><div><span>مستخدمو النظام</span><b>{{counts.total}}</b><span class="user-symbol"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-3a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v3"/></svg></span></div><div><span>المستخدمون الموقوفون</span><b>{{counts.disabled}}</b><span class="user-symbol"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-3a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v3"/></svg></span></div></div><FilterBar class="toolbar users-toolbar"><button v-if="session.can('staff.create')" class="btn primary" :disabled="!ready || state.loading" @click="modal={kind:'edit'}">إضافة موظف</button><button class="btn" @click="exportStaff">تصدير المستخدمين</button><input v-model="query" type="search" aria-label="بحث الموظفين" placeholder="بحث باسم الموظف أو البريد الإلكتروني…"/><span class="caption">{{state.meta.total}} سجل</span><select v-model.number="size" aria-label="عدد الموظفين في الصفحة"><option v-for="n in [10,25,50,100]" :value="n">{{n}}</option></select><small>{{state.meta.total ? (state.meta.current_page-1)*size+1 : 0}}–{{Math.min(state.meta.current_page*size,state.meta.total)}} من {{state.meta.total}}</small></FilterBar><p v-if="state.loading" class="read-loading empty" role="status">جارٍ تحميل الموظفين…</p><div v-else-if="state.error" class="notice notice-error" role="alert">{{state.error}}</div><TablePanel :start="(state.meta.current_page-1)*size+1" v-else class="tablewrap"><table><thead><tr><th scope="col">اسم الموظف</th><th scope="col">البريد الإلكتروني</th><th scope="col">نوع الصلاحية</th><th scope="col">الحالة</th><th scope="col">الإجراءات</th></tr></thead><tbody><tr v-for="staff in state.rows" :key="staff.id"><td>{{staff.name}}</td><td dir="ltr">{{staff.email || staff.login || '—'}}</td><td>{{staff.kind==='owner'?'صلاحيات حساب الجهة':staff.permission_profile?.name || staff.permission_profile_name || '—'}}</td><td><span class="badge" :class="{neutral:staff.status!=='active'}">{{staff.status==='active'?'مفعل':'موقوف'}}</span></td><td><div v-if="staff.kind==='employee' && staff.user_id!==session.state.identity?.user.id" class="actions"><button v-if="session.can('staff.update')" class="btn small" @click="modal={kind:'edit',staff}">تعديل</button><button v-if="session.can('staff.toggle')" class="btn small" @click="modal={kind:'status',staff}">{{staff.status==='active'?'إيقاف':'تفعيل'}}</button></div><button v-else-if="staff.user_id===session.state.identity?.user.id && session.state.identity?.membership?.kind==='owner'" class="btn small" @click="modal={kind:'self'}">تعديل الاسم</button><span v-else class="caption">حساب محمي</span><button v-if="staff.kind==='owner' && session.can('account.view')" class="btn small" @click="modal={kind:'details'}">تفاصيل الحساب</button></td></tr><tr v-if="!state.rows.length"><td colspan="5" class="empty">لا توجد نتائج مطابقة</td></tr></tbody></table></TablePanel><div class="table-pagination-footer"><button class="btn small" :disabled="state.meta.current_page<=1 || state.loading" @click="load(state.meta.current_page-1)">السابق</button><span>{{state.meta.current_page}} / {{state.meta.last_page}}</span><button class="btn small" :disabled="state.meta.current_page>=state.meta.last_page || state.loading" @click="load(state.meta.current_page+1)">التالي</button></div></div><AccountModal v-if="modal?.kind==='details'" title="تفاصيل الحساب" section="مستخدمو النظام" @close="modal=null"><dl class="details-grid"><div><dt>الحساب</dt><dd>{{account.name}}</dd></div><div><dt>رقم الهاتف</dt><dd>{{account.phone||'—'}}</dd></div><div><dt>الحالة</dt><dd>{{account.status==='active'?'مفعل':'موقوف'}}</dd></div></dl></AccountModal><SelfProfileDialog v-if="modal?.kind==='self'" @close="modal=null" @saved="saved"/><StaffDialog v-if="modal?.kind==='edit'" :account="account" :staff="modal.staff" @close="modal=null" @saved="saved"/><ActionDialog v-if="modal?.kind==='status'" :title="modal.staff.status==='active'?'إيقاف الموظف':'تفعيل الموظف'" :description="modal.staff.name" :action="statusAction" @close="modal=null" @saved="saved"/></div></template>









