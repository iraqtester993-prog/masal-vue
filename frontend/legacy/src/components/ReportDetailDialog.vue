<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["report-detail-dialog"]);
export default options;
</script>

<template>
  <div class="report-detail-dialog" data-no-pagination="">
    <div class="report-table-tools toolbar">
      <input
        v-model="vm.reportSearch"
        :placeholder="vm.tr('بحث داخل التقرير')"
        :aria-label="vm.tr('بحث داخل التقرير')"
      />
      <details class="column-picker">
        <summary class="btn">{{ $root.tr("الأعمدة") }}</summary>
        <div>
          <label
            v-for="c in vm.reportActive?.columns || []"
            class="inline-check"
            ><input
              type="checkbox"
              :checked="!vm.reportHidden.includes(c.key)"
              @change="vm.toggleReportColumn(c.key)"
            />{{ $root.tr(c.label) }}</label
          >
        </div>
      </details>
      <span class="badge"
        >{{ $root.tr(vm.reportFilteredRows.length)
        }}{{ $root.tr(" سجل") }}</span
      ><span class="badge">{{
        $root.tr(vm.reportActive?.snapshot ? "الوضع الحالي" : "حركات الفترة")
      }}</span
      ><select
        v-model.number="pageSize"
        :aria-label="$root.tr('عدد السجلات في الصفحة')"
      >
        <option :value="20">20</option>
        <option :value="50">50</option>
        <option :value="100">100</option></select
      ><button
        v-if="vm.can('reports.export')"
        class="btn"
        @click="vm.downloadReportDocument()"
      >
        {{ $root.tr("تصدير Excel") }}</button
      ><button
        v-if="vm.can('reports.print')"
        class="btn"
        @click="vm.printReport()"
      >
        {{ $root.tr("طباعة / حفظ PDF") }}
      </button>
    </div>
    <section
      v-if="selected"
      class="report-record-panel"
      :aria-label="$root.tr('تفاصيل السجل')"
    >
      <div class="cardhead">
        <h3>{{ $root.tr("تفاصيل السجل") }}</h3>
        <button class="btn small" @click="selected = null">
          {{ $root.tr("إغلاق التفاصيل") }}
        </button>
      </div>
      <dl class="report-record-fields">
        <div v-for="field in fields" :key="field.key">
          <dt>{{ $root.tr(field.label) }}</dt>
          <dd>{{ $root.tr(field.value) }}</dd>
        </div>
      </dl>
      <section
        v-for="section in related"
        :key="section.id"
        class="report-linked"
      >
        <div class="cardhead">
          <h4>{{ $root.tr(section.title) }}</h4>
          <span class="badge"
            >{{ $root.tr(section.rows.length) }}{{ $root.tr(" سجل") }}</span
          >
        </div>
        <div class="tablewrap">
          <table>
            <thead>
              <tr>
                <th v-for="c in section.columns">{{ $root.tr(c.label) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in section.rows.slice(0, 20)">
                <td v-for="c in section.columns">
                  {{ $root.tr(vm.reportCell(row[c.key], c)) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <button
          v-if="section.rows.length&gt;20"
          class="btn small"
          @click="vm.openReportDetails(section.id)"
        >
          {{ $root.tr("فتح التقرير الكامل") }}
        </button>
      </section>
    </section>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th v-for="c in vm.reportColumns">
              <button class="table-sort" @click="vm.sortReport(c.key)">
                {{ $root.tr(c.label) }}
                {{
                  $root.tr(
                    vm.reportSort.key === c.key
                      ? vm.reportSort.direction === 1
                        ? "↑"
                        : "↓"
                      : "",
                  )
                }}
              </button>
            </th>
            <th>{{ $root.tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in shown" :key="i">
            <td
              v-for="c in vm.reportColumns"
              :class="{ mono: ['money', 'number'].includes(c.type) }"
            >
              <span
                v-if="c.key === 'status'"
                class="badge"
                :class="vm.statusClass(row[c.key])"
                >{{ $root.tr(vm.reportCell(row[c.key], c)) }}</span
              ><template v-else="">{{
                $root.tr(vm.reportCell(row[c.key], c))
              }}</template>
            </td>
            <td>
              <div class="actions">
                <button
                  class="btn small report-show-record"
                  @click="showRecord(row)"
                >
                  {{ $root.tr("التفاصيل") }}</button
                ><template
                  v-if="vm.reportActive.id==='sales'&amp;&amp;vm.reportSale(row)"
                  ><button
                    v-if="vm.canViewSaleCards(vm.reportSale(row))"
                    class="btn small"
                    @click="vm.reportRecordAction(row, 'receipt')"
                  >
                    {{ $root.tr("الوصل") }}</button
                  ><button
                    v-if="vm.canViewSaleCards(vm.reportSale(row))&amp;&amp;vm.can('sell.reprint')&amp;&amp;['Printed','Print Failed','Reprinted'].includes(vm.reportSale(row).status)"
                    class="btn small"
                    @click="vm.reportRecordAction(row, 'reprint')"
                  >
                    {{ $root.tr("إعادة طباعة") }}
                  </button></template
                >
              </div>
            </td>
          </tr>
          <tr v-if="!shown.length">
            <td :colspan="vm.reportColumns.length + 1" class="empty">
              {{ $root.tr("لا توجد سجلات ضمن الفلاتر الحالية") }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="toolbar report-pagination">
      <button class="btn small" :disabled="page&lt;=1" @click="page--">
        {{ $root.tr("السابق") }}</button
      ><span>{{ $root.tr(page) }} / {{ $root.tr(pages) }}</span
      ><button class="btn small" :disabled="page&gt;=pages" @click="page++">
        {{ $root.tr("التالي") }}
      </button>
    </div>
    <div class="formfoot">
      <button
        v-if="vm.reportDestination"
        class="btn primary"
        @click="vm.goToReportDestination()"
      >
        {{ $root.tr(vm.reportDestination.label) }}</button
      ><button class="btn" @click="vm.closeModal()">
        {{ $root.tr("رجوع إلى التقارير") }}
      </button>
    </div>
  </div>
</template>
