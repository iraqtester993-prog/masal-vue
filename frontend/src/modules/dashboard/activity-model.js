import {systemLabel} from '../preferences/system-labels.js';

const completedStates = new Set(['recorded', 'posted', 'succeeded', 'refunded', 'Printed', 'Reprinted', 'Delivered', 'Issued']);
const pendingStates = new Set(['pending', 'processing', 'unknown', 'Print Requested', 'Reprint Requested', 'Reserved']);
export function activityTone(status) { return completedStates.has(status) ? 'success' : pendingStates.has(status) ? 'pending' : 'other'; }

export function activitySource(operation) {
  if(operation.kind==='ledger')return 'المحفظة';
  if(operation.kind==='stock')return 'البطاقات';
  if(['topup','rabiaa'].includes(operation.kind))return systemLabel(operation.kind,'provider');
  const sources={wallets:'المحافظ',invoices:'الفواتير',prices:'الأسعار',sell:'المبيعات',sales:'المبيعات',digital:'الخدمات الإلكترونية',topup:'Topup',auth:'الحساب',account:'الحسابات',staff:'المستخدمين',support:'الدعم الفني',notifications:'الإشعارات',security:'الحماية',company:'الشركة',inventory:'المخزون',import:'الطلبيات',products:'الفئات',providers:'الشركات',permission_profile:'الصلاحيات',backup:'النسخ الاحتياطي',branding:'تصميم الوصل',sessions:'الجلسات'};
  return sources[String(operation.action||'').split('.')[0]]||'النظام';
}

export function activityTitle(operation) {
  if(operation.kind==='ledger'){
    const label=systemLabel(operation.action,'movement');
    return label==='غير محدد'?'حركة مالية':label;
  }
  if(operation.action==='digital.purchase')return 'بيع خدمة إلكترونية';
  if(operation.kind==='stock'&&operation.action==='sales.issue')return 'بيع بطاقات';
  if(!operation.action)return operation.kind==='stock'?'بيع بطاقات':'شراء خدمة إلكترونية';
  const label=systemLabel(operation.action,'action');
  return label==='إجراء آخر'?`نشاط في ${activitySource(operation)}`:label;
}

export function activityDate(value, language = 'ar') {
  const raw = String(value || '').replace(' ', 'T');
  if (!raw) return '—';
  const normalized = /Z$|[+-]\d\d:\d\d$/.test(raw) ? raw : raw + 'Z';
  const instant = new Date(normalized);
  if (Number.isNaN(instant.getTime())) return '—';
  const locale = { ar: 'ar-IQ', en: 'en-GB', ckb: 'ckb-IQ' }[language] || 'ar-IQ';
  return instant.toLocaleString(locale, {numberingSystem:'latn', timeZone: 'Asia/Baghdad', year:'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
}
