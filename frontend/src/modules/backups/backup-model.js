export const UPLOAD_BYTES = 15*1024*1024;
export const statuses = {creating:'جارٍ النسخ الاحتياطي',completed:'مكتمل',failed:'فشل',rollback_failed:'فشل الرجوع الآلي؛ النظام في الصيانة',queued:'بانتظار عامل الاسترجاع',preparing:'إنشاء قاعدة مستقلة',validated:'اكتمل فحص القاعدة المستقلة',switching:'جارٍ التحويل',};
export function terminalJob(status) { return ['completed','failed','rollback_failed'].includes(status); }
export function fileForPreview(file) {
  if(!file || !/\.json$/i.test(file.name)) throw new Error('اختر نسخة Laravel موقعة بصيغة JSON.');
  if(!Number.isSafeInteger(file.size)||file.size<1||file.size>UPLOAD_BYTES) throw new Error('الرفع من الحاسبة محدود بـ15 ميغابايت؛ استخدم نسخة السيرفر للأحجام الأكبر.');
  const form=new FormData();form.append('file',file);return form;
}
export function restorePayload(preview,password) {
  if(!preview?.reference_verified||!Number.isSafeInteger(preview.version)||preview.version<1||!preview.id) throw new Error('فحص النسخة لم يكتمل.');
  if(!password||password.length>255) throw new Error('أدخل كلمة المرور الحالية.');
  return {preview_id:preview.id,version:preview.version,current_password:password};
}
export function manifestCounts(manifest) {
  return {accounts:manifest?.counts?.accounts??null,cards:manifest?.counts?.stock_cards??null,files:manifest?.files??null,rows:manifest?.counts?Object.values(manifest.counts).reduce((sum,count)=>sum+(Number.isSafeInteger(count)?count:0),0):null};
}
export function bytes(value) {
  if(!Number.isSafeInteger(value)||value<0)return '—';
  return new Intl.NumberFormat('en',{maximumFractionDigits:2}).format(value/1024/1024)+' MB';
}
export function date(value) {
  if(!value||!Number.isFinite(Date.parse(value)))return '—';
  return new Intl.DateTimeFormat('ar-IQ',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Baghdad'}).format(new Date(value));
}
