import {code, codes, date, referenceOf} from './order-parser.js';
export const stockStatus = (value) => ({pending:'بانتظار الاعتماد', approved:'معتمدة', returned:'معادة للتصحيح', rejected:'مرفوضة', reject:'مرفوضة', Loaded:'محمّلة', 'Partially Used':'مستخدمة جزئيًا', Completed:'مكتملة', Quarantined:'محجورة', Available:'متاحة', Sold:'مباعة', Damaged:'تالفة', Exported:'مسحوبة', 'Cancelled by Reversal':'ملغاة', cancelled:'ملغاة', restored:'مستعادة', restore:'مستعادة', compensated:'معوّضة', Compensated:'معوّضة', compensate:'معوّضة', replaced:'مستبدلة', Replaced:'مستبدلة', replace:'مستبدلة', loss:'خسارة', 'Written Off':'خسارة معتمدة', Reserved:'محجوزة', downloaded:'تم التنزيل', expired:'منتهية'})[value] || value || '—';
export const requestKey = () => crypto.randomUUID();
export function blankOrder(accountId = '') {
  return {order_key:requestKey(), account_id:accountId, provider_id:'', source_id:'', city:'', category_count:1, lines:[]};
}
export function matchedProduct(products, providerId, categoryCode) {
  const category = code(categoryCode);
  if (!category) return null;
  const matches = products.filter(product => Number(product.provider_id) === Number(providerId) && codes(product.import_codes).includes(category));
  return matches.length === 1 ? matches[0] : null;
}
export function importedLine(parsed, products, providerId, fallbackProduct = '') {
  const product = matchedProduct(products, providerId, parsed.categoryCode);
  return {key:requestKey(), name:parsed.name, category_code:parsed.categoryCode, product_id:product?.id || fallbackProduct || '', declared_count:parsed.declared, cost:'', expenses:'0.00', default_expiry:'', rows:parsed.rows, rawRows:parsed.rawRows, columnMap:{serial:'',pin:'',expiry:''}, reference_label:referenceOf(parsed.categoryCode)?.label || parsed.categoryCode || 'غير محددة'};
}
export function cardPayload(row, product) {
  const payload = {source_row:Number(row.source_row ?? row.sourceRow), serial:String(row.serial || ''), pin:String(row.pin || ''), expiry:date(row.expiry), cvc:String(row.cvc || ''), reference:String(row.reference || '')};
  if (row.parse_error || row.parseError) payload.parse_error = row.parse_error || row.parseError;
  const extraFields = Object.fromEntries((product?.extra_fields || []).map(field => [field.key, String(row.extra_fields?.[field.key] ?? row[field.key] ?? '')]));
  if (Object.keys(extraFields).length) payload.extra_fields = extraFields;
  return payload;
}
export function orderPayload(draft, products) {
  return {
    order_key:draft.order_key, account_id:Number(draft.account_id), provider_id:Number(draft.provider_id), source_id:Number(draft.source_id), city:draft.city, category_count:Number(draft.category_count),
    lines:draft.lines.map(line => ({key:line.key, name:line.name, category_code:line.category_code || null, product_id:line.product_id ? Number(line.product_id) : null, declared_count:line.declared_count ?? null, cost:String(line.cost).trim(), expenses:String(line.expenses || '0.00').trim(), default_expiry:line.default_expiry || null, rows:line.rows.map(row => cardPayload(row, products.find(product => product.id === Number(line.product_id)) || matchedProduct(products, draft.provider_id, line.category_code)))})),
  };
}
export function mapColumns(line) {
  line.rows = line.rawRows.map(raw => ({sourceRow:raw.line ?? raw.sourceRow, ...Object.fromEntries(['serial','pin','expiry'].map(key => [key, line.columnMap[key] === '' ? '' : String(raw.cells[Number(line.columnMap[key])] || '')]))}));
}
export function printableTime(value) {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.valueOf()) ? '—' : parsed.toLocaleString('ar-IQ', {timeZone:'Asia/Baghdad'});
}
export function amountText(amounts) {
  return Object.entries(amounts || {}).map(([currency, amount]) => `${amount} ${currency === 'IQD' ? 'د.ع' : '$'}`).join(' · ') || '—';
}
export function downloadText(name, text, type = 'text/csv;charset=utf-8') {
  const url = URL.createObjectURL(text instanceof Blob ? text : new Blob([text], {type})), link = document.createElement('a');
  link.href = url; link.download = name; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export function rejectedRows(lines) {
  return lines.flatMap(line => (line.checked || []).filter(row => row.error).map(row => ({'الملف':line.name,'سطر الملف':row.source_row,'رمز الفئة':line.category_code || '',Serial:String(row.serial || ''),PIN:String(row.pin || ''),Expiry:String(row.expiry || ''),CVC:String(row.cvc || ''),Reference:String(row.reference || ''),'سبب الرفض':row.error})));
}
export function rejectedCsv(lines) {
  const cell = value => `"${String(value ?? '').replaceAll('"','""')}"`;
  const rows = [['الملف','سطر الملف','Serial','Expiry','سبب الرفض']];
  for (const line of lines) for (const row of line.checked || []) if (row.error) rows.push([line.name, row.source_row, row.serial, row.expiry, row.error]);
  return '\uFEFF' + rows.map(row => row.map(cell).join(',')).join('\r\n');
}
