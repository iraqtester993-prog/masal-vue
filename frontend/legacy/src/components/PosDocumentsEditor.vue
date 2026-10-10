<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["pos-documents-editor"]);
export default options;
</script>

<template>
  <section class="pos-documents-editor">
    <div class="cardhead">
      <h3>{{ $root.tr("مستمـسكات نقطة البيع") }}</h3>
      <span class="help">{{
        $root.tr("اختياري ويمكن إضافة أكثر من صورة لكل وثيقة")
      }}</span>
    </div>
    <div
      v-for="type in [
        'هوية الأحوال المدنية',
        'البطاقة الوطنية',
        'بطاقة السكن',
        'إجازة أو رخصة المحل',
        'مستمـسكات أخرى',
      ]"
      :key="type"
      class="document-row"
    >
      <strong>{{ $root.tr(type) }}</strong
      ><label class="btn small"
        >{{ $root.tr("إضافة صور")
        }}<input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          multiple=""
          hidden=""
          @change="add(type, $event)"
      /></label>
      <div class="document-previews">
        <span
          v-for="(image,i) in (rows.find(x=&gt;x.type===type)?.images||[])"
          :key="i"
          class="document-thumb"
          ><img
            :src="image"
            :alt="$root.tr(type)"
            role="button"
            tabindex="0"
            @click="vm.previewDocumentImage(image, type)"
            @keydown.enter="vm.previewDocumentImage(image, type)"
          /><button type="button" @click="remove(type, i)">×</button></span
        >
      </div>
    </div>
    <p v-if="error" class="notice warn">{{ $root.tr(error) }}</p>
    <p v-if="busy" class="help">{{ $root.tr("جارٍ تجهيز الصور…") }}</p>
  </section>
</template>
