<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <p style="line-height: 2">{{ tr(modal.message) }}</p>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("رجوع") }}</button
    ><button
      class="btn primary"
      @click="confirmAction"
      :disabled="formPending.confirmAction"
    >
      {{ tr("تأكيد") }}
    </button>
  </div>
</template>
