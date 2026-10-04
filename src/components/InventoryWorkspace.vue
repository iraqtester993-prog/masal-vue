<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["inventory-workspace"]);
export default options;
</script>

<template>
  <section class="card inventory-workspace" data-no-pagination="">
    <div class="tabs inventory-tabs">
      <button
        :class="{ active: vm.inventoryWorkspaceTab === 'stock' }"
        @click="tab('stock')"
      >
        {{ $root.tr("بطاقات المخزون") }}</button
      ><button
        v-if="vm.can('claims.view')"
        :class="{ active: vm.inventoryWorkspaceTab === 'claims' }"
        @click="tab('claims')"
      >
        {{ $root.tr("التالف والمطالبات") }}</button
      ><button
        v-if="vm.can('exports.view')"
        :class="{ active: vm.inventoryWorkspaceTab === 'withdraw' }"
        @click="tab('withdraw')"
      >
        {{ $root.tr("طلبات الإرجاع") }}</button
      ><button
        v-if="vm.can('exports.view')"
        :class="{ active: vm.inventoryWorkspaceTab === 'copies' }"
        @click="tab('copies')"
      >
        {{ $root.tr("سجل الإرجاع") }}
      </button>
    </div>
    <template v-if="vm.inventoryWorkspaceTab === 'stock'"
      ><div class="inventory-controls">
        <input
          v-model="search"
          :placeholder="$root.tr('بحث بالطلبية أو الفئة')"
          :aria-label="$root.tr('بحث المخزون')"
        /><select v-model="agent" :aria-label="$root.tr('الوكيل')">
          <option value="">{{ $root.tr("جميع الوكلاء") }}</option>
          <option v-for="a in vm.inventoryAgents" :value="a.id">
            {{ $root.tr(a.name) }}
          </option></select
        ><select v-model="product" :aria-label="$root.tr('الفئة')">
          <option value="">{{ $root.tr("جميع الفئات") }}</option>
          <option
            v-for="p in vm.s.products.filter(p=&gt;vm.visibleBatches.some(b=&gt;b.product===p.id))"
            :value="p.id"
          >
            {{ $root.tr(p.name) }}
          </option></select
        ><select v-model="state" :aria-label="$root.tr('الحالة')">
          <option value="">{{ $root.tr("جميع الحالات") }}</option>
          <option
            v-for="s in [...new Set(vm.visibleBatches.map(b=&gt;b.status))]"
            :value="s"
          >
            {{ $root.tr(vm.status(s)) }}
          </option>
        </select>
      </div>
      <div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ $root.tr("رقم دفعة المخزون") }}</th>
              <th>{{ $root.tr("الفئة / الوكيل") }}</th>
              <th>{{ $root.tr("الإجمالي") }}</th>
              <th>{{ $root.tr("المتاح") }}</th>
              <th>{{ $root.tr("المباع") }}</th>
              <th>{{ $root.tr("التالف") }}</th>
              <th>{{ $root.tr("الملغي") }}</th>
              <th>{{ $root.tr("الحالة") }}</th>
              <th>{{ $root.tr("الإجراءات") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in shown" :key="b.id">
              <td>{{ $root.tr(b.id) }}</td>
              <td>
                {{ $root.tr(vm.nameOf("products", b.product))
                }}<small class="caption">{{
                  $root.tr(vm.nameOf("agents", b.agent))
                }}</small>
              </td>
              <td>{{ $root.tr(b.quantity) }}</td>
              <td>{{ $root.tr(count(b, "Available")) }}</td>
              <td>{{ $root.tr(count(b, "sold")) }}</td>
              <td>{{ $root.tr(count(b, "damage")) }}</td>
              <td>{{ $root.tr(count(b, "Cancelled by Reversal")) }}</td>
              <td>{{ $root.tr(vm.status(b.status)) }}</td>
              <td>
                <div class="actions">
                  <button
                    class="btn small"
                    v-if="vm.can('inventory.details')"
                    @click="vm.inspectBatch(b)"
                  >
                    {{ $root.tr("معاينة") }}</button
                  ><button
                    class="btn small primary"
                    @click="vm.openInventoryManager(b)"
                  >
                    {{ $root.tr("إدارة") }}
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="9" class="empty">
                {{ $root.tr("لا توجد طلبيات مطابقة") }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="actions">
        <button class="btn small" :disabled="page === 1" @click="page--">
          {{ $root.tr("السابق") }}</button
        ><span
          >{{ $root.tr(page) }} /
          {{ $root.tr(Math.max(1, Math.ceil(rows.length / 20))) }}</span
        ><button
          class="btn small"
          :disabled="page*20&gt;=rows.length"
          @click="page++"
        >
          {{ $root.tr("التالي") }}
        </button>
      </div></template
    ><template v-if="vm.inventoryWorkspaceTab === 'copies'"
      ><div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ $root.tr("رقم السجل") }}</th>
              <th>{{ $root.tr("الدفعة") }}</th>
              <th>{{ $root.tr("العدد") }}</th>
              <th>{{ $root.tr("السبب") }}</th>
              <th>{{ $root.tr("الحالة") }}</th>
              <th>{{ $root.tr("الإجراءات") }}</th>
              <th>{{ $root.tr("التاريخ") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in copies">
              <td>{{ $root.tr(r.id) }}</td>
              <td>{{ $root.tr(r.batch) }}</td>
              <td>{{ $root.tr(r.quantity) }}</td>
              <td>
                {{ r.reason
                }}<small v-if="r.rejectReason" class="caption">{{
                  $root.tr(r.rejectReason)
                }}</small>
              </td>
              <td>{{ $root.tr(r.status) }}</td>
              <td>
                <button
                  v-if="!r.rejectReason&amp;&amp;r.cardIds?.length&amp;&amp;vm.can('exports.encrypt')&amp;&amp;vm.can('data.pin')"
                  class="btn small"
                  @click="vm.downloadSupplierReturn(r)"
                >
                  {{ $root.tr("إعادة تنزيل") }}
                </button>
              </td>
              <td>{{ $root.tr(vm.formatTime(r.time)) }}</td>
            </tr>
            <tr v-if="!copies.length">
              <td colspan="7">{{ $root.tr("لا توجد عمليات إرجاع") }}</td>
            </tr>
          </tbody>
        </table>
      </div></template
    >
  </section>
</template>
