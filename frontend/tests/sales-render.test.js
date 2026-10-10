import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {routeLocationKey} from 'vue-router';

test('actual sales surfaces compile and render for system, subagent and POS without writing or fabricating a server response',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const pages=await Promise.all(['SellPage','SalesPage','PrintPoliciesPage','ReceiptDesignerPage'].map(async name=>[name,(await server.ssrLoadModule(`/src/modules/sales/${name}.vue`)).default]));
    for(const type of ['system','sub_agent','pos']) {
      const requests=[];const permissions=['account.view','sales.view','sell.create','sell.receipt','sell.print','sell.result','sell.reprint','sell.deliver','exceptions.view','exceptions.approve','security.view','security.policies','branding.view','branding.receipt','branding.edit','products.edit','data.pin'];
      const session={state:reactive({identity:{account:{id:12,type,name:'حساب الفحص'},user:{id:10},membership:{kind:'owner'},permissions}}),can:permission=>permissions.includes(permission),api:{request:(...args)=>{requests.push(args);throw Error('Render must not fabricate API data');},mutate:()=>{throw Error('Render must not write');}},refresh:async()=>null};
      for(const [name,page] of pages){for(const mode of name==='SalesPage'?['sales','exceptions']:['sales']){const app=createSSRApp({render:()=>h(page,{mode})});app.provide(portalContextKey,{session,portal:{id:type==='system'?'admin':type==='pos'?'pos':'agents'},config:{}});app.provide(routeLocationKey,reactive({name:mode,query:{}}));const html=await renderToString(app);assert.ok(html.length>100,`${name}/${type}/${mode}`);assert.ok(!html.includes('undefined'));assert.ok(!html.includes('بطاقات تجريبية'));if(name==='SalesPage'&&mode==='exceptions')assert.equal(html.includes('حالة عملياتي لإعادة الطباعة'),type!=='system');}}
      assert.deepEqual(requests,[]);
    }
  } finally {await server.close();}
});

test('receipt keeps all actual fields escaped and print result controls require an explicit pending attempt',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const card=(await server.ssrLoadModule('/src/modules/sales/ReceiptCard.vue')).default,dialog=(await server.ssrLoadModule('/src/modules/sales/ReceiptDialog.vue')).default;
    const receipt={sale:{id:9,account_id:12,product_name:'الفئة',account_name:'نقطة البيع',status:'Print Requested',currency:'IQD',retail_total:'110.50',issued_at:'2026-10-06T09:00:00Z',print_pending:false,version:3,failed_retry_count:0,reprints:0},cards:[{id:2,serial:'000001',expiry:'2027-01-01',fields:{pin:'<img src=x onerror=alert(1)>',extra_fields:{code:'007'}}}],design:{width:80,company_name:'الشركة',agent_name:'الوكيل',header:'عنوان',footer:'نهاية',color:'#172b4d',display_order:['company','header','category','image','codes','amount','agent','footer']},extra_fields:[{key:'code',label:'رمز إضافي'}],print_policy:{max_cards:10,failed_retries:2,interval_seconds:5},daily_print:{limit:0},print_wait_seconds:0};
    const html=await renderToString(createSSRApp({render:()=>h(card,{receipt})}));assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));assert.ok(!html.includes('<img src=x'));assert.ok(html.includes('رمز إضافي: 007'));assert.ok(html.includes('110.50'));
    for(const pending of [false,true]){receipt.sale.print_pending=pending;receipt.sale.attempts=pending?[{id:4,status:'pending'}]:[];const vm={state:reactive({busy:false,error:''}),receipt:ref(receipt),can:()=>true,key:()=>({for:()=>{throw Error('Render must not generate operation');},clear(){}}),clearReceipt(){},execute(){throw Error('Render must not write');}};const context={};await renderToString(createSSRApp({render:()=>h(dialog,{vm})}),context);const modal=context.teleports.body;assert.ok(modal.includes('نافذة الطباعة لا تؤكد خروج الورقة'));assert.equal(modal.includes('تأكيد نجاح الطباعة'),pending);}
  }finally{await server.close();}
});
