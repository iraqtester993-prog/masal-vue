const contexts={support:['الدعم الفني','support'],wallets:['المحافظ','wallets'],invoices:['الفواتير','wallets'],sales:['المبيعات','sales'],exceptions:['الطباعة','sales'],accounts:['الحسابات','agents'],prices:['الأسعار','prices'],import:['الطلبيات','inventory'],inventory:['المخزون','inventory'],products:['الفئات','products'],digital:['الخدمات الإلكترونية','pos'],branding:['تصميم الوصل','sales']};
export function notificationContext(notice) {
  const [label,icon]=Object.hasOwn(contexts,notice?.page)?contexts[notice.page]:['إشعار الحساب','notifications'];
  return {label,icon};
}
function instant(value) {
  const raw=String(value||'').replace(' ','T');
  if(!raw)return null;
  const date=new Date(/Z$|[+-]\d\d:\d\d$/.test(raw)?raw:raw+'Z');
  return Number.isNaN(date.getTime())?null:date;
}
function day(date){return new Intl.DateTimeFormat('en-CA', {numberingSystem:'latn',timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit'}).format(date);}
export function notificationGroups(rows,{now=new Date(),language='ar'}={}) {
  const today=day(now),yesterday=day(new Date(now.getTime()-86400000)),locale={ar:'ar-IQ',en:'en-GB',ckb:'ckb-IQ'}[language]||'ar-IQ',groups=new Map();
  for(const notice of rows){
    const date=instant(notice.time),key=date?day(date):'unknown';
    const label=key===today?'اليوم':key===yesterday?'أمس':date?new Intl.DateTimeFormat(locale, {numberingSystem:'latn',timeZone:'Asia/Baghdad',dateStyle:'medium'}).format(date):'تاريخ غير متوفر';
    if(!groups.has(key))groups.set(key,{key,label,rows:[]});
    groups.get(key).rows.push(notice);
  }
  return [...groups.values()];
}
