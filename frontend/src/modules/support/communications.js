import {createMutationKey, errorText, time} from '../finance/finance-model.js';

export {createMutationKey, errorText, time};
export const ticketStatuses = {open:'مفتوحة',replied:'تم الرد',escalated:'مصعّدة',closed:'مغلقة'};
export const audienceLabels = {all:'عام ضمن نطاقي',agents:'الوكلاء الرئيسيون فقط',branches:'الأفرع فقط',points:'نقاط البيع فقط',custom:'مخصص'};
export function searchUsers(users, query) {
  const term = query.trim().toLocaleLowerCase('ar');
  return users.filter(user => !term || `${user.name} ${user.email || ''} ${user.account_name || ''}`.toLocaleLowerCase('ar').includes(term));
}
export function changedNotifications() {
  window.dispatchEvent(new CustomEvent('masal:notifications-changed'));
}
export function attachmentUrl(id) {
  const base = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '');
  return `${base}/support/attachments/${Number(id)}`;
}
export function queryString(parameters) {
  return new URLSearchParams(Object.entries(parameters).filter(([,value]) => value !== '' && value !== null && value !== undefined)).toString();
}
export function downloadCsv({csv,filename}) {
  const url = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'}));
  const link = document.createElement('a'); link.href = url; link.download = filename; link.click();
  setTimeout(() => URL.revokeObjectURL(url),1000);
}
