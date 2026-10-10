<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import { inject, computed, ref } from "vue";
import { catalogKey, tr } from "./catalog-model.js";
const vm = inject(catalogKey);
const provider = computed(() => vm.modal.record);
</script>
<template>
  <div>
    <dl class="account-fields">
      <div>
        <dt>{{ tr("الشركة") }}</dt>
        <dd>{{ tr(provider.name) }}</dd>
      </div>
      <div>
        <dt>{{ tr("المجهز") }}</dt>
        <dd>{{ tr(provider.supplier) }}</dd>
      </div>
      <div>
        <dt>{{ tr("نوع الربط") }}</dt>
        <dd>{{ tr(provider.connection) }}</dd>
      </div>
      <div>
        <dt>{{ tr("الحالة") }}</dt>
        <dd>{{ tr(provider.active ? "مفعلة" : "معطلة") }}</dd>
      </div>
      <div>
        <dt>{{ tr("الشعار") }}</dt>
        <dd>
          <img
            v-if="provider.logo"
            :src="provider.logo"
            class="category-preview-image"
            :alt="tr('الشعار')"
          /><span v-else="">—</span>
        </dd>
      </div>
    </dl>
    <div class="formfoot">
      <button class="btn" @click="vm.closeModal()">
        {{ tr("إغلاق") }}</button
      ><button
        v-if="vm.can('providers.edit')"
        class="btn primary"
        @click="vm.openEdit(provider)"
      >
        {{ tr("تعديل") }}
      </button>
    </div>
  </div>
</template>
