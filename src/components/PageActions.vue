<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["operations-panel", "page-actions"]);
export default options;
</script>

<template>
  <div class="workspace-actions">
    <button
      v-if="vm.schema&amp;&amp;vm.can(vm.page+'.create')"
      class="btn primary"
      @click="vm.openEdit()"
    >
      {{ vm.tr(vm.createLabel) }}</button
    ><button
      v-if="vm.page==='agents'&amp;&amp;vm.managementRole==='owner'&amp;&amp;vm.can('agents.create')"
      class="btn"
      @click="vm.newSubAgent()"
    >
      {{ $root.tr("إضافة وكيل فرعي") }}</button
    ><button
      v-if="['inventory','batches'].includes(vm.page)&amp;&amp;vm.can('import.view')"
      class="btn primary"
      @click="vm.go('import')"
    >
      {{ $root.tr("طلبية جديدة") }}</button
    ><button
      v-if="vm.page==='sales'&amp;&amp;vm.can('sell.create')"
      class="btn primary"
      @click="vm.go('sell')"
    >
      {{ $root.tr("عملية بيع") }}</button
    ><button
      v-if="vm.page==='support'&amp;&amp;vm.can('support.create')"
      class="btn primary"
      @click="vm.openTicket()"
    >
      {{ $root.tr("رسالة جديدة") }}</button
    ><button
      v-if="vm.page==='users'&amp;&amp;vm.hasPermissionKey('users.export')&amp;&amp;vm.can('users.export')"
      class="btn export-action"
      @click="vm.exportCurrent()"
    >
      {{ $root.tr("تصدير المستخدمين") }}</button
    ><button
      v-if="['inventory','batches'].includes(vm.page)&amp;&amp;vm.hasPermissionKey(vm.page+'.export')&amp;&amp;vm.can(vm.page+'.export')"
      class="btn export-action"
      @click="vm.exportCurrent()"
    >
      {{ vm.tr("تصدير") }}
    </button>
    <details
      v-if="!['inventory','batches'].includes(vm.page)&amp;&amp;vm.page!=='reports'&amp;&amp;vm.page!=='users'&amp;&amp;vm.hasPermissionKey(vm.page+'.export')&amp;&amp;vm.can(vm.page+'.export')"
      class="workspace-more"
    >
      <summary
        :aria-label="
          $root.tr(
            ['agents', 'products', 'providers'].includes(vm.page)
              ? 'تصدير'
              : 'إجراءات إضافية',
          )
        "
      >
        {{
          $root.tr(
            ["agents", "products", "providers"].includes(vm.page)
              ? "تصدير ▾"
              : "⋯",
          )
        }}
      </summary>
      <div v-if="vm.page === 'agents'">
        <button class="btn" @click="vm.exportAgentNetwork('pdf')">
          {{ $root.tr("تصدير PDF") }}</button
        ><button class="btn" @click="vm.exportAgentNetwork('excel')">
          {{ $root.tr("تصدير Excel") }}
        </button>
      </div>
      <div v-else="">
        <button class="btn" @click="vm.exportCurrent()">
          {{ $root.tr("تصدير البيانات") }}
        </button>
      </div>
    </details>
  </div>
</template>
