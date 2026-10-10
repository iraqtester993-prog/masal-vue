<script setup>
defineProps({name:{type:String,default:''},phones:{type:Array,default:() => []}});
</script>
<template>
  <section v-if="phones.length" class="support-phone-links" aria-label="أرقام الدعم">
    <div class="support-phone-heading"><strong>أرقام الدعم</strong><span>{{name}}</span><small>{{phones.length}} رقم</small></div>
    <div class="support-phone-grid"><a v-for="(row,index) in phones" :key="index" :href="'tel:' + row.number" class="support-phone-card" :aria-label="'اتصال بـ' + row.label + ' ' + row.number"><span class="support-phone-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1Z"/></svg></span><span class="support-phone-text"><span>{{row.label || 'الدعم الفني'}}</span><bdi>{{row.number}}</bdi></span><span class="support-phone-call">اتصال</span></a></div>
  </section>
</template>
