import { amount, decimal, minor } from '../finance/finance-model.js';
export { amount, businessDay, createMutationKey, errorText, money, time } from '../finance/finance-model.js';
export const saleStatuses = {'Reserved':'محجوزة','Cancelled':'ملغاة','Print Requested':'بانتظار الطباعة','Print Failed':'فشل الطباعة','Printed':'مطبوعة','Reprinted':'أعيدت طباعتها','Reprint Requested':'طلب إعادة طباعة','Delivered':'تم التسليم',pending:'قيد المراجعة',approved:'معتمد',rejected:'مرفوض',used:'مستخدم',success:'نجاح الطباعة',failed:'فشل الطباعة'};
export const receiptBlocks = {company:'صورة الشركة واسمها',header:'النص العلوي',category:'اسم الفئة',image:'صورة الفئة',codes:'بيانات البطاقة',amount:'المبلغ',agent:'صورة الوكيل ونصه',footer:'النص السفلي'};
export const sellerTypes = ['sub_agent','sub_branch','pos'];
export function digitalCatalogProduct(offer) {
  const provider=offer.provider==='rabiaa'?(offer.provider_id??'rabiaa'):(offer.product_provider_id??'topup');
  return {id:offer.product_id,key:`digital:${offer.connection_id}:${offer.id}`,provider_id:/^[1-9]\d*$/.test(String(provider))?Number(provider):provider,provider_name:offer.provider==='rabiaa'?offer.company_name:(offer.product_provider_name??offer.company_name),name:offer.name,kind:offer.provider==='topup'?'topup':'rabiaa',price:offer.retail,currency:'IQD',digital_offer:offer};
}
export function catalogCompanyMatches(product,company) {return company==='all'||String(product.provider_id)===String(company);}
export function multiply(value,quantity) {if (value === null || value === undefined) return null; if (!Number.isSafeInteger(Number(quantity)) || Number(quantity) < 1) return null; return decimal(minor(value)*BigInt(quantity));}
export function salePayload(product,quantity,retail) {
  if (!product || product.price === null || !product.price_version) throw Error('لم يعتمد سعر هذه الفئة بعد.');
  const count = Number(quantity);
  if (!Number.isInteger(count) || count < 1 || count > 100000) throw Error('أدخل عددًا صحيحًا من البطاقات.');
  if (count > product.print_policy.max_cards) throw Error('عدد البطاقات أكبر من الحد المسموح للطلب الواحد.');
  if (count > product.stock_available) throw Error('عدد البطاقات المتاحة أقل من العدد المطلوب.');
  const price = amount(product.price);
  if (minor(multiply(price,count)) > minor(product.wallet_available)) throw Error('الرصيد المتاح لا يكفي لإصدار البطاقات.');
  return {product_id:Number(product.id),quantity:count,price_version:Number(product.price_version),expected_price:price,...(String(retail ?? '').trim() ? {retail_price:amount(retail)}:{})};
}
export function ownsSale(sale,identity) {return sellerTypes.includes(identity?.account.type) && Number(sale?.account_id) === Number(identity?.account.id);}
export function canReadReceipt(sale,identity,can) {return ownsSale(sale,identity) && !!sale?.issued_at && !['Reserved','Cancelled'].includes(sale.status) && can('sell.receipt') && can('data.pin');}
export function canRequestReprint(sale,identity,can) {return ownsSale(sale,identity) && !!sale?.issued_at && !sale.print_pending && ['Printed','Reprinted','Print Failed'].includes(sale.status) && can('sell.reprint');}
export function pendingAttempt(sale) {return sale?.attempt_id || sale?.attempts?.findLast(attempt => attempt.status === 'pending')?.id || null;}
export function canReviewRequest(request,identity,can) {return request?.status === 'pending' && can('exceptions.approve') && Number(request.recipient_id) === Number(identity?.account.id) && Number(request.creator_id) !== Number(identity?.user.id);}
export function copyPolicy(policy) {return {failed_retries:policy.failed_retries,max_cards:policy.max_cards,interval_seconds:policy.interval_seconds,daily_cards:policy.daily_cards,daily_mode:policy.daily_mode,daily_product_mode:policy.daily_product_mode,daily_products:[...policy.daily_products]};}
export function downloadBlob(blob,name) {const url = URL.createObjectURL(blob),link = document.createElement('a'); link.href = url; link.download = name; link.click(); setTimeout(()=>URL.revokeObjectURL(url),1000);}
export const browserAppVersion = '1.0.0'; // Matches this web client's package version; no invented OS/hardware version.

export function deviceHeartbeatPayload(device) {
  const serial=String(device.serial??'').trim(),os=String(device.os_version??'').trim();
  return {app_version:browserAppVersion,...(serial?{serial}:{}),...(os?{os_version:os}:{})};
}
