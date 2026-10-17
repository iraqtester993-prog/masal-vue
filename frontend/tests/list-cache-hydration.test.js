import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {computed,reactive,ref} from 'vue';
import {parse} from '@vue/compiler-sfc';
import {createCatalogApi} from '../src/modules/catalog/catalog-api.js';
import {createStockApi} from '../src/modules/stock/stock-api.js';
import {createReportApi} from '../src/modules/reports/report-api.js';
import * as catalogModel from '../src/modules/catalog/catalog-model.js';
import * as stockModel from '../src/modules/stock/stock-model.js';
import * as reportModel from '../src/modules/reports/report-model.js';

function memoryApi(cache=new Map()){
 const reads=[];return {reads,peekRequest:path=>structuredClone(cache.get(path)??null),hasFreshRequest:path=>cache.has(path),request:async(path,options)=>{reads.push({path,options});return structuredClone(cache.get(path)??{data:[],meta:{page:1,current_page:1,last_page:1,total:0}});},mutate(){throw Error('Display hydration cannot mutate');}};
}
async function controls(page,api,names){
 const source=await readFile(new URL(`../src/modules/${page}.vue`,import.meta.url),'utf8'),script=parse(source).descriptor.scriptSetup.content.replace(/^import[\s\S]*?;\r?$/gm,'');
 const session={state:reactive({identity:{user:{id:9},account:{id:12,type:'system'},membership:{kind:'owner'},permissions:[]}}),can:()=>true,api};
 const scope={...catalogModel,...stockModel,...reportModel,computed,reactive,ref,watch(){},provide(){},onMounted(){},onBeforeUnmount(){},onServerPrefetch(){},usePortal:()=>({session}),useRoute:()=>({name:'inventory',query:{},meta:{catalog:'products'}}),useRouter:()=>({}),useUiLabelTranslator:()=>value=>value,usePreferencesTranslator:()=>value=>value,createCatalogApi,createStockApi,createReportApi,AbortController,setTimeout,clearTimeout};
 runInNewContext(script+`\nglobalThis.controls={${names}};`,scope);return scope.controls;
}

test('metadata API peek helpers use the exact filter query and explicit refresh reaches the request boundary',async()=>{
 const api=memoryApi(new Map([['/catalog/products?query=one&page=2',{data:[{id:1}]}],['/stock/batches?page=3&status=Quarantined',{data:[{id:2}]}],['/reports/summary?currency=IQD&section_ids%5B%5D=sales',{data:{sections:[]}}]]));
 const catalog=createCatalogApi(api),stock=createStockApi(api),reports=createReportApi(api);
 assert.equal(catalog.peekList('products',{query:'one',page:2}).data[0].id,1);assert.equal(catalog.peekList('products',{query:'other',page:2}),null);
 assert.equal(stock.peekList('batches',{page:3,status:'Quarantined'}).data[0].id,2);assert.equal(stock.peekList('batches',{page:3,status:'active'}),null);
 assert.ok(reports.peekSummary({currency:'IQD',section_ids:['sales']}));assert.equal(reports.peekSummary({currency:'USD',section_ids:['sales']}),null);
 await catalog.list('products',{query:'one',page:2},undefined,{fresh:true});await stock.list('batches',{page:3,status:'Quarantined'},undefined,{fresh:true});await reports.summary({currency:'IQD',section_ids:['sales']},undefined,{fresh:true});
 assert.equal(api.reads.every(row=>row.options.fresh===true),true);
});

test('catalog initializes remembered rows and preferences synchronously and never displays rows for a different filter',async()=>{
 const cache=new Map([['/catalog/products?page=1&per_page=10',{data:[{id:2,name:'Remembered category',version:1}],meta:{current_page:1,last_page:1,total:1,per_page:10}}],['/catalog/options',{data:{providers:[],cities:[]}}],['/catalog/preferences',{data:{hidden_columns:['daily_quantity']}}]]),api=memoryApi(cache);
 const page=await controls('catalog/CatalogPage',api,'vm,initialize,load'),initial=page.initialize();
 assert.equal(page.vm.rows[0].name,'Remembered category');assert.equal(page.vm.hiddenColumns[0],'daily_quantity');assert.equal(page.vm.busy,false);await initial;
 let finish;api.request=(path,options)=>{api.reads.push({path,options});return new Promise(resolve=>finish=resolve);};
 page.vm.filters.query='different';const pending=page.load();assert.equal(page.vm.rows.length,0);assert.equal(page.vm.busy,true);
 finish({data:[{id:3,name:'New filter category',version:1}],meta:{current_page:1,last_page:1,total:1}});await pending;assert.equal(page.vm.rows[0].name,'New filter category');
});

test('stock setup hydrates batch metadata and summary while card secrets and managers remain unopened',async()=>{
 const parameters='page=1&per_page=10',cache=new Map([[`/stock/batches?${parameters}`,{data:[{id:7,product_name:'Remembered stock batch',quantity:100,available_count:80}],meta:{page:1,last_page:1,total:1}}],[`/stock/summary?${parameters}`,{data:{available_count:80,quarantined_count:0,exported_count:0}}],['/stock/options',{data:{accounts:[],products:[],providers:[],cities:[]}}]]),api=memoryApi(cache);
 const page=await controls('stock/StockWorkspace',api,'state,manager,claim,load');
 assert.equal(page.state.rows[0].product_name,'Remembered stock batch');assert.equal(page.state.summary.available_count,80);assert.equal(page.manager.value,null);assert.equal(page.claim.value,null);assert.equal(api.reads.length,0);
 await page.load(1,true,true);assert.equal(api.reads.length,3);assert.equal(api.reads.every(row=>row.options.fresh===true),true);assert.equal(api.reads.some(row=>/cards|selection/.test(row.path)),false);
 cache.clear();const invalidated=await controls('stock/StockWorkspace',api,'state');assert.equal(invalidated.state.rows.length,0);assert.equal(invalidated.state.summary,null);
});

test('report groups hydrate from allowed options and a selected group restores only its matching summary',async()=>{
 const options={accounts:[],products:[],providers:[],cities:[],currencies:['IQD'],groups:[{id:'sales',prefixes:['sales'],title:'Sales'}],sections:[{id:'sales',title:'Sales',available:true}]},cache=new Map([['/reports/options?compact=1',{data:options}]]),api=memoryApi(cache);
 const page=await controls('reports/ReportsPage',api,'options,sections,groups,group,filters,load,summaryFilters');assert.equal(page.groups.value.length,1);assert.equal(page.sections.value[0].id,'sales');assert.equal(api.reads.length,0);
 page.group.value='sales';let summaryPath;const capture={peekRequest:key=>{summaryPath=key;return null;}};createReportApi(capture).peekSummary(page.summaryFilters());cache.set(summaryPath,{data:{sections:[{id:'sales',title:'Sales',available:true,count:27}]}});
 const pending=page.load();assert.equal(page.sections.value[0].count,27);await pending;assert.equal(page.sections.value[0].count,27);
});
