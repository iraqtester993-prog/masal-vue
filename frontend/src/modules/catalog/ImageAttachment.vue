<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import { inject, ref, onBeforeUnmount } from "vue";
import { catalogKey, tr } from "./catalog-model.js";
const vm = inject(catalogKey);
defineProps({ modelValue: String });
const emit = defineEmits(["update:modelValue", "busy"]);
const busy = ref(false);
let objectUrl = "";
async function choose(event) {
  const file = event.target.files[0];
  event.target.value = "";
  if (!file) return;
  busy.value = true;
  vm.formError = "";
  emit("busy", true);
  try {
    if (!["image/png", "image/jpeg"].includes(file.type))
      throw Error("اختر صورة PNG أو JPEG.");
    if (file.size > 700000) throw Error("حجم الصورة يتجاوز 700000 بايت.");
    const picture = await createImageBitmap(file);
    const invalid = picture.width > 4096 || picture.height > 4096;
    picture.close();
    if (invalid) throw Error("أبعاد الصورة تتجاوز 4096 بكسل.");
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = URL.createObjectURL(file);
    vm.imageFile = file;
    emit("update:modelValue", objectUrl);
  } catch (error) {
    vm.formError =
      error.name === "Error"
        ? error.message
        : "تعذر قراءة الصورة. اختر صورة PNG أو JPEG سليمة.";
  } finally {
    busy.value = false;
    emit("busy", false);
  }
}
onBeforeUnmount(() => {
  if (objectUrl) URL.revokeObjectURL(objectUrl);
});
</script>
<template>
  <div class="attachment-field">
    <label class="btn small"
      >{{ tr(busy ? "جارٍ تجهيز الصورة" : "إضافة صورة (اختياري)")
      }}<input
        type="file"
        accept="image/png,image/jpeg"
        hidden=""
        :disabled="busy || !vm.can(vm.page + '.images')"
        @change="choose"
    /></label>
    <div v-if="modelValue" class="attachment-preview">
      <img :src="modelValue" :alt="tr('معاينة الصورة')" /><button
        type="button"
        class="btn small"
        @click="$emit('update:modelValue', '')"
        :disabled="busy || !vm.can(vm.page + '.images')"
      >
        {{ tr("إزالة") }}
      </button>
    </div>
  </div>
</template>
