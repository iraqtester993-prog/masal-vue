<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>
<template>
  <template v-if="dashboardDetail"
    ><div class="notice">
      <b
        >{{ tr(dashboardDetail.title) }}:
        {{
          $root.tr(
            dashboardDetail.value === null ? "—" : money(dashboardDetail.value),
          )
        }}
        {{ tr(dashboardDetail.unit) }}</b
      >
      <p>{{ tr(dashboardDetail.note) }}</p>
    </div>
    <p v-if="dashboardDetail.explanation">
      {{ tr(dashboardDetail.explanation) }}
    </p>
    <template v-if="dashboardDetail.balanceRows"
      ><div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ tr("الوكيل") }}</th>
              <th v-if="can('data.cost')">{{ tr("المبلغ المصروف") }}</th>
              <th>{{ tr("الحساب") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in dashboardDetail.balanceRows" :key="r.agent">
              <td>{{ tr(r.name) }}</td>
              <td v-if="can('data.cost')">
                {{ r.spent === null ? "—" : money(r.spent) + " " + tr("د.ع") }}
              </td>
              <td>
                <button class="btn small" @click="openTopupAgent(r.agent)">
                  {{ tr("عرض حساب الوكيل") }}
                </button>
              </td>
            </tr>
            <tr v-if="!dashboardDetail.balanceRows.length">
              <td colspan="3" class="empty">
                {{ tr("لا يوجد وكلاء مربوطون بخدمة Topup") }}
              </td>
            </tr>
          </tbody>
        </table>
      </div></template
    ><template v-else
      ><div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ tr("البيان") }}</th>
              <th>{{ tr("القيمة") }}</th>
              <th>{{ tr("الحالة") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in dashboardDetail.rows.slice(0, 5)" :key="i">
              <td>{{ tr(r.name) }}</td>
              <td>
                {{ $root.tr(r.value === null ? "—" : money(r.value)) }}
                {{ tr(r.unit) }}
              </td>
              <td>{{ tr(r.note || "—") }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="dashboardDetail.rows.length&gt;5" class="caption">
        {{ tr("عرض 5 سجلات؛ بقية التفاصيل في الصفحة الأصلية") }}
      </p>
      <div v-if="!dashboardDetail.rows.length" class="empty">
        {{ tr("لا توجد بيانات") }}
      </div></template
    ></template
  >
  <div v-else="" class="empty">
    {{ tr("لا توجد بيانات متاحة ضمن صلاحياتك") }}
  </div>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("إغلاق") }}</button
    ><button
      v-if="dashboardDetail"
      class="btn primary"
      @click="openDashboardPage"
    >
      {{ tr("الانتقال إلى الصفحة") }}
    </button>
  </div>
</template>
