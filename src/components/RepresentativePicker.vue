<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["representative-picker"]);
export default options;
</script>

<template>
  <section class="pos-related-editor representative-select-field">
    <div class="cardhead">
      <h3>{{ $root.tr("المندوبون") }}</h3>
      <span class="help">{{
        $root.tr("اختياري ويمكن اختيار أكثر من مندوب")
      }}</span>
    </div>
    <details class="representative-dropdown">
      <summary>
        <span
          >{{ $root.tr(selectedItems.length?selectedItems.map(r=&gt;r.name).join('، '):'اختر المندوبين') }}</span
        ><b>{{ $root.tr(selectedItems.length || "") }}</b>
      </summary>
      <div class="representative-dropdown-panel">
        <input
          class="representative-search"
          v-model="query"
          @click.stop=""
          :placeholder="$root.tr('بحث باسم المندوب أو الهاتف')"
          :aria-label="$root.tr('بحث المندوب')"
        />
        <div class="representative-options" v-if="items.length">
          <label v-for="r in items" :key="r.id" class="inline-check"
            ><input
              type="checkbox"
              :checked="(vm.editForm.representativeIds || []).includes(r.id)"
              @change="toggle(r.id)"
            /><span
              >{{ $root.tr(r.name || "مندوب بدون اسم")
              }}<small v-if="r.phone"> · {{ $root.tr(r.phone) }}</small></span
            ></label
          >
        </div>
        <p v-else="" class="empty">
          {{ $root.tr("لا يوجد مندوبون تابعون للوكيل المحدد") }}
        </p>
      </div>
    </details>
  </section>
</template>
