<script setup>
import {computed,onBeforeUnmount,onMounted,ref} from 'vue';
import {usePortal} from '../modules/auth/session.js';
import {usePreferencesTranslator} from '../modules/preferences/preferences-state.js';
import CatalogModal from '../modules/catalog/CatalogModal.vue';
import './pos-about.css';
const props=defineProps({kind:{type:String,required:true,validator:value=>['terms','privacy'].includes(value)}});
defineEmits(['close']);
const {session}=usePortal(),t=usePreferencesTranslator(),config=ref(null),error=ref('');
const title=computed(()=>props.kind==='privacy'?'سياسة الخصوصية':'الشروط والأحكام');
const paragraphs=computed(()=>String(config.value?.[props.kind]||'').trim().split(/\n\s*\n/).filter(Boolean));
const reader=new AbortController();let alive=true;
onMounted(async()=>{try{const response=await session.api.request('/company/app-config',{signal:reader.signal});if(alive)config.value=response.data.config;}catch(e){if(alive&&e.name!=='AbortError')error.value=e.message;}});
onBeforeUnmount(()=>{alive=false;reader.abort();});
</script>
<template>
 <CatalogModal :title="t(title)" section="دنانير" content-class="pos-info-dialog pos-legal-dialog" @close="$emit('close')">
  <div class="pos-info-body">
   <header class="pos-legal-intro"><span class="pos-legal-symbol" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><template v-if="kind==='privacy'"><path d="m16 3 11 4v8c0 7-11 14-11 14S5 22 5 15V7Z"/><rect x="11" y="13" width="10" height="8" rx="2"/><path d="M13 13v-2a3 3 0 0 1 6 0v2"/></template><template v-else><path d="M8 3h12l5 5v21H8Z M20 3v6h5M12 14h9M12 19h9M12 24h6"/></template></svg></span><div><p>{{config?.name||'دنانير'}}</p></div></header>
   <p v-if="error" class="notice warn" role="alert">{{error}}</p>
   <article v-else-if="paragraphs.length" class="pos-legal-document" :aria-label="t(title)"><p v-for="(paragraph,index) in paragraphs" :key="index" dir="auto">{{paragraph}}</p></article>
   <p v-else class="pos-info-empty" role="status">{{t(config?'لم تنشر الإدارة هذه الوثيقة بعد.':'جارٍ التحميل…')}}</p>
  </div>
 </CatalogModal>
</template>
