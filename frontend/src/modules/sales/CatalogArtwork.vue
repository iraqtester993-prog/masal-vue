<script setup>
import {computed,ref} from 'vue';
const props=defineProps({src:{type:String,default:null},label:{type:String,default:''},service:{type:String,default:'card'}});
const failed=ref(null),image=computed(()=>props.src&&props.src!==failed.value?props.src:null);
const initials=computed(()=>Array.from(props.label.trim()).slice(0,2).join(''));
</script>

<template>
 <span class="pos-catalog-art" :class="{'is-placeholder':!image}">
  <img v-if="image" :src="image" alt="" loading="lazy" decoding="async" @error="failed=src">
  <template v-else>
   <span v-if="initials" class="pos-catalog-art-initials" aria-hidden="true">{{initials}}</span>
   <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><template v-if="service==='topup'"><rect x="7" y="2" width="10" height="20" rx="3"/><path d="M10 18h4M10 7h4M12 5v4"/></template><template v-else><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h4"/></template></svg>
  </template>
 </span>
</template>
