<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["price-history"]);
export default options;
</script>

<template>
  <section class="card price-history" style="margin-top: 20px">
    <h3>{{ $root.tr("سجل تغييرات الأسعار") }}</h3>
    <div class="toolbar">
      <input
        v-model="query"
        type="search"
        :placeholder="$root.tr('بحث بالعملية أو الفئة أو المستخدم')"
        :aria-label="$root.tr('بحث في سجل الأسعار')"
      /><select v-model="state" :aria-label="$root.tr('حالة تغيير السعر')">
        <option value="">{{ $root.tr("جميع الحالات") }}</option>
        <option value="معتمد">{{ $root.tr("معتمد") }}</option>
        <option value="قيد المراجعة">{{ $root.tr("قيد المراجعة") }}</option>
        <option value="تم التراجع">{{ $root.tr("تم التراجع") }}</option></select
      ><label
        >{{ $root.tr("من")
        }}<input
          type="date"
          v-model="from"
          :aria-label="$root.tr('سجل الأسعار من تاريخ')" /></label
      ><label
        >{{ $root.tr("إلى")
        }}<input
          type="date"
          v-model="to"
          :aria-label="$root.tr('سجل الأسعار إلى تاريخ')"
      /></label>
    </div>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("رقم العملية") }}</th>
            <th>{{ $root.tr("التاريخ والوقت") }}</th>
            <th>{{ $root.tr("الوكيل") }}</th>
            <th>{{ $root.tr("الفئة") }}</th>
            <th>{{ $root.tr("السعر السابق · د.ع") }}</th>
            <th>{{ $root.tr("السعر الجديد · د.ع") }}</th>
            <th>{{ $root.tr("الفرق · د.ع") }}</th>
            <th>{{ $root.tr("نفّذ التعديل") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
            <th v-if="showActions">{{ $root.tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in rows" :key="c.key">
            <td class="mono">{{ $root.tr(c.request.id) }}</td>
            <td>{{ $root.tr(vm.formatTime(c.request.time)) }}</td>
            <td>{{ $root.tr(vm.nameOf("agents", c.agent)) }}</td>
            <td>{{ $root.tr(vm.nameOf("products", c.product)) }}</td>
            <td>{{ $root.tr(c.old == null ? "—" : vm.money(c.old)) }}</td>
            <td>{{ $root.tr(vm.money(c.price)) }}</td>
            <td dir="ltr">
              {{ $root.tr(c.old == null ? "—" : vm.money(c.price - c.old)) }}
            </td>
            <td>{{ $root.tr(vm.nameOf("users", c.request.creator)) }}</td>
            <td>
              <span
                class="badge"
                :class="{
                  warn: c.request.status === 'قيد المراجعة',
                  neutral: c.request.status !== 'معتمد',
                }"
                >{{ $root.tr(c.request.status) }}</span
              >
            </td>
            <td v-if="showActions">
              <div class="actions">
                <button
                  v-if="c.request.status==='قيد المراجعة'&amp;&amp;vm.can('prices.approve')"
                  class="btn small primary"
                  @click="vm.approvePrices(c.request)"
                >
                  {{ $root.tr("اعتماد") }}</button
                ><button
                  v-if="c.request.status==='معتمد'&amp;&amp;vm.can('prices.reverse')"
                  class="btn small"
                  @click="vm.reversePrices(c.request)"
                >
                  {{ $root.tr("تراجع") }}</button
                ><span
                  v-if="!(c.request.status==='قيد المراجعة'&amp;&amp;vm.can('prices.approve'))&amp;&amp;!(c.request.status==='معتمد'&amp;&amp;vm.can('prices.reverse'))"
                  >—</span
                >
              </div>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td :colspan="showActions ? 10 : 9" class="empty">
              {{ $root.tr("لا توجد تغييرات أسعار مطابقة") }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
