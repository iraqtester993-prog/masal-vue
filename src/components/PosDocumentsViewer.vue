<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["pos-documents-viewer"]);
export default options;
</script>

<template>
  <div class="pos-documents-viewer">
    <section v-if="pos.personalImage" class="viewer-personal">
      <h3>{{ $root.tr("الصورة الشخصية") }}</h3>
      <img
        :src="pos.personalImage"
        role="button"
        tabindex="0"
        @click="vm.previewDocumentImage(pos.personalImage, 'الصورة الشخصية')"
        @keydown.enter="
          vm.previewDocumentImage(pos.personalImage, 'الصورة الشخصية')
        "
        :alt="$root.tr('الصورة الشخصية')"
      />
    </section>
    <section v-for="doc in documents" :key="doc.type" class="viewer-document">
      <h3>{{ $root.tr(doc.type) }}</h3>
      <div class="viewer-grid">
        <img
          v-for="(image, i) in doc.images"
          :key="i"
          :src="image"
          :alt="$root.tr(doc.type)"
          role="button"
          tabindex="0"
          @click="vm.previewDocumentImage(image, doc.type)"
          @keydown.enter="vm.previewDocumentImage(image, doc.type)"
        />
      </div>
    </section>
    <div v-if="!pos.personalImage&amp;&amp;!documents.length" class="empty">
      {{ $root.tr("لا توجد صور مرفوعة لهذه النقطة") }}
    </div>
    <div v-if="!embedded" class="formfoot">
      <button class="btn" @click="vm.closeModal">
        {{ $root.tr("إغلاق") }}
      </button>
    </div>
  </div>
</template>
