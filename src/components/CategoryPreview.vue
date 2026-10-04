<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["category-preview"]);
export default options;
</script>

<template>
  <div class="category-preview">
    <img
      v-if="product.image"
      :src="product.image"
      :alt="$root.tr(product.name)"
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
        <dt>{{ $root.tr(f.label) }}</dt>
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
