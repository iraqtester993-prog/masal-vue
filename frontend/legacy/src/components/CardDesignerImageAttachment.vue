<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["card-designer", "image-attachment"]);
export default options;
</script>

<template>
  <div class="attachment-field">
    <label class="btn small"
      >{{ $root.tr(busy ? "جارٍ تجهيز الصورة" : "إضافة صورتي")
      }}<input
        type="file"
        accept="image/png,image/jpeg"
        hidden=""
        :disabled="busy"
        @change="choose"
    /></label>
    <div v-if="modelValue" class="attachment-preview">
      <img :src="modelValue" :alt="$root.tr('معاينة الصورة')" /><button
        type="button"
        class="btn small"
        @click="$emit('update:modelValue', '')"
        :disabled="busy"
      >
        {{ $root.tr("إزالة") }}
      </button>
    </div>
  </div>
</template>
