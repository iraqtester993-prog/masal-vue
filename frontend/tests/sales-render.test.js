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
      for(const [name,page] of pages){for(const mode of name==='SalesPage'?['sales','exceptions']:['sales']){const app=createSSRApp({render:()=>h(page,{mode})});app.provide(portalContextKey,{session,portal:{id:type==='system'?'admin':type==='pos'?'pos':'agents'},config:{}});app.provide(routeLocationKey,reactive({name:mode,query:{}}));const html=await renderToString(app);assert.ok(html.length>100,`${name}/${type}/${mode}`);assert.ok(!html.includes('undefined'));assert.ok(!html.includes('بطاقات تجريبية'));if(name==='SalesPage'&&mode==='exceptions')assert.equal(html.includes('حالة عملياتي لإعادة الطباعة'),type==='sub_agent');}}
      assert.deepEqual(requests,[]);
      if(type==='pos'){
        const html=await renderToString(createSSRApp({render:()=>h(pages[0][1])}).provide(portalContextKey,{session,portal:{id:'pos'},config:{}}).provide(routeLocationKey,reactive({name:'sell',query:{}})));
        assert.ok(html.includes('Topup'),'POS service tab uses the requested English service name');
      }
    }
  } finally {await server.close();}
});

test('receipt keeps all actual fields escaped and print result controls require an explicit pending attempt',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const card=(await server.ssrLoadModule('/src/modules/sales/ReceiptCard.vue')).default,dialog=(await server.ssrLoadModule('/src/modules/sales/ReceiptDialog.vue')).default;
    const receipt={sale:{id:9,account_id:12,product_name:'الفئة',account_name:'نقطة البيع',status:'Print Requested',currency:'IQD',retail_total:'110.50',issued_at:'2026-10-06T09:00:00Z',print_pending:false,version:3,failed_retry_count:0,reprints:0},cards:[{id:2,serial:'000001',expiry:'2027-01-01',fields:{pin:'<img src=x onerror=alert(1)>',extra_fields:{code:'007'}}}],design:{width:80,company_name:'الشركة',agent_name:'الوكيل',header:'عنوان',footer:'نهاية',color:'#172b4d',display_order:['company','header','category','image','codes','amount','agent','footer']},extra_fields:[{key:'code',label:'رمز إضافي'}],print_policy:{max_cards:10,failed_retries:2,interval_seconds:5},daily_print:{limit:0},print_wait_seconds:0};
    const html=await renderToString(createSSRApp({render:()=>h(card,{receipt})}));assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));assert.ok(!html.includes('<img src=x'));assert.ok(html.includes('رمز إضافي: 007'));assert.ok(html.includes('110.50'));
    for(const pending of [false,true]){receipt.sale.print_pending=pending;receipt.sale.attempts=pending?[{id:4,status:'pending'}]:[];const vm={state:reactive({busy:false,error:''}),receipt:ref(receipt),can:()=>true,key:()=>({for:()=>{throw Error('Render must not generate operation');},clear(){}}),clearReceipt(){},execute(){throw Error('Render must not write');}};const context={};await renderToString(createSSRApp({render:()=>h(dialog,{vm})}),context);const modal=context.teleports.body;assert.equal(modal.includes('اختر النتيجة بعد خروج الورقة.'),pending);assert.equal(modal.includes('تأكيد النتيجة'),pending);if(pending){assert.ok(/disabled[^>]*>تأكيد النتيجة/.test(modal));assert.ok(modal.includes('تمت الطباعة'));assert.ok(modal.includes('فشلت الطباعة'));assert.equal((modal.match(/aria-pressed="false"/g)||[]).length,2);}}
  }finally{await server.close();}
});

test('a receipt identifies another pending print and offers its recovery without marking it successful',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const dialog=(await server.ssrLoadModule('/src/modules/sales/ReceiptDialog.vue')).default;
    const receipt={sale:{id:7,status:'Print Requested',print_pending:false,retail_total:'6000.00',quantity:1},pending_print:{id:5,product_name:'آسياسيل 5,000'},design:{display_order:['category'],width:58},cards:[]};
    const vm={state:reactive({busy:false,error:'',options:{}}),receipt:ref(receipt),receiptLoading:ref(false),can:()=>true,key:()=>({for(){throw Error('Render must not write');},clear(){}})};
    const context={};await renderToString(createSSRApp({render:()=>h(dialog,{vm})}),context);
    const html=context.teleports.body;
    assert.ok(html.includes('فتح العملية المعلقة'));assert.ok(html.includes('#5'));assert.ok(html.includes('آسياسيل 5,000'));
    assert.ok(!html.includes('تأكيد النتيجة'));assert.ok(/disabled[^>]*>طباعة المتصفح/.test(html));
  }finally{await server.close();}
});

test('POS failed receipt preserves its original cards and allows a copy without an approval request',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const dialog=(await server.ssrLoadModule('/src/modules/sales/ReceiptDialog.vue')).default;
    const receipt={sale:{id:7,account_id:12,status:'Print Failed',issued_at:'2026-10-13T09:00:00Z',print_pending:false,retail_total:'6000.00',quantity:1,failed_retry_count:0,failure_reason:'نفاد الورق'},design:{display_order:['category','codes','footer'],width:80,footer:'نهاية محفوظة'},cards:[{id:20,serial:'same-card',fields:{pin:'123456'}}],print_policy:{failed_retries:3,max_cards:10,interval_seconds:0}};
    const vm={state:reactive({busy:false,error:'',options:{}}),identity:ref({account:{id:12,type:'pos'}}),pos:ref(true),receipt:ref(receipt),can:()=>true,key:()=>({for(){throw Error('Render must not write');},clear(){}})};
    const context={};await renderToString(createSSRApp({render:()=>h(dialog,{vm})}),context);
    const html=context.teleports.body;
    assert.ok(!html.includes('طلب إذن إعادة الطباعة'));assert.ok(html.includes('إعادة طباعة بالمتصفح'));assert.ok(html.includes('123456'));assert.ok(html.includes('نهاية محفوظة'));assert.ok(html.includes('--receipt-width:58mm'));
    assert.ok(!html.includes('ضوابط الطباعة'));assert.ok(!html.includes('إعادة طباعة نفس الوصل</button>'));assert.ok(!html.includes('تأكيد النتيجة'));
  }finally{await server.close();}
});

test('POS history starts with search and a compact collapsed filter panel and makes no request while rendering',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const page=(await server.ssrLoadModule('/src/modules/digital/POSHistory.vue')).default;
    const session={state:reactive({identity:{account:{id:12,type:'pos'},user:{id:7}}}),can:()=>true,api:{request(){throw Error('Render must not fetch');},mutate(){throw Error('Render must not write');}}};
    const sales={identity:ref(session.state.identity),can:()=>true,state:reactive({busy:false}),receiptLoading:ref(false)};
    const html=await renderToString(createSSRApp({render:()=>h(page,{sales})}).provide(portalContextKey,{session,portal:{id:'pos'},config:{}}));
    assert.ok(html.includes('سجل العمليات'));assert.ok(html.includes('بحث برقم العملية أو الفئة'));assert.ok(html.includes('aria-expanded="false"'));
    assert.ok(!html.includes('type="date"'));assert.ok(!html.includes('>تحديث</button>'));
  }finally{await server.close();}
});
