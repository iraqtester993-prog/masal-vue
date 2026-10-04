<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="metrics">
    <div class="card metric">
      <div class="metriclabel">{{ tr("بطاقات متاحة للبيع") }}</div>
      <div class="metricvalue">{{ tr(inventoryAvailableCards.length) }}</div>
    </div>
    <div class="card metric">
      <div class="metriclabel">{{ tr("تكلفة البطاقات المتاحة") }}</div>
      <div class="metricvalue">
        {{can('data.cost')?tr(money(inventoryAvailableCards.reduce((n,c)=&gt;n+c.cost,0))):'••••'}}
        <small>{{ tr("د.ع") }}</small>
      </div>
    </div>
    <div class="card metric">
      <div class="metriclabel">{{ tr("بطاقات محجورة أو مصدّرة") }}</div>
      <div class="metricvalue">
        {{tr(inventoryCards.filter(c=&gt;['Quarantined','Exported'].includes(c.status)).length)}}
      </div>
    </div>
    <div class="card metric">
      <div class="metriclabel">{{ tr("ترتيب بيع البطاقات") }}</div>
      <div class="metricvalue" style="font-size: 23px">
        {{ tr("الأقرب انتهاءً أولًا") }}
      </div>
      <div class="metricsub">
        {{ tr("عند تساوي الانتهاء، تُختار البطاقة الأقدم إدخالًا تلقائيًا") }}
      </div>
    </div>
  </div>
  <div class="card">
    <catalog-filter-bar
      class="inventory-catalog-filter"
      page="inventory"
      :key="'inventory-' + currentUser"
      ><template #actions=""><page-actions></page-actions></template
      ><template #trailing=""
        ><div class="tabs inventory-tabs">
          <button
            v-for="t in [
              { id: 'all', label: 'الكل' },
              { id: 'active', label: 'النشطة' },
              { id: 'stopped', label: 'الموقوفة' },
              { id: 'cancelled', label: 'الملغاة' },
            ]"
            :class="{ active: inventoryTab === t.id }"
            @click="
              inventoryTab = t.id;
              statusFilter = '';
            "
          >
            {{ tr(t.label) }}
          </button>
        </div></template
      ></catalog-filter-bar
    >
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ tr("الدفعة") }}</th>
            <th>{{ tr("الوكيل / الفئة") }}</th>
            <th>{{ tr("المتاح للبيع / إجمالي الدفعة") }}</th>
            <th>{{ tr("التكلفة") }}</th>
            <th>{{ tr("الانتهاء") }}</th>
            <th>{{ tr("الحالة") }}</th>
            <th>{{ tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in batchRows">
            <td class="mono">{{ tr(b.id) }}</td>
            <td>
              <strong>{{ tr(nameOf("products", b.product)) }}</strong>
              <div class="caption">{{ tr(nameOf("agents", b.agent)) }}</div>
            </td>
            <td>
              {{tr(inventoryAvailableCards.filter(c=&gt;c.batch===b.id).length)}}
              / {{ tr(b.quantity) }}
            </td>
            <td class="mono">
              {{
                can("data.cost")
                  ? tr(money(b.cost * b.quantity + (+b.expenses || 0)))
                  : "••••"
              }}
            </td>
            <td>{{ tr(b.expiry) }}</td>
            <td>
              <span class="badge" :class="statusClass(b.status)">{{
                tr(status(b.status))
              }}</span>
            </td>
            <td>
              <div class="actions">
                <button
                  class="btn small"
                  v-permit="can('inventory.details')"
                  @click="inspectBatch(b)"
                >
                  {{ tr("معاينة") }}</button
                ><button
                  class="btn small primary"
                  @click="openInventoryManager(b)"
                >
                  {{ $root.tr("إدارة") }}
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!batchRows.length">
            <td colspan="7" class="empty">{{ tr("لا توجد دفعات مطابقة") }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
