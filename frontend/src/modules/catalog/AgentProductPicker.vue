<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import { computed, ref, watch } from "vue";
import { tr, money } from "./catalog-model.js";
const props = defineProps({
  products: Array,
  modelValue: Array,
  readonly: Boolean,
  inline: { type: Boolean, default: true },
});
const emit = defineEmits(["update:modelValue"]);
const opened = ref(false),
  dialog = ref(),
  search = ref(),
  query = ref(""),
  provider = ref(""),
  selectedOnly = ref(false),
  page = ref(1);
const ids = computed(() => props.modelValue ?? []),
  draft = ids,
  saved = ids,
  selectedSet = computed(() => new Set(ids.value));
const providers = computed(() => [
  ...new Map(
    props.products.map((p) => [
      p.provider,
      { id: p.provider, name: p.provider_name },
    ]),
  ).values(),
]);
const vm = {
  s: {
    get providers() {
      return providers.value;
    },
  },
  money,
  nameOf(_, id) {
    return providers.value.find((p) => p.id === id)?.name ?? "—";
  },
};
const rows = computed(() =>
  props.products.filter(
    (p) =>
      (!props.readonly || selectedSet.value.has(p.id)) &&
      (!selectedOnly.value || selectedSet.value.has(p.id)) &&
      (!provider.value || p.provider === Number(provider.value)) &&
      [p.name, p.face, p.provider_name]
        .join(" ")
        .toLowerCase()
        .includes(query.value.trim().toLowerCase()),
  ),
);
const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / 25))),
  shown = computed(() =>
    rows.value.slice((page.value - 1) * 25, page.value * 25),
  );
watch([query, provider, selectedOnly], () => (page.value = 1));
watch(pages, () => (page.value = Math.min(page.value, pages.value)));
const toggle = (id, checked) =>
  emit(
    "update:modelValue",
    checked
      ? [...new Set([...ids.value, id])]
      : ids.value.filter((x) => x !== id),
  );
const selectResults = () =>
  emit("update:modelValue", [
    ...new Set([...ids.value, ...rows.value.map((p) => p.id)]),
  ]);
const clearResults = () => {
  const matching = new Set(rows.value.map((p) => p.id));
  emit(
    "update:modelValue",
    ids.value.filter((id) => !matching.has(id)),
  );
};
function open() {
  opened.value = true;
  dialog.value?.showModal();
}
function close() {
  opened.value = false;
  dialog.value?.close();
}
function confirm() {
  close();
}
</script>
<template>
  <section class="agent-product-picker">
    <div v-if="!readonly&amp;&amp;!inline" class="agent-product-tools">
      <b>{{ tr("الفئات المسموحة") }}</b
      ><span>{{ tr("تم تحديد ") }}{{ tr(saved.length) }}{{ tr(" فئة") }}</span
      ><button type="button" class="btn" @click="open">
        {{ tr(saved.length ? "معاينة وتعديل الفئات" : "اختيار الفئات") }}
      </button>
    </div>
    <component
      :is="readonly || inline ? 'div' : 'dialog'"
      ref="dialog"
      :class="{'agent-product-dialog':!readonly&amp;&amp;!inline}"
      :aria-label="tr(readonly ? 'الفئات المسموحة' : 'اختيار الفئات')"
      @cancel.prevent="close"
    >
      <template v-if="readonly || inline || opened"
        ><div class="agent-product-tools">
          <h3>{{ tr("الفئات المسموحة") }}</h3>
          <span class="badge">{{ tr(ids.length) }}{{ tr(" محددة") }}</span>
        </div>
        <div class="agent-product-tools">
          <input
            ref="search"
            type="search"
            v-model="query"
            :placeholder="tr('بحث بالفئة أو القيمة')"
            :aria-label="tr('بحث الفئات المسموحة')"
          /><select v-model="provider" :aria-label="tr('فلتر مزود الفئات')">
            <option value="">{{ tr("كل الشركات والمزودين") }}</option>
            <option v-for="p in vm.s.providers" :key="p.id" :value="p.id">
              {{ tr(p.name) }}
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
            {{ tr("تحديد كل نتائج البحث (") }}{{ tr(rows.length) }})</button
          ><button
            type="button"
            class="btn small"
            @click="clearResults"
            :disabled="!rows.length"
          >
            {{ tr("إلغاء تحديد النتائج") }}</button
          ><label class="inline-check"
            ><input type="checkbox" v-model="selectedOnly" />{{
              tr("عرض المحددة فقط")
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
              :aria-label="tr(p.name)"
              @change="toggle(p.id, $event.target.checked)"
            /><span
              ><b>{{ tr(p.name) }}</b
              ><small
                >{{ tr(vm.nameOf("providers", p.provider)) }} ·
                {{ tr(vm.money(p.face)) }}
                {{ tr(p.currency) }}</small
              ><small v-if="!p.active">{{ tr("معطلة") }}</small></span
            ></label
          >
        </div>
        <p v-if="!rows.length" class="empty">
          {{ tr("لا توجد فئات مطابقة") }}
        </p>
        <div class="agent-product-tools agent-product-footer">
          <button
            type="button"
            class="btn small"
            :disabled="page&lt;=1"
            @click="page--"
          >
            {{ tr("السابق") }}</button
          ><span
            >{{ tr(page) }} / {{ tr(pages) }} · {{ tr(rows.length)
            }}{{ tr(" فئة") }}</span
          ><button
            type="button"
            class="btn small"
            :disabled="page&gt;=pages"
            @click="page++"
          >
            {{ tr("التالي") }}
          </button>
        </div>
        <div v-if="!readonly&amp;&amp;!inline" class="formfoot">
          <button type="button" class="btn" @click="close">
            {{ tr("إلغاء") }}</button
          ><button type="button" class="btn primary" @click="confirm">
            {{ tr("تأكيد الاختيار (") }}{{ tr(draft.length) }})
          </button>
        </div></template
      ></component
    >
  </section>
</template>
