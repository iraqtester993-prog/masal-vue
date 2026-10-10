<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["operations-panel", "wallet-accounts-table"]);
export default options;
</script>

<template>
  <section class="card wallet-accounts-table" data-no-pagination="">
    <div class="cardhead">
      <h3>{{ vm.tr("الحسابات والأرصدة") }}</h3>
      <button class="btn" @click="vm.openWalletFilters()">
        {{ vm.tr("فلترة وبحث") }}
      </button>
    </div>
    <div class="wallet-account-tools">
      <input
        type="search"
        v-model="query"
        :placeholder="vm.tr('بحث باسم الحساب أو الهاتف')"
        :aria-label="$root.tr('بحث الحسابات')"
      /><select v-model="type" :aria-label="$root.tr('نوع الحساب في الجدول')">
        <option value="">{{ vm.tr("كل الأنواع ضمن نطاقي") }}</option>
        <option v-for="k in vm.scopeFilterKinds" :value="k.id">
          {{ vm.tr(k.label) }}
        </option></select
      ><label
        >{{ vm.tr("عرض")
        }}<select
          v-model.number="size"
          :aria-label="$root.tr('عدد الحسابات في الصفحة')"
        >
          <option v-for="n in [10, 25, 50, 100]" :value="n">
            {{ $root.tr(n) }}
          </option>
        </select></label
      >
    </div>
    <p class="caption">
      {{ vm.tr("عدد الحسابات المطابقة") }}: {{ $root.tr(filtered.length) }}
    </p>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ vm.tr("الحساب") }}</th>
            <th>{{ vm.tr("النوع") }}</th>
            <th>{{ vm.tr("تابع إلى") }}</th>
            <th>{{ vm.tr("الرصيد المتاح") }}</th>
            <th>{{ vm.tr("الإجراء") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in shown" :key="a.id">
            <td>{{ $root.tr(a.name) }}</td>
            <td>{{ vm.tr(vm.walletAccountType(a)) }}</td>
            <td>{{ $root.tr(parent(a)) }}</td>
            <td>
              {{ $root.tr(vm.money(vm.walletTableAmount(a.id, service))) }}
              {{ vm.tr("د.ع") }}
            </td>
            <td>
              <button class="btn small" @click="details(a)">
                {{ vm.tr("عرض التفاصيل") }}
              </button>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="5" class="empty">
              {{ vm.tr("لا توجد حسابات مطابقة") }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="table-pagination-footer">
      <button class="btn small" :disabled="page&lt;=1" @click="page--">
        {{ vm.tr("السابق") }}</button
      ><span
        >{{ vm.tr("الصفحة") }} {{ $root.tr(page) }} {{ vm.tr("من") }}
        {{ $root.tr(pages) }}</span
      ><button class="btn small" :disabled="page&gt;=pages" @click="page++">
        {{ vm.tr("التالي") }}
      </button>
    </div>
  </section>
</template>
