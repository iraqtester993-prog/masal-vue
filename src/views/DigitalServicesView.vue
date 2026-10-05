<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>
<template>
  <digital-service-panel
    :key="'digital-' + currentUser"
  ></digital-service-panel>
</template>
