<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <meeting-receipt :tx="modal.tx"></meeting-receipt
  ><print-policy-summary :tx="modal.tx"></print-policy-summary>
  <div class="notice no-print" style="margin-top: 18px">
    {{ receiptTr("الحالة: ") }}{{ receiptTr(status(modal.tx.status))
    }}{{
      receiptTr(
        ["Print Requested", "Reprint Requested"].includes(modal.tx.status)
          ? ". نافذة الطباعة لا تؤكد خروج الورقة؛ سجل النتيجة أدناه."
          : ". تم تسجيل نتيجة الطباعة.",
      )
    }}
  </div>
  <div
    v-if="modal.tx.status==='Reprint Requested'&amp;&amp;!printReady(modal.tx)"
    class="notice no-print"
  >
    بانتظار موافقة الأعلى؛ لا يمكن إعادة الطباعة أو تسجيل نتيجتها قبل الاعتماد.
  </div>
  <div
    v-if="modal.failureEditing&amp;&amp;modal.tx.printPending&amp;&amp;printReady(modal.tx)"
    class="no-print"
  >
    <label
      >سبب فشل الطباعة<textarea
        v-model="modal.failureReason"
        required=""
      ></textarea></label
    ><button
      class="btn danger"
      @click="printResult(false)"
      :disabled="!modal.failureReason.trim() || formPending.printResult"
    >
      تسجيل فشل الطباعة
    </button>
  </div>
  <div v-if="modal.tx.status === 'Print Failed'" class="notice no-print">
    <span>{{ modal.tx.printFailureReason }}</span
    ><button class="btn" @click="openReprint(modal.tx)">رفع طلب معالجة</button>
  </div>
  <div class="formfoot no-print">
    <button
      class="btn"
      v-if="printReady(modal.tx)&amp;&amp;can('sell.print')"
      @click="printReceipt"
      :disabled="printStartDisabled(modal.tx)"
    >
      {{ receiptTr("طباعة المتصفح") }}</button
    ><button
      v-if="printReady(modal.tx)&amp;&amp;can('sell.result')"
      class="btn danger"
      @click="modal.failureEditing = !modal.failureEditing"
      :disabled="formPending.printResult || !modal.tx.printPending"
    >
      {{ receiptTr("فشلت الطباعة") }}</button
    ><button
      v-if="printReady(modal.tx)&amp;&amp;can('sell.result')"
      class="btn primary"
      @click="printResult(true)"
      :disabled="formPending.printResult || !modal.tx.printPending"
    >
      {{ receiptTr("تأكيد نجاح الطباعة") }}
    </button>
  </div>
</template>
