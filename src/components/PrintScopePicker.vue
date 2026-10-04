<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions([
  "print-policy-settings",
  "print-scope-picker",
]);
export default options;
</script>

<template>
  <div class="print-scope-picker">
    <div class="scope-picker-head">
      <strong>{{ $root.tr("اختيار النطاق") }}</strong
      ><span class="scope-count"
        >{{ $root.tr(selected.length) }}{{ $root.tr(" محدد") }}</span
      >
    </div>
    <div class="scope-picker-tools">
      <div
        class="scope-kind-tabs"
        role="group"
        :aria-label="$root.tr('نوع النطاق')"
      >
        <button
          type="button"
          :aria-pressed="tab === 'agents'"
          :class="{ active: tab === 'agents' }"
          @click="tab = 'agents'"
        >
          {{ $root.tr("الوكلاء والفروع") }}</button
        ><button
          type="button"
          :aria-pressed="tab === 'pos'"
          :class="{ active: tab === 'pos' }"
          @click="tab = 'pos'"
        >
          {{ $root.tr("نقاط البيع") }}
        </button>
      </div>
      <input
        type="search"
        v-model="query"
        :placeholder="$root.tr('بحث بالاسم أو المعرّف…')"
        :aria-label="$root.tr('بحث بنطاق الطباعة')"
      />
    </div>
    <div class="scope-picker-filters">
      <label
        >{{ $root.tr("الوكيل الرئيسي")
        }}<select
          v-model="mainFilter"
          :aria-label="$root.tr('فلترة الوكيل الرئيسي')"
        >
          <option value="">{{ $root.tr("كل الوكلاء") }}</option>
          <option v-for="a in mains" :key="a.id" :value="a.id">
            {{ $root.tr(a.name) }}
          </option>
        </select></label
      ><label
        >{{ $root.tr("الفرع")
        }}<select v-model="branchFilter" :aria-label="$root.tr('فلترة الفرع')">
          <option value="">{{ $root.tr("كل الفروع") }}</option>
          <option v-for="a in branches" :key="a.id" :value="a.id">
            {{ $root.tr(a.name) }}
          </option>
        </select></label
      ><label class="scope-selected-filter"
        ><input type="checkbox" v-model="selectedOnly" />{{
          $root.tr("عرض المختارين فقط")
        }}</label
      >
    </div>
    <div class="scope-options scope-card-grid">
      <article
        v-for="a in rows"
        :key="tab + ':' + a.id"
        class="scope-choice-card"
        :class="{ 'is-selected': checked(a) }"
      >
        <label class="scope-choice-main"
          ><input
            type="checkbox"
            :checked="checked(a)"
            @change="toggle(a, $event.target.checked)"
            :aria-label="$root.tr('اختيار ' + a.name)"
          /><span class="scope-choice-text"
            ><strong>{{ $root.tr(a.name) }}</strong
            ><small
              >{{ $root.tr(kind(a))
              }}<template v-if="a.parent || tab === 'pos'">
                · {{ $root.tr(parent(a)) }}</template
              ></small
            ></span
          ></label
        ><select
          v-if="tab==='agents'&amp;&amp;checked(a)"
          class="scope-coverage"
          :value="coverage(a)"
          @change="changeCoverage(a, $event.target.value)"
          :aria-label="$root.tr('شمول ' + a.name)"
        >
          <option value="tree">{{ $root.tr("مع الفروع ونقاط البيع") }}</option>
          <option value="agent">
            {{ $root.tr("هذا الحساب فقط") }}
          </option></select
        ><span
          v-else-if="tab==='pos'&amp;&amp;checked(a)"
          class="scope-selected-mark"
          >{{ $root.tr("تم الاختيار ✓") }}</span
        >
      </article>
      <p v-if="!rows.length" class="scope-empty">
        {{ $root.tr("لا توجد نتائج مطابقة") }}
      </p>
    </div>
    <div class="scope-pagination">
      <span role="status">{{
        $root.tr(
          filtered.length
            ? "عرض " +
                ((page - 1) * 20 + 1) +
                "–" +
                Math.min(page * 20, filtered.length) +
                " من " +
                filtered.length
            : "0 نتائج",
        )
      }}</span>
      <div>
        <button
          type="button"
          class="btn small"
          :disabled="page&lt;=1"
          @click="page--"
          :aria-label="$root.tr('صفحة النطاقات السابقة')"
        >
          {{ $root.tr("السابق") }}</button
        ><span>{{ $root.tr(page) }} / {{ $root.tr(pages) }}</span
        ><button
          type="button"
          class="btn small"
          :disabled="page&gt;=pages"
          @click="page++"
          :aria-label="$root.tr('صفحة النطاقات التالية')"
        >
          {{ $root.tr("التالي") }}
        </button>
      </div>
    </div>
    <div v-if="selected.length" class="scope-selection">
      <div class="scope-picker-head">
        <strong>{{ $root.tr("النطاقات المختارة") }}</strong
        ><button
          type="button"
          class="btn ghost small"
          @click="$emit('update:modelValue', [])"
        >
          {{ $root.tr("مسح الاختيار") }}
        </button>
      </div>
      <div class="actions scope-selection-tags">
        <span v-for="key in selectedRows" :key="key" class="badge"
          ><span>{{ $root.tr(label(key)) }}</span
          ><button
            type="button"
            @click="remove(key)"
            :aria-label="$root.tr('إزالة ' + label(key))"
          >
            ×
          </button></span
        >
      </div>
      <div v-if="selectedPages&gt;1" class="scope-pagination">
        <span
          >{{ $root.tr("المختارون ") }}{{ $root.tr(selectedPage) }} /
          {{ $root.tr(selectedPages) }}</span
        >
        <div>
          <button
            type="button"
            class="btn small"
            :disabled="selectedPage&lt;=1"
            @click="selectedPage--"
            :aria-label="$root.tr('المختارون السابقون')"
          >
            {{ $root.tr("السابق") }}</button
          ><button
            type="button"
            class="btn small"
            :disabled="selectedPage&gt;=selectedPages"
            @click="selectedPage++"
            :aria-label="$root.tr('المختارون التاليون')"
          >
            {{ $root.tr("التالي") }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
