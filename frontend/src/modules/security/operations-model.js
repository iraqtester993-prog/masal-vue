export const operationFields = [{key:'app',label:'إيقاف النظام بالكامل'},{key:'login',label:'إيقاف الدخول'},{key:'sales',label:'إيقاف البيع'},{key:'printing',label:'إيقاف الطباعة'},{key:'import',label:'إيقاف رفع الطلبيات'}];
export const scopes = [{id:'all',label:'عام — جميع الحسابات'},{id:'main',label:'الوكلاء الرئيسيون فقط',type:'main_agent'},{id:'branch',label:'الوكلاء الفرعيون فقط',type:'sub_agent'},{id:'subbranch',label:'الأفرع الفرعية فقط',type:'sub_branch'},{id:'pos',label:'نقاط البيع فقط',type:'pos'},{id:'custom',label:'حسابات مخصصة'}];
export const scopeLabel = value => scopes.find(scope => scope.id === value)?.label || value;
export const actionLabel = value => operationFields.find(field => field.key === value)?.label || value;
export const emptyTimePolicy = () => ({enabled:false,dateEnabled:false,startAt:'',endAt:'',hoursEnabled:false,startTime:'08:00',endTime:'16:00',idleEnabled:false,idleMinutes:60,sessionEnabled:false,sessionMinutes:60});
export function previewCount(accounts,scope,selected) {
  if (scope === 'custom') {const set = new Set(selected.map(Number)); return accounts.filter(account => set.has(account.id)).length;}
  const type = scopes.find(option => option.id === scope)?.type;
  return accounts.filter(account => scope === 'all' || account.type === type).length;
}
export function systemOwner(identity) {return identity?.account?.type === 'system' && identity?.membership?.kind === 'owner';}
