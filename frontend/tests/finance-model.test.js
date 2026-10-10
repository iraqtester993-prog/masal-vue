import test from 'node:test';
import assert from 'node:assert/strict';
import { amount, createMutationKey, csv, decimal, difference, matchesAccount, minor, money, priceChanges, pricePreview, sum, transferRows } from '../src/modules/finance/finance-model.js';
import { allPages, createFinanceApi } from '../src/modules/finance/finance-api.js';
import { priceTemplateRows, readPriceTemplate } from '../src/modules/finance/price-template.js';

test('money input preserves Arabic digits and cents at the maximum API amount without floating point', () => {
  assert.equal(amount('٩٬٩٩٩٬٩٩٩٬٩٩٩٬٩٩٩٫٩٩'), '9999999999999.99');
  assert.equal(difference('9000000000000.11', '9000000000000.10'), '0.01');
  assert.equal(sum(['9000000000000.11','9000000000000.10']), '18000000000000.21');
  assert.equal(money('18000000000000.21'), '18,000,000,000,000.21');
  assert.equal(decimal(-1n), '-0.01');
  for (const invalid of ['0','-1','2.001','1e3','NaN','10000000000000','1.2.3']) assert.throws(() => amount(invalid));
  assert.equal(money(null), '—');
});
test('a retry after an uncertain result preserves its key and a completed operation gets a new key', () => {
  let n=0; const key=createMutationKey(()=>`key-${++n}`), payload={amount:'0.10',account_id:12};
  assert.equal(key.for(payload),'key-1');
  assert.equal(key.for({...payload}),'key-1');
  assert.equal(key.for({...payload,amount:'0.11'}),'key-2');
  key.clear(); assert.equal(key.for(payload),'key-3');
});
test('bulk funding accepts provided scoped recipients only, rejects duplicates and keeps exact amounts', () => {
  const choices=[{id:12},{id:15}];
  assert.deepEqual(transferRows([12,15],{12:'١٢٥٫١٠',15:'9999999999999.99'},choices),[{to_account_id:12,amount:'125.10'},{to_account_id:15,amount:'9999999999999.99'}]);
  assert.throws(()=>transferRows([12,12],{12:'1'},choices));
  assert.throws(()=>transferRows([90],{90:'1'},choices));
  assert.throws(()=>transferRows([12],{12:'1.001'},choices));
  assert.throws(()=>transferRows([],{12:'1'},choices));
});
test('price preview enforces minimums, retains expected price and exposes every invalid row before posting', () => {
  const products=[{product_id:2,name:'أ',currency:'IQD',minimum_price:'12.00',price:'12.10'},{product_id:3,name:'ب',currency:'USD',minimum_price:null,price:null}];
  const rows=pricePreview(products,{2:'12.11',3:'2.20'});
  assert.equal(rows[0].difference,'0.01');
  assert.deepEqual(priceChanges(rows),[{product_id:2,price:'12.11',expected_price:'12.10'},{product_id:3,price:'2.20',expected_price:null}]);
  const invalid=pricePreview(products,{2:'11.99'});assert.match(invalid[0].error,/أقل/);assert.throws(()=>priceChanges(invalid));
  const bulk=pricePreview(products,{}, {mode:'bulk',targetIds:[2,3],direction:'subtract',value:'0.01'});
  assert.equal(bulk[0].price,'12.09');assert.match(bulk[1].error,/فرديًا/);assert.throws(()=>priceChanges(bulk));
  assert.throws(()=>priceChanges(pricePreview(products,{2:'12.10'})));
});
test('account filters follow an authorized hierarchy without creating missing ancestors or looping on malformed topology', () => {
  const accounts=[{id:1,name:'إدارة',type:'system',status:'active'},{id:2,name:'رئيسي',type:'main_agent',parent_id:1,status:'active'},{id:3,name:'فرعي',type:'sub_agent',parent_id:2,status:'active'},{id:4,name:'نقطة',phone:'07701234567',city:'بغداد',type:'pos',parent_id:3,status:'active'},{id:5,name:'خارج',type:'pos',parent_id:1,status:'disabled'}];
  assert.equal(matchesAccount(accounts[3],{network:2,kind:'pos',query:'0770',active:'active',city:'بغداد'},accounts),true);
  assert.equal(matchesAccount(accounts[4],{network:2},accounts),false);
  assert.equal(matchesAccount(accounts[3],{account:5},accounts),false);
  assert.equal(matchesAccount({id:7,parent_id:7,name:'حلقة'},{network:2},accounts),false);
});
test('finance query filters encode each scoped id and never allow a reference to inject query parameters', async () => {
  const calls=[],api=createFinanceApi({request:(path)=>{calls.push(path);return {}},mutate:(...args)=>{calls.push(args);return {}}});
  await api.list('ledger',{q:'سند&account_id=90',account_ids:[2,3],from:'2026-10-01',currency:'IQD'});
  const query=new URL(calls[0],'https://example.test').searchParams;
  assert.equal(query.get('q'),'سند&account_id=90');assert.equal(query.get('account_id'),null);assert.deepEqual(query.getAll('account_ids[]'),['2','3']);
  const payload={version:2,decision:'reject',reason:'رفض',idempotency_key:'stable-key'};
  await api.action('funding-requests',12,'review',payload);
  assert.deepEqual(calls[1],['/finance/funding-requests/12/review','POST',payload,undefined]);
});
test('an all-page balance read returns complete data or fails instead of presenting a partial total', async () => {
  const signal=new AbortController().signal,calls=[];
  const api={list:async(kind,params,received)=>{calls.push(params.page);assert.equal(received,signal);return {data:[{id:params.page}],meta:{last_page:2}};}};
  assert.deepEqual(await allPages(api,'wallets',{currency:'IQD'},signal),[{id:1},{id:2}]);assert.deepEqual(calls,[1,2]);
  const partial={list:async(kind,params)=>{if(params.page===2)throw new Error('connection lost');return {data:[{balance:'100.00'}],meta:{last_page:2}};}};
  await assert.rejects(allPages(partial,'wallets',{},signal),/connection lost/);
});
test('downloaded labels cannot execute spreadsheet formulas and commas or quotes retain their values', () => {
  const text=csv([['=HYPERLINK("x")','أ,ب','12.10']]);
  assert.equal(text,'\uFEFF"\'=HYPERLINK(""x"")","أ,ب","12.10"');
});

