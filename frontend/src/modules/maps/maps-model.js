import { systemLabel } from '../preferences/system-labels.js';
export const presenceContextKey = Symbol('masal.presence');
export const roleSymbols = {main_agent:'◆',sub_agent:'■',sub_branch:'■',pos:'▲',employee:'●'};
export function validLocation(point) {return !!point&&typeof point.lat==='number'&&typeof point.lng==='number'&&Number.isFinite(point.lat)&&Number.isFinite(point.lng)&&Math.abs(point.lat)<=85&&Math.abs(point.lng)<=180;}
export function mapTime(value) {return value&&Number.isFinite(Date.parse(value))?new Date(value).toLocaleString('ar-IQ',{timeZone:'Asia/Baghdad'}):'غير متوفر';}
export function mapUser(row) {
  return {...row,kindLabel:systemLabel(row.kind,'accountType'),location:validLocation(row.location)?row.location:null,networkColor:/^#[0-9a-f]{6}$/i.test(row.networkColor||'')?row.networkColor:'#0898b5',symbol:roleSymbols[row.role]||'●',online:row.online===true,active:row.active===true};
}
export function branchIds(branch,branches) {
  const result=new Set([Number(branch)]);let changed=true;
  while(changed){changed=false;for(const row of branches)if(result.has(Number(row.parent_id))&&!result.has(Number(row.id))){result.add(Number(row.id));changed=true;}}
  return result;
}
export function filterMapUsers(users,filters,branches,serverNow) {
  const query=filters.query.trim().toLocaleLowerCase('ar'),tree=filters.branch?branchIds(filters.branch,branches):null,selected=new Set(filters.selected.map(Number));
  return users.map(row=>{
    const last=Date.parse(row.lastSeen),elapsed=serverNow-last;
    return {...row,online:row.online&&row.active&&Number.isFinite(elapsed)&&elapsed>=0&&elapsed<120000};
  }).filter(row=>(!query||`${row.name} ${row.account}`.toLocaleLowerCase('ar').includes(query))&&(!filters.type||(filters.type==='sub'?['sub_agent','sub_branch'].includes(row.role):row.role===(filters.type==='main'?'main_agent':filters.type)))&&(!tree||tree.has(Number(row.agent))||tree.has(Number(row.account_id)))&&(!filters.status||(filters.status==='online'?row.online:filters.status==='offline'?!row.online:!row.location))&&(!filters.custom||selected.has(Number(row.id))));
}
export function geolocationPayload(position,now=Date.now()) {
  const {latitude,longitude,accuracy}=position?.coords||{},point={lat:latitude,lng:longitude},timestamp=position?.timestamp;
  if(!validLocation(point)||!Number.isFinite(accuracy)||accuracy<0||accuracy>100000||!Number.isFinite(timestamp)||timestamp<now-300000||timestamp>now+60000) throw new Error('تعذر قبول الموقع؛ أعد تحديد موقع حديث وصحيح.');
  return {latitude,longitude,accuracy,recorded_at:new Date(timestamp).toISOString()};
}
export function identityKey(identity) {return identity?.user?.id?`${identity.user.id}:${identity.account.id}:${identity.membership.kind}`:'';}
export function requiresLocation(identity) {return !!identity?.user?.id&&identity.membership.kind==='owner'&&identity.account.type!=='system';}
