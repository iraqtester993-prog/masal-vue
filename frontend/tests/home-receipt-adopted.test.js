import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';
import {createServer} from 'vite';
import {createSSRApp,h,onServerPrefetch,reactive,ref,computed} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {parse} from '@vue/compiler-sfc';
import {createMemoryHistory,createRouter} from 'vue-router';

const serverOptions={root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'};
const storedReceipt=({status='Printed',pending=false}={})=>({
 sale:{id:38,account_id:12,status,quantity:1,issued_at:'2026-10-16T08:00:00Z',print_pending:pending,attempts:pending?[{id:9,status:'pending'}]:[],retail_total:'10000.00'},
 cards:[{id:7,serial:'SERIAL-38',fields:{pin:'0012-3456-7890'}}],
 design:{width:58,display_order:['category','codes','amount'],qr_enabled:true,recharge_template:'*123*{pin}#'},print_policy:{},daily_print:{},
});
const receiptVm=(receipt,can=()=>true)=>({receipt:ref(receipt),pos:ref(true),identity:ref({account:{id:12,type:'pos'}}),state:reactive({busy:false,error:'',options:{}}),can,key:()=>({for(){throw Error('Render cannot create an operation');},clear(){}}),execute(){throw Error('Render cannot write');}});

test('adopted POS home uses real separate balances, hides every hero figure, and retains cached company navigation',async()=>{
 const server=await createServer(serverOptions);
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),Dashboard=(await server.ssrLoadModule('/src/modules/dashboard/DashboardPage.vue')).default;
  const {useBalancePrivacy}=await server.ssrLoadModule('/src/shared/composables/balance-privacy.js'),privacy=useBalancePrivacy();
  if(privacy.hidden.value)privacy.toggle();
  const summary={data:{cards:[{key:'balance',value:'123456.00'},{key:'sales',value:'23456.00'}],recent_operations:[]}},calls=[];
  const cached=new Map([['/dashboard/summary?currency=IQD&view=cards',summary],['/sales/products',{data:[{provider_id:4,provider_name:'شركة فعلية',provider_image_url:'/provider-logo.png'}]}],['/digital/offers?',{data:[{provider:'topup',product_provider_id:4,product_provider_name:'شركة فعلية',retail:'10000',wallet_available:'78901.00'}]}],['/dashboard/activity?limit=10',{data:[],meta:{}}]]);
  const permissions=['dashboard.view','sell.create','digital.view','wallets.view','company.view','support.view','sales.view'];
  const session={state:reactive({identity:{user:{id:3},account:{id:12,type:'pos'},membership:{kind:'owner'},permissions}}),can:key=>permissions.includes(key),api:{peekRequest:path=>cached.get(path),hasFreshRequest:path=>cached.has(path),async request(path){calls.push(path);assert.equal(path,'/dashboard/summary?currency=IQD&view=cards');return summary;}}};
  const router=createRouter({history:createMemoryHistory(),routes:['dashboard','sell','wallets','support','company','topupAvailable','digital','sales','posActivity'].map(name=>({path:'/'+name,name,component:{render:()=>null}}))});await router.push('/dashboard');
  const render=()=>renderToString(createSSRApp({render:()=>h(Dashboard)}).provide(portalContextKey,{session,portal:{id:'pos'},config:{}}).use(router));
  const visible=await render(),hero=visible.match(/<section class="pos-home-hero"[\s\S]*?<\/section>/)?.[0];
  assert.ok(hero);for(const number of ['123,456','23,456','78,901'])assert.ok(hero.includes(number));
  assert.ok(hero.includes('مبيعات البطاقات اليوم'));assert.ok(hero.includes('رصيد Topup المتاح'));assert.ok(hero.includes('quick=funding'));
  assert.ok(visible.includes('pos-home-company'));assert.ok(visible.includes('شركة فعلية'));assert.ok(visible.includes('company=stock:4'));assert.ok(visible.includes('/provider-logo.png'));
  privacy.toggle();
  const concealed=await render(),hiddenHero=concealed.match(/<section class="pos-home-hero"[\s\S]*?<\/section>/)?.[0];
  for(const number of ['123,456','23,456','78,901'])assert.ok(!hiddenHero.includes(number));
  assert.equal((hiddenHero.match(/••••••/g)||[]).length,3);assert.ok(hiddenHero.includes('aria-label="إظهار الأرصدة"'));assert.equal(calls.length,2);
  privacy.toggle();
 }finally{await server.close();}
});

