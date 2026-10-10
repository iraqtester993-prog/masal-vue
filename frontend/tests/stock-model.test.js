import test from 'node:test';
import assert from 'node:assert/strict';
import {cardPayload, importedLine, mapColumns, matchedProduct, orderPayload, rejectedRows} from '../src/modules/stock/stock-model.js';
import {encryptFile} from '../src/shared/files/encrypted-file.js';
import {workbook} from '../src/shared/files/excel-export.js';

test('import links use the selected provider and explicit codes, including prefixed aliases', () => {
  const products = [{id:1,provider_id:10,import_codes:['EV5']},{id:2,provider_id:20,import_codes:['EV5']}];
  assert.equal(matchedProduct(products,10,'EVS-EV5').id,1);
  assert.equal(matchedProduct([...products,{id:3,provider_id:10,import_codes:['EV5']}],10,'EV5'),null);
  assert.equal(matchedProduct(products,10,'EV10'),null);
  const line = importedLine({name:'original.txt',categoryCode:'EV5',declared:1,rows:[{pin:'00000123456789012345'}]},products,10);
  assert.equal(line.product_id,1); assert.equal(line.cost,''); assert.equal(line.rows[0].pin,'00000123456789012345');
});

test('stock draft payload retains exact monetary and credential text while excluding local UI or authority fields', () => {
  const payload = orderPayload({order_key:'order-123',account_id:'10',provider_id:'20',source_id:'30',city:'بغداد',category_count:'1',role:'admin',lines:[{key:'line-123',name:'original.csv',product_id:'2',cost:'9000000000000.01',expenses:'0.07',reference_label:'الفئة',rows:[{sourceRow:2,pin:'00000000000000012345',serial:'000001',expiry:'31/12/2030',secret:'forged',custom:'0009'}]}]},[{id:2,extra_fields:[{key:'custom'}]}]);
  assert.equal(payload.lines[0].cost,'9000000000000.01'); assert.equal(payload.lines[0].expenses,'0.07'); assert.equal(payload.lines[0].rows[0].pin,'00000000000000012345');
  assert.equal(payload.lines[0].rows[0].expiry,'2030-12-31'); assert.deepEqual(payload.lines[0].rows[0].extra_fields,{custom:'0009'});
  assert.ok(!('role' in payload)); assert.ok(!('reference_label' in payload.lines[0])); assert.ok(!('secret' in payload.lines[0].rows[0]));
});

test('manual columns preserve source row and leading zero text for files with unnamed headers', () => {
  const line = {columnMap:{serial:'0',pin:'1',expiry:'2'},rawRows:[{sourceRow:1,cells:['0001','000000009','2030-12-31']}]};
  mapColumns(line); assert.equal(line.rows[0].pin,'000000009'); assert.equal(cardPayload(line.rows[0],{}).source_row,1);
  assert.equal(rejectedRows([{name:'example',category_code:'EV5',checked:[{...cardPayload(line.rows[0],{}),error:'مكرر'}]}])[0].PIN,'000000009');
});

test('exported workbook stores padded credentials and formula-looking labels as literal inline text', async () => {
  const blob = workbook([{Serial:'00001',PIN:'00000000000000001234',اسم:'=HYPERLINK("bad")'}]);
  const buffer = Buffer.from(await blob.arrayBuffer());
  assert.equal(buffer.readUInt32LE(0),0x04034b50);
  const xml = buffer.toString('utf8'); assert.match(xml,/t="inlineStr"/); assert.match(xml,/00000000000000001234/); assert.match(xml,/=HYPERLINK\(&quot;bad&quot;\)/); assert.ok(!xml.includes('<f>'));
});

test('encrypted stock copies remain compatible with the original authenticated file format and reject a wrong password', async () => {
  await assert.rejects(encryptFile('private','short'),/12/);
  const payload = JSON.stringify({cards:[{pin:'0000000000123456'}]}), password = 'test-only-password-123';
  const envelope = await encryptFile(payload,password), second = await encryptFile(payload,password);
  assert.equal(envelope.format,'masal-backup-encrypted-v1'); assert.notEqual(envelope.salt,second.salt); assert.ok(!JSON.stringify(envelope).includes('0000000000123456'));
  const decrypt = async value => {
    const salt = Buffer.from(envelope.salt,'base64'), material = await crypto.subtle.importKey('raw',new TextEncoder().encode(value),'PBKDF2',false,['deriveKey']);
    const key = await crypto.subtle.deriveKey({name:'PBKDF2',salt,iterations:210000,hash:'SHA-256'},material,{name:'AES-GCM',length:256},false,['decrypt']);
    return new TextDecoder().decode(await crypto.subtle.decrypt({name:'AES-GCM',iv:Buffer.from(envelope.iv,'base64')},key,Buffer.from(envelope.data,'base64')));
  };
  assert.equal(await decrypt(password),payload); await assert.rejects(decrypt('incorrect-password-123'));
});
