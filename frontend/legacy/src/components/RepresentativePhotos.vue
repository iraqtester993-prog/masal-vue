<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["representative-photos"]);
export default options;
</script>

<template>
  <section class="pos-related-editor">
    <div class="cardhead">
      <h3>{{ $root.tr("صور المندوب") }}</h3>
      <span class="help">{{
        $root.tr("اختياري ويمكن إضافة أكثر من صورة")
      }}</span>
    </div>
    <label class="btn small"
      >{{ $root.tr("إضافة صور")
      }}<input
        type="file"
        accept="image/png,image/jpeg,image/webp"
        multiple=""
        hidden=""
        @change="add($event)"
    /></label>
    <div class="document-previews">
      <span v-for="(image, i) in images" :key="i" class="document-thumb"
        ><img :src="image" :alt="$root.tr('صورة المندوب')" /><button
          type="button"
          @click="remove(i)"
          :aria-label="$root.tr('حذف الصورة')"
        >
          ×
        </button></span
      >
    </div>
    <p v-if="error" class="notice warn">{{ $root.tr(error) }}</p>
    <p v-if="busy" class="help">{{ $root.tr("جارٍ تجهيز الصور…") }}</p>
  </section>
</template>
