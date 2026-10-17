<script setup>
import {computed} from 'vue';
import {receiptQr,rechargeQrPreview} from './recharge-qr.js';
const props=defineProps({pin:{type:String,default:''},template:{type:String,default:''},value:{type:Object,default:null},preview:Boolean});
const qr=computed(()=>props.preview?rechargeQrPreview(props.template):props.value??receiptQr(props.pin,props.template));
</script>
<template>
  <img v-if="qr" class="receipt-recharge-qr" :src="qr.dataUrl" :width="qr.width" :height="qr.width"
    :data-native-width-pixels="qr.width" :style="{width:`${qr.width/8}mm`}" :alt="preview?'نموذج رمز QR للتعبئة':qr.kind==='pin'?'رمز QR لعرض البن':'رمز QR لفتح التعبئة في الاتصال على الأجهزة الداعمة'">
  <small v-if="qr&&preview">نموذج QR — غير صالح للتعبئة</small>
</template>
<style scoped>
.receipt-recharge-qr{display:block;height:auto;max-width:100%;margin:8px auto;background:#fff;image-rendering:pixelated}
</style>
