<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions([
  "cash-order-form",
  "multi-form",
  "order-lines",
  "rejected-export",
]);
export default options;
</script>

<template>
  <button
    v-if="allowed&amp;&amp;rows.length"
    type="button"
    class="btn small"
    @click="download"
  >
    {{ $root.tr("تصدير البطاقات المرفوضة (") }}{{ $root.tr(rows.length) }})
  </button>
</template>
