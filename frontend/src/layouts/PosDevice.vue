<script setup>
import {onMounted} from 'vue';
import CatalogModal from '../modules/catalog/CatalogModal.vue';
import {usePortal} from '../modules/auth/session.js';
import {posDeviceState as state,ensurePosDeviceSession} from '../shared/pos-device-session.js';
import './pos-about.css';
defineEmits(['close']);const {session}=usePortal();
function refresh(){ensurePosDeviceSession(session.api,session.state.identity.account.id,{force:true}).catch(()=>{});}
onMounted(refresh);
</script>
<template><CatalogModal title="الجهاز" content-class="pos-info-dialog" @close="$emit('close')"><div class="pos-info-body"><p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p><div class="pos-info-card"><h3>{{state.data?.device?.model||'جهاز نقطة البيع'}}</h3><p class="pos-info-copy">{{state.data?.device?.manufacturer}}</p><p class="pos-info-copy">{{state.data?.device_lock_enabled?(state.data?.device?.status==='approved'?'الجهاز معتمد':state.data?.device?.status==='revoked'?'تم إلغاء اعتماد الجهاز':'بانتظار اعتماد الوكيل أو مدير النظام'):'التقييد مطفأ · البيع متاح من هذا الجهاز'}}</p></div><dl v-if="state.data?.device" class="pos-own-device"><div><dt>معرّف الجهاز</dt><dd dir="ltr">{{state.data.device.device_id}}</dd></div><div v-if="state.data.device.serial"><dt>الرقم التسلسلي</dt><dd dir="ltr">{{state.data.device.serial}}</dd></div><div><dt>إصدار النظام</dt><dd>{{state.data.device.os_version||'غير متاح'}}</dd></div><div><dt>إصدار التطبيق</dt><dd>{{state.data.device.app_version}}</dd></div></dl><button type="button" class="btn primary" :disabled="state.busy" @click="refresh">{{state.busy?'جارٍ التحقق…':state.data?.device_lock_enabled?'تحقق من الاعتماد':'تحديث معلومات الجهاز'}}</button></div></CatalogModal></template>
<style scoped>.pos-own-device{display:grid;gap:16px;margin:20px 0;padding:16px;border:1px solid var(--line);border-radius:16px}.pos-own-device>div{display:grid;gap:7px}.pos-own-device dt{color:var(--muted);font-size:12px}.pos-own-device dd{margin:0;color:var(--ink);font-size:15px;overflow-wrap:anywhere}</style>
