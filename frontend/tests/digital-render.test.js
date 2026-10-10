import {createRouter,createMemoryHistory} from 'vue-router';
import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp as vueSSRApp,h,reactive,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {routeLocationKey} from 'vue-router';
function createSSRApp(component){const app=vueSSRApp(component);app.use(createRouter({history:createMemoryHistory(),routes:[{path:'/:pathMatch(.*)*',component:{render:()=>null}}]}));return app;}

test('category details render actual company names and IDs, escape text, and omit unauthorized cost',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const details=(await server.ssrLoadModule('/src/modules/digital/DigitalCategoryDetails.vue')).default;
    const connection={provider:'topup',account_name:'وكيل الفحص',offers:[{id:1,remote_id:'49',remote_name:'6000',type:'topup',retail:'6500.00',active:true},{id:2,remote_id:'4',remote_name:'<img src=x>',type:'bundle',retail:'35000.00',active:false}]};
    const html=await renderToString(createSSRApp({render:()=>h(details,{connection})}));
    assert.ok(html.includes('6000'));assert.ok(html.includes('49'));assert.ok(html.includes('6,500'));assert.ok(html.includes('تعبئة رصيد'));assert.ok(html.includes('باقة'));assert.ok(html.includes('معطّلة'));assert.ok(html.includes('&lt;img src=x&gt;'));assert.ok(!html.includes('سعر الشركة'));
    connection.offers[0].cost='6000.00';
    const allowed=await renderToString(createSSRApp({render:()=>h(details,{connection})}));assert.ok(allowed.includes('سعر الشركة'));assert.ok(allowed.includes('6,000'));
  }finally{await server.close();}
});
test('Fourth incoming token has no descending balance controls while Topup retains its real balance action',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const settings=(await server.ssrLoadModule('/src/modules/digital/DigitalSettings.vue')).default;
    const connection={account_id:2,account_name:'وكيل الفحص',active:true,version:1,offers:[],grants:[],company_balance:null};
    const vm={ownId:ref(1),key:()=>({}),state:reactive({options:{accounts:[]},connections:[{...connection,id:1,provider:'rabiaa',gateway:{configured:true,balance_supported:false}},{...connection,id:2,provider:'topup',gateway:{configured:true,balance_supported:true}}],connectionsMeta:{current_page:1,last_page:1,total:2},busy:false,loading:false})};
    const html=await renderToString(createSSRApp({render:()=>h(settings,{vm,canSetup:true,canAssign:false})}));
    assert.ok(html.includes('الرابعة'));assert.ok(html.includes('Topup'));
    assert.equal((html.match(/تحديث رصيد الشركة/g)||[]).length,1);
    assert.equal((html.match(/رصيد الشركة:/g)||[]).length,1);
    assert.ok(html.includes('لم يُجلب بعد'));assert.ok(!html.includes('مسار الرصيد غير وارد'));
  }finally{await server.close();}
});
test('actual dashboard link opens filtered digital history instead of the default POS sale screen',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),page=(await server.ssrLoadModule('/src/modules/digital/DigitalPage.vue')).default;
    const permissions=['account.view','digital.view','digital.create'],session={state:reactive({identity:{account:{id:17,type:'pos',name:'نقطة الفحص'},user:{id:10},membership:{kind:'owner'},permissions}}),can:key=>permissions.includes(key),api:{request:()=>{throw Error('History rendering must not purchase');}},refresh:async()=>null};
    const app=createSSRApp({render:()=>h(page)});
    app.provide(portalContextKey,{session,portal:{id:'pos'},config:{}});
    app.provide(routeLocationKey,reactive({matched:[],params:{},path:'/digital',query:{tab:'log',provider:'topup',main_account_id:'12',from:'2026-10-01',to:'2026-10-06',q:'provider-003',status:'succeeded'}}));
    const html=await renderToString(app);
    assert.ok(html.includes('سجل الخدمات الإلكترونية'));assert.ok(html.includes('value="provider-003"'));assert.ok(html.includes('value="2026-10-01"'));assert.ok(html.includes('value="2026-10-06"'));assert.match(html,/value="topup" selected/);assert.match(html,/value="succeeded" selected/);assert.ok(!html.includes('digital-sale-form'));
  }finally{await server.close();}
});
test('actual digital screens compile for owner, employee, agents and POS without fictitious company results or writes',async()=>{const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});try{const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),page=(await server.ssrLoadModule('/src/modules/digital/DigitalPage.vue')).default;for(const [type,kind] of [['system','owner'],['system','employee'],['main_agent','owner'],['sub_agent','owner'],['sub_branch','owner'],['pos','owner']]){const permissions=['account.view','digital.view','digital.create','digital.assign','digital.receipt','digital.export','integrations.view','integrations.edit','data.pin'],session={state:reactive({identity:{account:{id:12,type,name:'حساب الفحص'},user:{id:10},membership:{kind},permissions}}),can:key=>permissions.includes(key),api:{request:()=>{throw Error('SSR cannot fabricate provider results');},mutate:()=>{throw Error('SSR cannot execute provider writes');}},refresh:async()=>null};for(const mode of ['digital','integrations']){const app=createSSRApp({render:()=>h(page,{mode})});app.provide(portalContextKey,{session,portal:{id:type==='system'?'admin':type==='pos'?'pos':'agents'},config:{}});const html=await renderToString(app);assert.ok(html.length>150);assert.ok(!html.includes('undefined'));assert.equal(html.includes('إعداد الربط'),type==='system'&&kind==='owner');assert.equal(html.includes('البيع من الفئات'),type==='pos');assert.ok(!html.includes('actual-test-pin'));}}}finally{await server.close();}});
test('digital receipt escapes actual provider code and never marks physical printing successful',async()=>{const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});try{const receipt=(await server.ssrLoadModule('/src/modules/digital/DigitalReceipt.vue')).default,html=await renderToString(createSSRApp({render:()=>h(receipt,{vm:{api:{},state:{},failure:async()=>''},receipt:{order:{provider:'rabiaa',product_name:'بطاقة',account_name:'نقطة',creator_name:'أحمد',retail:'5000.25',company_transaction_id:'provider-003',created_at:'2026-10-06T12:00:00Z'},code:'<img src=x onerror=alert(1)>',serial:'00000001'}})}));assert.ok(html.includes('&lt;img src=x'));assert.ok(!html.includes('<img src=x'));assert.ok(html.includes('طباعة الإيصال'));assert.ok(!html.includes('نجاح الطباعة'));assert.ok(html.includes('5,000.25'));}finally{await server.close();}});

test('POS session hides serial when restriction is off and retains it when on',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const component=(await server.ssrLoadModule('/src/modules/sales/DeviceSession.vue')).default;
    for(const enabled of [true,false]) {
      const vm={pos:ref(true),identity:ref({account:{device_lock_enabled:enabled}}),api:{heartbeat:()=>{throw Error('SSR cannot start a device session');}}};
      const html=await renderToString(createSSRApp({render:()=>h(component,{vm})}));
      assert.equal(html.includes('الرقم التسلسلي المعتمد للجهاز'),enabled);
      assert.equal(html.includes('إعدادات جهاز البيع'),enabled);
      assert.ok(!html.includes('جلسة هذا المتصفح'));
      if(!enabled)assert.ok(!html.includes('إصدار النظام'));
    }
  } finally {await server.close();}
});
