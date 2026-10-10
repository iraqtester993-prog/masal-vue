import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createReportApi, query } from '../src/modules/reports/report-api.js';
import { formatNumber, reportCell, preset, descendantOf, groupCards, workbookSections, printableReport } from '../src/modules/reports/report-model.js';
import {systemLabel, auditActionLabel} from '../src/modules/preferences/system-labels.js';

test('system values are Arabic while customer names and provider identifiers remain unchanged',()=>{
  assert.equal(reportCell('auth.login',{label_type:'action'}),'تسجيل الدخول');
  assert.equal(reportCell('topup.categories.assigned',{label_type:'action'}),'توزيع فئات التعبئة');
  assert.equal(reportCell('admin',{label_type:'portal'}),'بوابة مدير النظام');
  assert.equal(reportCell('parent_id',{label_type:'field'}),'الوكيل الأعلى');
  assert.equal(reportCell('velocity_seconds',{label_type:'setting'}),'الفاصل بين العمليات');
  assert.equal(reportCell('network',{label_type:'dailyMode'}),'الحساب والشبكة التابعة');
  assert.equal(reportCell('Printed',{label_type:'status'}),'مطبوع');
  assert.equal(reportCell('active',{label_type:'change'},{field:'status'}),'مفعل');
  assert.equal(reportCell('Printed',{label_type:'change'},{field:'name'}),'Printed');
  assert.equal(reportCell(false,{label_type:'change'},{field:'allowed'}),'غير مسموح');
  for(const value of ['Printed','sale','EVS-E5K','provider.example.com','اسم المزود']) assert.equal(reportCell(value,{key:'name',type:'text'}),value);
  assert.equal(systemLabel('main_agent','accountType'),'وكيل رئيسي');
  assert.equal(auditActionLabel('staff.create'),'إنشاء الموظف');
  assert.equal(auditActionLabel('unknown.new'),'إجراء آخر');
  const output=workbookSections({currency:'IQD',timezone:'Asia/Baghdad',generated_at:'2026-10-08T12:00:00Z',sections:[{title:'التدقيق',available:true,columns:[{key:'action',label:'الإجراء',label_type:'action'},{key:'source',label:'المصدر',label_type:'portal'}],rows:[{action:'auth.login',source:'admin'}]}]});
  assert.deepEqual(output[0].rows[0],['تسجيل الدخول','بوابة مدير النظام']);
});

test('52 original sections and all 8 original additional group tiles preserve ordering and unavailable null counts',()=>{
  const schemas=JSON.parse(fs.readFileSync(new URL('../../backend/app/Services/Reports/schemas.json',import.meta.url)));
  assert.equal(schemas.length,52);assert.equal(new Set(schemas.map(s=>s.id)).size,52);
  const all=schemas.map(s=>({...s,available:true,count:0,subcounts:{main:2,branch:3,nested:4,active:5,inactive:6,available:7,issued:8,held:9}}));
  const groups=[{prefixes:['sales','prices']},{prefixes:['wallets']},{prefixes:['inventory','claims']},{prefixes:['network']},{prefixes:['users','audit','support','operations']}];
  assert.equal(groups.flatMap(g=>groupCards(g,all)).length,60);
  const missing=groupCards(groups[0],[{id:'sales-services',available:false,count:null}]);assert.equal(missing[0].count,null);
  assert.equal(groupCards(groups[3],all).find(s=>s.filter==='nested').count,4);
});
test('money display retains exact large values, null is protected mask and Baghdad presets include both boundaries',()=>{
  assert.equal(formatNumber('900719925474099.31'),'900,719,925,474,099.31');assert.equal(reportCell(null,{type:'money'}),'—');
  assert.deepEqual(preset('today',new Date('2026-10-05T22:00:00Z')),{from:'2026-10-06',to:'2026-10-06'});
  assert.deepEqual(preset('week',new Date('2026-10-05T22:00:00Z')),{from:'2026-09-30',to:'2026-10-06'});
  assert.deepEqual(preset('all'),{from:'',to:''});
});
test('hierarchy filters terminate for cycles and allow POS under any valid agent depth',()=>{
  const accounts=[{id:1,parent_id:null},{id:2,parent_id:1},{id:3,parent_id:2},{id:4,parent_id:3}];
  assert.equal(descendantOf(accounts,4,1),true);assert.equal(descendantOf(accounts,1,4),false);
  assert.equal(descendantOf([{id:1,parent_id:2},{id:2,parent_id:1}],1,3),false);
});
test('API filters omit blank export fields, preserve complete export rather than paging, and encode row keys',async()=>{
  const calls=[];const api=createReportApi({request:(...args)=>calls.push(args),mutate:(...args)=>calls.push(args)});
  api.rows('sales', {q:'%_&',page:3}); api.row('sales','1:2',{});api.export({section_ids:['sales'],from:'',q:'',currency:'IQD'});
  assert.equal(calls[0][0],'/reports/sections/sales/rows?q=%25_%26&page=3');assert.match(calls[1][0],/1%3A2/);
  assert.deepEqual(calls[2][2],{section_ids:['sales'],currency:'IQD'});assert.equal(query({ids:[1,2],q:''}),'ids%5B%5D=1&ids%5B%5D=2');
});
test('export/print use all eligible rows, exact monetary text, escaped untrusted text and unavailable explanation',()=>{
  const data={currency:'IQD',timezone:'Asia/Baghdad',generated_at:'2026-10-06T06:00:00Z',sections:[{title:'المبيعات',available:true,columns:[{key:'name',label:'الاسم',type:'text'},{key:'amount',label:'المبلغ',type:'money'}],rows:Array.from({length:24},()=>({name:'<img src=x onerror=alert(1)>',amount:'900719925474099.31'}))},{title:'الطلبات',available:false,reason:'المصدر غير متاح',columns:[{key:'id',label:'المعرف'}],rows:[]}]};
  const blocks=workbookSections(data);assert.equal(blocks[0].rows.length,24);assert.equal(blocks[0].rows[0][1],'900719925474099.31');
  const html=printableReport(data);assert.ok(!html.includes('<img'));assert.ok(html.includes('&lt;img'));assert.ok(html.includes('المصدر غير متاح'));assert.equal((html.match(/900719925474099\.31/g)||[]).length,24);
});
