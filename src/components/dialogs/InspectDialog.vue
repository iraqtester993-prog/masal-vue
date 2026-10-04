<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="audit-detail-view">
    <audit-fields
      :value="modal.data"
      :label-for="auditLabel"
      :display-value="auditValue"
      :tr="tr"
    ></audit-fields>
  </div>
</template>
