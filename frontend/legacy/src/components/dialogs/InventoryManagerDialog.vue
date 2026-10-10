<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <inventory-manager
    ref="inventoryManager"
    :batch-id="modal.batchId"
    :key="modal.batchId"
  ></inventory-manager>
</template>
