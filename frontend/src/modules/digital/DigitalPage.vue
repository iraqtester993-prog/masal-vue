<script setup>
import {RouterLink} from "vue-router";

import { computed,inject,onMounted,ref,watch } from 'vue';

import { routeLocationKey } from 'vue-router';

import { authorizedDigitalTab,digitalDeepLink } from './digital-model.js';

import { useDigitalRuntime } from './digital-runtime.js';
import {hasSystemScope} from '../auth/capabilities.js';
import {useRouteRefresh} from '../../shared/route-refresh.js';

import DigitalSettings from './DigitalSettings.vue';

import DigitalSell from './DigitalSell.vue';

import DigitalLog from './DigitalLog.vue';

import DigitalReceipt from './DigitalReceipt.vue';

import './digital.css';

const props=defineProps({mode:{type:String,default:'digital'},focusedOffer:{type:Object,default:null},deviceSession:{type:Boolean,default:true}}),emit=defineEmits(['back','issued']),vm=useDigitalRuntime();

const canSetup=computed(()=>hasSystemScope(vm.identity.value)&&vm.can('integrations.edit'));

const canAssign=computed(()=>['main_agent','sub_agent','sub_branch'].includes(vm.identity.value?.account.type)&&vm.can('digital.assign'));

const canSettings=computed(()=>!vm.pos.value&&(vm.can('integrations.view')||vm.can('integrations.balance')||canAssign.value));
const canSell=computed(()=>vm.pos.value&&vm.can('digital.create')),route=inject(routeLocationKey,null),deepLink=computed(()=>digitalDeepLink(route?.query)),tab=ref(props.focusedOffer?'sell':props.mode==='integrations'?'settings':canSell.value?'log':canSettings.value?'settings':'log');

watch(deepLink,link=>{if(!props.focusedOffer)tab.value=authorizedDigitalTab(link.tab,{sell:!!props.focusedOffer&&canSell.value,view:vm.can('digital.view'),settings:canSettings.value},tab.value);},{immediate:true});

function closeReceipt(){vm.clearReceipt();if(props.focusedOffer)emit('back');}

// The focused seller already has its assigned offer. Recovery and the fresh
// confirmation quote remain authoritative; settings/catalog reads are unrelated.
useRouteRefresh(async()=>{await vm.load(undefined,{withOffers:tab.value==='sell',fresh:true});return !vm.state.error;},{canRefresh:()=>!props.focusedOffer&&!vm.state.busy&&!vm.state.uncertain&&!vm.receipt.value});
onMounted(()=>{if(!props.focusedOffer)return vm.load(undefined,{withOffers:tab.value==='sell'});});

</script>

<template><section class="digital-services" data-no-pagination><div v-if="!focusedOffer" class="card digital-tabs"><div class="tabs"><RouterLink v-if="canSell" class="btn" to="/sell">البيع من الفئات</RouterLink><button v-if="vm.can('digital.view')" :class="{active:tab==='log'}" @click="tab='log'">سجل العمليات</button><button v-if="canSettings" :class="{active:tab==='settings'}" @click="tab='settings'">{{canSetup?'إعداد الربط':'توزيع الفئات'}}</button></div></div><p v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</p><p v-if="vm.state.notice" class="notice" role="status">{{vm.state.notice}}</p><p v-for="connection in vm.state.connections.filter(row=>!row.gateway.configured)" :key="connection.id" class="notice warn">{{connection.provider_name}} · {{connection.account_name}}: {{connection.gateway.missing.join('، ')}}.</p><p class="read-loading" v-if="vm.state.loading" role="status">جارٍ تحميل الخدمات…</p><DigitalSettings v-if="tab==='settings'&&canSettings" :vm="vm" :can-setup="canSetup" :can-assign="canAssign"/><DigitalSell v-if="tab==='sell'&&canSell&&focusedOffer" :vm="vm" :focused-offer="focusedOffer" :device-session="deviceSession" @back="emit('back')" @issued="emit('issued',$event)"/><DigitalLog v-if="tab==='log'&&vm.can('digital.view')" :vm="vm" :route-filters="deepLink.filters"/><DigitalReceipt v-if="vm.receipt.value" :vm="vm" :receipt="vm.receipt.value" @close="closeReceipt"/></section></template>

