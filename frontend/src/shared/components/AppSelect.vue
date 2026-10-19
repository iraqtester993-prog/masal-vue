<script setup>
import {computed,getCurrentInstance,inject,nextTick,onBeforeUnmount,onMounted,onUpdated,ref,shallowRef,useAttrs} from 'vue';
import {portalContextKey} from '../../modules/auth/session.js';
import {usePreferencesTranslator} from '../../modules/preferences/preferences-state.js';
import FinanceDialog from '../../modules/finance/FinanceDialog.vue';
import {nextChoice,selectChoice,selectChoices} from './select-options.js';
defineOptions({inheritAttrs:false});
const props=defineProps({modelValue:{type:[String,Number,Boolean,Object,Array],default:undefined},value:{type:[String,Number,Boolean,Object,Array],default:undefined},modelModifiers:Object,disabled:Boolean,required:Boolean,multiple:Boolean,label:String});
const emit=defineEmits(['update:modelValue','change']),attrs=useAttrs(),portal=inject(portalContextKey,null),t=usePreferencesTranslator();
const pos=computed(()=>portal?.session.state.identity?.account.type==='pos');
const instance=getCurrentInstance(),native=ref(null),trigger=ref(null),list=ref(null),open=ref(false),choices=shallowRef([]),fieldLabel=ref(''),validation=ref('');
const current=computed({get:()=>props.modelValue===undefined?props.value:props.modelValue,set:value=>{
 const number=entry=>{const parsed=Number.parseFloat(entry);return Number.isNaN(parsed)?entry:parsed;};
 if(Array.isArray(value)&&props.modelModifiers?.number){
  // Vue applies a component .number modifier to the emitted argument itself.
  // Multiple native selections convert each option instead of casting the array.
  const listeners=instance?.vnode.props?.['onUpdate:modelValue'],selected=value.map(number);
  for(const listener of Array.isArray(listeners)?listeners:listeners?[listeners]:[])listener(selected);
 }else emit('update:modelValue',value);
}});
const title=computed(()=>props.label||attrs['aria-label']||fieldLabel.value||t('اختر'));
const selectedText=computed(()=>choices.value.filter(option=>option.selected&&!option.hidden).map(option=>option.text).join('، ')||t('اختر'));
function sync(){
 if(!pos.value||!native.value)return;
 const next=selectChoices(native.value),same=next.length===choices.value.length&&next.every((option,index)=>Object.keys(option).every(key=>option[key]===choices.value[index]?.[key]));
 if(!same)choices.value=next;
 const label=native.value.closest('label');
 if(label){const text=Array.from(label.childNodes).filter(node=>node.nodeType===3).map(node=>node.textContent.trim()).filter(Boolean).join(' ');if(text!==fieldLabel.value)fieldLabel.value=text;}
}
function available(){return !props.disabled&&!native.value?.matches(':disabled');}
async function show(){
 if(!available())return;sync();open.value=true;await nextTick();await nextTick();
 const selected=choices.value.find(option=>option.selected&&!option.disabled&&!option.hidden)||choices.value.find(option=>!option.disabled&&!option.hidden);
 if(selected)list.value?.querySelector(`[data-choice="${selected.index}"]`)?.focus();
}
function close(){open.value=false;void nextTick(()=>trigger.value?.focus());}
function choose(index){
 if(!available()||!selectChoice(native.value,index))return;
 native.value.dispatchEvent(new Event('change',{bubbles:true}));validation.value='';sync();if(!props.multiple)close();
}
function change(event){emit('change',event);}
function invalid(event){
 if(!pos.value)return;event.preventDefault();validation.value=t('اختر قيمة لهذا الحقل.');
 // The browser checks every required control in a form. Open only the first
 // invalid control, leaving an earlier text field or picker in charge of focus.
 const first=native.value?.form?.querySelector('input:invalid,select:invalid,textarea:invalid');
 if(!first||first===native.value)void show();
}
function triggerKey(event){if(['ArrowDown','ArrowUp','Enter',' '].includes(event.key)){event.preventDefault();void show();}}
function optionKey(event,index){if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)){event.preventDefault();const next=nextChoice(choices.value,index,event.key);if(next!==null)list.value?.querySelector(`[data-choice="${next}"]`)?.focus();}}
onMounted(sync);onUpdated(sync);onBeforeUnmount(()=>open.value=false);
</script>
<template>
 <select v-if="!pos" v-bind="attrs" v-model="current" :disabled="disabled" :required="required" :multiple="multiple" @change="change"><slot/></select>
 <div v-else class="app-select" :class="attrs.class" :style="attrs.style">
  <button :id="attrs.id" ref="trigger" type="button" class="app-select-trigger" :disabled="disabled" :title="attrs.title" :aria-label="title" :aria-describedby="attrs['aria-describedby']" :aria-invalid="validation?'true':attrs['aria-invalid']" aria-haspopup="dialog" :aria-expanded="open" @click="show" @keydown="triggerKey"><span>{{selectedText}}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
  <select v-bind="attrs" :id="undefined" ref="native" v-model="current" class="app-select-native" :disabled="disabled" :required="required" :multiple="multiple" tabindex="-1" aria-hidden="true" @change="change" @invalid="invalid"><slot/></select>
  <span v-if="validation" class="field-error" role="alert">{{validation}}</span>
  <FinanceDialog :open="open" :title="title" variant="app-select-dialog" @close="close">
   <div ref="list" class="app-select-options" role="listbox" :aria-label="title" :aria-multiselectable="multiple||undefined">
    <button v-for="option in choices.filter(entry=>!entry.hidden)" :key="option.index" type="button" role="option" :data-choice="option.index" :aria-selected="option.selected" :disabled="disabled||option.disabled" @click="choose(option.index)" @keydown="optionKey($event,option.index)"><span>{{option.text}}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="option.selected?'m5 12 4 4L19 6':'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'"/></svg></button>
   </div>
   <footer v-if="multiple" class="formfoot"><button type="button" class="btn primary" @click="close">{{t('تم')}}</button></footer>
  </FinanceDialog>
 </div>
