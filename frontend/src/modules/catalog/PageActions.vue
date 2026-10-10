<script setup>
import { inject } from "vue";
import { catalogKey } from "./catalog-model.js";
const vm = inject(catalogKey);
</script>
<template>
  <div class="workspace-actions">
    <button
      v-if="vm.can(vm.page + '.create')"
      class="btn primary"
      :disabled="vm.busy"
      @click="vm.openEdit()"
    >
      {{ vm.page === "products" ? "إضافة فئة" : "إضافة شركة" }}
    </button>
    <details v-if="vm.can(vm.page + '.export')" class="workspace-more">
      <summary aria-label="تصدير">تصدير ▾</summary>
      <div>
        <button class="btn" :disabled="vm.busy" @click="vm.exportCurrent()">
          تصدير البيانات
        </button>
      </div>
    </details>
  </div>
</template>
