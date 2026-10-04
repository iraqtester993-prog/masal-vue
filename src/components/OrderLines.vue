<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions([
  "cash-order-form",
  "multi-form",
  "order-lines",
]);
export default options;
</script>

<template>
  <div>
    <div v-if="lines.some(l=&gt;l.rejected)" class="formfoot">
      <rejected-export :lines="lines"></rejected-export>
    </div>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("الملف") }}</th>
            <th>{{ $root.tr("الفئة") }}</th>
            <th>{{ $root.tr("رمز الفئة") }}</th>
            <th>{{ $root.tr("الإجمالي") }}</th>
            <th>{{ $root.tr("صالحة") }}</th>
            <th>{{ $root.tr("مرفوضة") }}</th>
            <th>{{ $root.tr("السعر") }}</th>
            <th>{{ $root.tr("إجمالي قيمة البطاقات") }}</th>
            <th>{{ $root.tr("رقم دفعة المخزون") }}</th>
            <th>{{ $root.tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in lines" :key="l.id">
            <td>{{ $root.tr(l.name) }}</td>
            <td>{{ $root.tr(vm.nameOf("products", l.product)) }}</td>
            <td>{{ $root.tr(l.categoryCode || "—") }}</td>
            <td>{{ $root.tr(l.rows.length) }}</td>
            <td>{{ $root.tr(l.accepted) }}</td>
            <td>{{ $root.tr(l.rejected) }}</td>
            <td>{{ $root.tr(vm.money(l.meta.loadPrice)) }}</td>
            <td>{{ $root.tr(vm.money(l.accepted * l.meta.loadPrice)) }}</td>
            <td>{{ $root.tr(l.batch || "—") }}</td>
            <td>
              <button
                class="btn small"
                @click="selected = selected === l.id ? '' : l.id"
              >
                {{ $root.tr(selected === l.id ? "إغلاق" : "البطاقات") }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <section v-if="current" class="order-file" data-no-pagination="">
      <header>
        <b>{{ $root.tr(current.name) }}</b
        ><button
          class="btn small"
          :aria-pressed="onlyErrors"
          @click="onlyErrors = !onlyErrors"
        >
          {{ $root.tr(onlyErrors ? "عرض الكل" : "المرفوضة فقط") }}
        </button>
      </header>
      <div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ $root.tr("سطر الملف") }}</th>
              <th>Serial</th>
              <th>Expiry</th>
              <th>{{ $root.tr("نتيجة الفحص") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in visible">
              <td>{{ $root.tr(r.sourceRow) }}</td>
              <td>{{ r.serial }}</td>
              <td>{{ $root.tr(r.expiry) }}</td>
              <td :class="{ 'text-danger': r.error }">
                {{ $root.tr(r.error || "صالح") }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="formfoot">
        <button class="btn small" :disabled="page&lt;=1" @click="page--">
          {{ $root.tr("السابق") }}</button
        ><span
          >{{ $root.tr(page) }} /
          {{ $root.tr(Math.max(1, Math.ceil(rows.length / 25))) }}</span
        ><button
          class="btn small"
          :disabled="page*25&gt;=rows.length"
          @click="page++"
        >
          {{ $root.tr("التالي") }}
        </button>
      </div>
    </section>
  </div>
</template>
