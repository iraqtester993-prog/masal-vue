<script setup>
import {onMounted,onBeforeUnmount,ref} from 'vue';
import {requiresNativePrinter} from '../shared/native-print.js';
const prompt=ref(null),installed=ref(false),offline=ref(false),ios=ref(false),hint=ref(false);
function available(event){event.preventDefault();prompt.value=event;}
function done(){installed.value=true;prompt.value=null;}
function network(){offline.value=!navigator.onLine;}
async function install(){if(!prompt.value){hint.value=!hint.value;return;}const event=prompt.value;prompt.value=null;try{await event.prompt();await event.userChoice;}catch{hint.value=true;}}
onMounted(()=>{installed.value=requiresNativePrinter()||window.matchMedia('(display-mode:standalone)').matches||navigator.standalone===true;ios.value=/iPhone|iPad|iPod/.test(navigator.userAgent);network();window.addEventListener('beforeinstallprompt',available);window.addEventListener('appinstalled',done);window.addEventListener('online',network);window.addEventListener('offline',network);});
onBeforeUnmount(()=>{window.removeEventListener('beforeinstallprompt',available);window.removeEventListener('appinstalled',done);window.removeEventListener('online',network);window.removeEventListener('offline',network);});
</script>
<template><div class="pos-install"><button v-if="!installed" type="button" class="btn small" @click="install">تثبيت تطبيق دنانير</button><p v-if="hint" class="caption">{{ios?'من قائمة المشاركة اختر «إضافة إلى الشاشة الرئيسية».':'من قائمة المتصفح اختر «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».'}}</p><p v-if="offline" class="notice warn" role="status">الاتصال بالإنترنت غير متاح؛ البيع وTopup يحتاجان اتصالًا بالسيرفر.</p></div></template>
