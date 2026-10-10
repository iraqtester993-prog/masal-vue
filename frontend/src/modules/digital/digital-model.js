import { amount,minor,decimal } from '../finance/finance-model.js';
export { money,time,errorText,createMutationKey } from '../finance/finance-model.js';
export const providerNames={rabiaa:'الرابعة',topup:'Topup'};
export const stateNames={pending:'قيد التنفيذ',review:'بانتظار التحقق',succeeded:'ناجحة',failed:'فاشلة',refunded:'مسترجعة'};
export function digitalDeepLink(query={}) {
  const text=key=>typeof query[key]==='string'?query[key]:'';
  const date=key=>{const value=text(key);if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return '';const parsed=new Date(`${value}T00:00:00Z`);return Number.isFinite(parsed.getTime())&&parsed.toISOString().slice(0,10)===value?value:'';};
  const id=key=>/^[1-9]\d*$/.test(text(key))&&Number.isSafeInteger(Number(text(key)))?text(key):'';
  const filters={provider:Object.hasOwn(providerNames,text('provider'))?text('provider'):'',main_account_id:id('main_account_id'),account_id:id('account_id'),product_id:id('product_id'),status:Object.hasOwn(stateNames,text('status'))?text('status'):'',from:date('from'),to:date('to'),q:text('q').slice(0,160)};
  if(filters.from&&filters.to&&filters.to<filters.from)filters.to='';
  const tab=['sell','log','settings'].includes(text('tab'))?text('tab'):Object.values(filters).some(Boolean)?'log':'';
  return {tab,filters};
}
export function authorizedDigitalTab(requested,permissions,fallback) {
  return requested==='sell'&&permissions.sell||requested==='log'&&permissions.view||requested==='settings'&&permissions.settings?requested:fallback;
}
export function credentialAfterIdentityChange(credential,field) {return field==='provider'||field==='company'?'':credential;}
export function normalizePhone(value) {
  let phone=String(value??'').replace(/[٠-٩۰-۹]/g,c=>String(c.charCodeAt(0)-(c<='٩'?1632:1776))).replace(/[\s()\-]/g,'').replace(/^\+|^00/,'');
  if(phone.startsWith('07'))phone=`964${phone.slice(1)}`;
  if(!/^9647\d{9}$/.test(phone))throw Error('أدخل رقم هاتف عراقي صحيحًا.');
  return phone;
}
export function salePayload(offer,form) {
  if(!offer?.gateway?.configured||!offer.gateway.purchases_enabled)throw Error('تنفيذ الخدمة غير مفعّل؛ يلزم اعتماد الربط الفعلي مع الشركة.');
  const payload={connection_id:Number(offer.connection_id),offer_id:Number(offer.id),offer_version:Number(offer.version),expected_retail:amount(offer.retail)};
  if(offer.provider==='topup'||offer.package_type==='premium') {
    const phone=normalizePhone(form.mobile),confirm=normalizePhone(form.confirm_mobile);
    if(phone!==confirm)throw Error('رقم الزبون وتأكيده غير متطابقين.');
    Object.assign(payload,{mobile:phone,confirm_mobile:confirm});
  }
  if(offer.package_type==='premium') {
    const first=String(form.first_name??'').trim(),last=String(form.last_name??'').trim(),province=String(form.bein_province_id??'');
    if(!first||!last||!offer.bein_provinces?.some(row=>String(row.id)===province))throw Error('أدخل اسم المشترك والعائلة ومحافظة صحيحة من القائمة.');
    Object.assign(payload,{first_name:first,last_name:last,bein_province_id:province});
  }
  return payload;
}
export function canReceipt(order,identity,can) {return identity?.account.type==='pos'&&Number(order.account_id)===Number(identity.account.id)&&order.status==='succeeded'&&can('digital.receipt')&&can('data.pin');}
export function canVerify(order,identity,can) {return order.verification_supported!==false&&identity?.account.type==='pos'&&Number(order.account_id)===Number(identity.account.id)&&['pending','review'].includes(order.status)&&can('digital.create');}
export function providerBalance(connections) {
  const rows=connections.filter(row=>row.provider==='topup');if(!rows.length)return null;
  const known=rows.filter(row=>row.company_balance!==null&&row.company_balance!==undefined);
  return {known:known.length,total:rows.length,amount:known.length===rows.length?decimal(known.reduce((sum,row)=>sum+minor(row.company_balance),0n)):null};
}
export function connectionPayload(draft,credential='') {
  if(!draft.account_id)throw Error('اختر الوكيل الرئيسي.');
  return {version:Number(draft.version||0),provider:draft.provider,account_id:Number(draft.account_id),...(draft.provider_id?{provider_id:Number(draft.provider_id)}:{}),active:!!draft.active,...(credential.trim()?{credential:credential.trim()}:{}),...(draft.catalog_snapshot_id?{catalog_snapshot_id:Number(draft.catalog_snapshot_id)}:{}),offers:draft.offers.map(row=>({...row.id?{id:Number(row.id)}:{},product_id:Number(row.product_id),remote_id:row.remote_id||'',province_id:row.province_id||'',...row.bein_province_id?{bein_province_id:row.bein_province_id}:{},retail:amount(row.retail),active:!!row.active}))};
}
export function catalogProductChoices(products,offers,boundProductId) {return products.filter(product=>!offers.some(offer=>Number(offer.product_id)===Number(product.id))||Number(boundProductId)===Number(product.id));}
