import { systemLabel } from '../preferences/system-labels.js';
const reportDateFormatter = new Intl.DateTimeFormat('ar-IQ',{timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'});
export const reportKinds = [ ['sales','المبيعات والأرباح'],['inventory','المخزون'],['wallets','المحافظ والتمويل'],['network','الوكلاء ونقاط البيع'],['prices','الأسعار'],['claims','المطالبات'],['support','الدعم'],['users','المستخدمون'],['audit','التدقيق'],['operations','الإعدادات والعمليات'] ];
export { statusLabels } from '../preferences/system-labels.js';
export const tr = (value) => String(value ?? '').replaceAll('المزودين','الشركات').replaceAll('المزود','الشركة').replaceAll('المنتجات والفئات','الفئات').replaceAll('سعر التحميل','سعر البيع');
export function formatNumber(value) {
  if (value === null || value === undefined || value === '') return '—';
  const text = String(value); const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text);
  if (!match) return text;
  const fraction = (match[3] || '').replace(/0+$/, '');
  return match[1] + match[2].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction ? '.' + fraction : '');
}
export function reportCell(value, column = {}, row = {}) {
  if (value === null || value === undefined || value === '') return '—';
  if (column.type === 'money' || column.type === 'number') return formatNumber(value);
  if (column.type === 'percent') return formatNumber(Number(value).toFixed(2)) + '%';
  if (column.type === 'date') {
    const raw = String(value), date = new Date(/(?:Z|[+-]\d\d:\d\d)$/.test(raw) ? raw : raw.replace(' ', 'T') + 'Z');
    return Number.isFinite(date.getTime()) ? reportDateFormatter.format(date) : raw;
  }
  if (column.label_type==='change') {
    if (row.field==='status') return systemLabel(value,'status');
    if (row.field==='allowed') return ['1',1,true,'true'].includes(value)?'مسموح':['0',0,false,'false'].includes(value)?'غير مسموح':String(value);
  }
  return column.label_type ? systemLabel(value,column.label_type) : String(value);
}
export function businessDay(date = new Date()) {
  return new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit'}).format(date);
}
export function preset(kind, now = new Date()) {
  const day = businessDay(now), date = new Date(`${day}T12:00:00Z`);
  if (kind === 'all') return {from:'',to:''};
  if (kind === 'week') date.setUTCDate(date.getUTCDate()-6);
  if (kind === 'month') date.setUTCDate(1);
  return {from:date.toISOString().slice(0,10),to:day};
}
export const emptyFilters = () => ({from:'',to:'',kind:'all',currency:'IQD',agent_id:'',pos_id:'',product_id:'',provider_id:'',city:'',status:''});
export function descendantOf(accounts, childId, ancestorId) {
  if (!ancestorId) return true;
  const byId = new Map(accounts.map((row)=>[Number(row.id),row])), seen = new Set();
  let current = Number(childId);
  while (current && !seen.has(current)) { if (current === Number(ancestorId)) return true; seen.add(current); current = Number(byId.get(current)?.parent_id); }
  return false;
}
const extraTitles = { network: {main:'الوكلاء الرئيسيون',branch:'الفروع',nested:'الفروع الفرعية'},'network-pos':{active:'نقاط البيع النشطة',inactive:'نقاط البيع المعطّلة'},'inventory-cards':{available:'البطاقات المتاحة',issued:'البطاقات المباعة',held:'البطاقات المحجورة والمصدّرة'} };
export function groupCards(group, sections) {
  return sections.filter((s)=>group.prefixes.includes(s.id.split('-')[0])).flatMap((s)=>[ {...s,filter:''},...Object.entries(extraTitles[s.id]??{}).map(([filter,title])=>({...s,filter,title,count:s.subcounts?.[filter]??null})) ]);
}
export function errorText(error) { return Object.values(error?.errors??{}).flat().filter((v)=>typeof v==='string').join(' ') || error?.message || 'تعذر تحميل البيانات.'; }
export function workbookSections(data) {
  return data.sections.map((section)=>({name:tr(section.title),title:tr(section.title),note:[...new Set([section.reason,section.note,...section.columns.flatMap((column)=>[column.reason,column.source_note])].filter(Boolean)),`العملة: ${data.currency} · ${data.timezone} · ${reportCell(data.generated_at,{type:'date'})}`].join(' · '),headers:section.columns.map((c)=>tr(c.label)),rows:section.rows.map((row)=>section.columns.map((c)=>c.type==='money'?String(row[c.key]??'—'):reportCell(row[c.key],c,row))),emptyMessage:section.available?'لا توجد سجلات مطابقة.':section.reason}));
}
export function printableReport(data) {
  const escape = (s) => String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
  const blocks=workbookSections(data);
  return '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>التقرير المنسق</title><style>body{font:12px Tahoma,sans-serif;color:#123d57}section{break-before:page}section:first-child{break-before:auto}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccdbe3;padding:7px;text-align:right;overflow-wrap:anywhere}th{background:#e4f3f7}h2{color:#078ba8}thead{display:table-header-group}tr{break-inside:avoid}@page{size:A4 landscape;margin:10mm}</style><body>'+blocks.map((s)=>'<section><h2>'+escape(s.title)+'</h2><p>'+escape(s.note)+'</p><table><thead><tr>'+s.headers.map((v)=>'<th>'+escape(v)+'</th>').join('')+'</tr></thead><tbody>'+s.rows.map((row)=>'<tr>'+row.map((v)=>'<td>'+escape(v)+'</td>').join('')+'</tr>').join('')+'</tbody></table>'+(!s.rows.length?'<p>'+escape(s.emptyMessage)+'</p>':'')+'</section>').join('')+'</body></html>';
}
