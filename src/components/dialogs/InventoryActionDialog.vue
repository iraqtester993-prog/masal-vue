<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <p>{{ tr(modal.message) }}</p>
  <label
    >{{ tr("سبب الإجراء")
    }}<textarea v-model="reason" :aria-label="tr('سبب الإجراء')"></textarea>
  </label>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("رجوع") }}</button
    ><button
      class="btn primary"
      :disabled="!reason.trim()"
      @click="confirmInventoryAction"
    >
      {{ tr("تأكيد") }}
    </button>
  </div>
</template>
