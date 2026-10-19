<script setup>
defineProps({readiness:{type:Object,default:null},service:{type:String,default:''}});
</script>
<template><section v-if="readiness?.issues?.length" class="notice warn digital-readiness-notice" role="status"><strong>{{service ? service+' · ' : ''}}تفاصيل إعداد الخدمة</strong><ul><li v-for="issue in readiness.issues" :key="issue.code"><b>{{issue.message}}</b><span>{{issue.action}}</span></li></ul></section></template>
