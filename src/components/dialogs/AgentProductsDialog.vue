<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <agent-product-picker :agent="modal.agent" readonly=""></agent-product-picker>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ $root.tr("إغلاق") }}</button
    ><button
      v-if="actor.role==='owner' &amp;&amp; can('agents.edit')"
      class="btn primary"
      @click="openEdit(modal.agent)"
    >
      {{ $root.tr("تعديل الفئات") }}
    </button>
  </div>
</template>
