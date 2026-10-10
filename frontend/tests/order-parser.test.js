import test from 'node:test';
import assert from 'node:assert/strict';
import {delimited} from '../src/shared/files/import-reader.js';
import {parseSheets,code} from '../src/modules/stock/order-parser.js';
test('the original file formats preserve padded credentials and declared card counts', () => {
 const sheets=[{name:'recorded.csv',rows:delimited('Provider,2,EV5,Office,Reference\n000012,0000000100,2028-01-02\n000013,0000000101,2028-02-03')}];
 const [line]=parseSheets(sheets);assert.equal(line.declared,2);assert.equal(line.categoryCode,'EV5');assert.equal(line.rows[0].serial,'000012');assert.equal(line.rows[0].pin,'0000000100');
 assert.equal(code(' evs-EV5 '),'EV5');
});
test('the original header aliases and date order are preserved', () => {
 const [line]=parseSheets([{name:'cards.txt',rows:delimited('SN;HRN;ExpirationDate;Reference\n00012;001234;31/12/2028;Recorded')}]);
 assert.equal(line.rows[0].expiry,'2028-12-31');assert.equal(line.rows[0].pin,'001234');assert.equal(line.rows[0].reference,'Recorded');
});
test('mixed categories or incorrect declared counts cannot become an accepted order', () => {
 assert.throws(()=>parseSheets([{name:'mixed.csv',rows:delimited('Serial,Pin,Category\n1,2,EV5\n3,4,EV10')}]),/أكثر من رمز/);
 assert.throws(()=>parseSheets([{name:'count.csv',rows:delimited('Provider,3,EV5,Office,Reference\n1,2,2028-01-01')}]),/العدد المعلن/);
});
