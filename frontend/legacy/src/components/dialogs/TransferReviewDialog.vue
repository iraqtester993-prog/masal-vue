<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="review-amount">
    <span>{{ tr("المبلغ • د.ع") }}</span
    ><strong>{{ tr(money(modal.transfer.amount)) }}</strong>
  </div>
  <div class="rowline">
    <span>{{ tr("المرسل") }}</span
    ><b>{{ tr(accountName(modal.transfer.from)) }}</b>
  </div>
  <div class="rowline">
    <span>{{ tr("المستلم") }}</span
    ><b>{{ tr(accountName(modal.transfer.to)) }}</b>
  </div>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("رجوع") }}</button
    ><button
      class="btn primary"
      @click="confirmTransfer"
      :disabled="formPending.confirmTransfer"
    >
      {{ tr("تأكيد التحويل") }}
    </button>
  </div>
</template>
