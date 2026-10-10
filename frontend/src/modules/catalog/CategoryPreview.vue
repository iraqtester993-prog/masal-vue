<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import { inject, computed, ref } from "vue";
import { catalogKey, tr } from "./catalog-model.js";
const vm = inject(catalogKey);
import { previewFields, catalogCell } from "./catalog-model.js";
import MeetingReceipt from "./MeetingReceipt.vue";
const product = computed(() => vm.modal.record);
const fields = previewFields;
const value = (key) =>
  ["face", "min", "dailyQty", "dailyAmount"].includes(key)
    ? (product.value[key] ?? "—")
    : catalogCell(vm, product.value, key);
</script>
<template>
  <div class="category-preview">
    <img
      v-if="product.image"
      :src="product.image"
      :alt="tr(product.name)"
      class="category-preview-image"
    /><span class="badge" :class="{ neutral: !product.active }">{{
      vm.tr(product.active ? "مفعلة" : "معطلة")
    }}</span>
    <dl class="account-fields">
      <div v-for="f in fields" :key="f[0]">
        <dt>{{ vm.tr(f[1]) }}</dt>
        <dd>{{ vm.tr(value(f[0])) }}</dd>
      </div>
      <div v-for="f in product.extraFields || []" :key="f.key">
        <dt>{{ tr(f.label) }}</dt>
        <dd>{{ vm.tr(f.required ? "مطلوب" : "اختياري") }}</dd>
      </div>
    </dl>
    <meeting-receipt :product-id="product.id"></meeting-receipt>
    <div class="formfoot">
      <button class="btn" @click="vm.closeModal()">{{ vm.tr("إغلاق") }}</button
      ><button
        v-if="vm.can('products.edit')"
        class="btn primary"
        @click="vm.openEdit(product)"
      >
        {{ vm.tr("تعديل") }}
      </button>
    </div>
  </div>
</template>
