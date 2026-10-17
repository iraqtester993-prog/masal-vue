import {pendingAttempt} from './sales-model.js';

// A printer accepting the job does not establish that the paper came out.
// Only an explicit user choice can produce a result for the current attempt.
export function printResultPayload(sale,success,reason='') {
  const attempt=Number(pendingAttempt(sale));
  if(!sale?.print_pending || !Number.isSafeInteger(attempt) || attempt<1)throw Error('لا توجد محاولة طباعة معلقة لتأكيد نتيجتها.');
  if(typeof success!=='boolean')throw Error('اختر نتيجة الطباعة أولًا.');
  const explanation=String(reason??'').trim();
  if(!success && !explanation)throw Error('اذكر سبب فشل الطباعة.');
  if(explanation.length>1000)throw Error('سبب الفشل أطول من الحد المسموح.');
  return {version:sale.version,attempt_id:attempt,success,...(!success?{reason:explanation}:{})};
}
