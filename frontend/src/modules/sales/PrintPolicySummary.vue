<script setup>
import { computed } from 'vue';
const props = defineProps({policy:Object,daily:Object,sale:Object,wait:{type:Number,default:0},canRetry:Boolean,busy:Boolean});
defineEmits(['retry']);
const remaining = computed(()=>Math.max(0,(props.policy?.failed_retries || 0)-(props.sale?.failed_retry_count || 0)));
</script>
<template>
  <div v-if="policy" class="notice no-print print-policy-summary"><b>ضوابط الطباعة المطبقة</b><div>الحد الأقصى للبطاقات في الطلب: {{policy.max_cards}} · محاولات الفشل {{sale ? 'المتبقية':'المسموحة'}}: {{remaining}} · الفاصل: {{policy.interval_seconds}} ثانية</div><div v-if="daily?.limit">البطاقات اليوم: {{daily.used}} / {{daily.limit}} · المتبقي: {{daily.remaining}}<span v-if="daily.pending"> · قيد الطباعة: {{daily.pending}}</span></div><div v-if="wait" role="status">الطباعة التالية بعد {{wait}} ثانية</div><div v-if="sale?.print_pending">بانتظار تسجيل نتيجة المحاولة الحالية. لا تبدأ طباعة ثانية قبل حسم النتيجة.</div><button v-if="sale?.status === 'Print Failed' && remaining > 0 && canRetry" type="button" class="btn" :disabled="busy || wait > 0" @click="$emit('retry')">{{wait ? `انتظر ${wait} ثانية`:'إعادة محاولة الطباعة الفاشلة'}}</button><span v-if="sale?.status === 'Print Failed' && !remaining">تم بلوغ الحد؛ اطلب موافقة إعادة الطباعة.</span></div>
</template>
