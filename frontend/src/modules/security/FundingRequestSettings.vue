<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { usePortal } from '../auth/session.js';
import { createFinanceApi } from '../finance/finance-api.js';
import { amount, errorText } from '../finance/finance-model.js';
const { session } = usePortal(), api = createFinanceApi(session.api);
const policy = ref(null), error = ref(''), notice = ref(''), busy = ref(false);
const controller = new AbortController();
async function load() {
  try { policy.value = (await api.policy(controller.signal)).data; policy.value.amounts=policy.value.amounts.map(value=>String(value).replace(/\.00$/, ''));  }
  catch (cause) { if (cause.name !== 'AbortError') error.value = errorText(cause); }
}
async function save() {
  if (busy.value || !policy.value) return;
  error.value = ''; notice.value = ''; busy.value = true;
  try {
    const amounts = policy.value.amounts.map(value => amount(String(value)));
    if (new Set(amounts).size !== amounts.length) throw new Error('يوجد مبلغ مكرر.');
    policy.value = (await api.savePolicy({ version: policy.value.version, daily_limit: Number(policy.value.daily_limit), amounts }, controller.signal)).data;
    notice.value = 'تم حفظ إعدادات التمويل.';
  } catch (cause) { if (cause.name !== 'AbortError') error.value = errorText(cause); }
  finally { busy.value = false; }
}
onMounted(load); onBeforeUnmount(() => controller.abort());
</script>
<template>
  <section class="card funding-request-settings">
    <form v-if="policy" @submit.prevent="save"><fieldset :disabled="busy">
      <div class="funding-settings-head"><h3>طلبات تمويل نقاط البيع</h3><button class="btn primary">حفظ إعدادات التمويل</button></div>
      <div class="funding-settings-limit"><label>عدد الطلبات المسموح يوميًا لكل نقطة<input v-model.number="policy.daily_limit" type="number" min="1" max="100" required></label></div>
      <div class="funding-settings-head"><h4>مبالغ الطلبات المتاحة · د.ع</h4><button type="button" class="btn" @click="policy.amounts.push('')">إضافة مبلغ</button></div>
      <div class="funding-settings-amounts"><label v-for="(_, index) in policy.amounts" :key="index">المبلغ {{index+1}}<div><input v-model="policy.amounts[index]" type="number" min="0.01" step="0.01" required :aria-label="'المبلغ '+(index+1)"><button type="button" class="btn small danger" @click="policy.amounts.splice(index,1)">حذف</button></div></label></div>
      <p v-if="!policy.amounts.length" class="caption">لا توجد مبالغ متاحة للطلبات.</p>
    </fieldset></form>
    <p v-if="error" class="notice warn" role="alert">{{error}} <button class="btn small" @click="load">إعادة التحميل</button></p><p v-if="notice" role="status">{{notice}}</p>
  </section>
</template>
<style scoped>
.funding-request-settings{padding:20px!important}fieldset{border:0;padding:0;margin:0;min-width:0}.funding-settings-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}.funding-settings-head h3,.funding-settings-head h4{font-size:14px;margin:0}.funding-settings-limit{display:grid;grid-template-columns:minmax(200px,330px) 1fr;gap:16px;margin-bottom:18px}label{display:grid;gap:7px}.funding-settings-amounts{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.funding-settings-amounts label>div{display:flex;gap:6px;align-items:center}input{width:100%;min-width:0;margin:0}.btn{flex:none}@media(max-width:700px){.funding-settings-limit{grid-template-columns:1fr}.funding-settings-head{flex-wrap:wrap}}
</style>
