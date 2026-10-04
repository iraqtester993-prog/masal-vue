<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["provider-editor"]);
export default options;
</script>

<template>
  <div v-if="vm.can('providers.images')" class="formgrid">
    <label
      >{{ $root.tr("شعار الشركة (اختياري)")
      }}<image-attachment
        v-model="vm.editForm.logo"
        @busy="vm.providerLogoBusy = $event"
      ></image-attachment
    ></label>
  </div>
</template>
