<script setup>
import {computed,nextTick,onBeforeUnmount,onMounted,ref,watch} from 'vue';
import '../finance/finance-parity.css';
import './location-gate.css';
const props=defineProps({required:Boolean,ready:Boolean,sharing:Boolean,checking:Boolean,initializing:Boolean,permission:{type:String,default:''},status:{type:String,default:''}}),emit=defineEmits(['enable','logout']);
const automatic=computed(()=>props.initializing||props.sharing||(props.permission==='granted'&&props.checking));
const dialog=ref(null);let alive=true;
async function sync(){await nextTick();if(!alive||!dialog.value)return;const needed=props.required&&!props.ready&&!automatic.value;if(needed&&!dialog.value.open)dialog.value.showModal();else if(!needed&&dialog.value.open)dialog.value.close();}
watch(()=>[props.required,props.ready,automatic.value],sync);onMounted(sync);onBeforeUnmount(()=>{alive=false;dialog.value?.close();});
</script>

<template>
  <Teleport to="body"><dialog ref="dialog" class="finance-modal location-required-dialog" aria-labelledby="location-required-title" @cancel.prevent>
    <div class="location-required-icon" aria-hidden="true">◎</div><h2 id="location-required-title">{{automatic?'جارٍ تحديد الموقع تلقائيًا':'تفعيل الموقع'}}</h2><p>مشاركة موقع جهازك مع الإدارة مطلوبة لاستخدام الحساب وإظهاره على الخريطة.</p><p v-if="status||checking" class="location-required-status" role="status">{{checking?'جارٍ التحقق من الموقع…':status}}</p><p v-if="permission==='denied'">اسمح للموقع من إعدادات المتصفح، وتأكد من تشغيل خدمة الموقع بالجهاز، ثم اضغط تفعيل الموقع.</p><div class="actions"><button v-if="!automatic" class="btn primary" :disabled="sharing||checking" @click="emit('enable')">{{sharing?'جارٍ تحديد الموقع…':'تفعيل الموقع'}}</button><button class="btn" @click="emit('logout')">تسجيل الخروج</button></div>
  </dialog></Teleport>
</template>
