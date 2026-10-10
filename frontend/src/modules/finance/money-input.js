import { amount, cleanMoney, minor } from './finance-model.js';
const states = new WeakMap();
function format(raw) {
  if (!/^-?\d*(?:\.\d*)?$/.test(raw)) return raw;
  const [whole, fraction] = raw.split('.');
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction === undefined ? '' : `.${fraction}`);
}
function render(el) {
  const value = cleanMoney(el.value), caret = el.selectionStart, count = cleanMoney(el.value.slice(0, caret ?? el.value.length)).length;
  el.value = format(value);
  if (document.activeElement === el && caret !== null) {
    let at=0, digits=0;
    while (at<el.value.length && digits<count) { if(el.value[at]!==',')digits++;at++; }
    el.setSelectionRange(at,at);
  }
  let error='';
  if (value) {
    try {
      amount(value);
      if (el.hasAttribute('min') && minor(value)<minor(el.getAttribute('min'))) error='المبلغ أقل من الحد المسموح.';
      if (el.hasAttribute('max') && minor(value)>minor(el.getAttribute('max'))) error='المبلغ أكبر من الحد المسموح.';
    } catch (failed) { error=failed.message; }
  }
  el.setCustomValidity(error);
}
export const vMoney = {
  created(el) {
    const clean=()=>{const before=cleanMoney(el.value.slice(0,el.selectionStart ?? el.value.length)).length;el.value=cleanMoney(el.value);if(el.selectionStart!==null)el.setSelectionRange(before,before);},display=()=>render(el);
    states.set(el,{clean,display});el.addEventListener('input',clean,true);el.addEventListener('change',clean,true);
  },
  mounted(el) {const {display}=states.get(el);el.addEventListener('input',display);el.addEventListener('change',display);render(el);},
  updated(el) {render(el);},
  beforeUnmount(el) {const state=states.get(el);if(!state)return;el.removeEventListener('input',state.clean,true);el.removeEventListener('change',state.clean,true);el.removeEventListener('input',state.display);el.removeEventListener('change',state.display);states.delete(el);},
};
