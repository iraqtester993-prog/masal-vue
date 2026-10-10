<script setup>
import {computed,inject,onBeforeUnmount,onMounted} from 'vue';
import {useRouter} from 'vue-router';
import {usePortal} from '../auth/session.js';
import {createCompanyApi} from './company-api.js';
import {inquiryNotificationsKey} from './inquiry-notifications.js';
import './inquiries.css';
const notifications = inject(inquiryNotificationsKey), router = useRouter(), {session} = usePortal();
const api = createCompanyApi(session.api);
const unread = computed(() => notifications.state.records.filter(row => row.unread > 0));
const count = computed(() => unread.value.reduce((total,row) => total + row.unread, 0));
let timer, reader, running = false, alive = true;
async function poll() {
  if (!alive || running || document.hidden || !navigator.onLine) return;
  running = true; reader = new AbortController();
  try {
    for (const row of [...notifications.state.records]) {
      if (!alive) break;
      try {const result = await api.track(row.token,reader.signal); if (alive) notifications.accept(row.token,result.data);}
      catch (error) {if (error.status === 404) notifications.forget(row.token);}
    }
  } finally {running = false;}
}
function openReply() {
  const token = unread.value[0]?.token;
  if (!token) return;
  notifications.markRead(token);
  void router.push({name:'companyMessages',hash:`#inquiry=${token}`});
}
onMounted(() => {notifications.restore(); void poll(); timer = setInterval(poll,20000); document.addEventListener('visibilitychange',poll); window.addEventListener('online',poll);});
onBeforeUnmount(() => {alive = false; clearInterval(timer); reader?.abort(); document.removeEventListener('visibilitychange',poll); window.removeEventListener('online',poll);});
</script>
<template><aside v-if="count" class="site-reply-notification" role="status" aria-live="polite"><span class="site-reply-icon" aria-hidden="true">✉</span><div><strong>لديك رد جديد من إدارة الشركة</strong><small>{{count}} رد غير مقروء</small></div><button type="button" class="btn primary" @click="openReply">عرض الرد</button></aside></template>
