<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';

defineProps({records:{type:Array,default:null},compact:{type:Boolean,default:false}});
const types={topup:'تعبئة رصيد',bundle:'باقة',bill:'فاتورة'};
</script>
<template><section class="digital-excluded-catalog"><h4>السجلات المستبعدة · {{records===null?'غير معلوم':records.length}}</h4><p v-if="records===null" class="notice warn">{{compact?'تفاصيل المستبعدات غير متاحة.':'تفاصيل المستبعدات لم تُسجّل في هذا الجلب. أعد جلب فئات الشركة لتسجيل الأسماء وأسباب الاستبعاد.'}}</p><p v-else-if="!records.length&&!compact" class="help">لم تُستبعد سجلات بسبب السعر في آخر جلب.</p><TablePanel v-if="records?.length" class="tablewrap"><table><thead><tr><th>اسم السجل لدى الشركة</th><th>معرّف الشركة</th><th>النوع</th><th>السعر · د.ع</th><th>سبب الاستبعاد</th></tr></thead><tbody><tr v-for="(row,index) in records" :key="index"><td>{{row.remote_name}}</td><td><span dir="ltr">{{row.remote_id||'—'}}</span></td><td>{{types[row.type]||'غير محدد'}}</td><td>{{row.price===null?'لم ترسله الشركة':row.price}}</td><td>{{row.reason}}</td></tr></tbody></table></TablePanel><p v-if="!compact" class="caption">هذه السجلات للمراجعة فقط ولا يمكن تخصيصها أو بيعها.</p></section></template>
