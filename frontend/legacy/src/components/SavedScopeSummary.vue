<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions([
  "print-policy-settings",
  "saved-scope-summary",
]);
export default options;
</script>

<template>
  <div class="saved-scope-summary">
    <b>{{ $root.tr(rule.name) }}</b>
    <div class="saved-scope-actions">
      <span
        >{{ $root.tr((rule.targets || []).length)
        }}{{ $root.tr(" نطاق") }}</span
      ><button type="button" class="btn small" @click="open">
        {{ $root.tr("عرض النطاق") }}
      </button>
    </div>
    <teleport to="body"
      ><dialog
        ref="dialog"
        class="saved-scope-dialog"
        @cancel.prevent="close"
        @click="$event.target===$refs.dialog &amp;&amp; close()"
        :aria-label="$root.tr('النطاقات المختارة')"
      >
        <template v-if="opened"
          ><header>
            <div>
              <h3>{{ $root.tr(rule.name) }}</h3>
              <span
                >{{ $root.tr((rule.targets || []).length)
                }}{{ $root.tr(" نطاق مختار") }}</span
              >
            </div>
            <button
              type="button"
              class="btn small"
              @click="close"
              :aria-label="$root.tr('إغلاق النطاقات')"
            >
              ×
            </button>
          </header>
          <input
            autofocus=""
            type="search"
            v-model="query"
            :placeholder="$root.tr('بحث بالاسم…')"
            :aria-label="$root.tr('بحث في النطاقات المختارة')"
          />
          <ul>
            <li v-for="t in rows" :key="t.key">{{ $root.tr(t.label) }}</li>
          </ul>
          <p v-if="!rows.length" class="empty">
            {{ $root.tr("لا توجد نتائج") }}
          </p>
          <footer>
            <span>{{ $root.tr(filtered.length) }}{{ $root.tr(" نتيجة") }}</span>
            <div class="actions">
              <button
                type="button"
                class="btn small"
                :disabled="page&lt;=1"
                @click="page--"
              >
                {{ $root.tr("السابق") }}</button
              ><span>{{ $root.tr(page) }} / {{ $root.tr(pages) }}</span
              ><button
                type="button"
                class="btn small"
                :disabled="page&gt;=pages"
                @click="page++"
              >
                {{ $root.tr("التالي") }}
              </button>
            </div>
          </footer></template
        >
      </dialog></teleport
    >
  </div>
</template>
