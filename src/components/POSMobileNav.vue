<script>
import { componentOptions } from "../services/component-registry.js";
export default componentOptions(["pos-mobile-nav"]);
</script>
<template>
  <nav class="pos-phone-nav" :aria-label="vm.tr('تنقل نقطة البيع')">
    <button
      v-for="n in items"
      :key="n.id"
      :class="{active:vm.page===n.id||(n.id==='sell'&amp;&amp;vm.page==='digital')}"
      :aria-current="vm.page === n.id ? 'page' : undefined"
      @click="vm.go(n.id)"
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
      >
        <path :d="vm.navIconPath(n.id)"></path></svg
      ><span>{{ vm.tr(n.label) }}</span>
    </button>
  </nav>
</template>
