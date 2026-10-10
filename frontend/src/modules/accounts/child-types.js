const children = Object.freeze({
  system: ['main_agent'],
  main_agent: ['sub_agent', 'pos'],
  sub_agent: ['sub_branch', 'pos'],
  sub_branch: ['pos'],
  pos: [],
});
export function childTypes(account) {
  return account?.status === 'active' ? children[account.type] || [] : [];
}
