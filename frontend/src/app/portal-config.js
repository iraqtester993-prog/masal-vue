export const portalConfigs = Object.freeze({
  admin: { id: 'admin', title: 'مدير النظام', description: 'إدارة النظام والحسابات والصلاحيات', accent: 'admin' },
  agents: { id: 'agents', title: 'الوكلاء', description: 'حساب الوكيل والشبكة التابعة له', accent: 'agents' },
  pos: { id: 'pos', title: 'نقاط البيع', description: 'حساب نقطة البيع وعملياتها', accent: 'pos' },
});

export const accountTypeLabels = Object.freeze({
  system: 'إدارة النظام',
  admin: 'إدارة النظام',
  main_agent: 'وكيل رئيسي',
  sub_agent: 'وكيل فرعي',
  sub_branch: 'فرع فرعي',
  pos: 'نقطة بيع',
});

export function accountTypeLabel(type) {
  return accountTypeLabels[type] || 'حساب';
}
