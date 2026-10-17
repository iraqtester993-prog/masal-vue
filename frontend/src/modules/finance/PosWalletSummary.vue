<script setup>
import {computed} from 'vue';
import {useBalancePrivacy} from '../../shared/composables/balance-privacy.js';
import {usePreferencesTranslator} from '../preferences/preferences-state.js';
import {navIconPath} from '../../layouts/navigation.js';
import {money} from './finance-model.js';
const props=defineProps({services:{type:Array,default:()=>[]},summaries:{type:Object,default:()=>({})},selected:{type:String,default:'voucher'},loading:Boolean,filtered:Boolean});
const emit=defineEmits(['select']),uiLabel=usePreferencesTranslator(),{hidden,toggle}=useBalancePrivacy();
const wallets=computed(()=>props.services.filter(item=>['voucher','topup'].includes(item.id)));
const label=id=>id==='topup'?'محفظة Topup':'محفظة البطاقات';
function amount(id,field){const value=props.summaries[id]?.[field];return hidden.value&&value!==null&&value!==undefined?'••••••':money(value);}
</script>
<template>
 <section class="pos-wallet-overview" aria-label="أرصدة حسابك">
  <header class="pos-wallet-heading"><div><h2>{{uiLabel('المحافظ')}}</h2><p>{{uiLabel('كل رصيد مخصص لخدمته')}}</p></div><button type="button" class="pos-wallet-privacy" :aria-pressed="hidden" :aria-label="uiLabel(hidden?'إظهار الأرصدة':'إخفاء الأرصدة')" @click="toggle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path :d="hidden?'m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.8 4.3A10.8 10.8 0 0 1 12 4c6 0 10 8 10 8a19 19 0 0 1-3.1 4.2M6.1 6.1A20.3 20.3 0 0 0 2 12s4 8 10 8a10.8 10.8 0 0 0 5.9-2.1':'M2 12s4-8 10-8 10 8 10 8-4 8-10 8S2 12 2 12Zm13 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'"/></svg></button></header>
  <div class="pos-wallet-cards" role="group" aria-label="اختيار المحفظة">
   <button v-for="wallet in wallets" :key="wallet.id" type="button" class="pos-wallet-card" :class="{'is-selected':selected===wallet.id,'is-topup':wallet.id==='topup'}" :aria-pressed="selected===wallet.id" :aria-busy="selected===wallet.id&&loading" @click="emit('select',wallet.id)">
    <span class="pos-wallet-card-heading"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path :d="navIconPath(wallet.id==='topup'?'pos':'wallets')"/></svg>{{uiLabel(label(wallet.id))}}</span><small>IQD</small></span>
    <span class="pos-wallet-available"><small>{{uiLabel('الرصيد المتاح للاستخدام')}}</small><span><strong class="mono">{{amount(wallet.id,'available')}}</strong><small>{{uiLabel('د.ع')}}</small></span></span>
    <span class="pos-wallet-card-details"><span>{{uiLabel('الرصيد الحالي')}}<b class="mono">{{amount(wallet.id,'current')}}</b></span><span>{{uiLabel('المحجوز')}}<b class="mono">{{amount(wallet.id,'held')}}</b></span></span>
    <span class="pos-wallet-selected"><i aria-hidden="true">{{selected===wallet.id?'✓':'○'}}</i>{{uiLabel(selected===wallet.id?'المحفظة المحددة':'عرض الحركات والإجراءات')}}</span>
   </button>
  </div>
  <p v-if="filtered" class="pos-wallet-scope">{{uiLabel('امسح الفلاتر لعرض أرصدة حسابك')}}</p><p v-else class="pos-wallet-scope">{{uiLabel('الرصيد المحجوز غير متاح للبيع حتى تسوية العملية')}}</p>
 </section>
</template>
