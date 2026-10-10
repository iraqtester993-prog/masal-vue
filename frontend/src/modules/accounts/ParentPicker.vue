<script setup>
import { onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useAccountAccess } from './use-account-access.js';
import { childTypes } from './child-types.js';
const props = defineProps({ modelValue: Object, disabled: Boolean, allAccounts:Boolean, ancestorId:Number, label: {type:String,default:'الوكيل الأعلى'} });
const emit = defineEmits(['update:modelValue']);
const { session, handleFailure } = useAccountAccess();
const query = ref(''), rows = ref([]), loading = ref(false), error = ref(''), page = ref(1), lastPage = ref(1);
const selectId=`account-picker-${useId()}`;
let controller, timer;
async function load(nextPage = 1) {
  controller?.abort(); const own = new AbortController(); controller = own;
  loading.value = true; error.value = '';
  try {
    const result = await session.api.accounts({...(props.allAccounts?{}:{kind:'agents'}),ancestor_id:props.ancestorId,q:query.value,page:nextPage,per_page:25},own.signal);
    if (controller !== own) return;
    rows.value = result.data.filter((account) => (props.allAccounts || childTypes(account).length) && account.id !== props.modelValue?.id);
    page.value = result.meta.current_page; lastPage.value = result.meta.last_page;
  } catch(failure) { if(failure.name !== 'AbortError' && controller===own) error.value = await handleFailure(failure); }
  finally { if(controller === own) loading.value = false; }
}
function choose(event) { const account = rows.value.find((row)=>String(row.id)===event.target.value); if(account) emit('update:modelValue',account); }
watch(query,()=>{clearTimeout(timer);timer=setTimeout(()=>load(),300);});
watch(()=>props.ancestorId,()=>load());
onMounted(()=>load());
onBeforeUnmount(()=>{clearTimeout(timer);controller?.abort();});
</script>
<template><div class="parent-picker"><label :for="selectId">{{ label }}</label><input v-model="query" type="search" :disabled="disabled" :aria-label="'بحث · '+label" :placeholder="allAccounts?'بحث باسم الحساب أو الهاتف…':'بحث باسم الوكيل أو المحافظة…'"/><select :id="selectId" :value="String(modelValue.id)" :disabled="disabled || loading" @change="choose"><option :value="String(modelValue.id)">{{ modelValue.name }}</option><option v-for="row in rows" :key="row.id" :value="String(row.id)">{{ row.name }} · {{ row.city || '—' }}</option></select><p v-if="error" role="alert" class="field-error">{{error}}</p><div v-if="lastPage>1" class="picker-tools"><button type="button" class="btn" :disabled="disabled || loading || page<=1" @click="load(page-1)">السابق</button><small>{{page}} / {{lastPage}}</small><button type="button" class="btn" :disabled="disabled || loading || page>=lastPage" @click="load(page+1)">التالي</button></div></div></template>
