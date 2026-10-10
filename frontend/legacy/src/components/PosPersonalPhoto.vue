<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["pos-personal-photo"]);
export default options;
</script>

<template>
  <section class="pos-related-editor personal-photo-editor">
    <div class="cardhead">
      <h3>{{ $root.tr("الصورة الشخصية") }}</h3>
      <span class="help">{{ $root.tr("اختياري") }}</span>
    </div>
    <label class="btn small"
      >{{ $root.tr("إضافة صورة")
      }}<input
        type="file"
        accept="image/png,image/jpeg,image/webp"
        hidden=""
        @change="add($event)"
    /></label>
    <div v-if="image" class="document-thumb personal-photo-thumb">
      <img
        :src="image"
        role="button"
        tabindex="0"
        @click="vm.previewDocumentImage(image, 'الصورة الشخصية')"
        @keydown.enter="vm.previewDocumentImage(image, 'الصورة الشخصية')"
        :alt="$root.tr('الصورة الشخصية')"
      /><button
        type="button"
        @click="remove"
        :aria-label="$root.tr('حذف الصورة')"
      >
        ×
      </button>
    </div>
    <p v-if="error" class="notice warn">{{ $root.tr(error) }}</p>
    <p v-if="busy" class="help">{{ $root.tr("جارٍ تجهيز الصورة…") }}</p>
  </section>
</template>
