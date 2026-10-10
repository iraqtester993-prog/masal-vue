<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["pending-order-review"]);
export default options;
</script>

<template>
  <multi-history></multi-history>
  <details
    v-if="($root.s.pendingOrders||[]).some(r=&gt;r.version!==2&amp;&amp;$root.engine.allowed(r.agent))"
  >
    <summary>{{ $root.tr("الطلبيات السابقة") }}</summary>
    <legacy-history></legacy-history>
  </details>
</template>
