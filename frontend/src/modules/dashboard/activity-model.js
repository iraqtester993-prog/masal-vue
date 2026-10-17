const completedStates = new Set(['recorded', 'succeeded', 'Printed', 'Reprinted', 'Delivered', 'Issued']);
const pendingStates = new Set(['pending', 'processing', 'unknown', 'Print Requested', 'Reprint Requested', 'Reserved']);
export function activityTone(status) { return completedStates.has(status) ? 'success' : pendingStates.has(status) ? 'pending' : 'other'; }

export function activityDate(value, language = 'ar') {
  const raw = String(value || '').replace(' ', 'T');
  if (!raw) return '—';
  const normalized = /Z$|[+-]\d\d:\d\d$/.test(raw) ? raw : raw + 'Z';
  const instant = new Date(normalized);
  if (Number.isNaN(instant.getTime())) return '—';
  const locale = { ar: 'ar-IQ', en: 'en-GB', ckb: 'ckb-IQ' }[language] || 'ar-IQ';
  return instant.toLocaleString(locale, { timeZone: 'Asia/Baghdad', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
}
