<script setup>
import {computed,onBeforeUnmount,onMounted,ref} from 'vue';
import {usePreferences} from '../../modules/preferences/preferences-state.js';
const props=defineProps({modelValue:{type:String,default:''},min:String,max:String}),emit=defineEmits(['update:modelValue']);
const preferences=usePreferences(),root=ref(null),open=ref(false),today=new Date(),month=ref(today.getMonth()),year=ref(today.getFullYear());
const locale=computed(()=>({ar:'ar-IQ',en:'en-GB',ckb:'ckb-IQ'}[preferences?.state.language]||'ar-IQ'));
const label=computed(()=>{if(!props.modelValue)return 'اختر التاريخ';const [y,m,d]=props.modelValue.split('-').map(Number);return new Intl.DateTimeFormat(locale.value, {numberingSystem:'latn',year:'numeric',month:'long',day:'numeric'}).format(new Date(y,m-1,d));});
const monthLabel=computed(()=>new Intl.DateTimeFormat(locale.value, {numberingSystem:'latn',year:'numeric',month:'long'}).format(new Date(year.value,month.value,1)));
const weekdays=computed(()=>Array.from({length:7},(_,i)=>new Intl.DateTimeFormat(locale.value, {numberingSystem:'latn',weekday:'short'}).format(new Date(2026,0,4+i))));
const days=computed(()=>Array.from({length:new Date(year.value,month.value,1).getDay()},()=>null).concat(Array.from({length:new Date(year.value,month.value+1,0).getDate()},(_,i)=>i+1)));
function value(day){return `${year.value}-${String(month.value+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;}
function disabled(day){return props.min&&value(day)<props.min||props.max&&value(day)>props.max;}
function toggle(){if(!open.value&&props.modelValue){const [y,m]=props.modelValue.split('-').map(Number);year.value=y;month.value=m-1;}open.value=!open.value;}
function move(n){const d=new Date(year.value,month.value+n,1);year.value=d.getFullYear();month.value=d.getMonth();}
function outside(e){if(!root.value?.contains(e.target))open.value=false;}
onMounted(()=>document.addEventListener('pointerdown',outside));onBeforeUnmount(()=>document.removeEventListener('pointerdown',outside));
</script>
<template><div ref="root" class="app-date-field" @keydown.esc.stop="open=false"><button type="button" class="app-date-trigger" :aria-expanded="open" @click="toggle"><span>{{label}}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v5M17 3v5M3 11h18"/></svg></button><div v-if="open" class="app-date-calendar" role="group" aria-label="اختيار التاريخ"><header><button type="button" aria-label="الشهر السابق" @click="move(-1)">‹</button><b>{{monthLabel}}</b><button type="button" aria-label="الشهر التالي" @click="move(1)">›</button></header><div class="app-date-days"><small v-for="day in weekdays" :key="day">{{day}}</small><template v-for="(day,i) in days" :key="i"><button v-if="day" type="button" :disabled="disabled(day)" :class="{selected:value(day)===modelValue}" :aria-label="value(day)" @click="emit('update:modelValue',value(day));open=false">{{new Intl.NumberFormat(locale,{numberingSystem:'latn'}).format(day)}}</button><span v-else/></template></div><footer><button type="button" @click="year=today.getFullYear();month=today.getMonth()">اليوم</button><button type="button" @click="emit('update:modelValue','');open=false">مسح</button></footer></div></div></template>
