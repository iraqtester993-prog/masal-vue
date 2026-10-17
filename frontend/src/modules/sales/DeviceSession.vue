<script setup>
import {onMounted} from 'vue';
import {usePortal} from '../auth/session.js';
import {posDeviceState,ensurePosDeviceSession} from '../../shared/pos-device-session.js';
defineProps({vm:{type:Object,required:true}});
const {session}=usePortal();
function retry(){if(session.state.identity?.account.type==='pos')ensurePosDeviceSession(session.api,session.state.identity.account.id,{force:true}).catch(()=>{});}
onMounted(()=>{if(session.state.identity?.account.type==='pos')ensurePosDeviceSession(session.api,session.state.identity.account.id).catch(()=>{});});
</script>
<template><div v-if="vm.pos.value&&posDeviceState.data?.device_lock_enabled===true&&(posDeviceState.error||posDeviceState.data?.allowed===false)" class="notice warn no-print" role="status"><span>{{posDeviceState.error||'هذا الجهاز بانتظار اعتماد الوكيل أو مدير النظام. تقدر تتابع حالته من المزيد ← الجهاز.'}}</span> <button type="button" class="btn small" :disabled="posDeviceState.busy" @click="retry">{{posDeviceState.busy?'جارٍ التحقق…':'تحقق من الاعتماد'}}</button></div></template>
