<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="notice">
    {{ $root.tr("المستلم الحالي: ")
    }}{{ $root.tr(engine.supportName(modal.ticket.recipient || "@owner")) }}
  </div>
  <p>{{ tr(modal.ticket.description) }}</p>
  <div v-for="h in modal.ticket.history || []" class="caption">
    {{ $root.tr(h.status) }}: {{ $root.tr(engine.supportName(h.from)) }} ←
    {{ $root.tr(engine.supportName(h.to)) }} ·
    {{ $root.tr(formatTime(h.time)) }}
  </div>
  <img
    v-if="modal.ticket.image"
    :src="modal.ticket.image"
    style="max-width: 100%; max-height: 200px"
  />
  <div v-for="r in engine.visibleSupportReplies(modal.ticket)" class="notice">
    <b>{{ tr(nameOf("users", r.user)) }}</b
    ><br />{{ tr(r.body) }}
    <div class="caption">{{ tr(formatTime(r.time)) }}</div>
  </div>
  <form
    v-if="engine.supportReplyAllowed(modal.ticket)"
    v-permit="can('support.reply')"
    @submit.prevent="replyTicket"
  >
    <label
      >{{ tr("الرد") }}<textarea v-model="reply" required=""></textarea>
    </label>
    <div class="formfoot">
      <button
        type="button"
        class="btn"
        v-if="engine.supportCanManage(modal.ticket)&amp;&amp;engine.supportParent(modal.ticket.recipient)"
        v-permit="can('support.escalate')"
        @click="ticketStatus('مصعّدة')"
      >
        {{ tr("تصعيد للمستوى الأعلى") }}</button
      ><button
        type="button"
        class="btn"
        v-if="engine.supportCanManage(modal.ticket)"
        v-permit="can('support.close')"
        @click="ticketStatus('مغلقة')"
      >
        {{ tr("إغلاق") }}</button
      ><button class="btn primary">{{ tr("إرسال الرد") }}</button>
    </div>
  </form>
</template>
