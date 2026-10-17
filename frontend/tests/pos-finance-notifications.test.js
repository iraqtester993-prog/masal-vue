import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive} from 'vue';
import {createMemoryHistory,createRouter} from 'vue-router';
import {renderToString} from '@vue/server-renderer';
import {posWalletValues} from '../src/modules/finance/pos-wallet-summary.js';
import {notificationContext,notificationGroups} from '../src/modules/notifications/pos-notifications.js';

test('POS balances use explicit own amounts and preserve unknown values without inferred totals',()=>{
 assert.deepEqual(posWalletValues({balance:'999999',available:'999999',held:'999999'}),{available:null,current:null,held:null});
 assert.deepEqual(posWalletValues({own_balance:'155000.00',own_held:'5000.00'}),{available:null,current:'155000.00',held:'5000.00'});
 assert.deepEqual(posWalletValues({own_balance:'0',own_available:0,own_held:'invalid'}),{available:0,current:'0',held:null});
 assert.deepEqual(posWalletValues({own_balance:'12',own_available:'10',own_held:'2'},false),{available:null,current:null,held:null});
});

test('notifications group by Baghdad calendar date while retaining row order and safe contexts',()=>{
 const rows=[{id:1,time:'2026-10-15T22:30:00Z'},{id:2,time:'2026-10-15 20:59:00'},{id:3,time:'2026-10-15T23:30:00+03:00'},{id:4,time:'invalid'},{id:5,time:null}];
 const groups=notificationGroups(rows,{now:new Date('2026-10-16T03:00:00Z')});
 assert.deepEqual(groups.map(group=>[group.label,group.rows.map(row=>row.id)]),[['اليوم',[1]],['أمس',[2,3]],['تاريخ غير متوفر',[4,5]]]);
 assert.deepEqual(notificationContext({page:'wallets'}),{label:'المحافظ',icon:'wallets'});
 assert.deepEqual(notificationContext({page:'__proto__'}),{label:'إشعار الحساب',icon:'notifications'});
});

test('actual POS wallet and notification components render independent balances, privacy and safe notification content',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
 try{
  const Summary=(await server.ssrLoadModule('/src/modules/finance/PosWalletSummary.vue')).default;
  const Notices=(await server.ssrLoadModule('/src/modules/notifications/PosNotificationsList.vue')).default;
  const {useBalancePrivacy}=await server.ssrLoadModule('/src/shared/composables/balance-privacy.js');
  const privacy=useBalancePrivacy();if(privacy.hidden.value)privacy.toggle();
  const props={services:[{id:'voucher'},{id:'topup'},{id:'cash'}],selected:'topup',summaries:{voucher:{available:'150000',current:'155000',held:'5000'},topup:{available:'94000',current:'100000',held:'6000'}}};
  const html=await renderToString(createSSRApp({render:()=>h(Summary,props)}));
  assert.equal((html.match(/<button[^>]*class="[^"]*\bpos-wallet-card\b/g)||[]).length,2);
  for(const amount of ['150,000','155,000','5,000','94,000','100,000','6,000'])assert.ok(html.includes(amount),amount);
  assert.ok(html.includes('محفظة البطاقات'));assert.ok(html.includes('محفظة Topup'));
  privacy.toggle();
  const concealed=await renderToString(createSSRApp({render:()=>h(Summary,props)}));
  assert.ok(!concealed.includes('150,000'));assert.ok(!concealed.includes('94,000'));assert.ok(concealed.includes('••••••'));
  privacy.toggle();
  const unknown=await renderToString(createSSRApp({render:()=>h(Summary,{...props,summaries:{}})}));
  assert.ok(!unknown.includes('150,000'));assert.ok(unknown.includes('—'));
  const notices=await renderToString(createSSRApp({render:()=>h(Notices,{reading:7,rows:[{id:7,title:'<script>unsafe</script>',body:'حالة طلب التمويل',time:'2026-10-16T06:00:00Z',page:'wallets',read:false,mine:false,can_open:true,secret:'NEVER-RENDER',entity_id:45},{id:8,title:'رد الدعم',body:'تم تحديث التذكرة',time:'2026-10-15T06:00:00Z',page:'support',read:true,mine:false,can_open:false}]})}));
  assert.ok(notices.includes('&lt;script&gt;unsafe&lt;/script&gt;'));assert.ok(!notices.includes('<script>unsafe'));
  assert.ok(!notices.includes('NEVER-RENDER'));assert.ok(notices.includes('غير مقروء'));assert.ok(notices.includes('مقروء'));assert.ok(notices.includes('المحافظ'));assert.ok(notices.includes('الدعم الفني'));
  assert.equal((notices.match(/ disabled/g)||[]).length,2);
 }finally{await server.close();}
});

test('actual POS wallets hydrate both cached own summaries without extra reads; admin keeps its existing view',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
 try{
  const Page=(await server.ssrLoadModule('/src/modules/finance/WalletsPage.vue')).default;
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  for(const type of ['pos','system']){
   const calls=[],account={id:31,type,name:'حساب المعاينة',status:'active'},permissions=['wallets.view','wallets.request','ledger.view'];
   const options={own_account_id:31,parent_account_id:30,accounts:[account],services:[{id:'voucher',name:'البطاقات'},{id:'topup',name:'Topup'}],currencies:['IQD']};
   const session={state:reactive({identity:{account,membership:{kind:'owner'},permissions}}),can:permission=>permissions.includes(permission),refresh:async()=>null,api:{
    peekRequest(path){if(path==='/finance/options?compact=1')return{data:options};if(path.startsWith('/finance/wallets?service=voucher&'))return{summary:{own_balance:'155000',own_available:'150000',own_held:'5000',balance:'999999'}};if(path.startsWith('/finance/wallets?service=topup&'))return{summary:{own_balance:'100000',own_available:'94000',own_held:'6000'}};},
    request(path){calls.push(path);throw Error('SSR must not add a request');},mutate(){throw Error('Rendering must not write');}}};
   const router=createRouter({history:createMemoryHistory(),routes:[{path:'/',name:'wallets',component:Page}]});
   await router.push('/');const app=createSSRApp({render:()=>h(Page)});app.use(router);app.provide(portalContextKey,{session,portal:{id:type==='pos'?'pos':'admin'},config:{}});
   const html=await renderToString(app);assert.deepEqual(calls,[]);
   if(type==='pos'){for(const amount of ['150,000','155,000','94,000','100,000'])assert.ok(html.includes(amount),amount);assert.ok(!html.includes('999,999'));assert.ok(html.includes('طلب تمويل'));}
   else assert.ok(!html.includes('class="pos-wallet-overview"'));
  }
 }finally{await server.close();}
});
