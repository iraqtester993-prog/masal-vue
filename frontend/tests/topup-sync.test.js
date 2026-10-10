import {createRouter,createMemoryHistory} from 'vue-router';
import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp as vueSSRApp,h,reactive,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createDigitalApi} from '../src/modules/digital/digital-api.js';

function createSSRApp(component){const app=vueSSRApp(component);app.use(createRouter({history:createMemoryHistory(),routes:[{path:'/:pathMatch(.*)*',component:{render:()=>null}}]}));return app;}

test('Topup synchronization uses the stored server connection and version without forwarding a key or purchasing',async()=>{
  const calls=[],signal=new AbortController().signal;
  const api=createDigitalApi({mutate:async(...args)=>{calls.push(args);return {data:{sync:{balance:'ok',catalog:'empty'}}};}});
  await api.sync(12,3,signal);
  assert.deepEqual(calls,[['/digital/connections/12/sync','POST',{version:3},signal,{timeoutMs:120000}]]);
});

test('Topup settings distinguish an empty provider catalog from a known balance and restrict synchronization to the owner',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const settings=(await server.ssrLoadModule('/src/modules/digital/DigitalSettings.vue')).default;
    const connection={id:12,account_id:2,account_name:'الوكيل الرئيسي',provider:'topup',credential_present:true,active:true,version:3,offers:[],grants:[],company_balance:'100000.00',catalog_count:0,gateway:{configured:true,balance_supported:true}};
    const vm={ownId:ref(1),key:()=>({}),state:reactive({options:{accounts:[]},connections:[connection],connectionsMeta:{current_page:1,last_page:1,total:1},busy:false,loading:false})};
    const owner=await renderToString(createSSRApp({render:()=>h(settings,{vm,canSetup:true,canAssign:false})}));
    assert.ok(owner.includes('100,000'));
    assert.ok(owner.includes('الشركة رجّعت قائمة فئات فارغة'));
    assert.ok(owner.includes('جلب فئات الشركة'));
    assert.ok(!owner.includes('test-private-topup-key'));
    const agent=await renderToString(createSSRApp({render:()=>h(settings,{vm,canSetup:false,canAssign:true})}));
    assert.ok(!agent.includes('جلب فئات الشركة'));
  }finally{await server.close();}
});


test('company-only catalog refresh does not request a balance',async()=>{
  const calls=[],signal=new AbortController().signal;
  const api=createDigitalApi({mutate:async(...args)=>{calls.push(args);return {data:{}};}});
  await api.syncCatalog(12,3,signal);
  assert.deepEqual(calls,[['/digital/connections/12/sync','POST',{version:3,catalog_only:true},signal,{timeoutMs:120000}]]);
});

test('Topup company category page has no manual creation or category-name submission controls',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const page=(await server.ssrLoadModule('/src/modules/digital/TopupCategoriesPage.vue')).default;
    const session={state:reactive({identity:{account:{id:1,type:'system'},membership:{kind:'owner'}}}),can:()=>true,api:{request:()=>{throw Error('SSR must not fetch');},mutate:()=>{throw Error('Company category page cannot write');}}};
    const app=createSSRApp({render:()=>h(page)});
    app.provide(portalContextKey,{session,portal:{id:'admin'},config:{}});
    const html=await renderToString(app);
    assert.ok(html.includes('معرف فئة الشركة'));
    assert.ok(html.includes('الرصيد وحده لا يتيح التعبئة'));
    assert.ok(!html.includes('إضافة فئة'));
    assert.ok(!html.includes('استخدام اسم الفئة'));
  }finally{await server.close();}
});
