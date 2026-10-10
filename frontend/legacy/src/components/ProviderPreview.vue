<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["provider-preview"]);
export default options;
</script>

<template>
  <div>
    <dl class="account-fields">
      <div>
        <dt>{{ $root.tr("الشركة") }}</dt>
        <dd>{{ $root.tr(provider.name) }}</dd>
      </div>
      <div>
        <dt>{{ $root.tr("المجهز") }}</dt>
        <dd>{{ $root.tr(provider.supplier) }}</dd>
      </div>
      <div>
        <dt>{{ $root.tr("نوع الربط") }}</dt>
        <dd>{{ $root.tr(provider.connection) }}</dd>
      </div>
      <div>
        <dt>{{ $root.tr("الحالة") }}</dt>
        <dd>{{ $root.tr(provider.active ? "مفعلة" : "معطلة") }}</dd>
      </div>
      <div>
        <dt>{{ $root.tr("الشعار") }}</dt>
        <dd>
          <img
            v-if="provider.logo"
            :src="provider.logo"
            class="category-preview-image"
            :alt="$root.tr('الشعار')"
          /><span v-else="">—</span>
        </dd>
      </div>
    </dl>
    <div class="formfoot">
      <button class="btn" @click="vm.closeModal()">
        {{ $root.tr("إغلاق") }}</button
      ><button
        v-if="vm.can('providers.edit')"
        class="btn primary"
        @click="vm.openEdit(provider)"
      >
        {{ $root.tr("تعديل") }}
      </button>
    </div>
  </div>
</template>
