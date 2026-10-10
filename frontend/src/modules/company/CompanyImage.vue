<script setup>
import { onBeforeUnmount,ref,watch } from 'vue';
import {usePortal} from '../auth/session.js';
import {createCompanyApi} from './company-api.js';
import {imageUrl} from './company-model.js';
const props = defineProps({modelValue:{type:Object,default:null},disabled:Boolean});
const emit = defineEmits(['update:modelValue','busy','error']);
const {session} = usePortal(), api = createCompanyApi(session.api), busy = ref(false), error = ref(''), preview = ref('');
let reader, revision = 0;
function release() { if (preview.value.startsWith('blob:')) URL.revokeObjectURL(preview.value); preview.value = ''; }
watch(()=>props.modelValue,value=>{ if (!value) release(); });
async function choose(event) {
  const file = event.target.files?.[0]; event.target.value = ''; if (!file || busy.value || props.disabled) return;
  reader?.abort(); reader = new AbortController(); const expected = ++revision, actor = session.state.identity;
  busy.value = true; error.value = ''; emit('busy',true);
  try {
    if (!['image/png','image/jpeg'].includes(file.type) || file.size > 700000) throw new Error('اختر PNG أو JPG بحجم أقل من 700 كيلوبايت');
    const response = await api.upload(file,reader.signal);
    if (expected !== revision || reader.signal.aborted || session.state.identity !== actor) return;
    release(); preview.value = URL.createObjectURL(file); emit('update:modelValue',response.data);
  } catch (cause) { if (expected === revision && cause.name !== 'AbortError') { error.value = cause.message; emit('error',cause); } }
  finally { if (expected === revision) { busy.value = false; emit('busy',false); } }
}
onBeforeUnmount(()=>{revision++;reader?.abort();release();if(busy.value)emit('busy',false);});
</script>
<template>
  <div class="image-field">
    <label class="btn small">{{busy?'جارٍ تجهيز الصورة':'إضافة صورة (اختياري)'}}<input type="file" accept="image/png,image/jpeg" hidden :disabled="busy || disabled" @change="choose"></label>
    <div v-if="modelValue" class="attachment-preview"><img :src="preview || imageUrl(modelValue)" alt="معاينة الصورة"><button class="btn small" type="button" :disabled="busy || disabled" @click="emit('update:modelValue',null)">إزالة</button></div>
    <p v-if="error" class="notice warn" role="alert">{{error}}</p>
  </div>
</template>