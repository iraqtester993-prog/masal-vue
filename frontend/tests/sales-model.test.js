import test from 'node:test';
import assert from 'node:assert/strict';
import {createSalesApi,queryString} from '../src/modules/sales/sales-api.js';
import {canReadReceipt,canRequestReprint,canReviewRequest,createMutationKey,multiply,pendingAttempt,salePayload,digitalCatalogProduct,catalogCompanyMatches} from '../src/modules/sales/sales-model.js';
const product={id:3,price:'100.25',price_version:8,stock_available:5,wallet_available:'501.25',print_policy:{max_cards:10}};
test('Fourth with only a token keeps its service bucket while Topup joins the actual local product company and stock remains selectable',()=>{
  const base={id:7,connection_id:3,product_id:8,name:'API category',retail:'5000.25',provider_id:null,product_provider_id:'2',product_provider_name:'آسياسيل'};
  const fourth=digitalCatalogProduct({...base,provider:'rabiaa',company_name:'الرابعة'}),topup=digitalCatalogProduct({...base,id:9,product_id:10,provider:'topup',company_name:'Topup'}),stock={id:11,provider_id:2,name:'Physical category'};
  assert.equal(fourth.kind,'rabiaa');assert.equal(topup.kind,'topup');assert.equal(fourth.provider_id,'rabiaa');assert.equal(fourth.provider_name,'الرابعة');assert.equal(topup.provider_id,2);assert.equal(topup.provider_name,'آسياسيل');
  assert.deepEqual([stock,fourth,topup].filter(row=>catalogCompanyMatches(row,'rabiaa')).map(row=>row.id),[8]);
  assert.deepEqual([stock,fourth,topup].filter(row=>catalogCompanyMatches(row,'2')).map(row=>row.id),[11,10]);
  assert.deepEqual([stock,fourth,topup].filter(row=>catalogCompanyMatches(row,'all')).map(row=>row.id),[11,8,10]);
  assert.equal(digitalCatalogProduct({...base,provider:'rabiaa',provider_id:5,company_name:'الرابعة'}).provider_id,5);
  const legacyTopup=digitalCatalogProduct({...base,provider:'topup',product_provider_id:undefined,company_name:'Topup'});
  assert.equal(legacyTopup.provider_id,'topup');assert.equal(catalogCompanyMatches(legacyTopup,'topup'),true);
  assert.equal(catalogCompanyMatches(fourth,''),false);
});
test('sale quote uses exact decimal strings and rejects unapproved, insufficient or fractional quantities',()=>{
  assert.deepEqual(salePayload(product,3,'١١٠٫٥٠'),{product_id:3,quantity:3,price_version:8,expected_price:'100.25',retail_price:'110.50'});
  assert.equal(multiply('123456789.99',100000),'12345678999000.00');
  assert.throws(()=>salePayload({...product,price:null},1,''),/لم يعتمد/);
  assert.throws(()=>salePayload(product,1.5,''),/عددًا صحيحًا/);
  assert.throws(()=>salePayload(product,6,''),/المتاحة/);
  assert.throws(()=>salePayload({...product,wallet_available:'100.24'},1,''),/الرصيد/);
});
test('receipt is restricted to the actual selling account and issued records even for a privileged system actor',()=>{
  const sale={account_id:12,issued_at:'2026-10-06T09:00:00Z',status:'Printed'};
  assert.equal(canReadReceipt(sale,{account:{id:12,type:'pos'}},()=>true),true);
  for(const identity of [{account:{id:1,type:'system'}},{account:{id:11,type:'main_agent'}},{account:{id:13,type:'pos'}}])assert.equal(canReadReceipt(sale,identity,()=>true),false);
  assert.equal(canReadReceipt({...sale,issued_at:null,status:'Reserved'},{account:{id:12,type:'pos'}},()=>true),false);
  assert.equal(canReadReceipt(sale,{account:{id:12,type:'pos'}},permission=>permission!=='data.pin'),false);
});
test('reprint review stays with current recipient and requires a different creator user',()=>{
  const request={status:'pending',creator_id:40,recipient_id:11};
  assert.equal(canReviewRequest(request,{account:{id:11},user:{id:41}},()=>true),true);
  assert.equal(canReviewRequest(request,{account:{id:11},user:{id:40}},()=>true),false);
  assert.equal(canReviewRequest(request,{account:{id:1},user:{id:41}},()=>true),false);
  assert.equal(canReviewRequest({...request,status:'approved'},{account:{id:11},user:{id:41}},()=>true),false);
});
test('a POS owner can request another reprint after successful printing while an active attempt or foreign sale blocks it',()=>{
  const identity={account:{id:12,type:'pos'}},sale={account_id:12,issued_at:'2026-10-06T09:00:00Z',status:'Printed',print_pending:false};
  for(const status of ['Printed','Reprinted','Print Failed'])assert.equal(canRequestReprint({...sale,status},identity,permission=>permission==='sell.reprint'),true);
  for(const status of ['Reserved','Cancelled','Delivered','Print Requested','Reprint Requested'])assert.equal(canRequestReprint({...sale,status},identity,()=>true),false);
  assert.equal(canRequestReprint({...sale,print_pending:true},identity,()=>true),false);
  assert.equal(canRequestReprint({...sale,account_id:13},identity,()=>true),false);
  assert.equal(canRequestReprint(sale,{account:{id:12,type:'system'}},()=>true),false);
});
test('a new print attempt supersedes earlier history and an uncertain retry retains the exact key',()=>{
  assert.equal(pendingAttempt({attempt_id:9,attempts:[{id:3,status:'pending'}]}),9);
  assert.equal(pendingAttempt({attempts:[{id:3,status:'success'},{id:4,status:'pending'}]}),4);
  let serial=0;const key=createMutationKey(()=>`test-key-${++serial}`),payload={version:8,attempt_id:9,success:false,reason:'ورقة عالقة'};
  assert.equal(key.for(payload),key.for({...payload}));
  assert.notEqual(key.for(payload),key.for({...payload,success:true}));
});
test('API contracts preserve arrays, versions, keys and explicit print results without hidden writes',async()=>{
  const calls=[];const api=createSalesApi({request:async(...args)=>{calls.push(args);return {data:[]};},mutate:async(...args)=>{calls.push(args);return {data:{}};}});
  await api.list('reprint-requests',{account_ids:[4,5],q:'ورقة',direction:'incoming'});
  assert.equal(calls[0][0],`/sales/reprint-requests?${queryString({account_ids:[4,5],q:'ورقة',direction:'incoming'})}`);
  const payload={version:8,attempt_id:9,success:false,reason:'لم تخرج الورقة',idempotency_key:'test-print-result'};
  await api.action(12,'print/result',payload);
  assert.equal(calls[1][0],'/sales/12/print/result');assert.equal(calls[1][1],'POST');assert.deepEqual(calls[1][2],payload);
  await api.reservation(12,'cancel',{version:1,idempotency_key:'cancel-sale'});
  assert.equal(calls[2][0],'/sales/reservations/12/cancel');
});

import {deviceHeartbeatPayload} from '../src/modules/sales/sales-model.js';
test('device heartbeat accepts nullable server OS and serial across repeated renewals',()=>{
 for(const data of [{serial:'',os_version:null},{serial:null,os_version:null}]){const payload=deviceHeartbeatPayload(data);assert.equal(payload.serial,undefined);assert.equal(payload.os_version,undefined);assert.ok(payload.app_version);}
 assert.equal(deviceHeartbeatPayload({serial:' DEVICE ',os_version:' 1.2 '}).os_version,'1.2');
});
