<script setup>
import { computed,onBeforeUnmount,onMounted,reactive,ref } from 'vue';
import { deviceHeartbeatPayload } from './sales-model.js';
const props = defineProps({vm:{type:Object,required:true}});
const device = reactive({serial:'',os_version:'',online:false,expires_at:null,error:'',busy:false,started:false}),clock = ref(Date.now());
let pulse,tick,controller;
const locked=computed(()=>device.device_lock_enabled??props.vm.identity?.value?.account.device_lock_enabled??true);
const online = computed(()=>device.online && device.expires_at && Date.parse(device.expires_at)>clock.value);
async function heartbeat() {
  if (device.busy || document.visibilityState === 'hidden') return;
  device.busy = true; device.error = ''; controller = new AbortController();
  try {const response = await props.vm.api.heartbeat(deviceHeartbeatPayload(device),controller.signal); Object.assign(device,response.data);device.serial=String(device.serial??'');device.os_version=String(device.os_version??''); device.started = true;}
  catch(error) {if(error.name !== 'AbortError'){device.online = false;device.error = await props.vm.failure(error);}}
  finally {device.busy = false;}
}
function visibility() {if(device.started && document.visibilityState === 'visible')heartbeat();}
onMounted(()=>{pulse = setInterval(()=>{if(device.started)heartbeat();},30000); tick=setInterval(()=>clock.value=Date.now(),1000); document.addEventListener('visibilitychange',visibility);if(!locked.value)heartbeat();});
onBeforeUnmount(()=>{clearInterval(pulse);clearInterval(tick);controller?.abort();document.removeEventListener('visibilitychange',visibility);device.online=false;});
</script>
<template>
  <details v-if="vm.pos.value && (locked || device.error)" class="device-settings no-print" :open="!online"><summary>إعدادات جهاز البيع</summary><div class="local-session-prompt" role="status"><div><strong>{{online ? 'جهاز البيع متصل':'التحقق من جهاز البيع'}}</strong><small>{{online ? 'الجلسة مرتبطة بتسجيل دخولك الحالي':'ابدأ الجلسة من الجهاز الذي تستخدمه للبيع والطباعة'}}</small></div><small v-if="!locked">قيد الرقم التسلسلي مطفأ؛ البيع متاح من أي جهاز بعد تسجيل الدخول.</small><label v-if="locked">الرقم التسلسلي المعتمد للجهاز<input v-model="device.serial" autocomplete="off" maxlength="190" :disabled="device.busy" placeholder="إذا كان محددًا في حسابك"></label><label>إصدار النظام<input v-model="device.os_version" :disabled="device.busy" placeholder="إذا كانت الإدارة تشترطه" maxlength="30"></label><button type="button" class="btn" :class="{primary:!online}" :disabled="device.busy" @click="heartbeat">{{device.busy ? 'جارٍ الاتصال…':online ? 'تحديث الجلسة':'بدء جلسة الجهاز'}}</button><p v-if="device.error" class="notice warn">{{device.error}}</p></div></details>
</template>
