import test from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import {fileURLToPath} from 'node:url';
import {createSSRApp,h,reactive} from 'vue';
import {createRouter,createMemoryHistory} from 'vue-router';
import {renderToString} from '@vue/server-renderer';
import {activitySource,activityTitle,activityTone} from '../src/modules/dashboard/activity-model.js';

test('activity labels identify financial actions and audit actions without exposing unknown machine codes',()=>{
  assert.equal(activityTitle({kind:'ledger',action:'transfer'}),'تحويل');
  assert.equal(activityTitle({kind:'ledger',action:'future-transfer'}),'حركة مالية');
  assert.equal(activityTitle({kind:'topup',action:'digital.refund'}),'استرجاع مبلغ الخدمة الإلكترونية');
  assert.equal(activityTitle({kind:'topup',action:'digital.purchase'}),'بيع خدمة إلكترونية');
  assert.equal(activityTitle({kind:'stock',action:'sales.issue'}),'بيع بطاقات');
  assert.equal(activitySource({kind:'topup'}),'Topup');
  assert.equal(activityTitle({kind:'audit',action:'support.reply'}),'إرسال رد للدعم');
  assert.equal(activityTitle({kind:'audit',action:'support.future-code'}),'نشاط في الدعم الفني');
  assert.equal(activityTone('posted'),'success');
});

test('POS preview and full page use the same financial feed even without stock-sales permission',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const Activity=(await server.ssrLoadModule('/src/modules/dashboard/DashboardActivity.vue')).default;
    const Full=(await server.ssrLoadModule('/src/modules/dashboard/PosActivityPage.vue')).default;
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const permissions=['dashboard.view','digital.view','wallets.view'];
    const session={state:reactive({identity:{user:{id:7},account:{id:12,type:'pos'},permissions}}),can:key=>permissions.includes(key),api:{
      peekRequest:path=>path.startsWith('/dashboard/activity?')?{data:[{key:'ledger:4',kind:'ledger',action:'transfer',occurred_at:'2026-10-19 08:00:00',status:'posted',amount:'5000.00',direction:'credit',destination:'wallets',parameters:{}}],meta:{next_cursor:'more'}}:null,
      request:()=>{throw Error('Rendering must not fetch or mutate');},
    }};
    const router=createRouter({history:createMemoryHistory(),routes:[{path:'/',name:'dashboard',component:Activity},{path:'/activity',name:'posActivity',component:Full},{path:'/wallets',name:'wallets',component:{render:()=>null},meta:{permission:'wallets.view'}}]});await router.push('/');
    async function render(component,props){const app=createSSRApp({render:()=>h(component,props)});app.use(router);app.provide(portalContextKey,{session});return renderToString(app);}
    const preview=await render(Activity,{compact:true});
    assert.ok(preview.includes('href="/activity"'));assert.ok(preview.includes('تحويل'));assert.ok(preview.includes('وارد'));assert.ok(!preview.includes('href="/sales"'));
    const full=await render(Full);
    assert.ok(full.includes('العمليات المالية'));assert.ok(full.includes('5,000'));assert.ok(full.includes('التالي'));assert.ok(full.includes('حتى 100 عملية'));
  }finally{await server.close();}
});
