import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';
import {parse} from '@vue/compiler-sfc';
import {computed,createSSRApp,h,reactive,ref} from 'vue';
import {createMemoryHistory,createRouter} from 'vue-router';
import {renderToString} from '@vue/server-renderer';
import {createServer} from 'vite';
import {createReportApi} from '../src/modules/reports/report-api.js';
import {emptyFilters,groupCards,tr,errorText,formatNumber} from '../src/modules/reports/report-model.js';

const sections=JSON.parse(await readFile(new URL('../../backend/app/Services/Reports/schemas.json',import.meta.url),'utf8')).filter(section=>['sales','wallets'].includes(section.id.split('-')[0]));
const groups=[{id:'sales',title:'المبيعات والأرباح',prefixes:['sales','prices'],note:'المبيعات والطباعة والأسعار',icon:'reports'},{id:'wallets',title:'المحافظ والتمويل',prefixes:['wallets'],note:'الأرصدة والتمويل والتحصيل',icon:'wallets'}];
const options={accounts:[],products:[],providers:[],cities:[],currencies:['IQD'],groups,sections};

test('POS report groups expose actual sales, printing, digital and financial sections with lazy scoped summaries only',async()=>{
 const calls=[],session={state:reactive({identity:{account:{id:12,type:'pos'},user:{id:7}}}),can:()=>true,api:{request:async(path)=>{
  calls.push(path);if(path==='/reports/options?compact=1')return{data:structuredClone(options)};
  if(path.startsWith('/reports/summary?')){const ids=new URL(path,'http://local.test').searchParams.getAll('section_ids[]');return{data:{sections:sections.filter(section=>ids.includes(section.id)).map(section=>({...section,available:true,count:2}))}};}
  throw Error('Unexpected report read '+path);
 },mutate(){throw Error('Report browsing cannot mutate');}}};
 const source=await readFile(new URL('../src/modules/reports/ReportsPage.vue',import.meta.url),'utf8'),script=parse(source).descriptor.scriptSetup.content.replace(/^import[\s\S]*?;\r?$/gm,'');
 const scope={computed,reactive,ref,usePortal:()=>({session}),usePreferencesTranslator:()=>value=>value,createReportApi,emptyFilters,groupCards,tr,errorText,formatNumber,exportReport(){throw Error('Only explicit export may write');},AbortController,onMounted(){},onServerPrefetch(){},onBeforeUnmount(){}};
 runInNewContext(script+'\nglobalThis.controls={load,selectGroup,apply,groups,sections,filters};',scope);const page=scope.controls;
 await page.load(true);assert.deepEqual(calls,['/reports/options?compact=1']);
 const ids=Array.from(page.groups.value.flatMap(group=>group.sections.map(section=>section.id)));
 for(const id of ['sales','sales-printing','sales-deliveries','sales-services','wallets','wallets-ledger','wallets-requests','wallets-transfers','wallets-holds','wallets-collections'])assert.ok(ids.includes(id),id);
 page.selectGroup('sales');await new Promise(setImmediate);
 let query=new URL(calls.at(-1),'http://local.test').searchParams;assert.ok(query.getAll('section_ids[]').every(id=>id.startsWith('sales')));assert.ok(query.getAll('section_ids[]').includes('sales-services'));
 page.apply({...emptyFilters(),from:'2026-10-01',to:'2026-10-16',product_id:23,status:'Print Failed'});await new Promise(setImmediate);
 query=new URL(calls.at(-1),'http://local.test').searchParams;assert.equal(query.get('from'),'2026-10-01');assert.equal(query.get('product_id'),'23');assert.equal(query.get('status'),'Print Failed');
 page.selectGroup('wallets');await new Promise(setImmediate);
 query=new URL(calls.at(-1),'http://local.test').searchParams;assert.ok(query.getAll('section_ids[]').every(id=>id.startsWith('wallets')));
 const before=calls.length;page.selectGroup('');await new Promise(setImmediate);assert.equal(calls.length,before,'returning to groups cannot count every section');
 assert.ok(calls.every(path=>path.startsWith('/reports/')),'report page cannot add unrequested operational reads');
});

