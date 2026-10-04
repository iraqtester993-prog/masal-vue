<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["pending-order-review", "multi-history"]);
export default options;
</script>

<template>
  <section class="card multi-orders">
    <header class="order-history-heading">
      <h3>{{ $root.tr("سجل الطلبيات متعددة الملفات") }}</h3>
      <span class="badge"
        >{{ $root.tr(rows.length) }}{{ $root.tr(" طلبية") }}</span
      ><button class="btn" @click="expanded = !expanded">
        {{ $root.tr(expanded ? "إغلاق الجدول" : "عرض الجدول") }}
      </button>
    </header>
    <template v-if="expanded"
      ><div class="toolbar">
        <input
          v-model="query"
          :placeholder="$root.tr('بحث بالطلبية أو الوكيل أو الفئة')"
        /><select v-model="status">
          <option value="">{{ $root.tr("جميع الحالات") }}</option>
          <option value="بانتظار الاعتماد">
            {{ $root.tr("بانتظار الاعتماد") }}
          </option>
          <option value="معتمدة">{{ $root.tr("معتمدة") }}</option>
          <option value="معادة للتصحيح">{{ $root.tr("معادة للتصحيح") }}</option>
          <option value="مرفوضة">{{ $root.tr("مرفوضة") }}</option>
        </select>
      </div>
      <div class="tablewrap" data-no-pagination="">
        <table>
          <thead>
            <tr>
              <th>{{ $root.tr("الطلبية") }}</th>
              <th>{{ $root.tr("تاريخ الإرسال") }}</th>
              <th>{{ $root.tr("الوكيل") }}</th>
              <th>{{ $root.tr("الملفات") }}</th>
              <th>{{ $root.tr("صالحة / مرفوضة") }}</th>
              <th>{{ $root.tr("القيمة") }}</th>
              <th>{{ $root.tr("الحالة") }}</th>
              <th>{{ $root.tr("الإجراءات") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows.slice((page - 1) * 20, page * 20)" :key="r.id">
              <td>{{ $root.tr(r.id) }}</td>
              <td>{{ $root.tr(vm.formatTime(r.time)) }}</td>
              <td>{{ $root.tr(vm.nameOf("agents", r.agent)) }}</td>
              <td>{{ $root.tr(r.lines.length) }}</td>
              <td>{{ $root.tr(r.quantity) }} / {{ $root.tr(r.rejected) }}</td>
              <td>{{ $root.tr(vm.money(r.amount)) }}</td>
              <td>
                <span class="badge">{{ $root.tr(r.status) }}</span>
              </td>
              <td>
                <div class="actions">
                  <button
                    class="btn small"
                    @click="
                      reviewAction = '';
                      selected = selected === r.id ? '' : r.id;
                    "
                  >
                    {{ $root.tr("معاينة") }}</button
                  ><template
                    v-if="admin&amp;&amp;vm.can('import.approve')&amp;&amp;r.status==='بانتظار الاعتماد'"
                    ><button
                      class="btn small primary"
                      @click="start(r, 'approve')"
                    >
                      {{ $root.tr("اعتماد") }}</button
                    ><button class="btn small" @click="start(r, 'return')">
                      {{ $root.tr("إعادة للتصحيح") }}</button
                    ><button
                      class="btn small danger"
                      @click="start(r, 'reject')"
                    >
                      {{ $root.tr("رفض") }}
                    </button></template
                  ><button
                    v-if="r.user===vm.currentUser&amp;&amp;r.status==='معادة للتصحيح'"
                    class="btn small"
                    @click="edit(r)"
                  >
                    {{ $root.tr("تصحيح") }}</button
                  ><button
                    v-if="r.status==='معتمدة'&amp;&amp;vm.can('inventory.view')"
                    class="btn small"
                    @click="
                      vm.inventoryAgent = r.agent;
                      vm.go('inventory');
                    "
                  >
                    {{ $root.tr("المخزون") }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="!rows.length" class="empty">
        {{ $root.tr("لا توجد طلبيات") }}
      </div>
      <div class="formfoot">
        <button class="btn small" :disabled="page&lt;=1" @click="page--">
          {{ $root.tr("السابق") }}</button
        ><span
          >{{ $root.tr(page) }} /
          {{ $root.tr(Math.max(1, Math.ceil(rows.length / 20))) }}</span
        ><button
          class="btn small"
          :disabled="page*20&gt;=rows.length"
          @click="page++"
        >
          {{ $root.tr("التالي") }}
        </button>
      </div>
      <section v-if="current" class="order-file" ref="reviewPanel">
        <header>
          <h3>{{ $root.tr(current.id) }}</h3>
          <button class="btn small" @click="selected = ''">
            {{ $root.tr("إغلاق المعاينة") }}
          </button>
        </header>
        <div v-if="current.reason" class="notice warn">
          {{ current.reason }}
        </div>
        <div class="order-metrics">
          <span
            >{{ $root.tr(vm.nameOf("providers", current.provider)) }} ·
            {{ $root.tr(current.meta.supplier) }}</span
          ><span>{{ $root.tr(current.city) }}</span>
        </div>
        <order-lines :lines="current.lines"></order-lines>
        <details>
          <summary>{{ $root.tr("سجل المتابعة") }}</summary>
          <div v-for="e in current.events" class="order-metrics">
            <span>{{ $root.tr(e.action) }}</span
            ><span>{{ $root.tr(vm.nameOf("users", e.user)) }}</span
            ><span>{{ $root.tr(vm.formatTime(e.time)) }}</span
            ><span>{{ e.reason }}</span>
          </div>
        </details>
        <div
          v-if="reviewAction&amp;&amp;admin&amp;&amp;vm.can('import.approve')&amp;&amp;current.status==='بانتظار الاعتماد'"
          class="formfoot"
        >
          <input
            v-if="reviewAction !== 'approve'"
            v-model="reason"
            :placeholder="$root.tr('سبب الإعادة أو الرفض')"
            :aria-label="$root.tr('سبب مراجعة الطلبية')"
          /><button
            class="btn primary"
            :disabled="busy||(reviewAction!=='approve'&amp;&amp;!reason.trim())"
            @click="review(reviewAction)"
          >
            {{
              $root.tr(
                reviewAction === "approve"
                  ? "تأكيد الاعتماد"
                  : reviewAction === "return"
                    ? "تأكيد الإعادة"
                    : "تأكيد الرفض",
              )
            }}</button
          ><button class="btn" @click="reviewAction = ''">
            {{ $root.tr("إلغاء") }}
          </button>
        </div>
        <button
          v-if="current.user===vm.currentUser&amp;&amp;current.status==='معادة للتصحيح'"
          class="btn primary"
          @click="edit(current)"
        >
          {{ $root.tr("تصحيح وإعادة إرسال") }}
        </button>
      </section></template
    >
  </section>
</template>
