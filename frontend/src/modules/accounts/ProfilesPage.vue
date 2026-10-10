<script setup>

import {defineAsyncComponent,onBeforeUnmount,onMounted,ref,watch} from 'vue';import {useAccountAccess} from './use-account-access.js';import {createPagedResource} from './paged-resource.js';import {initialAccountContext} from './account-model.js';import './accounts.css';

const PermissionEditor=defineAsyncComponent(()=>import('./PermissionEditor.vue'));const ActionDialog=defineAsyncComponent(()=>import('./ActionDialog.vue'));

const {session,handleFailure,router}=useAccountAccess();const account=ref(session.state.identity.account),editor=ref(null),action=ref(null),success=ref('');const resource=createPagedResource((params,signal)=>session.api.profiles(account.value.id,params,signal),handleFailure);const state=resource.state;const controller=new AbortController();const ready=ref(false);

function load(page=1){return resource.load({page,per_page:25});}

function confirmAction(payload,signal){const profile=action.value.profile;const body={version:profile.version,...payload};return action.value.kind==='delete'?session.api.deleteProfile(account.value.id,profile.id,body,signal):session.api.setProfileStatus(account.value.id,profile.id,{...body,status:profile.status==='active'?'disabled':'active'},signal);}

async function saved(){editor.value=null;action.value=null;success.value='تم حفظ الصلاحيات.';await session.refresh();if(!session.state.identity)return router.replace({name:'login'});await load(state.meta.current_page);}

watch(account,()=>{editor.value=null;action.value=null;success.value='';if(ready.value)load();});onMounted(async()=>{try{account.value=await initialAccountContext(session.state.identity,session.api,controller.signal);await load();ready.value=true;}catch(failure){if(failure.name!=='AbortError')state.error=await handleFailure(failure);}});onBeforeUnmount(()=>{controller.abort();resource.dispose();});

</script>

<template><div class="account-workspace"><p v-if="success" class="notice notice-success" role="status">{{success}}</p><PermissionEditor v-if="editor" inline :account="account" :profile="editor.profile" :preview="editor.preview" @close="editor=null" @saved="saved"/><div v-else class="card permission-directory"><div class="permission-directory-head"><b>اسم الصلاحية</b><div class="actions"><button v-if="session.can('permission_profile.create')" class="btn primary" :disabled="!ready || state.loading" @click="editor={}">＋ إضافة صلاحية</button></div></div><p v-if="state.loading" class="read-loading empty" role="status">جارٍ تحميل الصلاحيات…</p><div v-else-if="state.error" class="notice notice-error" role="alert">{{state.error}}</div><template v-else><article v-for="profile in state.rows" :key="profile.id" class="permission-role-row"><div class="permission-role-identity"><span class="permission-role-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6zM8 12l3 3 5-6"/></svg></span><div><button class="permission-role-name" @click="editor={profile,preview:true}">{{profile.name}}</button><span class="badge" :class="{neutral:profile.status!=='active'}">{{profile.status==='active'?'مفعلة':'معطلة'}}</span><small class="caption"> · {{profile.members_count}} موظف</small></div></div><div class="actions permission-role-actions"><button class="btn small" @click="editor={profile,preview:true}">معاينة</button><button v-if="session.can('permission_profile.update')" class="btn small" @click="editor={profile,preview:false}">تعديل</button><button v-if="session.can('permission_profile.toggle')" class="btn small" @click="action={kind:'status',profile}">{{profile.status==='active'?'تعطيل':'تفعيل'}}</button><button v-if="session.can('permission_profile.delete')" class="btn small danger" :disabled="profile.members_count>0" @click="action={kind:'delete',profile}">حذف</button></div></article><p v-if="!state.rows.length" class="empty">لا توجد صلاحيات مضافة</p></template><div v-if="state.meta.last_page>1" class="table-pagination-footer"><button class="btn small" :disabled="state.meta.current_page<=1 || state.loading" @click="load(state.meta.current_page-1)">السابق</button><span>{{state.meta.current_page}} / {{state.meta.last_page}}</span><button class="btn small" :disabled="state.meta.current_page>=state.meta.last_page || state.loading" @click="load(state.meta.current_page+1)">التالي</button></div></div><ActionDialog v-if="action" :title="action.kind==='delete'?'حذف نوع الصلاحية':action.profile.status==='active'?'تعطيل نوع الصلاحية':'تفعيل نوع الصلاحية'" :description="action.profile.name" :action="confirmAction" @close="action=null" @saved="saved"/></div></template>





