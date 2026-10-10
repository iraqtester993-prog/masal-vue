<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["pending-order-review", "legacy-history"]);
export default options;
</script>

<template>
  <section v-if="vm.page === 'import'" class="card order-history">
    <div class="order-history-heading">
      <h3>{{ $root.tr("سجل الطلبيات") }}</h3>
      <span class="badge"
        >{{ $root.tr(rows.length) }}{{ $root.tr(" طلبية") }}</span
      ><button class="btn" :aria-expanded="expanded" @click="toggle">
        {{ $root.tr(expanded ? "إغلاق الجدول" : "عرض الجدول") }}
      </button>
    </div>
    <template v-if="expanded"
      ><div class="toolbar">
        <input
          type="search"
          v-model="query"
          :placeholder="$root.tr('بحث برقم الطلبية أو الوكيل أو الفئة')"
          :aria-label="$root.tr('بحث الطلبيات')"
        /><select v-model="status" :aria-label="$root.tr('حالة الطلبية')">
          <option value="">{{ $root.tr("جميع الحالات") }}</option>
          <option value="بانتظار الاعتماد">
            {{ $root.tr("بانتظار الاعتماد") }}
          </option>
          <option value="معتمدة">{{ $root.tr("معتمدة") }}</option>
          <option value="مرفوضة">{{ $root.tr("مرفوضة") }}</option>
        </select>
      </div>
      <div class="tablewrap">
        <table class="order-history-table">
          <thead>
            <tr>
              <th>{{ $root.tr("رقم الطلبية") }}</th>
              <th>{{ $root.tr("تاريخ الإدخال") }}</th>
              <th>{{ $root.tr("الوكيل") }}</th>
              <th>{{ $root.tr("الفئة") }}</th>
              <th>{{ $root.tr("المجهز") }}</th>
              <th>{{ $root.tr("عدد البطاقات") }}</th>
              <th>{{ $root.tr("الإجمالي · د.ع") }}</th>
              <th>{{ $root.tr("الحالة") }}</th>
              <th>{{ $root.tr("الإجراءات") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filtered" :key="r.id">
              <td class="mono">{{ $root.tr(r.id) }}</td>
              <td>{{ $root.tr(vm.formatTime(r.time)) }}</td>
              <td>{{ $root.tr(vm.nameOf("agents", r.agent)) }}</td>
              <td>{{ $root.tr(vm.nameOf("products", r.meta.product)) }}</td>
              <td>{{ $root.tr(r.meta.supplier || "—") }}</td>
              <td>{{ $root.tr(r.quantity) }}</td>
              <td>{{ $root.tr(vm.money(r.quantity * r.meta.loadPrice)) }}</td>
              <td>
                <span
                  class="badge"
                  :class="{
                    warn: r.status === 'بانتظار الاعتماد',
                    neutral: r.status === 'مرفوضة',
                  }"
                  >{{ $root.tr(r.status) }}</span
                >
              </td>
              <td>
                <button
                  class="btn small"
                  :aria-expanded="selected === r.id"
                  @click="selected = selected === r.id ? '' : r.id"
                >
                  {{
                    $root.tr(selected === r.id ? "إغلاق المعاينة" : "معاينة")
                  }}
                </button>
              </td>
            </tr>
            <tr v-if="!filtered.length">
              <td colspan="9" class="empty">
                {{ $root.tr("لا توجد طلبيات مطابقة") }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <section v-if="current" class="order-card-preview">
        <div class="order-history-heading">
          <h3>{{ $root.tr("بطاقات الطلبية · ") }}{{ $root.tr(current.id) }}</h3>
          <button class="btn" @click="selected = ''">
            {{ $root.tr("إغلاق المعاينة") }}
          </button>
        </div>
        <p v-if="current.reason" class="notice warn">
          {{ $root.tr("سبب الرفض: ") }}{{ current.reason }}
        </p>
        <div class="tablewrap">
          <table>
            <thead>
              <tr>
                <th>{{ $root.tr("التسلسل") }}</th>
                <th>{{ $root.tr("السيريال") }}</th>
                <th>{{ $root.tr("الانتهاء") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in current.rows" :key="i">
                <td>{{ $root.tr(i + 1) }}</td>
                <td>{{ r.serial || "—" }}</td>
                <td>{{ $root.tr(r.expiry || current.meta.expiry) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div
          v-if="admin&amp;&amp;vm.can('import.approve')&amp;&amp;current.status==='بانتظار الاعتماد'"
          class="order-review-actions"
        >
          <button class="btn primary" :disabled="busy" @click="approve(true)">
            {{ $root.tr("اعتماد") }}</button
          ><label>{{ $root.tr("سبب الرفض") }}<input v-model="reason" /></label
          ><button
            class="btn danger"
            :disabled="busy || !reason.trim()"
            @click="approve(false)"
          >
            {{ $root.tr("رفض") }}
          </button>
        </div>
      </section></template
    >
  </section>
</template>
