import test from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import {fileURLToPath} from 'node:url';
import {createSSRApp,h,reactive,ref} from 'vue';
import {createRouter,createMemoryHistory} from 'vue-router';
import {renderToString} from '@vue/server-renderer';
import {systemLabel} from '../src/modules/preferences/system-labels.js';
import {reportCell} from '../src/modules/reports/report-model.js';

test('actual POS history hydrates cached ten-row pages and identifies Topup bundles without previous/next controls',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
 try{
  const History=(await server.ssrLoadModule('/src/modules/digital/POSHistory.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const record={id:42,provider:'topup',type:'bundle',product_name:'باقة الإنترنت',status:'succeeded',retail:'5000.00',created_at:'2026-10-18T06:00:00Z'};
  const permissions=['account.view','sales.view','digital.view'];
  const session={state:reactive({identity:{user:{id:7},account:{id:12,type:'pos'},permissions}}),can:key=>permissions.includes(key),api:{
   peekRequest:path=>path.startsWith('/digital/history?')?{data:[{kind:'digital',record}],meta:{pagination:'cursor',per_page:10,has_more:true,next_cursor:'next-record'}}:null,
   request:()=>{throw Error('History rendering cannot purchase or fetch another page');}
  }};
  const sales={identity:ref(session.state.identity),can:session.can,state:{busy:false}};
  const router=createRouter({history:createMemoryHistory(),routes:[{path:'/sales',name:'sales',component:{render:()=>null}}]});await router.push('/sales');
  const app=createSSRApp({render:()=>h(History,{sales})});app.use(router);app.provide(portalContextKey,{session});
  const html=await renderToString(app);
  for(const label of ['باقة الإنترنت','باقة','Topup','5,000','عرض عمليات أكثر'])assert.ok(html.includes(label),label);
  assert.ok(!html.includes('>السابق<'));assert.ok(!html.includes('>التالي<'));assert.ok(!html.includes('undefined'));
  assert.ok(html.includes('1 عملية معروضة'));assert.ok(!html.includes('11 عملية'));
 }finally{await server.close();}
});

test('transaction report service and financial movement kinds have specific Arabic labels while keeping Topup identity',()=>{
 assert.equal(systemLabel('topup','provider'),'Topup');
 assert.equal(reportCell('topup',{label_type:'movement'}),'Topup');
 assert.equal(reportCell('topup-allocation',{label_type:'movement'}),'تخصيص رصيد Topup');
 assert.equal(reportCell('stock-revaluation',{label_type:'movement'}),'إعادة تقييم المخزون');
 assert.equal(reportCell('released',{label_type:'status'}),'تم تحرير الحجز');
});
