<script setup>
import {onBeforeUnmount,ref,watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createSupportApi} from './support-api.js';
import {attachmentUrl,errorText} from './communications.js';
const props = defineProps({modelValue:{type:Object,default:null},kind:{type:String,default:'support'},disabled:Boolean});
const emit = defineEmits(['update:modelValue','busy','error']);
const {session} = usePortal(), api = createSupportApi(session.api), busy = ref(false), error = ref(''), preview = ref('');
let controller, revision = 0;
function release() {if (preview.value.startsWith('blob:')) URL.revokeObjectURL(preview.value); preview.value = '';}
watch(() => props.modelValue, value => {if (!value) release(); else if (!preview.value) preview.value = attachmentUrl(value.id);});
async function dimensions(file) {
  if (typeof createImageBitmap === 'function') {
    const bitmap = await createImageBitmap(file); const size = {width:bitmap.width,height:bitmap.height}; bitmap.close(); return size;
  }
  const url = URL.createObjectURL(file);
  try {return await new Promise((resolve,reject) => {const image = new Image(); image.onload = () => resolve({width:image.naturalWidth,height:image.naturalHeight}); image.onerror = () => reject(new Error('تعذر قراءة الصورة.')); image.src = url;});}
  finally {URL.revokeObjectURL(url);}
}
async function choose(event) {
  const file = event.target.files?.[0]; event.target.value = ''; if (!file || busy.value || props.disabled) return;
  error.value = ''; controller?.abort(); controller = new AbortController(); const expected = ++revision;
  busy.value = true; emit('busy',true);
  try {
    if (!['image/png','image/jpeg'].includes(file.type)) throw new Error('اختر صورة PNG أو JPEG.');
    if (file.size > 700000) throw new Error('حجم الصورة يجب ألا يتجاوز 700000 بايت.');
    const size = await dimensions(file);
    if (size.width > 4096 || size.height > 4096 || !size.width || !size.height) throw new Error('أبعاد الصورة يجب ألا تتجاوز 4096 × 4096.');
    if (expected !== revision || controller.signal.aborted) return;
    const response = await api.upload(file,props.kind,controller.signal); if (expected !== revision) return;
    release(); preview.value = URL.createObjectURL(file); emit('update:modelValue',response.data);
  } catch (cause) {if (expected === revision && cause.name !== 'AbortError') {error.value = errorText(cause); emit('error',cause);}}
  finally {if (expected === revision) {busy.value = false; emit('busy',false);}}
}
onBeforeUnmount(() => {revision++; controller?.abort(); release();});
</script>
<template>
  <div class="attachment-field">
    <label class="btn small">{{busy ? 'جارٍ تجهيز الصورة' : 'إضافة صورة (اختياري)'}}<input type="file" accept="image/png,image/jpeg" hidden :disabled="busy || disabled" @change="choose"></label>
    <div v-if="modelValue" class="attachment-preview"><img :src="preview || attachmentUrl(modelValue.id)" alt="معاينة الصورة"><button type="button" class="btn small" :disabled="busy || disabled" @click="emit('update:modelValue',null)">إزالة</button></div>
    <p v-if="error" class="help" role="alert">{{error}}</p>
  </div>
</template>
