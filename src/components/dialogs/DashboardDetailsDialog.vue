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
    <div class="tablewrap">
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