test('actual POS bottom navigation replaces Topup shortcut with authorized Reports and retains Topup in services',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const Layout=(await server.ssrLoadModule('/src/layouts/PortalLayout.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const routes=[['dashboard','/dashboard',null],['sell','/sell','sell.create'],['sales','/sales','sales.view'],['reports','/reports','reports.view'],['topupAvailable','/topup/available','digital.view'],['posMore','/more',null]].map(([name,path,permission])=>({name,path,meta:{requiresAuth:true,permission},component:{render:()=>null}}));
  for(const allowed of [true,false]){
   const router=createRouter({history:createMemoryHistory(),routes});await router.push('/reports');const session={state:reactive({identity:{user:{id:7,name:'معاينة'},account:{id:12,type:'pos'},membership:{kind:'owner'}}}),can:key=>allowed||key!=='reports.view',api:{}};
   const app=createSSRApp({render:()=>h(Layout,{scopeLabel:'POS'})});app.use(router);app.provide(portalContextKey,{session});const html=await renderToString(app),nav=html.match(/<nav[^>]*class="[^"]*pos-app-nav[^>]*>([\s\S]*?)<\/nav>/)?.[1];
   assert.ok(nav);assert.equal(nav.includes('href="/reports"'),allowed);assert.equal(nav.includes('>التقارير<'),allowed);assert.ok(!nav.includes('href="/topup/available"'));
   assert.ok(html.includes('href="/topup/available"'),'Topup category page remains reachable from services');
  }
 }finally{await server.close();}
});

test('actual POS report overview lists authorized operation metadata without loading all counts or exposing the POS presentation to admin',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const Page=(await server.ssrLoadModule('/src/modules/reports/ReportsPage.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  for(const type of ['pos','system']){
   const calls=[],session={state:reactive({identity:{account:{id:12,type},user:{id:7},membership:{kind:'owner'}}}),can:()=>true,api:{request:async path=>{calls.push(path);assert.equal(path,'/reports/options?compact=1');return{data:structuredClone(options)};},mutate(){throw Error('No write');}}};
   const app=createSSRApp({render:()=>h(Page)});app.provide(portalContextKey,{session});const html=await renderToString(app);assert.deepEqual(calls,['/reports/options?compact=1']);
   if(type==='pos'){assert.ok(html.includes('تقارير نقطة البيع'));for(const title of ['سجل محاولات الطباعة','سجل تسليم البطاقات','حركات محافظ الخدمات','طلبات التمويل ومتابعتها'])assert.ok(html.includes(title),title);}
   else{assert.ok(!html.includes('تقارير نقطة البيع'));assert.ok(!html.includes('class="report-group-sections"'));}
  }
 }finally{await server.close();}
});

test('actual print report table renders all three attempt outcomes in Arabic from existing schema status metadata',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const Details=(await server.ssrLoadModule('/src/modules/reports/ReportDetails.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const section=sections.find(item=>item.id==='sales-printing'),columns=section.columns.map(column=>({...column,...(column.key==='status'?{label_type:'status'}:{})}));
  const session={state:reactive({identity:{account:{id:12,type:'pos'},user:{id:7},membership:{kind:'owner'}}}),can:()=>true};
  const router=createRouter({history:createMemoryHistory(),routes:[{path:'/reports',name:'reports',component:{render:()=>null}}]});await router.push('/reports');let calls=0;
  const api={rows:async id=>{calls++;assert.equal(id,'sales-printing');return{data:['pending','success','failed'].map((status,index)=>({row_key:String(index+1),sale:21,time:'2026-10-16T00:00:00Z',user:'معاينة',status,reason:null})),columns,meta:{current_page:1,total:3,last_page:1}};}};
  const app=createSSRApp({render:()=>h(Details,{section,filters:{currency:'IQD'},api})});app.use(router);app.provide(portalContextKey,{session});const html=await renderToString(app);
  assert.equal(calls,1);for(const label of ['بانتظار تأكيد الطباعة','نجاح الطباعة','فشل الطباعة'])assert.ok(html.includes(label),label);
  assert.ok(!html.includes('>success<'));assert.ok(!html.includes('غير محدد'));
 }finally{await server.close();}
});
