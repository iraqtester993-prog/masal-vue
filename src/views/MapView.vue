<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <user-map v-if="can('map.view')" :vm="$root" :key="currentUser"></user-map>
  <div v-else="" class="card empty">
    {{ $root.tr("ليس لديك صلاحية عرض المواقع") }}
  </div>
</template>
