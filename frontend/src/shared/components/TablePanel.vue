<script setup>
import {ref} from 'vue';
import {numberTables} from './numbered-table.js';
import {usePreferencesTranslator} from '../../modules/preferences/preferences-state.js';
defineOptions({inheritAttrs:false});
const props=defineProps({start:{type:Number,default:1},numbered:{type:Boolean,default:true}});
const visible=ref(true),t=usePreferencesTranslator();
const Rows=(_,context)=>props.numbered?numberTables(context.slots.default?.()||[],props.start,t('التسلسل')):context.slots.default?.();
</script>
<template><section class="table-panel"><div class="table-panel-controls"><button type="button" class="btn small" :aria-expanded="visible" @click="visible=!visible">{{visible?'إخفاء الجدول':'إظهار الجدول'}}</button></div><div v-show="visible" v-bind="$attrs" class="tablewrap"><Rows><slot /></Rows></div></section></template>
