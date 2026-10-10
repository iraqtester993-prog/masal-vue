import test from 'node:test';
import assert from 'node:assert/strict';
import {categoryReport} from '../src/modules/digital/category-export.js';
import {workbookSections,printableReport} from '../src/modules/reports/report-model.js';
import {reportWorkbook} from '../src/shared/files/excel-export.js';
test('category Excel and PDF contain every offered and excluded record with reasons, literal IDs and protected costs',async()=>{
  const connection={account_name:'وكيل الفحص',offers:[{remote_id:'00049',remote_name:'6000',type:'topup',retail:'6500.00',active:true}],excluded_catalog:[{remote_id:'3',remote_name:'=GROUP()',type:'bundle',price:'0.00',reason:'السعر صفر'},{remote_id:'52',remote_name:'<script>alert(1)</script>',type:'bundle',price:null,reason:'لم ترسل الشركة السعر'}]};
  const report=categoryReport(connection);assert.equal(report.sections[1].rows.length,1);assert.equal(report.sections[2].rows.length,2);assert.ok(!report.sections[1].columns.some(row=>row.key==='cost'));
  const excel=await reportWorkbook(workbookSections(report)).text();assert.ok(excel.includes('00049'));assert.ok(excel.includes('=GROUP()'));assert.ok(!excel.includes('<f>'));assert.ok(excel.includes('لم ترسل الشركة السعر'));assert.ok(excel.includes('لم ترسله الشركة'));
  const pdf=printableReport(report);assert.ok(pdf.includes('السعر صفر'));assert.ok(pdf.includes('00049'));assert.ok(pdf.includes('&lt;script&gt;'));assert.ok(!pdf.includes('<script>'));assert.ok(pdf.includes('السجلات المستبعدة'));
  const old=categoryReport({...connection,excluded_catalog:null});assert.ok(old.sections[2].note.includes('غير مسجلة'));assert.equal(old.sections[2].rows.length,0);
  const allocation=categoryReport({...connection,allocation:true});assert.equal(allocation.sections[1].rows[0].status,'محددة للوكيل');assert.ok(allocation.sections[1].columns.some(row=>row.label==='سعر الصرف · د.ع'));
});
