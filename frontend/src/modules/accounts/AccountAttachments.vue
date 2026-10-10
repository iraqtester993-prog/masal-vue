<script setup>
import {computed,onBeforeUnmount,onMounted,ref} from 'vue';
import {useAccountAccess} from './use-account-access.js';import AccountModal from './AccountModal.vue';import FormField from './FormField.vue';
import {canManageAttachments} from './account-model.js';
const props=defineProps({account:Object,disabled:Boolean});const emit=defineEmits(['version','changed','busy','conflict']);const {session,handleFailure}=useAccountAccess();
const rows=ref([]),version=ref(props.account.version),loading=ref(true),busy=ref(false),conflict=ref(false),error=ref(''),preview=ref(null),deletion=ref(null),reason=ref('');const controller=new AbortController();
const documentTypes=[['civil_id','هوية الأحوال المدنية'],['national_card','البطاقة الوطنية'],['residence','بطاقة السكن'],['shop_license','إجازة أو رخصة المحل'],['other','مستمـسكات أخرى']];
const canManage=computed(()=>!conflict.value && canManageAttachments(session.state.identity,session.can,props.account));
const isPos=computed(()=>props.account.type==='pos');
const imageKind=computed(()=>isPos.value?'personal_image':'agent_image');
const imageLabel=computed(()=>isPos.value?'الصورة الشخصية':'الصورة (اختياري)');
function contentUrl(attachment){try{const url=new URL(attachment.content_url,window.location.origin);return url.origin===window.location.origin?url.href:'';}catch{return '';}}
function setVersion(value,changed=false){version.value=value;emit('version',value);if(changed)emit('changed',value);}
async function load(){loading.value=true;try{const result=await session.api.attachments(props.account.id,controller.signal);rows.value=result.data;if(result.account_version!==version.value){conflict.value=true;error.value='تغير الحساب منذ فتحه. أغلق النافذة وحدّث القائمة قبل تعديل الصور.';emit('conflict');}}catch(failure){if(failure.name!=='AbortError')error.value=await handleFailure(failure);}finally{loading.value=false;}}
async function upload(kind,event,documentType){
  const files=[...(event.target.files || [])];event.target.value='';
  if(!files.length || busy.value || props.disabled || !canManage.value)return;
  if(files.some((file)=>file.size>700000 || !['image/png','image/jpeg','image/webp'].includes(file.type))){error.value='اختر صور PNG أو JPEG أو WEBP، حجم كل صورة لا يتجاوز 700 كيلوبايت.';return;}
  busy.value=true;emit('busy',true);error.value='';
  try{for(const file of files){const form=new FormData();form.append('version',String(version.value));form.append('kind',kind);form.append('file',file);if(documentType)form.append('document_type',documentType);const result=await session.api.uploadAttachment(props.account.id,form,controller.signal);setVersion(result.account_version,true);}await load();}
  catch(failure){if(failure.name!=='AbortError'){error.value=await handleFailure(failure);if(failure.status===409){conflict.value=true;emit('conflict');}else await load();}}
  finally{busy.value=false;emit('busy',false);}
}
async function remove(){if(!deletion.value || busy.value || props.disabled || !canManage.value || reason.value.trim().length<3)return;busy.value=true;emit('busy',true);error.value='';try{const result=await session.api.deleteAttachment(props.account.id,deletion.value.id,{version:version.value,reason:reason.value.trim()},controller.signal);setVersion(result.account_version,true);deletion.value=null;reason.value='';await load();}catch(failure){if(failure.name!=='AbortError'){error.value=await handleFailure(failure);if(failure.status===409){conflict.value=true;emit('conflict');}}}finally{busy.value=false;emit('busy',false);}}
onMounted(()=>load());onBeforeUnmount(()=>controller.abort());
</script>
<template>
<div class="account-attachments"><p v-if="error" class="notice notice-error" role="alert">{{error}}</p><p v-if="loading" class="read-loading help" role="status">جارٍ تحميل الصور…</p>
<section class="pos-related-editor personal-photo-editor network-image-field"><div class="cardhead"><h3>{{imageLabel}}</h3><span class="help">اختياري</span></div><label v-if="canManage" class="btn small">إضافة صورة<input type="file" accept="image/png,image/jpeg,image/webp" hidden :disabled="disabled || busy || loading" @change="upload(imageKind,$event)"/></label><div class="document-previews"><span v-for="attachment in rows.filter((row)=>row.kind===imageKind)" :key="attachment.id" class="document-thumb personal-photo-thumb"><button type="button" class="image-preview-button" :aria-label="'عرض '+imageLabel" @click="preview=attachment"><img :src="contentUrl(attachment)" loading="lazy" :alt="imageLabel"/></button><button v-if="canManage" type="button" class="image-remove" :disabled="disabled || busy" aria-label="حذف الصورة" @click="deletion=attachment">×</button></span></div></section>
<section v-if="isPos" class="pos-documents-editor"><div class="cardhead"><h3>مستمـسكات نقطة البيع</h3><span class="help">اختياري ويمكن إضافة أكثر من صورة لكل وثيقة</span></div><div v-for="[type,label] in documentTypes" :key="type" class="document-row"><strong>{{label}}</strong><label v-if="canManage" class="btn small">إضافة صور<input type="file" accept="image/png,image/jpeg,image/webp" multiple hidden :disabled="disabled || busy || loading" @change="upload('document',$event,type)"/></label><div class="document-previews"><span v-for="attachment in rows.filter((row)=>row.kind==='document' && row.document_type===type)" :key="attachment.id" class="document-thumb"><button type="button" class="image-preview-button" :aria-label="'عرض '+label" @click="preview=attachment"><img :src="contentUrl(attachment)" loading="lazy" :alt="label"/></button><button v-if="canManage" type="button" class="image-remove" :disabled="disabled || busy" :aria-label="'حذف صورة '+label" @click="deletion=attachment">×</button></span></div></div></section>
<p v-if="busy" class="help" role="status">جارٍ حفظ الصور…</p><p v-if="canManage" class="muted-note">تُحفظ الصور عند اختيارها بصورة مستقلة عن تعديل بيانات الحساب.</p>
<div v-if="deletion" class="scope-section"><FormField v-model="reason" name="attachment-reason" label="سبب حذف الصورة" type="textarea" required :minlength="3" :maxlength="2000"/><div class="actions"><button type="button" class="btn danger" :disabled="busy || reason.trim().length<3" @click="remove">تأكيد حذف الصورة</button><button type="button" class="btn" :disabled="busy" @click="deletion=null;reason=''">إلغاء</button></div></div>
<AccountModal v-if="preview" :title="preview.label || 'معاينة الصورة'" section="الصور والمستمسكات" @close="preview=null"><img :src="contentUrl(preview)" class="attachment-full-preview" :alt="preview.label || 'الصورة'"/><footer class="formfoot"><button type="button" class="btn primary" @click="preview=null">إغلاق</button></footer></AccountModal>
</div>
</template>

