import test from 'node:test';
import assert from 'node:assert/strict';
import {rechargePin,rechargeDial,rechargeContent,rechargeQr,receiptQr,rechargeQrPreview} from '../src/modules/sales/recharge-qr.js';

test('recharge dial retains leading PIN zeros and escapes the terminal hash for the dialer',()=>{
  assert.deepEqual(rechargeDial('0012345678901234','*123*{pin}#'),{code:'*123*0012345678901234#',uri:'tel:*123*0012345678901234%23'});
  assert.equal(rechargeDial('1','*12*34*{pin}*9#').uri,'tel:*12*34*1*9%23');
});

test('grouped voucher digits normalize only validated separators and preserve every leading zero',()=>{
  for (const pin of ['0012-0034-0056-0078', '0012 0034 0056 0078', '0012-0034 0056-0078']) {
    assert.equal(rechargePin(pin),'0012003400560078');
    assert.deepEqual(rechargeDial(pin,'*123*{pin}#'),{code:'*123*0012003400560078#',uri:'tel:*123*0012003400560078%23'});
    assert.equal(rechargeQr(pin,'*123*{pin}#').dataUrl,rechargeQr('0012003400560078','*123*{pin}#').dataUrl);
  }
  assert.equal(rechargePin('0-0'),'00');
  assert.equal(rechargePin('0'.repeat(64).split('').join('-')),'0'.repeat(64));
  assert.equal(rechargePin('0'.repeat(65).split('').join('-')),null);
});

test('malformed groups, control characters and dial instructions cannot be repaired into a recharge PIN',()=>{
  const invalid=['-1234','1234-','12--34','12  34','12- 34','12 -34',' 1234','1234 ',
    '12\t34','12\n34','12\r34','1234\n','12\u000034','12\u202e34','12\u206634','12\u00a034',
    '12\u201034','12\u201134','12\u221234','٠٠١٢-٣٤٥٦','12*34','12#34','12-3a4','tel:1234',1234,null,undefined];
  for (const pin of invalid) {
    assert.equal(rechargePin(pin),null);
    assert.equal(rechargeQr(pin,'*123*{pin}#'),null);
  }
});

test('alphanumeric vouchers receive an exact plain-text QR without a dial URI or letter conversion',()=>{
  for (const pin of ['a1B2-c3D4-0056', '00Ab 01cD', 'ONLYLETTERS']) {
    assert.deepEqual(rechargeContent(pin,'*123*{pin}#'),{kind:'pin',text:`PIN: ${pin}`});
    const qr=receiptQr(pin,'*123*{pin}#');
    assert.equal(qr.kind,'pin');assert.equal(qr.text,`PIN: ${pin}`);
    assert.equal(Object.hasOwn(qr,'uri'),false);assert.equal(Object.hasOwn(qr,'code'),false);
    assert.ok(qr.dataUrl.startsWith('data:image/svg+xml;'));assert.ok(qr.width<=360);
    assert.equal(rechargeDial(pin,'*123*{pin}#'),null);
  }
  const numeric=receiptQr('0012-0034','*123*{pin}#');
  assert.equal(numeric.kind,'recharge');assert.equal(numeric.uri,'tel:*123*00120034%23');
  assert.equal(Object.hasOwn(numeric,'text'),false);
  const maximum=receiptQr('a'.repeat(127),'*123*{pin}#');assert.ok(maximum);assert.ok(maximum.width<=360);
});

test('both receipt QR modes require the configured valid template and reject malformed or unbounded content',()=>{
  const invalid=['A1--B2','A1  B2','-A1','A1-','A1\n','A1\rB2','A1\tB2','A1\u202eB2','A1\u0000B2','A1\u00a0B2',
    'A1#B2','A1*B2','https://example.test','tel:1234','a'.repeat(128),'1'.repeat(65),1234,null];
  for (const pin of invalid) assert.equal(receiptQr(pin,'*123*{pin}#'),null);
  for (const pin of ['0012','a1B2-c3D4']) for(const template of ['', '*123*{pin}#\n', 'https://example.test/{pin}']) {
    assert.equal(rechargeContent(pin,template),null);assert.equal(receiptQr(pin,template),null);
  }
});

test('recharge QR stays disabled without an explicit valid carrier template and never accepts arbitrary URI content',()=>{
  const invalidPins=['','1  234','١٢٣٤','12#34','12*34','x123','123\n','1'.repeat(65),1234,null];
  for(const pin of invalidPins)assert.equal(rechargeQr(pin,'*123*{pin}#'),null);
  const invalidTemplates=['','*123*1234#','*123*{pin}{pin}#','tel:*123*{pin}#','https://example.com/{pin}',
    '*123*{pin}#\n',' *123*{pin}#','*123*{pin} #','#123*{pin}#','*1234567*{pin}#','*123*{pin}#*2#','*123*{PIN}#',
    '*123*{pin}#'.padEnd(81,'0')];
  for(const template of invalidTemplates)assert.equal(rechargeQr('001234',template),null);
});

test('designer preview contains a harmless example rather than an actionable recharge URI',()=>{
  const preview=rechargeQrPreview('*123*{pin}#');
  assert.ok(preview);assert.equal(preview.uri,undefined);assert.equal(preview.code,undefined);
  assert.equal(rechargeQrPreview(''),null);
});

test('local QR image has integer six-dot modules, a four-module quiet zone and exact native dimensions',()=>{
  const value=rechargeQr('1234567890123456','*123*{pin}#');
  assert.ok(value.dataUrl.startsWith('data:image/svg+xml;charset=utf-8,'));
  assert.ok(value.width>=174&&value.width<=360);assert.equal(value.width%6,0);
  const svg=decodeURIComponent(value.dataUrl.split(',')[1]);
  assert.ok(svg.includes(`width="${value.width}px"`));assert.ok(svg.includes(`height="${value.width}px"`));
  assert.ok(svg.includes(`viewBox="0 0 ${value.width} ${value.width}"`));
  assert.ok(svg.includes('fill="white"'));assert.ok(svg.includes('fill="black"'));
  assert.equal(svg.includes('http://example.com'),false);
  assert.equal(svg.includes('1234567890123456'),false);
});

test('maximum supported PIN and long valid templates still fit a 58mm receipt without browser globals',()=>{
  const pin='0'.repeat(64),template=`*${'123456*'.repeat(10)}12*{pin}#`;
  assert.equal(template.length,80);
  const value=rechargeQr(pin,template);
  assert.ok(value);assert.ok(value.width<=360);assert.equal(value.code.split('#').length,2);
  assert.equal(value.uri.includes('#'),false);assert.equal(value.uri.endsWith('%23'),true);
});
