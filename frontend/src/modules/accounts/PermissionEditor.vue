<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();

import {computed,onBeforeUnmount,onMounted,ref} from 'vue';

import {useAccountAccess} from './use-account-access.js';

import {changePermission,grantablePermissions,permissionDependencies,permissionGroups,selectedPermissions} from './account-model.js';

import {navIconPath} from '../../layouts/navigation.js';

import AccountModal from './AccountModal.vue';

import FormField from './FormField.vue';

const props=defineProps({account:Object,profile:Object,preview:Boolean,inline:Boolean});

const emit=defineEmits(['close','saved']);

const {session,handleFailure}=useAccountAccess();

const catalog=ref([]),authority=ref([]),authorityLoaded=ref(false);

const permissions=ref([...(props.profile?.permissions || props.account?.permissions || [])]);

const name=ref(props.profile?.name || ''),search=ref(''),reason=ref(''),module=ref(null);

const busy=ref(false),loading=ref(true),error=ref(''),errors=ref({});

const controller=new AbortController();

const groupOrder=['dashboard','reports','sell','sales','inventory','import','products','providers','prices','exceptions','claims','exports','account','pos','wallets','map','support','notifications','staff','representatives','posTypes','permission_profile','security','branding','company','governorates','digital','data','sources','integrations','backup'];

const groups=computed(()=>{const collected=new Map();for(const g of permissionGroups(catalog.value,search.value)){let id=g.items[0].group;if(['ledger','invoices'].includes(id))id='wallets';if(id==='agents')id='account';if(!collected.has(id))collected.set(id,{...g,id,items:[]});collected.get(id).items.push(...g.items);}return [...collected.values()].sort((a,b)=>(groupOrder.indexOf(a.id)<0?999:groupOrder.indexOf(a.id))-(groupOrder.indexOf(b.id)<0?999:groupOrder.indexOf(b.id)));});

const titles={reports:'مركز التقارير',sales:'سجل العمليات',import:'الطلبيات',claims:'البطاقات التالفة',exports:'تصدير البطاقات',account:'الوكلاء والشجرة',pos:'نقاط البيع والأجهزة',wallets:'المحافظ والتحويلات',map:'خريطة الانتشار',notifications:'الإشعارات والتنبيهات',staff:'مستخدمو النظام',permission_profile:'الصلاحيات والنطاق',security:'الحماية والأمان',digital:'الخدمات الإلكترونية',data:'البيانات الحساسة',backup:'النسخ الاحتياطي'};

const groupIcon=g=>navIconPath(({account:'agents',staff:'users',permission_profile:'permissions'})[g.id]||g.id);

const editable=computed(()=>authorityLoaded.value && !props.preview && (props.profile?.id?session.can('permission_profile.update'):props.inline?session.can('permission_profile.create'):session.can('account.permissions')));

const available=computed(()=>grantablePermissions(catalog.value,authority.value,session.can));

const availableKeys=computed(()=>available.value.map((item)=>item.key));

const allSelected=computed(()=>available.value.length>0 && available.value.every((item)=>permissions.value.includes(item.key)));

const mixed=computed(()=>!allSelected.value && available.value.some((item)=>permissions.value.includes(item.key)));

function canChange(key){return availableKeys.value.includes(key) && permissionDependencies(key,props.inline).every((dependency)=>permissions.value.includes(dependency) || availableKeys.value.includes(dependency));}

function toggle(key,checked){if(!editable.value)return;permissions.value=changePermission(permissions.value,key,checked,availableKeys.value,props.inline);}

function selectAll(checked){for(const item of available.value)toggle(item.key,checked);}

async function save(){

  if(busy.value || !editable.value)return;

  if((props.profile?.id || !props.inline) && !reason.value.trim()){
    errors.value={reason:'أدخل سبب تعديل الصلاحيات قبل الحفظ.'};
    return;
  }

  busy.value=true;error.value='';errors.value={};

  try{

    const payload={permissions:selectedPermissions(catalog.value,permissions.value),...(props.inline?{name:name.value.trim()}:{}),...((props.profile?.id || !props.inline)?{version:props.profile?.version ?? props.account.version,reason:reason.value.trim()}: {})};

    if(!props.inline)await session.api.setAccountPermissions(props.account.id,payload,controller.signal);

    else if(props.profile?.id)await session.api.updateProfile(props.account.id,props.profile.id,payload,controller.signal);

    else await session.api.createProfile(props.account.id,payload,controller.signal);

    emit('saved');

  }catch(failure){if(failure.name!=='AbortError'){errors.value=failure.errors || {};error.value=await handleFailure(failure);}}

  finally{busy.value=false;}

}

