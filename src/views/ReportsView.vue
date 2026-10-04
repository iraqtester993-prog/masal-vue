<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="card report-export-bar report-unified-toolbar">
    <div class="report-toolbar-title">
      <h2 v-if="selectedReportGroup">{{ tr(selectedReportGroup.title) }}</h2>
      <span v-else="" class="help"
        >{{ tr("التقارير حسب الفترة والجهة المختارة") }} ·
        {{ $root.tr(reportSections.length) }} {{ tr("أقسام التقرير") }}</span
      >
    </div>
    <div class="actions">
      <button
        v-if="selectedReportGroup"
        class="btn"
        @click="reportOpenGroup = ''"
      >
        {{ $root.tr("رجوع إلى المجموعات") }}</button
      ><button class="btn primary" @click="openReportSettings">
        {{ tr("إعداد التقرير") }}</button
      ><button
        v-if="can('reports.export')"
        class="btn"
        @click="downloadReportDocument"
      >
        {{ tr("تنزيل التقرير المنسق") }} · Excel</button
      ><button v-if="can('reports.print')" class="btn" @click="printReport">
        {{ tr("طباعة / حفظ PDF") }}
      </button>
    </div>
  </div>
  <div v-if="reportBundle.error" class="notice warn" role="alert">
    {{ tr(reportBundle.error) }}
  </div>
  <template v-else=""
    ><div v-if="!selectedReportGroup" class="report-group-grid">
      <article
        v-for="g in reportGroups"
        :key="g.id"
        class="card report-group-card"
        :class="[
          'report-group-' + g.id,
          { selected: reportOpenGroup === g.id },
        ]"
        role="button"
        tabindex="0"
        @click="toggleReportGroup(g.id)"
        @keydown.enter="toggleReportGroup(g.id)"
        @keydown.space.prevent="toggleReportGroup(g.id)"
      >
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.7"
        >
          <path :d="navIconPath(g.icon)"></path></svg
        ><strong>{{ tr(g.title) }}</strong
        ><span>{{ tr(g.note) }}</span
        ><small>{{ $root.tr(g.sections.length) }} {{ tr("تقارير") }}</small
        ><button
          class="btn small report-group-action"
          type="button"
          @click.stop="toggleReportGroup(g.id)"
        >
          {{ tr("عرض التفاصيل") }} <span aria-hidden="true">←</span>
        </button>
      </article>
    </div>
    <section
      v-if="selectedReportGroup"
      id="report-group-content"
      class="report-related"
      :class="'report-group-' + selectedReportGroup.id"
      :aria-label="tr(selectedReportGroup.title)"
    >
      <div class="report-related-grid">
        <article
          v-for="section in selectedReportGroup.cards"
          :key="section.id + section.filter"
          class="card report-related-card report-tile"
        >
          <div class="report-tile-heading">
            <span class="report-tile-icon" aria-hidden="true"
              ><svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
              >
                <path :d="navIconPath(selectedReportGroup.icon)"></path></svg
            ></span>
            <h3>{{ tr(section.title) }}</h3>
          </div>
          <div class="report-tile-footer">
            <div class="report-tile-count">
              <strong>{{ $root.tr(money(section.count)) }}</strong
              ><small>{{ tr("سجل") }}</small>
            </div>
            <button
              class="btn small report-tile-open"
              :aria-label="tr('عرض التفاصيل') + ' — ' + tr(section.title)"
              @click="
                openReportDetails(section.id, section.filter, section.title)
              "
            >
              <span>{{ tr("عرض التفاصيل") }}</span
              ><svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
              >
                <path d="M19 12H5m6-6-6 6 6 6"></path>
              </svg>
            </button>
          </div>
        </article>
      </div>
    </section>
    <div v-if="!reportGroups.length" class="card empty">
      {{ tr("لا توجد أقسام تقارير مسموحة لهذا الحساب") }}
    </div></template
  >
</template>
