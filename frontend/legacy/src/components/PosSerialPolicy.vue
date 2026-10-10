<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["pos-serial-policy"]);
export default options;
</script>

<template>
  <section class="pos-related-editor serial-binding-policy">
    <div>
      <h3>{{ $root.tr("مطابقة الرقم التسلسلي للجهاز") }}</h3>
      <p class="help">
        {{
          $root.tr(
            "عند التفعيل لا يعمل حساب نقطة البيع إلا عندما يرسل الجهاز نفس الرقم التسلسلي المسجل أعلاه.",
          )
        }}
      </p>
    </div>
    <button
      type="button"
      class="serial-binding-toggle"
      role="switch"
      :aria-checked="enabled"
      @click="toggle"
      :aria-label="$root.tr('مطابقة الرقم التسلسلي للجهاز')"
    >
      <span class="serial-switch-track" aria-hidden="true"
        ><span class="serial-switch-thumb"></span></span
      ><span>{{ $root.tr(enabled ? "مفعّل" : "غير مفعّل") }}</span>
    </button>
  </section>
</template>
