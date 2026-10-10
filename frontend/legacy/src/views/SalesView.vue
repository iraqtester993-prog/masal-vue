<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="card" :class="{ 'sales-log-card': page === 'sales' }">
    <div v-if="page === 'sales'" class="tabs operation-status-tabs">
      <button
        v-for="item in [
          { id: '', name: 'الكل' },
          { id: 'Print Requested', name: 'بانتظار الطباعة' },
          { id: 'Print Failed', name: 'فشل الطباعة' },
          { id: 'Printed', name: 'مطبوعة' },
          { id: 'Reprinted', name: 'أعيدت طباعتها' },
        ]"
        :class="{ active: statusFilter === item.id }"
        @click="statusFilter = item.id"
      >
        {{ tr(item.name) }} ·
        {{ $root.tr(visibleSales.filter(t=&gt;!item.id||t.status===item.id).length) }}
      </button>
    </div>
    <div
      class="toolbar"
      :class="{ 'exceptions-toolbar': page === 'exceptions' }"
    >
      <input
        v-model="search"
        style="max-width: 300px"
        :placeholder="tr('بحث برقم العملية أو نقطة البيع')"
      /><select v-model="statusFilter" style="width: 190px">
        <option value="">{{ tr("جميع الحالات") }}</option>
        <option
          v-for="t in [
            'Printed',
            'Print Requested',
            'Print Failed',
            'Reprinted',
            'Reprint Requested',
          ]"
          :value="t"
        >
          {{ tr(status(t)) }}
        </option></select
      ><page-actions v-if="page === 'exceptions'"></page-actions>
    </div>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ tr("رقم العملية") }}</th>
            <th>{{ tr("التاريخ والوقت") }}</th>
            <th>{{ tr("الوكيل") }}</th>
            <th>{{ tr("نقطة البيع") }}</th>
            <th>{{ tr("المنتج") }}</th>
            <th>{{ tr("الكمية") }}</th>
            <th>{{ tr("الإجمالي · د.ع") }}</th>
            <th>{{ tr("الحالة") }}</th>
            <th>{{ tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in transactionRows">
            <td class="mono">{{ tr(t.id) }}</td>
            <td>{{ tr(formatTime(t.time)) }}</td>
            <td>{{ tr(nameOf("agents", t.agent)) }}</td>
            <td>{{ tr(nameOf("pos", t.pos)) }}</td>
            <td>{{ tr(nameOf("products", t.product)) }}</td>
            <td class="mono">{{ tr(t.quantity) }}</td>
            <td class="mono">{{ tr(money(t.total)) }}</td>
            <td>
              <span class="badge" :class="statusClass(t.status)">{{
                tr(status(t.status))
              }}</span>
            </td>
            <td>
              <div class="actions">
                <button
                  v-if="canViewSaleCards(t)"
                  class="btn small"
                  @click="viewReceipt(t)"
                >
                  {{ tr("الوصل") }}</button
                ><button
                  v-if="canViewSaleCards(t)&amp;&amp;['Print Requested','Reprint Requested'].includes(t.status)"
                  class="btn small"
                  v-permit="can('sell.receipt')"
                  @click="viewReceipt(t)"
                >
                  {{
                    tr(
                      printReady(t)
                        ? "متابعة الطباعة"
                        : "بانتظار موافقة الأعلى",
                    )
                  }}</button
                ><button
                  v-else-if="canViewSaleCards(t)&amp;&amp;['Printed','Print Failed','Reprinted'].includes(t.status)"
                  class="btn small"
                  v-permit="can('sell.reprint')"
                  @click="openReprint(t)"
                >
                  {{ tr("إعادة طباعة") }}</button
                ><button
                  class="btn small"
                  v-permit="can('audit.details')"
                  @click="inspect(t)"
                >
                  {{ tr("السجل") }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="!transactionRows.length" class="empty">
      {{ tr("لا توجد عمليات مطابقة") }}
    </div>
  </div>
</template>
