<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["support-phone-links"]);
export default options;
</script>

<template>
  <section
    v-if="rows.length"
    class="support-phone-links"
    :aria-label="$root.tr('أرقام الدعم')"
  >
    <div class="support-phone-heading">
      <strong>{{ $root.tr("أرقام الدعم") }}</strong
      ><span>{{ $root.tr(vm.engine.supportName(account)) }}</span
      ><small>{{ $root.tr(rows.length) }}{{ $root.tr(" رقم") }}</small>
    </div>
    <div class="support-phone-grid">
      <a
        v-for="(r, i) in rows"
        :key="i"
        :href="tel(r.number)"
        class="support-phone-card"
        :aria-label="$root.tr('اتصال بـ' + r.label + ' ' + r.number)"
        ><span class="support-phone-icon" aria-hidden="true"
          ><svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path
              d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1Z"
            ></path></svg></span
        ><span class="support-phone-text"
          ><span>{{ $root.tr(r.label || "الدعم الفني") }}</span
          ><bdi>{{ $root.tr(r.number) }}</bdi></span
        ><span class="support-phone-call">{{ $root.tr("اتصال") }}</span></a
      >
    </div>
  </section>
</template>
