import test from 'node:test';
import assert from 'node:assert/strict';
import { accountPayload, canManageAttachments, changePermission, displayLogin, grantablePermissions, initialAccountContext, permissionGroups, selectedPermissions, staffPayload } from '../src/modules/accounts/account-model.js';
import { createPagedResource } from '../src/modules/accounts/paged-resource.js';

const deferred = () => { let resolve, reject; const promise = new Promise((yes,no) => {resolve=yes;reject=no;});return {promise,resolve,reject}; };
const page = (name) => ({data:[{id:name,name}],meta:{current_page:1,last_page:1,total:1}});

test('account creation follows original email identifier and preserves secret whitespace', () => {
  const secret='  ALongSecret123  ';
  const result=accountPayload({name:'  مكتب  ',city:'بغداد',phone:'123',owner_name:'صاحب المكتب',address:'عنوان',serial:'ABC',device_model:'M',app_version:'1',notes:'  نص  ',role:'system',status:'disabled',permissions:['admin']},{parent:{id:9},type:'pos',email:'  OWNER@EXAMPLE.TEST  ',password:secret,confirmation:secret});
  assert.equal(result.name,'مكتب');assert.equal(result.notes,'نص');assert.equal(result.parent_id,9);
  assert.deepEqual(result.user,{email:'owner@example.test',password:secret,password_confirmation:secret});
  assert.equal(result.role,undefined);assert.equal(result.status,undefined);assert.equal(result.permissions,undefined);
});
test('profile updates cannot carry topology, credentials or privilege fields', () => {
  const result=accountPayload({name:'Agent',city:'بغداد',phone:'123',color:'#168baf',notes:'',parent_id:99,type:'system',password:'ignored',permissions:['ignored']},{account:{version:7},type:'main_agent'});
  assert.equal(result.version,7);assert.equal(result.parent_id,undefined);assert.equal(result.type,undefined);assert.equal(result.password,undefined);assert.equal(result.permissions,undefined);assert.equal(result.user,undefined);
});
test('POS profile edits omit separately protected device and location fields', () => {
  const result=accountPayload({name:'POS',city:'بغداد',address:'Old address',serial:'OLD',device_model:'M',app_version:'1',phone:'123',owner_name:'Owner'},{account:{version:3},type:'pos',canDevice:false,canLocation:false});
  assert.equal(result.city,undefined);assert.equal(result.address,undefined);assert.equal(result.serial,undefined);assert.equal(result.device_model,undefined);assert.equal(result.app_version,undefined);assert.equal(result.device_lock_enabled,undefined);
  assert.equal(result.owner_name,'Owner');assert.equal(result.version,3);
});
test('staff data edit omits unchanged role and scope, without sending password', () => {
  const staff={version:4,permission_profile_id:3,scope_roots:[8,9],include_descendants:false};
  const result=staffPayload({name:' Test ',email:'TEST@EXAMPLE.TEST',notes:' Note ',permission_profile_id:'3',scope_roots:[9,8],include_descendants:false},{staff,reason:'Reason',password:'ignored'});
  assert.deepEqual(result,{name:'Test',email:'test@example.test',notes:'Note',version:4,reason:'Reason'});
});
test('staff editor never submits role or scope changes without their separate capabilities', () => {
  const result=staffPayload({name:'Test',email:'test@example.test',notes:'',permission_profile_id:'99',scope_roots:[999],include_descendants:true},{staff:{version:1,permission_profile_id:3,scope_roots:[1],include_descendants:false},canRole:false,canScope:false,reason:'Name only'});
  assert.equal(result.permission_profile_id,undefined);assert.equal(result.scope_roots,undefined);assert.equal(result.include_descendants,undefined);
});
test('catalog boundaries exclude unsupported permissions and give original Arabic module names', () => {
  const catalog=[{key:'staff.view',label:'عرض الموظفين',group:'staff'},{key:'account.view',label:'عرض الحسابات',group:'account'}];
  assert.deepEqual(selectedPermissions(catalog,['staff.view','finance.transfer']),['staff.view']);
  assert.equal(permissionGroups(catalog,'موظف')[0].title,'الموظفون');
  assert.equal(displayLogin({login:null,email:'email@example.test'}),'email@example.test');
});
test('grant options use server authority limits in addition to actor permissions', () => {
  const catalog=[{key:'account.view'},{key:'account.create'},{key:'staff.view'}];
  const options=grantablePermissions(catalog,['account.view','staff.view'],(key)=>key!=='staff.view');
  assert.deepEqual(options,[{key:'account.view'}]);
  assert.deepEqual(grantablePermissions(catalog,undefined,()=>true),[]);
});
test('permission changes retain locked rights and add required view dependencies only within authority', () => {
  const keys=['account.view','staff.view','staff.create'];
  assert.deepEqual(changePermission([], 'staff.create', true, keys, true),keys);
  assert.deepEqual(changePermission([], 'staff.create', true, ['staff.create'], true),[]);
  assert.deepEqual(changePermission(['staff.view','staff.create'],'staff.view',false,['staff.view']),['staff.view','staff.create']);
  assert.deepEqual(changePermission(keys,'account.view',false,keys,true),[]);
  assert.deepEqual(changePermission(['account.view'],'account.view',false,['account.view'],false),[]);
});
test('employee pages start from a visible scoped account instead of an unavailable employer', async () => {
  const identity={account:{id:1,name:'System'},membership:{kind:'employee'}};const calls=[];
  const selected=await initialAccountContext(identity,{accounts:async(query)=>{calls.push(query);return {data:[{id:9,name:'Assigned agent'}]};}});
  assert.equal(selected.id,9);assert.deepEqual(calls,[{page:1,per_page:1}]);
  await assert.rejects(initialAccountContext(identity,{accounts:async()=>({data:[]})}));
  assert.equal(await initialAccountContext({...identity,membership:{kind:'owner'}},{}),identity.account);
});
test('image management also requires account update and forbids self or system targets', () => {
  const identity={account:{id:1}};const target={id:2,type:'main_agent'};
  assert.equal(canManageAttachments(identity,(key)=>key==='account.attachments.manage',target),false);
  assert.equal(canManageAttachments(identity,()=>true,target),true);
  assert.equal(canManageAttachments(identity,()=>true,{id:1,type:'main_agent'}),false);
  assert.equal(canManageAttachments(identity,()=>true,{id:2,type:'system'}),false);
});
test('an older list response cannot overwrite a newer server filter', async () => {
  const older=deferred();
  const resource=createPagedResource((parameters)=>parameters.q==='old'?older.promise:Promise.resolve(page('new')));
  const pending=resource.load({q:'old'});await resource.load({q:'new'});older.resolve(page('old'));await pending;
  assert.equal(resource.state.rows[0].name,'new');assert.equal(resource.state.loading,false);resource.dispose();
});
test('an old forbidden request cannot clear a newer identity through failure handling', async () => {
  const older=deferred();let failures=0;
  const resource=createPagedResource((parameters)=>parameters.q==='old'?older.promise:Promise.resolve(page('new')),async()=>{failures+=1;return 'forbidden';});
  const pending=resource.load({q:'old'});await resource.load({q:'new'});older.reject(Object.assign(new Error('old'),{status:403}));await pending;
  assert.equal(failures,0);assert.equal(resource.state.rows[0].name,'new');resource.dispose();
});
test('disposing a page keeps pending results out of its previous account context', async () => {
  const pending=deferred();const resource=createPagedResource(()=>pending.promise);
  const work=resource.load();resource.dispose();pending.resolve(page('previous account'));await work;
  assert.deepEqual(resource.state.rows,[]);assert.equal(resource.state.loading,false);
});

