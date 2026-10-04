<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <p class="help">{{ tr("اختر الإجراء") }}</p>
  <div class="quick-grid">
    <button class="quick-tile" @click="quickAction('sell')">
      <span aria-hidden="true">▣</span><b>{{ tr("＋ عملية بيع") }}</b></button
    ><button
      v-if="can('import.view')"
      class="quick-tile"
      @click="quickAction('import')"
    >
      <span aria-hidden="true">↥</span><b>{{ tr("＋ طلبية جديدة") }}</b></button
    ><button
      v-if="can('agents.create')"
      class="quick-tile"
      @click="quickAction('agents', true)"
    >
      <span aria-hidden="true">◇</span><b>{{ tr("إضافة وكيل") }}</b></button
    ><button
      v-if="can('pos.create')"
      class="quick-tile"
      @click="quickAction('pos', true)"
    >
      <span aria-hidden="true">▤</span><b>{{ tr("إضافة نقطة بيع") }}</b></button
    ><button
      class="quick-tile"
      v-permit="can('support.create')"
      @click="openTicket"
    >
      <span aria-hidden="true">☏</span><b>{{ tr("＋ رسالة جديدة") }}</b></button
    ><button
      v-if="can('wallets.deposit')"
      class="quick-tile"
      v-permit="can('wallets.deposit')"
      @click="openDeposit"
    >
      <span aria-hidden="true">＋</span><b>{{ tr("＋ إيداع") }}</b>
    </button>
  </div>
</template>
