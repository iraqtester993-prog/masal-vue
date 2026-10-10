<script setup>
import { onMounted, onBeforeUnmount, ref, useId } from 'vue';
defineProps({ title: String, section: { type: String, default: 'الحسابات والشبكة' }, busy: Boolean });
const emit = defineEmits(['close']);
const dialog = ref();
const titleId = useId();
onMounted(() => dialog.value.showModal());
onBeforeUnmount(() => dialog.value?.close());
</script>
<template>
  <dialog ref="dialog" class="account-modal modal" :aria-labelledby="titleId" @cancel.prevent="!busy && emit('close')">
    <div class="cardhead dialog-heading"><div class="dialog-heading-main"><span class="dialog-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 3h6v5H9ZM3 16h6v5H3Zm12 0h6v5h-6ZM12 8v4M6 16v-4h12v4"/></svg></span><div><span class="dialog-section">{{ section }}</span><h2 :id="titleId">{{ title }}</h2></div></div><button type="button" class="iconbtn dialog-close" :disabled="busy" aria-label="إغلاق" @click="emit('close')">×</button></div>
    <slot/>
  </dialog>
</template>