</template>
<style>
html[data-portal=pos] .app-select{position:relative;min-width:0;max-width:100%;width:100%;font:inherit}
html[data-portal=pos] .app-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;min-width:0;min-height:46px;padding:10px 13px;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--ink);font:inherit;text-align:start;cursor:pointer}
html[data-portal=pos] .app-select-trigger>span{min-width:0;overflow-wrap:anywhere}
html[data-portal=pos] .app-select-trigger>svg{width:18px;height:18px;flex:none;color:var(--muted)}
html[data-portal=pos] .app-select-trigger:disabled{opacity:.55;cursor:default}
html[data-portal=pos] .app-select-native{position:absolute!important;inset:0 auto auto 0!important;width:1px!important;height:1px!important;min-width:0!important;min-height:0!important;padding:0!important;margin:0!important;opacity:0!important;clip-path:inset(50%);pointer-events:none}
html[data-portal=pos] .app-select-dialog{width:min(520px,calc(100vw - 24px));max-height:calc(100dvh - 24px);padding:18px;border-radius:20px}
html[data-portal=pos] .app-select-options{display:grid;gap:8px;max-height:58dvh;overflow:auto;overscroll-behavior:contain;padding:3px}
html[data-portal=pos] .app-select-options>button{display:flex;align-items:center;justify-content:space-between;gap:14px;min-width:0;min-height:48px;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--ink);font:inherit;text-align:start;cursor:pointer}
html[data-portal=pos] .app-select-options>button>span{overflow-wrap:anywhere;min-width:0}
html[data-portal=pos] .app-select-options>button>svg{width:20px;height:20px;flex:none;color:var(--muted)}
html[data-portal=pos] .app-select-options>button[aria-selected=true]{background:var(--accent-soft);border-color:var(--pos-accent,var(--accent));color:var(--pos-accent,var(--accent))}
html[data-portal=pos] .app-select-options>button[aria-selected=true]>svg{color:inherit}
html[data-portal=pos] .app-select-options>button:disabled{opacity:.5;cursor:default}
</style>
