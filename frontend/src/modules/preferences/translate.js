let dictionaries=null,pending=null;
const patterns={};
export const languages=[{id:'ar',name:'العربية',direction:'rtl'},{id:'en',name:'English',direction:'ltr'},{id:'ckb',name:'کوردی',direction:'rtl'}];
export async function prepareLanguage(language) {
  if(language==='ar')return;
  if(!['en','ckb'].includes(language))throw Error('لغة غير مسموحة.');
  if(dictionaries)return;
  pending||=import('./original-dictionaries.js').then(module=>{dictionaries=module.default;}).catch(error=>{pending=null;throw Error('تعذر تحميل لغة الواجهة؛ أعد المحاولة.',{cause:error});});
  await pending;
}
export function translate(value,language='ar',protectedRecords=[]) {
  if(typeof value!=='string'||language==='ar')return value;
  const dictionary=dictionaries?.[language];if(!dictionary)return value;
  const names=[...new Set(protectedRecords.filter(name=>typeof name==='string'&&name))].sort((a,b)=>b.length-a.length),saved=[];
  let source=value;for(const name of names)if(source.includes(name)){const token=`\u0001${saved.length}\u0002`;saved.push(name);source=source.split(name).join(token);}
  const trimmed=source.trim();
  if(Object.hasOwn(dictionary,trimmed))source=source.replace(trimmed,()=>dictionary[trimmed]);
  else {patterns[language]||=new RegExp(`(?<![\\p{L}\\p{N}_])(?:${Object.keys(dictionary).filter(Boolean).sort((a,b)=>b.length-a.length).map(key=>key.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')).join('|')})(?![\\p{L}\\p{N}_])`,'gu');source=source.replace(patterns[language],key=>dictionary[key]??key);}
  return source.replace(/\u0001(\d+)\u0002/g,(_,index)=>saved[Number(index)]);
}
export function translateKnown(value,language='ar') {
  if(typeof value!=='string'||language==='ar')return value;
  const dictionary=dictionaries?.[language],key=value.trim();
  return dictionary&&Object.hasOwn(dictionary,key)?value.replace(key,()=>dictionary[key]):value;
}
