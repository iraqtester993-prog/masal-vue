import test from 'node:test';
import assert from 'node:assert/strict';
import {normalizePhone,salePayload,canReceipt,canVerify,providerBalance,connectionPayload,digitalDeepLink,authorizedDigitalTab,credentialAfterIdentityChange,catalogProductChoices} from '../src/modules/digital/digital-model.js';
const offer={id:7,connection_id:3,version:2,retail:'5000.25',provider:'topup',package_type:'standard',gateway:{configured:true,purchases_enabled:true}};
test('digital quote retains exact approved decimals and normalizes both actual phone confirmations',()=>{assert.equal(normalizePhone('٠٧٧٠١٢٣٤٥٦٧'),'9647701234567');assert.deepEqual(salePayload(offer,{mobile:'+964 7701234567',confirm_mobile:'07701234567'}),{connection_id:3,offer_id:7,offer_version:2,expected_retail:'5000.25',mobile:'9647701234567',confirm_mobile:'9647701234567'});assert.throws(()=>salePayload(offer,{mobile:'07701234567',confirm_mobile:'07701234568'}),/غير متطابقين/);});
test('real gateway must be configured and enabled before review can become a purchase',()=>{for(const gateway of [{configured:false,purchases_enabled:true},{configured:true,purchases_enabled:false}])assert.throws(()=>salePayload({...offer,gateway},{}),/الربط الفعلي/);assert.throws(()=>normalizePhone('123'),/عراقي/);});
test('premium requires actual subscriber names and provider province while vouchers do not add invented phone fields',()=>{const premium={...offer,provider:'rabiaa',package_type:'premium',bein_provinces:[{id:'2',name:'بغداد'}]},input={mobile:'07701234567',confirm_mobile:'07701234567',first_name:' أحمد ',last_name:'علي',bein_province_id:'2'};assert.equal(salePayload(premium,input).first_name,'أحمد');assert.throws(()=>salePayload(premium,{...input,bein_province_id:'8'}),/محافظة صحيحة/);assert.ok(!('mobile' in salePayload({...offer,provider:'rabiaa'},{mobile:'777'})));});
test('receipts and verification only target current POS while System and parent read metadata',()=>{const order={account_id:12,status:'succeeded'},all=()=>true,pos={account:{id:12,type:'pos'}};assert.equal(canReceipt(order,pos,all),true);for(const type of ['system','main_agent','sub_agent'])assert.equal(canReceipt(order,{account:{id:12,type}},all),false);assert.equal(canReceipt(order,{account:{id:13,type:'pos'}},all),false);assert.equal(canReceipt(order,pos,key=>key!=='data.pin'),false);assert.equal(canVerify({...order,status:'review'},pos,all),true);assert.equal(canVerify(order,pos,all),false);});
test('unknown company balance stays unknown and confirmed zero is a real amount',()=>{assert.equal(providerBalance([{provider:'topup',company_balance:null}]).amount,null);assert.equal(providerBalance([{provider:'topup',company_balance:'0.00'}]).amount,'0.00');assert.equal(providerBalance([{provider:'topup',company_balance:'99999999.99'},{provider:'topup',company_balance:'0.01'}]).amount,'100000000.00');assert.equal(providerBalance([{provider:'topup',company_balance:'1.01'},{provider:'topup',company_balance:null}]).amount,null);});
test('configuration writes explicit public fields and never manufactures company cost',()=>{const draft={version:2,provider:'rabiaa',account_id:2,provider_id:5,active:true,offers:[{id:7,product_id:9,retail:'5.01',active:true,cost:'0.00',credential:'forged',remote_id:'71',province_id:'2'}]};const payload=connectionPayload(draft);assert.ok(!('credential' in payload));assert.ok(!('cost' in payload.offers[0]));assert.ok(!('credential' in payload.offers[0]));assert.equal(payload.offers[0].retail,'5.01');});
test('incoming token onboarding needs its main agent and service without a fabricated local API company',()=>{
  const draft={provider:'rabiaa',account_id:2,active:true,offers:[]},payload=connectionPayload(draft,' incoming-test-token ');
  assert.deepEqual(payload,{version:0,provider:'rabiaa',account_id:2,active:true,credential:'incoming-test-token',offers:[]});
  assert.ok(!('provider_id' in connectionPayload({...draft,provider_id:null})));
  assert.equal(connectionPayload({...draft,provider_id:5}).provider_id,5);
  assert.throws(()=>connectionPayload({...draft,account_id:''}),/اختر الوكيل الرئيسي/);
});
test('remote categories may map to permitted products from any local company while a product is not mapped twice',()=>{
  const products=[{id:1,provider_id:4},{id:2,provider_id:5},{id:3,provider_id:6}],offers=[{product_id:2}];
  assert.deepEqual(catalogProductChoices(products,offers,null).map(row=>row.id),[1,3]);
  assert.deepEqual(catalogProductChoices(products,offers,2).map(row=>row.id),[1,2,3]);
});
test('dashboard deep links preserve the selected provider, account and history filters only',()=>{
  assert.deepEqual(digitalDeepLink({tab:'log',provider:'topup',main_account_id:'12',account_id:'17',product_id:'9',status:'succeeded',from:'2026-10-01',to:'2026-10-06',q:'provider-003',currency:'USD',credential:'must-not-forward'}),{tab:'log',filters:{provider:'topup',main_account_id:'12',account_id:'17',product_id:'9',status:'succeeded',from:'2026-10-01',to:'2026-10-06',q:'provider-003'}});
  const link=digitalDeepLink({provider:['topup','rabiaa'],main_account_id:'9007199254740992',account_id:'-1',product_id:'0',status:'anything',from:'2026-02-30',to:'2026-10-06',q:'x'.repeat(200)});
  assert.equal(link.tab,'log');assert.equal(link.filters.provider,'');assert.equal(link.filters.main_account_id,'');assert.equal(link.filters.from,'');assert.equal(link.filters.to,'2026-10-06');assert.equal(link.filters.q.length,160);
  assert.equal(digitalDeepLink({from:'2026-10-06',to:'2026-10-01'}).filters.to,'');
});
test('deep-link tabs do not acquire setup or selling authority',()=>{
  assert.equal(authorizedDigitalTab('settings',{view:true,sell:false,settings:false},'log'),'log');
  assert.equal(authorizedDigitalTab('sell',{view:true,sell:false,settings:true},'settings'),'settings');
  assert.equal(authorizedDigitalTab('log',{view:true,sell:false,settings:true},'settings'),'log');
});
test('changing provider identity clears the typed secret while selecting its agent preserves it',()=>{
  for(const field of ['provider','company'])assert.equal(credentialAfterIdentityChange('typed-secret',field),'');
  assert.equal(credentialAfterIdentityChange('typed-secret','agent'),'typed-secret');
});
