<script setup>
import {ref} from 'vue';
import CatalogModal from '../catalog/CatalogModal.vue';
const props=defineProps({vm:Object,sale:Object}),emit=defineEmits(['close','changed']);
const reason=ref(props.sale.failure_reason || ''),key=props.vm.key();
async function submit(){const result=await props.vm.execute({version:props.sale.version,reason:reason.value.trim()},key,(payload,signal)=>props.vm.api.action(props.sale.id,'reprint-request',payload,signal),'أرسل الطلب؛ بانتظار موافقة الأعلى دون خصم جديد.');if(result){emit('changed',result.sale);emit('close');}}
</script>
<template><CatalogModal title="إعادة طباعة البطاقة نفسها" section="معالجة الطباعة" content-class="sales-modal" :busy="vm.state.busy" @close="$emit('close')"><form @submit.prevent="submit"><div class="notice">يرسل الطلب إلى المسؤول الأعلى للحساب: {{vm.state.options.parent?.name || 'الجهة الأعلى'}}، دون خصم جديد.</div><label>سبب طلب إعادة الطباعة<textarea v-model="reason" required maxlength="1000"></textarea></label><p v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</p><div class="formfoot"><button type="button" class="btn" :disabled="vm.state.busy" @click="$emit('close')">إلغاء</button><button class="btn primary" :disabled="vm.state.busy || !reason.trim()">إرسال طلب المعالجة</button></div></form></CatalogModal></template>
