import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';
import {computed,createSSRApp,h,onServerPrefetch,reactive,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {parse} from '@vue/compiler-sfc';
import {createMemoryHistory,createRouter} from 'vue-router';
import {createServer} from 'vite';
import {posMoreIdentity,posMoreIcon} from '../src/layouts/pos-more-presentation.js';
import {money} from '../src/modules/finance/finance-model.js';

test('More identifies the current point owner and direct parent without substituting the agent logo or accepting arbitrary photo URLs',()=>{
 const identity={user:{name:'صاحب النقطة'},account:{name:'نقطة البيع',parent_name:'الوكيل المباشر',personal_image_url:'/api/v1/auth/personal-image?v=45'},brand:{name:'وكيل أعلى',logo_url:'/api/v1/auth/brand-image?v=8'}};
 assert.deepEqual(posMoreIdentity(identity),{name:'صاحب النقطة',point:'نقطة البيع',parent:'الوكيل المباشر',photo:'/api/v1/auth/personal-image?v=45',initial:'ص'});
 assert.equal(posMoreIdentity(identity,'https://api.example.test/api/v1').photo,'https://api.example.test/api/v1/auth/personal-image?v=45');
 for(const path of ['https://example.test/image.png','/api/v1/accounts/2/attachments/3/content','javascript:alert(1)','/api/v1/auth/personal-image?v=4&account_id=1'])assert.equal(posMoreIdentity({...identity,account:{...identity.account,personal_image_url:path}}).photo,null);
 assert.equal(posMoreIdentity({account:{name:'نقطة',parent_id:2},brand:identity.brand}).parent,'');
 assert.equal(posMoreIdentity({user:{name:'نقطة'},account:{name:'نقطة'}}).point,'');
 const employee=posMoreIdentity({user:{name:'موظف النقطة'},account:{owner_name:'صاحب النقطة',name:'متجر النقطة',parent_name:'فرع الوكيل'}});
 assert.equal(employee.name,'صاحب النقطة');assert.equal(employee.point,'متجر النقطة');assert.equal(employee.parent,'فرع الوكيل');
 for(const id of ['profile','language','about','logout','digital','topupAvailable','sources','accountTime','printPolicies'])assert.notEqual(posMoreIcon(id),'M4 4h16v16H4Z');
});

test('the funding amount chooser emits only an approved amount and retains a disabled choice without writing',async()=>{
 const source=await readFile(new URL('../src/modules/finance/FundingAmountPicker.vue',import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const props=reactive({modelValue:'25000.00',amounts:['25000.00','50000.00'],currency:'د.ع',disabled:false}),events=[];
 const scope={computed,ref,money,defineProps:()=>props,defineEmits:()=>((...args)=>events.push(args))};
 runInNewContext(script+'\nglobalThis.controls={choose,open,selected};',scope);
 scope.controls.open.value=true;scope.controls.choose('12345.00');assert.equal(events.length,0);assert.equal(scope.controls.open.value,true);
 props.disabled=true;scope.controls.choose('50000.00');assert.equal(events.length,0);
 props.disabled=false;scope.controls.choose('50000.00');assert.deepEqual(events,[['update:modelValue','50000.00']]);assert.equal(scope.controls.open.value,false);
 props.amounts=['50000.00'];assert.equal(scope.controls.selected.value,undefined,'a removed amount cannot keep a valid-looking selected label');
});

async function fixture(run){
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{return await run(server);}finally{await server.close();}
}

test('wallet cards contain separate selection and privacy buttons, without duplicated page headings or nested buttons',()=>fixture(async server=>{
 const Summary=(await server.ssrLoadModule('/src/modules/finance/PosWalletSummary.vue')).default;
 const html=await renderToString(createSSRApp({render:()=>h(Summary,{services:[{id:'voucher'},{id:'topup'}],selected:'voucher',summaries:{voucher:{available:'15000.00',current:'15000.00',held:'0.00'},topup:{available:'10000.00',current:'10000.00',held:'0.00'}}})}));
 assert.equal((html.match(/<article\b/g)||[]).length,2);assert.equal((html.match(/class="pos-wallet-privacy"/g)||[]).length,2);
 assert.equal((html.match(/class="pos-wallet-card-select"/g)||[]).length,2);assert.ok(!html.includes('<h2>'));
 let depth=0;for(const tag of html.match(/<\/?button\b[^>]*>/g)||[]){depth+=tag.startsWith('</')?-1:1;assert.ok(depth<=1,'privacy cannot be nested inside a selection button');}assert.equal(depth,0);
 assert.ok(html.includes('15,000'));assert.ok(html.includes('10,000'));
}));

test('the actual More page renders the owner card above its permission-filtered options and retains the delete-account control',()=>fixture(async server=>{
 const More=(await server.ssrLoadModule('/src/layouts/PosMore.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
 const router=createRouter({history:createMemoryHistory(),routes:[{path:'/more',name:'posMore',component:More},{path:'/profile',name:'profile',component:{render:()=>null},meta:{requiresAuth:true}}]});await router.push('/more');
 const session={state:reactive({identity:{user:{id:1,name:'مالك النقطة'},account:{id:2,type:'pos',name:'نقطة الاختبار',parent_name:'وكيل النقطة',personal_image_url:'/api/v1/auth/personal-image?v=7'}},busy:false}),can:()=>false,refresh:async()=>assert.fail('SSR cannot refresh'),logout:()=>assert.fail('render cannot log out')};
 const app=createSSRApp({render:()=>h(More)});app.use(router);app.provide(portalContextKey,{session,portal:{id:'pos'},config:{}});
 const html=await renderToString(app);assert.ok(html.includes('مالك النقطة'));assert.ok(html.includes('نقطة الاختبار'));assert.ok(html.includes('وكيل النقطة'));
 assert.ok(html.indexOf('class="pos-more-profile"')<html.indexOf('class="pos-more-list"'));assert.ok(!html.includes('<h1>'));assert.ok(html.includes('/api/v1/auth/personal-image?v=7'));
 assert.ok(html.includes('class="pos-more-delete"'));assert.ok(html.includes('حذف الحساب'));assert.ok(!html.includes('مستخدمو النظام'));
 assert.match(html,/<button\b[^>]*role="switch"[^>]*aria-checked="(?:true|false)"[^>]*aria-label="الوضع الليلي"/);assert.equal((html.match(/role="switch"/g)||[]).length,1);assert.match(html,/<span class="pos-switch[^\"]*" aria-hidden="true"/);
}));

test('a wallet pull refresh keeps the service, filters, current ledger page and registered child while using fresh GET reads',()=>fixture(async server=>{
 const Page=(await server.ssrLoadModule('/src/modules/finance/WalletsPage.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
 const account={id:31,type:'pos',name:'نقطة',status:'active'},options={own_account_id:31,parent_account_id:30,accounts:[account],services:[{id:'voucher',name:'البطاقات'},{id:'topup',name:'Topup'}],currencies:['IQD']},calls=[];
 const summary={own_balance:'15000.00',own_available:'15000.00',own_held:'0.00'},session={state:reactive({identity:{account,membership:{kind:'owner'},permissions:['wallets.view','ledger.view']}}),can:key=>['wallets.view','ledger.view'].includes(key),api:{peekRequest:path=>path==='/finance/options?compact=1'?{data:options}:null,request:async(path,requestOptions)=>{calls.push({path,options:requestOptions});if(path==='/finance/options?compact=1')return{data:options};if(path.startsWith('/finance/ledger?'))return{data:[{id:93,kind:'funding',amount:'15000.00'}],meta:{current_page:3,last_page:4,total:93}};return{data:[],summary,meta:{current_page:1,last_page:1,total:0}};},mutate:()=>assert.fail('refresh cannot mutate'),clearReadCache:()=>assert.fail('refresh cannot discard navigation state')}};
 let childReads=0;
 const inspected={...Page,setup(props,ctx){const c=Page.setup(props,ctx);c.tab.value='fundingRequests';c.service.value='topup';c.filters.from='2026-10-01';c.ledger.state.meta.current_page=3;
  const unregister=c.registerRefresh(async()=>childReads++);
  onServerPrefetch(async()=>{await c.refreshPage();assert.equal(c.tab.value,'fundingRequests');assert.equal(c.service.value,'topup');assert.equal(c.filters.from,'2026-10-01');assert.equal(childReads,1);assert.equal(c.ledger.state.meta.current_page,3);unregister();});return c;}};
 const router=createRouter({history:createMemoryHistory(),routes:[{path:'/',name:'wallets',component:inspected}]});await router.push('/');const app=createSSRApp({render:()=>h(inspected)});app.use(router);app.provide(portalContextKey,{session,portal:{id:'pos'},config:{}});await renderToString(app);
 assert.ok(calls.some(call=>call.path.startsWith('/finance/ledger?')&&call.path.includes('page=3')&&call.path.includes('service=topup')));
 for(const call of calls)assert.equal(call.options.fresh,true,call.path);
}));

test('More reports a failed background identity refresh as failed and leaves the current point visible',()=>fixture(async server=>{
 const More=(await server.ssrLoadModule('/src/layouts/PosMore.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
 const identity={user:{id:1,name:'المستخدم'},account:{id:2,type:'pos',owner_name:'صاحب النقطة',name:'نقطة الاختبار'}},session={state:reactive({identity,busy:false,error:'انقطع الاتصال أثناء التحديث.'}),can:()=>false,refresh:async options=>{assert.equal(options.background,true);return null;}};
 const inspected={...More,setup(props,ctx){const c=More.setup(props,ctx);onServerPrefetch(async()=>{assert.equal(await c.refreshIdentity(),false);assert.equal(c.error.value,session.state.error);assert.equal(c.profile.value.name,'صاحب النقطة');assert.equal(session.state.identity.account.id,2);});return c;}};
 const router=createRouter({history:createMemoryHistory(),routes:[{path:'/more',name:'posMore',component:inspected}]});await router.push('/more');const app=createSSRApp({render:()=>h(inspected)});app.use(router);app.provide(portalContextKey,{session,portal:{id:'pos'},config:{}});const html=await renderToString(app);assert.ok(html.includes('انقطع الاتصال أثناء التحديث.'));assert.ok(html.includes('صاحب النقطة'));
}));

test('funding history stays available for POS and admin after removal of the generic deposit workflow',()=>fixture(async server=>{
 const Component=(await server.ssrLoadModule('/src/modules/finance/SimpleWallets.vue')).default,{financeKey}=await server.ssrLoadModule('/src/modules/finance/finance-model.js');
 for(const type of ['pos','system']){
  const rows=['approved','pending','rejected','cancelled'].map((status,index)=>({id:17+index,amount:'25000.00',currency:'IQD',service:'cash',from_account_id:1,from_account_name:'الممول',to_account_id:4,to_account_name:'المستفيد',status,created_at:'2026-10-18T08:00:00Z'}));
  const vm={service:ref('cash'),currency:ref('IQD'),identity:ref({account:{type}}),ownId:ref(4),admin:ref(type==='system'),ownVisible:ref(true),hasChildren:ref(0),filters:reactive({}),options:{accounts:[],services:[],funding_policy:{amounts:['25000.00'],daily_limit:3},counts:{}},can:()=>true,resource:()=>({state:{rows,meta:{current_page:1,last_page:1,total:rows.length}}}),key:()=>({}),name:id=>'#'+id,serviceName:()=> 'المحفظة',currencyLabel:()=> 'د.ع',walletAmount:()=> '25000.00',busy:ref(false),error:ref('')};
  const app=createSSRApp({render:()=>h(Component)});app.provide(financeKey,vm);const html=await renderToString(app);
  for(const text of ['سجل التمويل','25,000','الممول','المستفيد'])assert.ok(html.includes(text),`${type}: ${text}`);
  assert.ok(!html.includes('إيداع'));assert.ok(!html.includes('تسجيل الإيداع'));if(type==='pos')assert.ok(html.includes('class="pos-funding-record"'));
  for(const badge of ['good','warn','danger','neutral'])assert.match(html,new RegExp('class="(?=[^"]*\\bbadge\\b)(?=[^"]*\\b'+badge+'\\b)[^"]*"'),`${type}: distinguish ${badge} funding status`);
 }
}));
