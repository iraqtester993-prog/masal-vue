<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <nav
    class="price-mode-tabs order-page-tabs"
    :aria-label="$root.tr('تبويبات الطلبيات')"
  >
    <button
      class="btn"
      type="button"
      :aria-pressed="importTab === 'records'"
      @click="importTab = 'records'"
    >
      {{ $root.tr("سجل الطلبيات") }}</button
    ><button
      class="btn"
      type="button"
      :aria-pressed="importTab === 'new'"
      @click="importTab = 'new'"
    >
      {{ $root.tr("طلبية جديدة") }}
    </button>
  </nav>
  <div v-show="importTab === 'new'">
    <cash-order-form ref="cashOrder" :key="currentUser"></cash-order-form>
  </div>
  <div v-show="importTab === 'records'">
    <pending-order-review :key="'orders-' + currentUser"></pending-order-review>
  </div>
</template>
