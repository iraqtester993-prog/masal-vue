<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="pos-detail-layout">
    <dl class="account-fields">
      <div v-for="(value, key) in modal.data" :key="key">
        <dt>{{ tr(key) }}</dt>
        <dd>{{ tr(value) }}</dd>
      </div>
    </dl>
    <h3>{{ $root.tr("الصور والمستمسكات") }}</h3>
    <pos-documents-viewer embedded=""></pos-documents-viewer>
    <div class="formfoot">
      <button class="btn" @click="closeModal">{{ $root.tr("إغلاق") }}</button
      ><button
        v-if="can('pos.edit')"
        class="btn primary"
        @click="openEdit(modal.pos)"
      >
        {{ $root.tr("تعديل نقطة البيع") }}
      </button>
    </div>
  </div>
</template>
