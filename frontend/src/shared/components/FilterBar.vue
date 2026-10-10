<script setup>
import {ref} from 'vue';
defineOptions({inheritAttrs:false});
defineProps({collapsible:{type:Boolean,default:false}});
const visible=ref(true);
</script>
<template><section class="filter-bar"><button v-if="collapsible" type="button" class="btn small filter-toggle" :aria-expanded="visible" @click="visible=!visible">{{visible?'إخفاء الفلاتر':'إظهار الفلاتر'}}</button><div v-bind="$attrs" class="filter-bar-fields" :hidden="!visible" :style="visible?null:{display:'none !important'}"><slot /></div></section></template>
