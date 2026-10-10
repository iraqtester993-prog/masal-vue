<script setup>
import {computed, ref} from 'vue';
import './reference.css';
const props = defineProps({options:Object,typeId:[Number,String],representativeIds:{type:Array,default:()=>[]},disabled:Boolean,canType:Boolean,canRepresentatives:Boolean,errors:{type:Object,default:()=>({})}});
const emit = defineEmits(['update:typeId','update:representativeIds']);
const query = ref('');
const representatives = computed(() => [...new Map([...(props.options.available_representatives || []), ...(props.options.selected_representatives || [])].map(row => [row.id,row])).values()]);
const selected = computed(() => representatives.value.filter(row => props.representativeIds.includes(row.id)));
const visible = computed(() => representatives.value.filter(row => `${row.name} ${row.phone}`.toLowerCase().includes(query.value.trim().toLowerCase())));
function toggle(id, checked) { emit('update:representativeIds', checked ? [...new Set([...props.representativeIds,id])] : props.representativeIds.filter(value => value !== id)); }
</script>
<template>
  <div class="reference-module pos-reference-fields">
    <label class="form-field">نوع نقطة البيع<select :value="typeId || ''" :disabled="disabled || !canType" @change="emit('update:typeId',$event.target.value ? Number($event.target.value) : null)"><option value="">بدون نوع</option><option v-for="type in options.pos_types || []" :key="type.id" :value="type.id">{{type.name}}{{type.status==='disabled'?' — موقوف':''}}</option></select><span v-if="errors.pos_type_id" class="field-error">{{errors.pos_type_id.join(' ')}}</span></label>
    <section class="pos-related-editor representative-select-field"><div class="cardhead"><h3>المندوبون</h3><span class="help">اختياري ويمكن اختيار أكثر من مندوب</span></div>
      <details class="representative-dropdown"><summary><span>{{selected.length ? selected.map(row => row.name || 'مندوب بدون اسم').join('، ') : 'اختر المندوبين'}}</span><b>{{selected.length || ''}}</b></summary><div class="representative-dropdown-panel"><input v-model="query" class="representative-search" placeholder="بحث باسم المندوب أو الهاتف" aria-label="بحث المندوب" /><div v-if="visible.length" class="representative-options"><label v-for="row in visible" :key="row.id" class="inline-check"><input type="checkbox" :checked="representativeIds.includes(row.id)" :disabled="disabled || !canRepresentatives" @change="toggle(row.id,$event.target.checked)" /><span>{{row.name || 'مندوب بدون اسم'}}<small v-if="row.phone"> · {{row.phone}}</small><small v-if="row.status==='disabled'"> · موقوف</small></span></label></div><p v-else class="empty">لا يوجد مندوبون تابعون للوكيل المحدد</p></div></details>
      <span v-if="errors.representative_ids" class="field-error">{{errors.representative_ids.join(' ')}}</span>
    </section>
  </div>
</template>
