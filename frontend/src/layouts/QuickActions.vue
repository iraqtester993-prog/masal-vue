<script setup>
import {computed,ref} from 'vue';
import {useRouter} from 'vue-router';
import {usePortal} from '../modules/auth/session.js';
import {usePreferencesTranslator} from '../modules/preferences/preferences-state.js';
import FinanceDialog from '../modules/finance/FinanceDialog.vue';
import {quickActions} from './quick-actions.js';
import {NAVIGATION_DESTINATIONS,navIconPath} from './navigation.js';
import '../modules/finance/finance-parity.css';
const {session}=usePortal(),router=useRouter(),t=usePreferencesTranslator(),open=ref(false);
const actions=computed(()=>quickActions(session.state.identity,session.can));
const sectionTitle=computed(()=>NAVIGATION_DESTINATIONS.find(item=>item.id===(router.currentRoute.value.meta.navigationId || router.currentRoute.value.name))?.label || router.currentRoute.value.meta.title || 'لوحة التحكم');
async function choose(action){
  if(!actions.value.some(row=>row.id===action.id))return;
  open.value=false;
  if(router.currentRoute.value.query.quick){
    const {quick,...query}=router.currentRoute.value.query;
    await router.replace({query});
  }
  await router.push({name:action.route,query:{quick:action.id}});
}
</script>
<template>
  <button v-if="actions.length" type="button" class="iconbtn quick-button" aria-label="إجراء سريع" title="إجراء سريع" @click="open=true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
  <FinanceDialog :open="open" title="إجراء سريع" variant="quick-actions-dialog" @close="open=false">
    <template #heading><div class="dialog-heading"><div class="dialog-heading-main"><span class="dialog-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path :d="navIconPath(router.currentRoute.value.meta.navigationId || router.currentRoute.value.name)"/></svg></span><div><span class="dialog-section">{{t(sectionTitle)}}</span><h2>{{t('إجراء سريع')}}</h2></div></div><button type="button" class="iconbtn" aria-label="إغلاق إجراء سريع" @click="open=false">×</button></div></template>
    <p class="help">{{t('اختر الإجراء')}}</p><div class="quick-grid"><button v-for="action in actions" :key="action.id" type="button" class="quick-tile" @click="choose(action)"><span aria-hidden="true">{{action.symbol}}</span><b>{{t(action.label)}}</b></button></div>
  </FinanceDialog>
</template>
<style>
.finance-modal.quick-actions-dialog{width:min(900px,calc(100vw - 56px));max-width:calc(100vw - 24px);padding:28px;border-radius:24px;--card-a:#edfbf8;--card-b:#edf5ff;--card-c:#f4f0ff}
.quick-actions-dialog>.dialog-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:0 0 24px;padding:0 0 22px;border-bottom:1px solid var(--line)}
.quick-actions-dialog .dialog-heading-main{display:flex;align-items:center;gap:16px;min-width:0}
.quick-actions-dialog .dialog-icon{display:grid;place-items:center;width:54px;height:54px;border-radius:15px;background:var(--blue-bg,#e4f3f7);color:var(--blue-text);flex:none}
.quick-actions-dialog .dialog-icon svg{width:24px;height:24px}
.quick-actions-dialog .dialog-section{display:block;font-size:12px;font-weight:700;color:var(--blue-text);margin-bottom:3px}
.quick-actions-dialog .dialog-heading h2{font-size:21px;line-height:1.6;margin:0;overflow-wrap:anywhere}
.quick-actions-dialog .help{margin:14px 0}
.quick-actions-dialog::backdrop{background:rgba(24,37,59,.65);backdrop-filter:blur(5px)}
.quick-actions-dialog .quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:20px}
.quick-actions-dialog .quick-tile{display:flex;align-items:center;gap:14px;min-height:90px;padding:18px;border:1px solid var(--line);border-radius:16px;background:var(--card-b);color:var(--ink);font:inherit;text-align:start;cursor:pointer;transition:.18s}
.quick-actions-dialog .quick-tile:nth-child(3n+2){background:var(--card-a)}
.quick-actions-dialog .quick-tile:nth-child(3n){background:var(--card-c)}
.quick-actions-dialog .quick-tile>span{font-size:27px;color:var(--blue-text);width:35px;text-align:center}
.quick-actions-dialog .quick-tile b{font-size:16px}
.quick-actions-dialog .quick-tile:hover{border-color:var(--cyan,#0898b5);transform:translateY(-2px);box-shadow:0 5px 14px #0b1e3610}
html[data-theme="dark"] .quick-actions-dialog{--card-a:#113736;--card-b:#122e4d;--card-c:#2b2746}
@media(max-width:760px){.quick-actions-dialog .quick-grid{gap:10px}.quick-actions-dialog .quick-tile{padding:14px;gap:8px;min-height:92px}.quick-actions-dialog .quick-tile b{font-size:14px}.quick-actions-dialog .quick-tile>span{font-size:23px;width:25px}}
@media(max-width:420px){.quick-actions-dialog .quick-tile{flex-direction:column;align-items:flex-start}}
@media(max-width:650px){.finance-modal.quick-actions-dialog{width:calc(100vw - 24px);padding:20px;max-height:calc(100dvh - 24px);border-radius:18px}.quick-actions-dialog>.dialog-heading{margin-bottom:20px;padding-bottom:18px}.quick-actions-dialog .dialog-icon{width:42px;height:42px;border-radius:12px}.quick-actions-dialog .dialog-heading h2{font-size:18px}.quick-actions-dialog .dialog-heading>.iconbtn{width:34px;height:34px}}
</style>
