export function selectChoices(select){
 return Array.from(select?.options||[],(option,index)=>({index,value:Object.hasOwn(option,'_value')?option._value:option.value,text:option.label||option.textContent||'',selected:option.selected,disabled:option.disabled||option.parentElement?.disabled===true,hidden:option.hidden===true||option.parentElement?.hidden===true}));
}
export function selectChoice(select,index){
 const option=select?.options?.[index];
 if(!option||select.disabled||select.matches?.(':disabled')||option.disabled||option.hidden||option.parentElement?.disabled===true||option.parentElement?.hidden===true)return false;
 if(select.multiple)option.selected=!option.selected;
 else for(const entry of select.options)entry.selected=entry===option;
 return true;
}
export function nextChoice(choices,index,key){
 const enabled=choices.filter(option=>!option.disabled&&!option.hidden).map(option=>option.index);
 if(!enabled.length)return null;
 if(key==='Home')return enabled[0];if(key==='End')return enabled.at(-1);
 const current=enabled.indexOf(index),step=key==='ArrowUp'?-1:1;
 return enabled[(current<0?(step<0?enabled.length-1:0):(current+step+enabled.length)%enabled.length)];
}
