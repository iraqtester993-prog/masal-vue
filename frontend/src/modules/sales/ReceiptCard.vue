<script setup>
import { computed } from 'vue';
import { money,time } from './sales-model.js';
import RechargeQr from './RechargeQr.vue';
import {receiptQr} from './recharge-qr.js';
const props = defineProps({receipt:{type:Object,required:true},preview:Boolean});
const layout = computed(()=>props.receipt.design),sale = computed(()=>props.receipt.sale),cards = computed(()=>props.receipt.cards || []);
const currency = computed(()=>'د.ع');
const labels = computed(()=>Object.fromEntries((props.receipt.extra_fields || []).map(field=>[field.key,field.label])));
const vouchers = computed(()=>cards.value.map(card=>({...card,qr:layout.value.qr_enabled?receiptQr(card.fields?.pin,layout.value.recharge_template):null})));
</script>
<template>
  <div class="receipt" :style="{'--receipt-width':`${layout.width || 80}mm`,color:layout.color}" :lang="layout.language === 'en' ? 'en':'ar'">
    <template v-for="block in layout.display_order" :key="block">
      <div v-if="block === 'company'" class="receipt-block"><img v-if="layout.company_image" :src="layout.company_image" alt="صورة الشركة" class="receipt-logo receipt-company-image"><b>{{layout.company_name}}</b><div>{{layout.agent_name}}</div></div>
      <div v-else-if="block === 'header' && layout.header" class="receipt-block receipt-template-header">{{layout.header}}</div>
      <div v-else-if="block === 'agent' && (layout.agent_image || layout.agent_text)" class="receipt-block receipt-agent-block"><img v-if="layout.agent_image" :src="layout.agent_image" alt="صورة الوكيل" class="receipt-logo receipt-agent-image"><div class="receipt-agent-text" :style="{color:layout.agent_color}">{{layout.agent_text}}</div></div>
      <div v-else-if="block === 'category'" class="receipt-block"><b>{{sale?.product_name || receipt.product_name}}</b><div v-if="sale">{{sale.account_name}}</div><small v-if="sale?.reprints">إعادة طباعة · {{sale.reprints}}</small></div>
      <div v-else-if="block === 'image' && layout.category_image" class="receipt-block"><img :src="layout.category_image" alt="صورة الفئة" class="receipt-art receipt-category-image"></div>
      <div v-else-if="block === 'codes'" class="receipt-block">
        <div v-for="card in vouchers" :key="card.id" class="receipt-voucher">
          <small class="receipt-pin-label">رمز التعبئة · PIN</small>
          <div class="pin receipt-pin" dir="ltr" data-native-pin>{{card.fields?.pin}}</div>
          <small>الرقم التسلسلي: {{card.serial || '—'}}</small>
          <small>تاريخ الانتهاء: {{card.expiry || '—'}}</small>
          <small v-if="card.fields?.cvc">CVC: {{card.fields.cvc}}</small>
          <small v-if="card.fields?.reference">الرقم المرجعي: {{card.fields.reference}}</small>
          <small v-for="(value,key) in card.fields?.extra_fields || {}" :key="key">{{labels[key] || key}}: {{value}}</small>
          <div v-if="card.qr" class="receipt-recharge">
            <RechargeQr :value="card.qr"/>
            <small>{{card.qr.kind==='recharge'?'امسح الرمز ثم اضغط اتصال للتعبئة':'امسح الرمز لعرض البن'}}</small>
            <a v-if="card.qr.kind==='recharge'" class="receipt-recharge-code" :href="card.qr.uri" dir="ltr">{{card.qr.code}}</a>
          </div>
        </div>
        <template v-if="preview">
          <small class="receipt-pin-label">رمز التعبئة · PIN</small>
          <div class="pin receipt-pin" dir="ltr" data-native-pin>•••• •••• ••••</div>
          <small>الرقم التسلسلي: —<br>تاريخ الانتهاء: —</small>
          <div v-if="layout.qr_enabled && layout.recharge_template" class="receipt-recharge">
            <RechargeQr :template="layout.recharge_template" preview/>
            <small>معاينة QR تجريبي · ليس للتعبئة</small>
          </div>
        </template>
      </div>
      <div v-else-if="block === 'amount'" class="receipt-block"><b>{{sale ? money(sale.retail_total) : '—'}} {{currency}}</b><small v-if="sale">{{sale.id}} · {{time(sale.issued_at)}}</small></div>
      <div v-else-if="block === 'footer'" class="receipt-block"><div class="receipt-template-footer">{{layout.footer}}</div></div>
    </template>
  </div>
</template>