test('original versioned price template binds every price to its account and authorized product', () => {
  const products = [{ product_id: 12, name: 'بطاقة أ', price: '9000000000000.10', minimum_price: '12.10' }];
  const rows = priceTemplateRows(products, 4);
  assert.deepEqual(Object.keys(rows[0]), ['version', 'agent', 'product', 'name', 'price']);
  assert.deepEqual(rows[0], { version: '1', agent: '4', product: '12', name: 'بطاقة أ', price: '9000000000000.10' });
  const sheet = (body, headers = Object.keys(rows[0])) => [{ rows: [headers, ...body] }];
  const valid = ['1', '4', '12', 'بطاقة أ', '٩٠٠٠٠٠٠٠٠٠٠٠٠٫١١'];
  assert.deepEqual(readPriceTemplate(sheet([valid]), products, 4), { 12: '9000000000000.11' });
  for (const [index, value] of [[0, '2'], [1, '5'], [2, '90'], [4, '12.09'], [4, ''], [4, '1.001']]) {
    const invalid = [...valid]; invalid[index] = value;
    assert.throws(() => readPriceTemplate(sheet([invalid]), products, 4));
  }
  assert.throws(() => readPriceTemplate(sheet([valid, valid]), products, 4), /مكررة/);
  assert.throws(() => readPriceTemplate(sheet([valid], ['version', 'agent', 'product', 'price', 'price']), products, 4), /قالب/);
  assert.throws(() => readPriceTemplate([{ rows: [['product_id', 'price'], ['12', '15']] }], products, 4), /قالب/);
});

test('XLSX price template preserves exact decimal text, original headers, RTL and non-executable names', async () => {
  const { workbook } = await import('../src/shared/files/excel-export.js');
  const blob = workbook(priceTemplateRows([{ product_id: 12, name: '=2+2<&', price: '9999999999999.99' }], 4));
  assert.equal(blob.type, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  const bytes = new Uint8Array(await blob.arrayBuffer()), view = new DataView(bytes.buffer), decoder = new TextDecoder();
  const entries = {}; let offset = 0;
  while (view.getUint32(offset, true) === 0x04034b50) {
    const length = view.getUint32(offset + 18, true), nameLength = view.getUint16(offset + 26, true), extraLength = view.getUint16(offset + 28, true);
    const start = offset + 30 + nameLength + extraLength;
    entries[decoder.decode(bytes.slice(offset + 30, offset + 30 + nameLength))] = decoder.decode(bytes.slice(start, start + length));
    offset = start + length;
  }
  const xml = entries['xl/worksheets/sheet1.xml'];
  assert.match(xml, /rightToLeft="1"/);
  for (const header of ['version', 'agent', 'product', 'name', 'price']) assert.ok(xml.includes(`>${header}</t>`));
  assert.match(xml, /t="inlineStr"><is><t xml:space="preserve">9999999999999\.99<\/t>/);
  assert.ok(xml.includes('=2+2&lt;&amp;')); assert.ok(!xml.includes('<f>'));
});