test('receipt state is derived from stored outcomes and PIN text controls stay outside the exact printable receipt',async()=>{
 const server=await createServer(serverOptions);
 try{
  const ReceiptDialog=(await server.ssrLoadModule('/src/modules/sales/ReceiptDialog.vue')).default,ReceiptCard=(await server.ssrLoadModule('/src/modules/sales/ReceiptCard.vue')).default;
  for(const [status,pending,title] of [['Printed',false,'تمت الطباعة'],['Print Requested',false,'الوصل جاهز للطباعة'],['Print Requested',true,'بانتظار تأكيد نتيجة الطباعة'],['Print Failed',false,'لم تكتمل الطباعة']]){
   const receipt=storedReceipt({status,pending}),context={};await renderToString(createSSRApp({render:()=>h(ReceiptDialog,{vm:receiptVm(receipt)})}),context);
   const html=context.teleports.body,state=html.match(/<header class="receipt-app-state[\s\S]*?<\/header>/)?.[0];assert.ok(state?.includes(title));
   if(pending){assert.ok(html.includes('تأكيد النتيجة'));assert.ok(!state.includes('تمت الطباعة'));}
   const paper=await renderToString(createSSRApp({render:()=>h(ReceiptCard,{receipt})}));assert.ok(html.includes(paper),'paper is the actual unmodified ReceiptCard output');
   const copyAt=html.indexOf('class="receipt-pin-copy no-print"');assert.ok(copyAt>html.indexOf(paper)+paper.length-1,'text copy is outside the printable DOM');
   assert.ok(!paper.includes('نسخ PIN'));assert.ok(html.includes('نسخ PIN'));assert.ok(html.includes('receipt-primary-actions no-print'));
  }
  const context={};await renderToString(createSSRApp({render:()=>h(ReceiptDialog,{vm:receiptVm(storedReceipt(),permission=>permission!=='data.pin')})}),context);
  assert.ok(!context.teleports.body.includes('receipt-pin-copy'));
 }finally{await server.close();}
});

test('PIN clipboard action copies only an authorized current card and performs no sale or print mutation',async()=>{
 const source=await readFile(new URL('../src/modules/sales/ReceiptDialog.vue',import.meta.url),'utf8'),script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const vm=receiptVm(storedReceipt()),copied=[];
 const scope={ref,computed,watch(){},onBeforeUnmount(){},nextTick:async()=>{},defineProps:()=>({vm}),defineEmits:()=>()=>{},usePrinterRoute:()=>({printRoute:ref('browser')}),pendingAttempt:()=>null,saleStatuses:{},navigator:{clipboard:{async writeText(value){copied.push(value);}}}};
 runInNewContext(script+'\nglobalThis.handlers={copyPin,pinCopyNotice};',scope);
 const current=vm.receipt.value.cards[0];await scope.handlers.copyPin(current);assert.deepEqual(copied,['0012-3456-7890']);assert.ok(!scope.handlers.pinCopyNotice.value.includes('0012'));
 await scope.handlers.copyPin({id:99,fields:{pin:'STALE-PIN'}});assert.equal(copied.length,1);
 vm.can=()=>false;await scope.handlers.copyPin(current);assert.equal(copied.length,1);
});

test('receipt delivery keeps the branded channel selector outside unchanged paper and preserves encrypted-file validation',async()=>{
 const server=await createServer(serverOptions);
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),Dialog=(await server.ssrLoadModule('/src/modules/sales/ReceiptDialog.vue')).default,Card=(await server.ssrLoadModule('/src/modules/sales/ReceiptCard.vue')).default,receipt=storedReceipt();
  for(const channel of ['استلام مباشر','ملف مشفر']){
   const inspected={...Dialog,setup(props,context){const controls=Dialog.setup(props,context);onServerPrefetch(()=>{controls.delivery.value=true;controls.channel.value=channel;});return controls;}},context={};
   const app=createSSRApp({render:()=>h(inspected,{vm:receiptVm(receipt)})});app.provide(portalContextKey,{session:{state:{identity:{account:{type:'pos'}}}}});
   await renderToString(app,context);const html=context.teleports.body,paper=await renderToString(createSSRApp({render:()=>h(Card,{receipt})}));
   assert.ok(html.includes(paper));assert.ok(html.indexOf('class="app-select"')>html.indexOf(paper)+paper.length-1);assert.ok(html.includes('class="app-select-trigger"'));assert.ok(html.includes('طريقة التسليم'));assert.equal(html.includes('minlength="12"'),channel==='ملف مشفر');assert.ok(html.includes('maxlength="200"'));
  }
 }finally{await server.close();}
});
