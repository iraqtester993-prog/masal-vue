<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["provider-table"]);
export default options;
</script>

<template>
  <div class="provider-list">
    <catalog-filter-bar page="providers" :key="'providers-' + vm.currentUser"
      ><template #actions=""><page-actions></page-actions></template
    ></catalog-filter-bar>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("الشعار") }}</th>
            <th>{{ $root.tr("الشركة") }}</th>
            <th>{{ $root.tr("المجهز") }}</th>
            <th>{{ $root.tr("نوع الربط") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
            <th>{{ $root.tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in rows" :key="p.id">
            <td>
              <img
                v-if="p.logo"
                :src="p.logo"
                :alt="$root.tr(p.name)"
                class="category-thumb"
              /><span v-else="">—</span>
            </td>
            <td>{{ $root.tr(p.name) }}</td>
            <td>{{ $root.tr(p.supplier) }}</td>
            <td>{{ $root.tr(p.connection) }}</td>
            <td>
              <span class="badge" :class="{ neutral: !p.active }">{{
                $root.tr(p.active ? "مفعلة" : "معطلة")
              }}</span>
            </td>
            <td>
              <div class="actions">
                <button class="btn small" @click="vm.previewProvider(p)">
                  {{ $root.tr("معاينة") }}</button
                ><button
                  v-if="vm.can('providers.edit')"
                  class="btn small"
                  @click="vm.openEdit(p)"
                >
                  {{ $root.tr("تعديل") }}</button
                ><button
                  v-if="vm.can('providers.toggle')"
                  class="btn small"
                  @click="vm.toggleEntity(p)"
                >
                  {{ $root.tr(p.active ? "تعطيل" : "تفعيل") }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="!rows.length" class="empty">
      {{ $root.tr("لا توجد شركات مطابقة") }}
    </div>
  </div>
</template>
