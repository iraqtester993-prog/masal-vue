<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="monitor-summary">
    <div v-for="m in monitorMetrics" class="monitor-summary-item">
      <div class="metriclabel">{{ tr(m.label) }}</div>
      <div class="metricvalue">{{ tr(m.value) }}</div>
      <div class="metricsub">{{ tr(m.note) }}</div>
    </div>
  </div>
  <div class="two">
    <div class="card">
      <div v-for="a in alerts" class="rowline">
        <div>
          <b>{{ tr(a.title) }}</b>
          <div class="caption">{{ tr(a.body) }}</div>
        </div>
        <button class="btn small" @click="go(a.page)">{{ tr("فتح") }}</button>
      </div>
    </div>
    <div class="card">
      <div
        v-for="x in [
          'خادم التطبيق',
          'قاعدة البيانات',
          'بوابة API للمزودين',
          'خدمة OTP / TOTP',
          'بوابة طابعات POS',
        ]"
        class="rowline"
      >
        <span>{{ tr(x) }}</span
        ><span class="badge neutral">{{ tr("غير مربوط") }}</span>
      </div>
    </div>
  </div>
  <div class="card">
    <h3>{{ $root.tr("حالة الأجهزة") }}</h3>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("نقطة البيع") }}</th>
            <th>{{ $root.tr("الجهاز") }}</th>
            <th>{{ $root.tr("آخر اتصال") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in visiblePOS" :key="p.id">
            <td>{{ $root.tr(p.name) }}</td>
            <td>{{ p.serial || "—" }}</td>
            <td>{{ $root.tr(p.lastSeen ? formatTime(p.lastSeen) : "—") }}</td>
            <td>{{ $root.tr(p.online ? "متصل" : "غير متصل") }}</td>
          </tr>
          <tr v-if="!visiblePOS.length">
            <td colspan="4" class="empty">
              {{ $root.tr("لا توجد أجهزة ضمن النطاق") }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