onMounted(async()=>{

  try{

    const [response,account]=await Promise.all([session.api.permissionCatalog(controller.signal),props.inline?session.api.account(props.account.id,controller.signal):Promise.resolve({data:props.account})]);

    catalog.value=response.data;

    authority.value=props.inline?account.data.profile_grantable_permissions:account.data.grantable_permissions;

    if(!Array.isArray(authority.value))throw new Error('تعذر قراءة حدود التفويض لهذا الحساب. أعد فتح الصفحة.');

    authorityLoaded.value=true;

  }catch(failure){if(failure.name!=='AbortError')error.value=await handleFailure(failure);}

  finally{loading.value=false;}

});

onBeforeUnmount(()=>controller.abort());

</script>

<template>

<component :is="inline?'div':AccountModal" :title="'صلاحيات التابع · '+account.name" :busy="busy" class="account-workspace" @close="emit('close')">

  <p v-if="error" class="notice notice-error" role="alert">{{error}}</p>

  <form class="card permission-editor-toolbar" @submit.prevent="save">

    <button v-if="inline" type="button" class="btn" :disabled="busy" @click="emit('close')">رجوع</button>

    <label v-if="inline" for="profile-name">اسم الصلاحية<input id="profile-name" v-model="name" :disabled="!editable || busy" placeholder="مثال: محاسب أو موظف دعم" maxlength="80" required/></label>

    <label for="permission-search">البحث في الصلاحيات<input id="permission-search" v-model="search" type="search" placeholder="ابحث عن قسم أو إجراء…"/></label>

    <FormField v-if="editable && (profile?.id || !inline)" v-model="reason" name="permission-reason" label="سبب التعديل" required :disabled="busy" :maxlength="500" :error="errors.reason"/>

    <span class="badge">{{permissions.length}} صلاحية مفعلة</span>

    <button v-if="editable" type="submit" class="btn primary" :disabled="busy || loading || (inline && (!permissions.length || !name.trim()))">{{busy?'جارٍ الحفظ…':'حفظ'}}</button>

  </form>

  <p v-if="loading" class="read-loading empty" role="status">جارٍ تحميل الصلاحيات…</p>

  <template v-else>

    <div class="card permission-selection-bar">

      <label class="permission-select-all"><input type="checkbox" :checked="allSelected" :indeterminate.prop="mixed" :disabled="!editable || busy" @change="selectAll($event.target.checked)"/><span>تحديد كل الصلاحيات<small>يشمل جميع المجموعات حتى مع وجود بحث أو فلتر</small></span></label>

      <div class="actions"><span class="badge">{{permissions.length}} / {{catalog.length}}</span><button class="btn small" :disabled="!editable || busy" @click="selectAll(false)">إلغاء تحديد الكل</button><button class="btn small" :disabled="!editable || busy" @click="selectAll(false)">مسح الاختيارات</button></div>

    </div>

    <nav class="permission-section-cards" aria-label="أقسام الصلاحيات"><button v-for="group in groups" :key="group.title" class="permission-section-card" aria-haspopup="dialog" @click="module=group"><span class="permission-section-symbol"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path :d="groupIcon(group)"/></svg></span><strong>{{uiLabel(titles[group.id]||group.title)}}</strong><span class="badge">{{group.items.filter((item)=>permissions.includes(item.key)).length}} / {{group.items.length}}</span><span aria-hidden="true">‹</span></button></nav>

    <p v-if="!groups.length" class="empty">لا توجد نتائج مطابقة</p>

    <p v-if="errors.permissions" class="field-error" role="alert">تحقق من صلاحيات العرض وحدود التفويض؛ نوع صلاحية الموظف يحتاج إلى عرض الحسابات.</p>

  </template>

  <AccountModal v-if="module" :title="module.title" section="الصلاحيات" @close="module=null">

    <div class="permission-check-items"><label v-for="item in module.items" :key="item.key" class="permission-check-item" :class="{'is-selected':permissions.includes(item.key)}"><span>{{uiLabel(item.label)}}<small v-if="!canChange(item.key)">مقيدة من الأعلى أو من نوع الحساب</small></span><input type="checkbox" :aria-label="item.label" :checked="permissions.includes(item.key)" :disabled="!editable || busy || !canChange(item.key)" @change="toggle(item.key,$event.target.checked)"/></label></div>

    <footer class="formfoot"><button class="btn primary" @click="module=null">رجوع للأقسام</button></footer>

  </AccountModal>

</component>

</template>

