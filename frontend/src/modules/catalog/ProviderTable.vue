<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';

import { inject, computed, ref } from "vue";
import { catalogKey, tr } from "./catalog-model.js";
const vm = inject(catalogKey);
import CatalogFilterBar from "./CatalogFilterBar.vue";
import PageActions from "./PageActions.vue";
const rows = computed(() => vm.rows);
</script>
<template>
  <div class="provider-list">
    <catalog-filter-bar page="providers" :key="'providers-' + vm.currentUser"
      ><template #actions=""><page-actions></page-actions></template
    ></catalog-filter-bar>
    <TablePanel class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ tr("الشعار") }}</th>
            <th>{{ tr("الشركة") }}</th>
            <th>{{ tr("المجهز") }}</th>
            <th>{{ tr("نوع الربط") }}</th>
            <th>{{ tr("الحالة") }}</th>
            <th>{{ tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in rows" :key="p.id">
            <td>
              <img
                v-if="p.logo"
                :src="p.logo"
                :alt="tr(p.name)"
                class="category-thumb"
              /><span v-else="">—</span>
            </td>
            <td>{{ tr(p.name) }}</td>
            <td>{{ tr(p.supplier) }}</td>
            <td>{{ tr(p.connection) }}</td>
            <td>
              <span class="badge" :class="{ neutral: !p.active }">{{
                tr(p.active ? "مفعلة" : "معطلة")
              }}</span>
            </td>
            <td>
              <div class="actions">
                <button class="btn small" @click="vm.previewProvider(p)">
                  {{ tr("معاينة") }}</button
                ><button
                  v-if="vm.can('providers.edit')"
                  class="btn small"
                  @click="vm.openEdit(p)"
                >
                  {{ tr("تعديل") }}</button
                ><button
                  v-if="vm.can('providers.toggle')"
                  class="btn small"
                  @click="vm.toggleEntity(p)"
                >
                  {{ tr(p.active ? "تعطيل" : "تفعيل") }}
                </button>
              </div>
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
      {{ tr("لا توجد شركات مطابقة") }}
    </div>
  </div>
</template>
