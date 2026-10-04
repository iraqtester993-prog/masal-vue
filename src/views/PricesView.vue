<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <price-editor :key="currentUser"></price-editor
  ><price-history :key="'history-' + currentUser"></price-history>
</template>
