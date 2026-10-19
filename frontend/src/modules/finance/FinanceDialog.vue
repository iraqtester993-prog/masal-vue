<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';
import './finance-dialog.css';
const props = defineProps({ open: Boolean, title: String, busy: Boolean, variant: String });
const emit = defineEmits(['close']);
const dialog = ref(null);
watch(() => props.open, async (open) => {
  await nextTick();
  if (open && dialog.value && !dialog.value.open) dialog.value.showModal();
  else if (!open && dialog.value?.open) dialog.value.close();
}, { immediate: true });
function close() { if (!props.busy) emit('close'); }
onBeforeUnmount(() => dialog.value?.close());
</script>
<template>
  <Teleport to="body"><dialog v-if="open" ref="dialog" class="finance-modal funding-preview-dialog" :class="variant" tabindex="-1" :aria-label="title" @cancel.prevent="close">
    <slot name="heading"><div class="support-section-heading"><h2>{{ title }}</h2><button type="button" class="iconbtn" :disabled="busy" :aria-label="`إغلاق ${title}`" @click="close">×</button></div></slot>
    <div class="finance-dialog-body"><slot /></div>
    <footer v-if="$slots.footer" class="finance-dialog-footer"><slot name="footer" /></footer>
  </dialog></Teleport>
</template>