test('POS device restriction payload preserves explicit off and defaults old accounts to on',()=>{
  for(const [flag,expected] of [[false,false],[true,true],[undefined,true]]) {
    assert.equal(accountPayload({device_lock_enabled:flag},{type:'pos',account:{version:2}}).device_lock_enabled,expected);
  }
  assert.equal(accountPayload({device_lock_enabled:false},{type:'main_agent',account:{version:2}}).device_lock_enabled,undefined);
});


test('authorized login edits accompany the profile and omit unchanged or unauthorized identifiers', () => {
  const account={version:7,owner_user:{email:'old@example.test',login:null}};
  const form={name:'Agent',city:'بغداد',phone:'123',color:'#168baf'};
  const options={account,type:'main_agent',login:' NEW.LOGIN ',loginReason:' Correction ',canLogin:true};
  const updated=accountPayload(form,options);
  assert.equal(updated.login,'new.login');assert.equal(updated.reason,'Correction');assert.equal(updated.version,7);
  assert.equal(accountPayload(form,{...options,canLogin:false}).login,undefined);
  assert.equal(accountPayload(form,{...options,login:' OLD@EXAMPLE.TEST '}).login,undefined);
  assert.equal(accountPayload(form,{...options,login:' OLD@EXAMPLE.TEST '}).reason,undefined);
});


test('website inquiry tracking requires a private signed token and keeps it out of URL query strings', async () => {
  const {validTrackingToken,inquiryTrackingUrl,inquiryTokenFromHash}=await import('../src/modules/company/inquiry-model.js');
  const token='42.'+'a'.repeat(64);
  assert.equal(validTrackingToken(token),true);assert.equal(validTrackingToken('42'),false);assert.equal(validTrackingToken('javascript:alert(1)'),false);
  const url=new URL(inquiryTrackingUrl(token));assert.equal(url.origin,'https://dananir-iq.com');assert.equal(url.pathname,'/messages');assert.equal(url.search,'');assert.equal(inquiryTokenFromHash(url.hash),token);
  assert.equal(inquiryTrackingUrl('42'),'');assert.equal(inquiryTokenFromHash('#inquiry=42'),'');
});
