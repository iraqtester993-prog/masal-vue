import test from 'node:test';
import assert from 'node:assert/strict';
import {printResultPayload} from '../src/modules/sales/print-confirmation.js';

const sale={id:12,version:9,status:'Print Requested',print_pending:true,attempt_id:41};

test('paper confirmation requires an explicit outcome for the active print attempt',()=>{
  for(const value of [null,undefined,'success',1])assert.throws(()=>printResultPayload(sale,value),/اختر نتيجة/);
  for(const record of [null,{...sale,print_pending:false},{...sale,attempt_id:null},{...sale,attempt_id:0},{...sale,attempt_id:'bad'}])assert.throws(()=>printResultPayload(record,true),/محاولة طباعة معلقة/);
  assert.deepEqual(printResultPayload(sale,true),{version:9,attempt_id:41,success:true});
});

test('failure records a reason and cannot silently become success or a new sale',()=>{
  for(const reason of ['',null,'  '])assert.throws(()=>printResultPayload(sale,false,reason),/سبب فشل/);
  assert.throws(()=>printResultPayload(sale,false,'x'.repeat(1001)),/أطول/);
  const payload=printResultPayload(sale,false,'  لم يخرج أسفل الوصل  ');
  assert.deepEqual(payload,{version:9,attempt_id:41,success:false,reason:'لم يخرج أسفل الوصل'});
  assert.ok(!Object.hasOwn(payload,'quantity'));
  assert.ok(!Object.hasOwn(payload,'product_id'));
  assert.deepEqual(printResultPayload(sale,true,'reason from an earlier failure'),{version:9,attempt_id:41,success:true});
});

test('confirmation uses the latest pending attempt when viewing the same stored receipt from history',()=>{
  const record={...sale,attempt_id:undefined,attempts:[{id:15,status:'failed'},{id:16,status:'success'},{id:43,status:'pending'}]};
  assert.deepEqual(printResultPayload(record,false,'نفاد الورق'),{version:9,attempt_id:43,success:false,reason:'نفاد الورق'});
});
