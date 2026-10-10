<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="sale-detail-banner">
    <span class="badge" :class="statusClass(modal.data.status)">{{
      tr(status(modal.data.status))
    }}</span
    ><span class="mono">{{ $root.tr(modal.data.id) }}</span>
  </div>
  <div
    v-if="canViewSaleCards(modal.data)&amp;&amp;['Print Requested','Reprint Requested'].includes(modal.data.status)"
    class="notice"
  >
    {{
      tr(
        "البطاقات أُصدرت والمبلغ خُصم. نتيجة الطباعة لم تُسجّل بعد؛ افتح الوصل لمتابعة الطباعة وتسجيل نتيجتها.",
      )
    }}
  </div>
  <dl class="account-fields">
    <div>
      <dt>{{ tr("نقطة البيع") }}</dt>
      <dd>{{ $root.tr(nameOf("pos", modal.data.pos)) }}</dd>
    </div>
    <div>
      <dt>{{ tr("الوكيل") }}</dt>
      <dd>{{ $root.tr(nameOf("agents", modal.data.agent)) }}</dd>
    </div>
    <div>
      <dt>{{ tr("الفئة") }}</dt>
      <dd>{{ $root.tr(nameOf("products", modal.data.product)) }}</dd>
    </div>
    <div>
      <dt>{{ tr("وقت العملية") }}</dt>
      <dd>{{ $root.tr(formatTime(modal.data.time)) }}</dd>
    </div>
    <div>
      <dt>{{ tr("عدد البطاقات") }}</dt>
      <dd>{{ $root.tr(modal.data.quantity) }}</dd>
    </div>
    <div>
      <dt>{{ tr("سعر البطاقة") }}</dt>
      <dd>{{ $root.tr(money(modal.data.price)) }} {{ tr("د.ع") }}</dd>
    </div>
    <div>
      <dt>{{ tr("المبلغ المخصوم") }}</dt>
      <dd>{{ $root.tr(money(modal.data.total)) }} {{ tr("د.ع") }}</dd>
    </div>
    <div v-if="can('data.cost')">
      <dt>{{ tr("تكلفة البطاقات") }}</dt>
      <dd>{{ $root.tr(money(modal.data.cost)) }} {{ tr("د.ع") }}</dd>
    </div>
    <div v-if="can('data.profit')">
      <dt>{{ $root.tr("ربح الوكيل على البطاقات الصادرة") }}</dt>
      <dd>{{ $root.tr(saleAgentProfit(modal.data)) }}</dd>
    </div>
    <div v-if="can('data.profit')">
      <dt>{{ $root.tr("ربح نقطة البيع") }}</dt>
      <dd>{{ $root.tr(money(modal.data.posProfit || 0)) }}</dd>
    </div>
    <div>
      <dt>{{ tr("عدد طلبات إعادة الطباعة") }}</dt>
      <dd>{{ $root.tr(modal.data.reprints || 0) }}</dd>
    </div>
  </dl>
  <h3>{{ tr("سجل الطباعة") }}</h3>
  <div v-if="!modal.data.attempts?.length" class="empty">
    {{ tr("لم تُسجّل نتيجة طباعة حتى الآن") }}
  </div>
  <ol v-else="" class="sale-print-history">
    <li v-for="(attempt, i) in modal.data.attempts" :key="i">
      <b>{{ tr(status(attempt.status)) }}</b
      ><span
        >{{ $root.tr(formatTime(attempt.time)) }} ·
        {{ $root.tr(nameOf("users", attempt.user)) }} ·
        {{ $root.tr(nameOf("pos", attempt.device)) }}</span
      >
      <p v-if="attempt.reason">{{ tr("السبب") }}: {{ attempt.reason }}</p>
    </li>
  </ol>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("إغلاق") }}</button
    ><button
      v-if="canViewSaleCards(modal.data)"
      class="btn primary"
      @click="viewReceipt(s.sales.find(t=&gt;t.id===modal.data.id))"
    >
      {{
        tr(
          ["Print Requested", "Reprint Requested"].includes(modal.data.status)
            ? "متابعة الطباعة"
            : "عرض الوصل",
        )
      }}
    </button>
  </div>
</template>
