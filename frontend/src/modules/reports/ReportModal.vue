<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
defineProps({title:{type:String,required:true},wide:Boolean}); const emit=defineEmits(['close']); const dialog=ref(null);
onMounted(()=>dialog.value.showModal()); onBeforeUnmount(()=>dialog.value?.close());
</script>
<template><dialog ref="dialog" class="reports-modal modal" :class="{'reports-wide':wide}" aria-modal="true" :aria-label="title" @cancel.prevent="emit('close')" @click="event=>{if(event.target===dialog)emit('close')}"><div class="modalhead"><h2>{{title}}</h2><button class="iconbtn" aria-label="إغلاق" @click="emit('close')">×</button></div><div class="modalbody"><slot/></div></dialog></template>
