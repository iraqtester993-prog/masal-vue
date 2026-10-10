<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import { inject, computed, ref } from "vue";
import { catalogKey, tr } from "./catalog-model.js";
const vm = inject(catalogKey);
import ImageAttachment from "./ImageAttachment.vue";
</script>
<template>
  <div v-if="vm.can('providers.images')" class="formgrid">
    <label
      >{{ tr("شعار الشركة (اختياري)")
      }}<image-attachment
        v-model="vm.editForm.logo"
        @busy="vm.providerLogoBusy = $event"
      ></image-attachment
    ></label>
  </div>
</template>
