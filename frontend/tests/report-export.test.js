import test from 'node:test';
import assert from 'node:assert/strict';
import {exportReport} from '../src/modules/reports/report-export.js';

test('a bounded server download uses its private same-origin ticket without constructing an XLSX',async()=>{
 const previous=globalThis.document,created=[],requests=[];
 globalThis.document={createElement:tag=>{const node={tag,click(){this.clicked=true;}};created.push(node);return node;}};
 const url='/api/v1/reports/exports/00000000-0000-4000-8000-000000000001';
 const api={export:async parameters=>{requests.push(parameters);return {data:{sections:[],download:{url,name:'bounded.csv',rows:5000}}};}};
 try{
  await exportReport(api,{section_ids:['sales']},false);
  assert.deepEqual(requests,[{section_ids:['sales'],purpose:'export'}]);
  assert.equal(created.length,1);assert.equal(created[0].tag,'a');assert.equal(created[0].href,url);assert.equal(created[0].download,'bounded.csv');assert.equal(created[0].clicked,true);
 }finally{if(previous===undefined)delete globalThis.document;else globalThis.document=previous;}
});

test('external ticket URLs and download tickets in print mode are rejected before opening content',async()=>{
 const previous=globalThis.document;globalThis.document={createElement:()=>assert.fail('Unsafe ticket must not open a document')};
 try{
  for(const url of ['https://attacker.example/export.csv','//attacker.example/export.csv','javascript:alert(1)','/api/v1/reports/exports/../../private']){
   await assert.rejects(exportReport({export:async()=>({data:{download:{url}}})},{},false));
  }
  await assert.rejects(exportReport({export:async()=>({data:{download:{url:'/api/v1/reports/exports/00000000-0000-4000-8000-000000000001'}}})},{},true));
 }finally{if(previous===undefined)delete globalThis.document;else globalThis.document=previous;}
});
