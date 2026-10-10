import test from 'node:test';
import assert from 'node:assert/strict';
import {createReferenceApi, referenceDraft, referencePayload} from '../src/modules/reference/reference-api.js';
import {accountPayload, permissionDependencies} from '../src/modules/accounts/account-model.js';
test('source mutations retain server network and omit it on edits', () => {
  assert.deepEqual(referencePayload('sources',{name:' مصدر ',provider_id:'4'},'9'),{name:'مصدر',provider_id:4,network_account_id:9});
  assert.deepEqual(referencePayload('sources',{id:3,version:7,name:' مصدر ',provider_id:'4'},'99'),{name:'مصدر',version:7,provider_id:4});
});
test('optional representative text is retained and never carries photos or privileges', () => {
  const draft=referenceDraft('representatives',{id:4,version:5,agent_account_id:8,name:'',phone:' 123 ',address:' Address ',permissions:['ignored'],photos:[{id:1}]});
  assert.deepEqual(referencePayload('representatives',draft),{name:'',version:5,phone:'123',address:'Address',agent_account_id:8});
});
test('reference reads use the strict query contract and profile writes carry the version', async () => {
  const calls=[];const api=createReferenceApi({request:async(...args)=>calls.push(args),mutate:async(...args)=>calls.push(args)});
  await api.list('representatives',{query:'بغداد',page:2});await api.getProfile(7);await api.profile(7,{version:5,pos_type_id:null,representative_ids:[4]});
  assert.equal(calls[0][0],'/reference/representatives?query=%D8%A8%D8%BA%D8%AF%D8%A7%D8%AF&page=2');
  assert.equal(calls[1][0],'/accounts/7/reference-profile');assert.deepEqual(calls[2].slice(0,3),['/accounts/7/reference-profile','PUT',{version:5,pos_type_id:null,representative_ids:[4]}]);
});
test('POS selections are sent with account data only when each capability is present', () => {
  const options={account:{version:2},type:'pos',reference:{pos_type_id:'3',representative_ids:[5,6]},canType:false,canRepresentatives:true};
  const payload=accountPayload({name:'POS'},options);assert.equal(payload.pos_type_id,undefined);assert.deepEqual(payload.representative_ids,[5,6]);
  assert.equal(accountPayload({name:'Agent'},{...options,type:'main_agent'}).representative_ids,undefined);
  assert.deepEqual(permissionDependencies('representatives.images'),['representatives.view']);
});
