<script setup>
import {computed,ref} from 'vue';
import FinanceDialog from './FinanceDialog.vue';
import {money} from './finance-model.js';
const props=defineProps({modelValue:[String,Number],amounts:{type:Array,default:()=>[]},currency:{type:String,default:'د.ع'},disabled:Boolean});
const emit=defineEmits(['update:modelValue']),open=ref(false);
const selected=computed(()=>props.amounts.find(value=>String(value)===String(props.modelValue)));
function choose(value){if(props.disabled||!props.amounts.includes(value))return;emit('update:modelValue',value);open.value=false;}
</script>
<template>
 <div class="funding-amount-picker">
  <button type="button" class="funding-amount-trigger" aria-haspopup="dialog" aria-label="مبلغ طلب التمويل" :aria-expanded="open" :disabled="disabled||!amounts.length" @click="open=true"><span>{{selected===undefined?'اختر مبلغ التمويل':money(selected)+' '+currency}}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
  <FinanceDialog :open="open" title="اختر مبلغ التمويل" variant="funding-amount-dialog" @close="open=false">
   
   <div class="funding-amount-options" role="listbox" aria-label="مبالغ طلب التمويل">
    <button v-for="entry in amounts" :key="entry" type="button" role="option" :aria-selected="String(entry)===String(modelValue)" :disabled="disabled" @click="choose(entry)"><span><strong class="mono">{{money(entry)}}</strong><small>{{currency}}</small></span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path :d="String(entry)===String(modelValue)?'m5 12 4 4L19 6':'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'"/></svg></button>
   </div>
  </FinanceDialog>
 </div>
</template>
<style>
.funding-amount-picker{margin-top:6px;min-width:0;width:100%}
.funding-amount-trigger{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;min-height:46px;padding:10px 13px;text-align:start;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--ink);font:inherit;font-size:14px}
.funding-amount-trigger svg{width:19px;height:19px;flex:none}
.funding-amount-dialog{width:min(440px,calc(100vw - 28px));max-height:min(80dvh,680px);padding:20px;border-radius:18px}
.funding-amount-options{display:grid;gap:8px;max-height:55dvh;overflow:auto;padding:2px}
.funding-amount-options>button{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 15px;min-height:54px;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--ink);text-align:start;font:inherit}
.funding-amount-options>button>span{display:flex;align-items:baseline;gap:9px}
.funding-amount-options strong{font-size:19px}
.funding-amount-options small{font-size:11px;color:var(--muted)}
.funding-amount-options svg{width:22px;height:22px;flex:none;color:var(--muted)}
.funding-amount-options>button[aria-selected=true]{background:var(--accent-soft);border-color:var(--accent);color:var(--accent)}
.funding-amount-options>button[aria-selected=true] svg{color:var(--accent)}
</style>
