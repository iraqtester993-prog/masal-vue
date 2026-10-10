<script setup>
import { onMounted, onBeforeUnmount, ref, useId } from "vue";
defineProps({ title: String, section: String, busy: Boolean, contentClass: String });
const emit = defineEmits(["close"]);
const dialog = ref(),
  titleId = useId();
onMounted(() => dialog.value.showModal());
onBeforeUnmount(() => dialog.value?.close());
</script>
<template>
  <Teleport to="body"
    ><dialog
      ref="dialog"
      class="catalog-modal modal"
      :class="contentClass"
      :aria-labelledby="titleId"
      @cancel.prevent="!busy && emit('close')"
    >
      <div class="cardhead dialog-heading">
        <div class="dialog-heading-main">
          <span class="dialog-icon" aria-hidden="true"
            ><svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.7"
            >
              <path d="M12 5v14M5 12h14" /></svg
          ></span>
          <div>
            <span class="dialog-section">{{ section }}</span>
            <h2 :id="titleId">{{ title }}</h2>
          </div>
        </div>
        <button
          type="button"
          class="iconbtn dialog-close"
          :disabled="busy"
          aria-label="إغلاق"
          @click="emit('close')"
        >
          ×
        </button>
      </div>
      <slot /></dialog
  ></Teleport>
</template>
