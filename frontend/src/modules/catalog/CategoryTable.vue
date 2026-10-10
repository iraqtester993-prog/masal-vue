<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';

import { inject, computed, ref } from "vue";
import { catalogKey, tr } from "./catalog-model.js";
const vm = inject(catalogKey);
import CatalogFilterBar from "./CatalogFilterBar.vue";
import PageActions from "./PageActions.vue";
const rows = computed(() => vm.rows);
import { columns, catalogCell } from "./catalog-model.js";
const columnsOpen = ref(false),
  columnSearch = ref(""),
  columnsMenu = ref(),
  columnsButton = ref();
const visibleColumns = computed(() => columns.filter(([key]) => show(key)));
const activeFilters = computed(() => vm.filtersActive);
const show = (key) => !vm.hiddenColumns.includes(key);
const toggleColumn = (key) => vm.toggleColumn(key);
const closeColumns = () => {
  columnsOpen.value = false;
  columnsButton.value?.focus();
};
const value = (row, key) => catalogCell(vm, row, key);
</script>
<template>
  <div class="category-list">
    <catalog-filter-bar page="products" :key="'products-' + vm.currentUser"
      ><template #actions=""
        ><FilterBar class="toolbar category-unified-toolbar">
          <page-actions></page-actions
          ><span class="caption">{{ tr(rows.length) }} {{ vm.tr("فئة") }}</span>
          <div
            class="category-columns-menu"
            ref="columnsMenu"
            @keydown.esc.stop.prevent="closeColumns"
          >
            <button
              ref="columnsButton"
              :aria-label="vm.tr('الأعمدة')"
              type="button"
              class="btn small"
              :aria-expanded="columnsOpen"
              aria-controls="category-column-options"
              @click="columnsOpen = !columnsOpen"
            >
              {{ vm.tr("الأعمدة") }} ▾
            </button>
            <div
              v-if="columnsOpen"
              id="category-column-options"
              class="category-column-options"
              role="group"
              :aria-label="vm.tr('الأعمدة')"
            >
              <input
                class="category-column-search"
                type="search"
                v-model="columnSearch"
                :placeholder="vm.tr('بحث في الأعمدة')"
                :aria-label="vm.tr('بحث في الأعمدة')"
              /><label
                v-for="c in columns.filter(c=&gt;!['name','actions'].includes(c[0])&amp;&amp;vm.tr(c[1]).toLowerCase().includes(columnSearch.trim().toLowerCase()))"
                :key="c[0]"
                ><input
                  type="checkbox"
                  :checked="show(c[0])"
                  @change="toggleColumn(c[0])"
                /><span>{{ vm.tr(c[1]) }}</span></label
              >
            </div>
          </div>
        </FilterBar></template
      ></catalog-filter-bar
    >
    <TablePanel class="tablewrap">
      <table class="category-table">
        <thead>
          <tr>
            <th v-for="c in visibleColumns" :key="c[0]">{{ vm.tr(c[1]) }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in rows" :key="p.id">
            <td
              v-for="c in visibleColumns"
              :key="c[0]"
              :class="'category-col-' + c[0]"
            >
              <template v-if="['image', 'receiptImage'].includes(c[0])"
                ><img
                  v-if="p[c[0]]"
                  :src="p[c[0]]"
                  :alt="vm.tr(c[1])"
                  class="category-thumb"
                /><span v-else="">—</span></template
              ><b v-else-if="c[0] === 'name'">{{ tr(p.name) }}</b
              ><template v-else-if="c[0] === 'order'"
                ><span>{{ tr(p.order ?? "—") }}</span>
                <div
                  v-if="vm.can('products.order')&amp;&amp;!vm.search&amp;&amp;!activeFilters"
                  class="actions"
                >
                  <button
                    class="btn small"
                    :disabled="
                      rows[0]?.id === p.id && vm.meta.current_page === 1
                    "
                    @click="vm.moveProduct(p.id, -1)"
                    :aria-label="tr('تحريك للأعلى')"
                  >
                    ↑</button
                  ><button
                    class="btn small"
                    :disabled="
                      rows.at(-1)?.id === p.id &&
                      vm.meta.current_page === vm.meta.last_page
                    "
                    @click="vm.moveProduct(p.id, 1)"
                    :aria-label="tr('تحريك للأسفل')"
                  >
                    ↓
                  </button>
                </div></template
              ><span
                v-else-if="c[0] === 'active'"
                class="badge"
                :class="{ neutral: !p.active }"
                >{{ vm.tr(p.active ? "مفعلة" : "معطلة") }}</span
              ><template v-else-if="c[0] === 'actions'"
                ><div class="category-actions">
                  <button class="btn small" @click="vm.previewCategory(p)">
                    {{ vm.tr("معاينة") }}</button
                  ><button
                    v-if="vm.can('products.edit')"
                    class="btn small"
                    @click="vm.openEdit(p)"
                  >
                    {{ vm.tr("تعديل") }}</button
                  ><button
                    v-if="vm.can('products.toggle')"
                    class="btn small"
                    @click="vm.toggleEntity(p)"
                  >
                    {{ vm.tr(p.active ? "تعطيل" : "تفعيل") }}
                  </button>
                </div></template
              ><span v-else="">{{ vm.tr(value(p, c[0])) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </TablePanel>
    <div v-if="vm.meta.total" class="table-pagination-footer">
      <button
        class="btn small"
        :disabled="vm.busy || vm.meta.current_page === 1"
        @click="vm.load(vm.meta.current_page - 1)"
      >
        السابق</button
      ><span>الصفحة {{ vm.meta.current_page }} من {{ vm.meta.last_page }}</span
      ><button
        class="btn small"
        :disabled="vm.busy || vm.meta.current_page === vm.meta.last_page"
        @click="vm.load(vm.meta.current_page + 1)"
      >
        التالي
      </button>
    </div>
    <div v-if="!rows.length" class="empty">
      {{ vm.tr("لا توجد فئات مطابقة") }}
    </div>
  </div>
</template>
