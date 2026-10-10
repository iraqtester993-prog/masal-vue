<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["AuditFields"]);
export default options;
</script>

<template>
  <div class="audit-fields">
    <div
      v-for="(v,k) in (value&amp;&amp;typeof value==='object'?value:{value})"
      :key="k"
      class="audit-field"
    >
      <div class="audit-field-label">
        {{ tr(Array.isArray(value) ? "عنصر " + (Number(k) + 1) : labelFor(k)) }}
      </div>
      <div class="audit-field-value">
        {{ $root.tr(v!==null&amp;&amp;typeof v==='object'?JSON.stringify(v):displayValue(v,Array.isArray(value)?fieldKey:k)) }}
      </div>
    </div>
    <p
      v-if="value&amp;&amp;typeof value==='object'&amp;&amp;!Object.keys(value).length"
      class="help"
    >
      {{ tr("لا توجد بيانات") }}
    </p>
  </div>
</template>
