<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["document-image-preview"]);
export default options;
</script>

<template>
  <teleport to="body"
    ><div
      v-if="vm.documentPreview"
      class="document-lightbox"
      role="dialog"
      aria-modal="true"
      :aria-label="$root.tr(vm.documentPreview.title)"
      @click.self="vm.closeDocumentPreview"
    >
      <button
        type="button"
        @click="vm.closeDocumentPreview"
        :aria-label="$root.tr('إغلاق الصورة')"
      >
        ×</button
      ><strong>{{ $root.tr(vm.documentPreview.title) }}</strong
      ><img
        :src="vm.documentPreview.image"
        :alt="$root.tr(vm.documentPreview.title)"
      /></div
  ></teleport>
</template>
