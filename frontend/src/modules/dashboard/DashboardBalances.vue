<script setup>
import { formatNumber } from '../reports/report-model.js';
import { balanceTime as time } from './dashboard-time.js';
defineProps({rows:{type:Array,required:true},total:{type:Number,default:0}});
</script>
<template><div class="dashboard-company-balances"><span class="caption">الرصيد المتبقي لدى الشركة · Topup</span><div v-for="row in rows" :key="row.agent" class="dashboard-company-balance"><div><span>{{row.name}}</span><strong>{{row.remaining===null||row.remaining===undefined?'لم يُجلب بعد':formatNumber(row.remaining)+' د.ع'}}</strong></div><small v-if="row.updatedAt">آخر جلب: {{time(row.updatedAt)}} · رصيد وقت الجلب</small></div><p v-if="!rows.length" class="caption">لا يوجد ربط Topup متاح لهذا الحساب.</p><small v-if="total>rows.length">عرض أول {{rows.length}} حسابات؛ بقية الأرصدة في خدمات API.</small></div></template>
