<script setup>
import {onBeforeUnmount,ref} from 'vue';
import AccountModal from './AccountModal.vue';import FormField from './FormField.vue';import {useAccountAccess} from './use-account-access.js';
const props=defineProps({title:String,description:String,action:Function,login:String});const emit=defineEmits(['close','saved']);const {handleFailure}=useAccountAccess();const reason=ref(''),identifier=ref(props.login || ''),busy=ref(false),error=ref(''),errors=ref({});const controller=new AbortController();
async function submit(){if(busy.value)return;busy.value=true;error.value='';errors.value={};try{await props.action({reason:reason.value.trim(),...(props.login!==undefined?{login:identifier.value.trim()}: {})},controller.signal);emit('saved');}catch(failure){if(failure.name!=='AbortError'){errors.value=failure.errors || {};error.value=await handleFailure(failure);}}finally{busy.value=false;}}
onBeforeUnmount(()=>controller.abort());
</script>
<template><AccountModal :title="title" :busy="busy" @close="$emit('close')"><p class="help">{{description}}</p><p v-if="error" class="notice notice-error" role="alert">{{error}}</p><form @submit.prevent="submit"><fieldset class="formgrid" :disabled="busy"><FormField v-if="login!==undefined" v-model="identifier" name="login" label="بريد أو اسم مستخدم تسجيل الدخول" required :maxlength="190" full :error="errors.login"/><FormField v-model="reason" name="reason" label="سبب التعديل" type="textarea" required :maxlength="500" full :error="errors.reason"/></fieldset><footer class="formfoot"><button type="button" class="btn" :disabled="busy" @click="$emit('close')">إلغاء</button><button class="btn primary" :disabled="busy">{{busy?'جارٍ الحفظ…':'تأكيد'}}</button></footer></form></AccountModal></template>

