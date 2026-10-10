import {childTypes} from '../modules/accounts/child-types.js';

export function quickActions(identity, can) {
  if (!identity?.account) return [];
  const types=childTypes(identity.account), type=identity.account.type;
  return [
    {id:'sell',label:'＋ عملية بيع',symbol:'▣',route:'sell',allowed:['sub_agent','sub_branch','pos'].includes(type)&&can('sell.view')&&can('sell.create')},
    {id:'import',label:'＋ طلبية جديدة',symbol:'↥',route:'import',allowed:['system','main_agent'].includes(type)&&can('import.view')&&can('import.preview')},
    {id:'agent',label:'إضافة وكيل',symbol:'◇',route:'agents',allowed:types.some(t=>t!=='pos')&&can('account.view')&&can('account.create')},
    {id:'pos',label:'إضافة نقطة بيع',symbol:'▤',route:'pos',allowed:type!=='pos'&&can('account.view')&&can('account.create')},
    {id:'support',label:'＋ رسالة جديدة',symbol:'☏',route:'support',allowed:can('support.view')&&can('support.create')},
    {id:'deposit',label:'＋ إيداع',symbol:'＋',route:'wallets',allowed:type==='system'&&can('wallets.view')&&can('wallets.deposit')},
  ].filter(action=>action.allowed);
}
