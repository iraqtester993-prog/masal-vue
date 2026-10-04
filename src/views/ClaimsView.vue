<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <inventory-workspace :key="currentUser"></inventory-workspace
  ><operations-panel
    v-if="page==='claims'&amp;&amp;inventoryWorkspaceTab==='claims'"
    ref="operations"
  ></operations-panel
  ><workflow-panel
    v-if="page==='exports'&amp;&amp;inventoryWorkspaceTab==='withdraw'"
    ref="workflow"
  ></workflow-panel>
</template>
