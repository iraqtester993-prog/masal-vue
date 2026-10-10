<script setup>
import {ref} from 'vue';
import {usePortal} from '../auth/session.js';
import SupportPhoneLinks from './SupportPhoneLinks.vue';
import {attachmentUrl,ticketStatuses,time} from './communications.js';
defineProps({ticket:{type:Object,default:null},counterpart:{type:Object,default:() => ({name:'',phones:[]})},modelValue:{type:String,default:''},busy:Boolean,loading:Boolean});
const emit = defineEmits(['update:modelValue','reply','status','refresh']);
const {session} = usePortal(), stream = ref(null);
defineExpose({scrollToEnd() {if (stream.value) stream.value.scrollTop = stream.value.scrollHeight;}});
</script>
<template>
  <section class="support-chat-detail" aria-label="المحادثة المفتوحة" :aria-busy="loading">
    <template v-if="ticket">
      <header><h3>{{ticket.title}}</h3><div>{{ticket.origin_name}} ← {{ticket.recipient_name}}</div><div class="actions"><span class="badge">{{ticketStatuses[ticket.status] || ticket.status}}</span><button v-if="ticket.can_close && session.can('support.close')" type="button" class="btn small" :disabled="busy" @click="emit('status','close')">إغلاق المحادثة</button><button v-if="ticket.can_escalate && session.can('support.escalate')" type="button" class="btn small" :disabled="busy" @click="emit('status','escalate')">تصعيد</button><button type="button" class="btn small" :disabled="busy || loading" @click="emit('refresh')">تحديث المحادثة</button></div><SupportPhoneLinks :name="counterpart.name" :phones="counterpart.phones"/></header>
      <div ref="stream" class="support-message-stream" aria-live="polite"><article v-for="message in ticket.messages" :key="message.id" class="support-message" :class="{mine:message.mine}"><b>{{message.mine ? 'أنت' : message.name}}</b><p dir="auto">{{message.body}}</p><img v-if="message.attachment_id" :src="attachmentUrl(message.attachment_id)" alt="مرفق الرسالة" loading="lazy"><time>{{time(message.time)}}</time></article></div>
      <form v-if="ticket.can_reply && session.can('support.reply')" class="support-chat-reply" @submit.prevent="emit('reply')"><textarea :value="modelValue" aria-label="الرد على المحادثة" placeholder="اكتب ردك…" required maxlength="10000" :disabled="busy" @input="emit('update:modelValue',$event.target.value)"></textarea><button class="btn primary" :disabled="busy || !modelValue.trim()">إرسال الرد</button></form><p v-else class="caption">{{ticket.status === 'closed' ? 'المحادثة مغلقة' : 'بانتظار رد الجهة المسؤولة'}}</p>
    </template>
    <p v-else-if="loading" class="read-loading empty" role="status">جارٍ تحميل المحادثة…</p><p v-else class="empty">اختر محادثة لعرض الرسائل والرد عليها</p>
  </section>
</template>
