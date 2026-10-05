<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions([
  "print-policy-settings",
  "agent-product-picker",
]);
export default options;
</script>

<template>
  <section class="agent-product-picker">
    <div v-if="!readonly&amp;&amp;!inline" class="agent-product-tools">
      <b>{{ $root.tr("الفئات المسموحة") }}</b
      ><span
        >{{ $root.tr("تم تحديد ") }}{{ $root.tr(saved.length)
        }}{{ $root.tr(" فئة") }}</span
      ><button type="button" class="btn" @click="open">
        {{ $root.tr(saved.length ? "معاينة وتعديل الفئات" : "اختيار الفئات") }}
      </button>
    </div>
    <component
      :is="readonly || inline ? 'div' : 'dialog'"
      ref="dialog"
      :class="{'agent-product-dialog':!readonly&amp;&amp;!inline}"
      :aria-label="$root.tr(readonly ? 'الفئات المسموحة' : 'اختيار الفئات')"
      @cancel.prevent="close"
    >
      <template v-if="readonly || inline || opened"
        ><div class="agent-product-tools">
          <h3>{{ $root.tr("الفئات المسموحة") }}</h3>
          <span class="badge"
            >{{ $root.tr(ids.length) }}{{ $root.tr(" محددة") }}</span
          >
        </div>
        <div class="agent-product-tools">
          <input
            ref="search"
            type="search"
            v-model="query"
            :placeholder="$root.tr('بحث بالفئة أو القيمة')"
            :aria-label="$root.tr('بحث الفئات المسموحة')"
          /><select
            v-model="provider"
            :aria-label="$root.tr('فلتر مزود الفئات')"
          >
            <option value="">{{ $root.tr("كل الشركات والمزودين") }}</option>
            <option v-for="p in vm.s.providers" :key="p.id" :value="p.id">
              {{ $root.tr(p.name) }}
            </option>
          </select>
        </div>
        <div v-if="!readonly" class="agent-product-tools">
          <button
            type="button"
            class="btn small"
            @click="selectResults"
            :disabled="!rows.length"
          >
            {{ $root.tr("تحديد كل نتائج البحث (")
            }}{{ $root.tr(rows.length) }})</button
          ><button
            type="button"
            class="btn small"
            @click="clearResults"
            :disabled="!rows.length"
          >
            {{ $root.tr("إلغاء تحديد النتائج") }}</button
          ><label class="inline-check"
            ><input type="checkbox" v-model="selectedOnly" />{{
              $root.tr("عرض المحددة فقط")
            }}</label
          >
        </div>
        <div class="agent-product-grid">
          <label
            v-for="p in shown"
            :key="p.id"
            class="agent-product-item"
            :class="{ 'is-selected': selectedSet.has(p.id) }"
            ><input
              v-if="!readonly"
              type="checkbox"
              :checked="selectedSet.has(p.id)"
              :aria-label="$root.tr(p.name)"
              @change="toggle(p.id, $event.target.checked)"
            /><span
              ><b>{{ $root.tr(p.name) }}</b
              ><small
                >{{ $root.tr(vm.nameOf("providers", p.provider)) }} ·
                {{ $root.tr(vm.money(p.face)) }}
                {{ $root.tr(p.currency) }}</small
              ><small v-if="!p.active">{{ $root.tr("معطلة") }}</small></span
            ></label
          >
        </div>
        <p v-if="!rows.length" class="empty">
          {{ $root.tr("لا توجد فئات مطابقة") }}
        </p>
        <div class="agent-product-tools agent-product-footer">
          <button
            type="button"
            class="btn small"
            :disabled="page&lt;=1"
            @click="page--"
          >
            {{ $root.tr("السابق") }}</button
          ><span
            >{{ $root.tr(page) }} / {{ $root.tr(pages) }} ·
            {{ $root.tr(rows.length) }}{{ $root.tr(" فئة") }}</span
          ><button
            type="button"
            class="btn small"
            :disabled="page&gt;=pages"
            @click="page++"
          >
            {{ $root.tr("التالي") }}
          </button>
        </div>
        <div v-if="!readonly&amp;&amp;!inline" class="formfoot">
          <button type="button" class="btn" @click="close">
            {{ $root.tr("إلغاء") }}</button
          ><button type="button" class="btn primary" @click="confirm">
            {{ $root.tr("تأكيد الاختيار (") }}{{ $root.tr(draft.length) }})
          </button>
        </div></template
      ></component
    >
  </section>
</template>
