<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="card-design-preview">
    <meeting-receipt
      :product-id="modal.productId"
      :design="modal.design"
      :agent-override="modal.agent"
    ></meeting-receipt>
  </div>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("إغلاق") }}</button>
  </div>
</template>
