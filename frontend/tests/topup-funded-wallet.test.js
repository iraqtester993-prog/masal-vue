import {topupKind,topupKinds} from '../src/modules/digital/pos-topup-context.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {fileURLToPath} from 'node:url';
import {compileScript,parse} from '@vue/compiler-sfc';
import * as Vue from 'vue';
import {computed,createSSRApp,effectScope,h,nextTick,reactive,ref,watch} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createServer} from 'vite';
import * as moneyModel from '../src/modules/finance/finance-model.js';
import * as digitalModel from '../src/modules/digital/digital-model.js';
import {topupUnavailableReason,topupWalletMetrics} from '../src/modules/finance/topup-funding.js';
import {digitalCatalogProduct,stockUnavailableReason,multiply,salePayload as stockSalePayload,catalogCompanyMatches} from '../src/modules/sales/sales-model.js';
import {createDigitalApi} from '../src/modules/digital/digital-api.js';
import {preferencesContextKey,usePreferencesTranslator} from '../src/modules/preferences/preferences-state.js';
import {prepareLanguage,translate} from '../src/modules/preferences/translate.js';

const offer=(extra={})=>({id:9,connection_id:2,product_id:4,provider:'topup',name:'6000',retail:'6500.00',version:3,wallet_available:'10000.00',funding_required:true,purchasable:true,availability_reason:'available',gateway:{configured:true,purchases_enabled:true},...extra});
async function handlers(file,context,names){
 context.usePreferencesTranslator??=()=>value=>value;
 context.onBeforeUnmount??=()=>{};context.registerPosBackLayer??=()=>()=>{};
 context.useRouteRefresh??=()=>{};context.topupKind??=topupKind;context.topupKinds??=topupKinds;
 const source=await readFile(new URL(`../src/modules/${file}.vue`,import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 runInNewContext(script+`\nglobalThis.controls={${names.join(',')}};`,context);
 return context.controls;
}

test('assigned Topup categories remain catalog rows at zero/low budget and fail closed for unknown funding metadata',()=>{
 for(const [extra,reason] of [[{wallet_available:'0.00',purchasable:false,availability_reason:'funding_required'},/غير ممول/],[{wallet_available:'6499.99',purchasable:false,availability_reason:'insufficient_balance'},/غير كاف/],[{wallet_available:'0.00',purchasable:false,availability_reason:'insufficient_balance'},/غير كاف/],[{wallet_available:null},/تعذر التحقق/],[{purchasable:undefined},/تعذر التحقق/],[{wallet_available:'invalid'},/تعذر التحقق/]]){
  const row=digitalCatalogProduct(offer(extra));assert.equal(row.id,4);assert.equal(row.name,'6000');assert.match(stockUnavailableReason(row),reason);
 }
 assert.equal(topupUnavailableReason(offer({wallet_available:'6500.00'})),'');
 assert.equal(stockUnavailableReason(digitalCatalogProduct({...offer(),provider:'rabiaa',wallet_available:null,purchasable:undefined})), '');
});

test('wallet metrics use server scoped spendable funds, never grant or accounting margin, and omit group money for POS',()=>{
 const summary={own_available:'90000.00',own_held:'0.00',available:'93500.00',held:'0.00'};
 const topup={allocation_balance:'94000.00',allocation_held:'0.00',funded_points_balance:'3500.00',funded_points_held:'0.00',agent_margin_accounting:'500.00'};
 const main=topupWalletMetrics(summary,topup,{own:true,group:true,costs:true,profits:true});
 assert.equal(main.find(row=>row.id==='available').value,'90000.00');
 assert.equal(main.find(row=>row.id==='accounting-margin').value,'500.00');
 const pos=topupWalletMetrics({own_available:'3500.00',own_held:'0.00'},topup,{own:true,group:false});
 assert.deepEqual(pos.map(row=>row.id),['available','held']);assert.equal(pos[0].value,'3500.00');
 assert.deepEqual(topupWalletMetrics(null,null,{own:true}).map(row=>row.value),[undefined,undefined]);
});

test('digital offers fresh validation bypasses cached reads without changing route or mutating',async()=>{
 const calls=[],api=createDigitalApi({request:async(path,options)=>{calls.push({path,options});return {data:[]};},mutate:()=>assert.fail('read cannot mutate')});
 await api.offers(undefined,{fresh:true});assert.equal(calls[0].path,'/digital/offers?');assert.equal(calls[0].options.fresh,true);
});

async function digitalSale({fresh=offer(),readError,createError}={}){
 const scope=effectScope(),creates=[],reads=[],props={focusedOffer:offer(),deviceSession:false,vm:{identity:ref({account:{id:12,type:'pos',name:'نقطة'}}),state:reactive({connections:[],offers:[],busy:false,error:''}),receipt:ref(null),can:()=>false,failure:async error=>error.message,api:{recovery:async()=>({data:{order:null}}),offers:async(...args)=>{reads.push(args);if(readError)throw readError;return {data:fresh?[fresh]:[]};},create:async payload=>{creates.push(payload);if(createError)throw createError;return {data:{id:71,account_id:12,status:'succeeded'}};}}}};
 let control;
 await scope.run(async()=>{control=await handlers('digital/DigitalSell',{...digitalModel,computed,reactive,ref,watch,onMounted(){},defineProps:()=>props,defineEmits:()=>()=>{},topupUnavailableReason,crypto:{randomUUID:()=> 'request-fixed'}},['form','confirmation','pending','recover','preview','submit','quoteLoading','selected']);});
 await nextTick();await control.recover();Object.assign(control.form,{mobile:'07701234567',confirm_mobile:'07701234567'});
 return {control,props,creates,reads,stop:()=>scope.stop()};
}

test('Topup confirmation reads fresh funded wallet/price and only the explicit confirmation dispatches one purchase',async()=>{
 const page=await digitalSale({fresh:offer({retail:'7500.00',version:4,wallet_available:'7500.00'})});
 try{await page.control.preview();assert.equal(page.reads.length,1);assert.equal(page.reads[0][1].fresh,true);assert.equal(page.creates.length,0);assert.equal(page.control.confirmation.value.expected_retail,'7500.00');assert.equal(page.control.confirmation.value.offer_version,4);await page.control.submit();await page.control.submit();assert.equal(page.creates.length,1);assert.equal(page.creates[0].request_id,'request-fixed');assert.equal(page.creates[0].expected_retail,'7500.00');assert.ok(!Object.hasOwn(page.creates[0],'admin_price'));}finally{page.stop();}
});

test('fresh zero/insufficient/removed/unknown offers and network failure cannot reach create, even through submit handler',async()=>{
 for(const options of [{fresh:offer({wallet_available:'0.00',purchasable:false,availability_reason:'funding_required'})},{fresh:offer({wallet_available:'6499.99',purchasable:false,availability_reason:'insufficient_balance'})},{fresh:null},{fresh:offer({wallet_available:null})},{readError:Error('انقطع الاتصال')}]){
  const page=await digitalSale(options);try{await page.control.preview();await page.control.submit();assert.equal(page.control.confirmation.value,null);assert.equal(page.creates.length,0);assert.ok(page.props.vm.state.error);}finally{page.stop();}
 }
});

async function funding({available='2500.00',freshFailed=false,executeSuccess=true,admin=false}={}){
 const scope=effectScope(),writes=[],reads=[],resources=()=>({state:reactive({rows:[],meta:{current_page:1,last_page:1}}),load:async()=>{},dispose(){}});
 const vm={service:ref('topup'),currency:ref('IQD'),filters:reactive({}),ownId:ref(2),admin:ref(admin),ownVisible:ref(true),hasChildren:ref(1),children:ref([{id:3,parent_id:2,type:'pos'}]),identity:ref({account:{type:admin?'system':'main_agent'}}),options:reactive({parent_account_id:1,parent_account_type:'system',accounts:[{id:3,parent_id:2,type:'pos'}],services:[]}),can:()=>true,busy:ref(false),error:ref(''),key:()=>moneyModel.createMutationKey(()=> 'funding-stable'),resource:resources,walletAmount:()=>available,emptyScope:()=>false,recordParameters:extra=>extra,refresh:async()=>{},loadWallets:async(...args)=>{reads.push(args);return freshFailed?false:[{account_id:2,service:'topup',currency:'IQD',available}];},execute:async(payload,key)=>{writes.push({...payload,idempotency_key:key.for(payload)});return executeSuccess?{data:{id:1}}:null;},api:{create:()=>assert.fail('execute stub owns writes')}};
 let control;await scope.run(async()=>{control=await handlers('finance/SimpleWallets',{...moneyModel,computed,reactive,ref,watch,inject:()=>vm,defineProps:()=>({}),onMounted(){}},['value','to','reference','direct','directConfirm','requestFunding','decide','tabs','funderName']);});
 control.value.value='1000';control.to.value='3';control.reference.value='QA-ref';await nextTick();
 return {vm,control,writes,reads,stop:()=>scope.stop()};
}

test('direct Topup funding previews both wallets fresh, then reuses the exact payload/key after an uncertain transfer',async()=>{
 const page=await funding({executeSuccess:false});try{await page.control.direct();assert.equal(page.control.directConfirm.value,true);assert.equal(page.writes.length,0);assert.deepEqual(Array.from(page.reads[0][1]),[2,3]);assert.equal(page.reads[0][2].fresh,true);await page.control.direct();await page.control.direct();assert.equal(page.reads.length,1);assert.equal(page.writes.length,2);assert.deepEqual(page.writes[0],page.writes[1]);assert.equal(page.writes[0].service,'topup');assert.equal(page.writes[0].amount,'1000.00');}finally{page.stop();}
});

test('direct Topup funding requires known own available funds and admin deposit/allocation shortcuts never write',async()=>{
 for(const options of [{available:'0.00'},{freshFailed:true},{admin:true}]){const page=await funding(options);try{await page.control.direct();assert.equal(page.control.directConfirm.value,false);assert.equal(page.writes.length,0);assert.ok(page.vm.error.value);}finally{page.stop();}}
 const page=await funding({admin:true});try{assert.ok(!page.control.tabs.value.some(row=>row.id==='deposit'));await page.control.requestFunding();await page.control.decide({id:17,service:'topup'},'approve');assert.equal(page.writes.length,0);assert.match(page.vm.error.value,/تخصيص الإدارة/);}finally{page.stop();}
});

test('actual funded Topup wallet cards render scoped values and never expose group margin to a POS',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),{financeKey}=await server.ssrLoadModule('/src/modules/finance/finance-model.js'),{preferencesContextKey}=await server.ssrLoadModule('/src/modules/preferences/preferences-state.js'),{prepareLanguage,translate}=await server.ssrLoadModule('/src/modules/preferences/translate.js'),component=(await server.ssrLoadModule('/src/modules/finance/TopupWallet.vue')).default;
  for(const language of ['ar','en','ckb']){
  await prepareLanguage(language);
  for(const [type,narrowed,profitOnly=false] of [['main_agent',false],['pos',false],['main_agent',true],['main_agent',false,true]]){
   const session={state:reactive({identity:{account:{id:12,type},membership:{kind:'owner'}}}),can:key=>key==='wallets.view'||type==='main_agent'&&!narrowed&&(['data.cost','data.profit'].includes(key))&&(!profitOnly||key==='data.profit'),api:{request:()=>assert.fail('SSR must not read'),mutate:()=>assert.fail('render cannot write')},refresh:async()=>null};
   const vm={identity:ref(session.state.identity),admin:ref(false),ownVisible:ref(!narrowed),filtersActive:ref(false),can:session.can,filters:{},options:reactive({wallet_summary:{available:'3500.00',held:'0.00',own_available:narrowed?'0.00':'3500.00',own_held:'0.00'},topup_summary:narrowed?null:{allocation_balance:'94000.00',funded_points_balance:'3500.00',agent_margin_accounting:'500.00'}})};
   const app=createSSRApp({render:()=>h(component)});app.provide(portalContextKey,{session,portal:{id:type==='pos'?'pos':'agents'},config:{}});app.provide(financeKey,vm);
   app.provide(preferencesContextKey,{state:reactive({language}),t:value=>translate(value,language)});
   const html=await renderToString(app);assert.ok(html.includes(translate('رصيد Topup الممول',language)));assert.ok(html.includes('3,500'));assert.equal(html.includes(translate('ربح الوكيل المحاسبي · غير قابل للصرف',language)),type==='main_agent'&&!narrowed&&!profitOnly);assert.equal(html.includes('94,000'),type==='main_agent'&&!narrowed&&!profitOnly);if(narrowed){assert.ok(html.includes(translate('الرصيد الممول المتاح للحسابات المعروضة',language)));assert.ok(!html.includes(translate('رصيدي المتاح في Topup',language)));}else assert.ok(html.includes(translate('رصيدي المتاح في Topup',language)));assert.ok(!html.includes('رصيد الإدارة'));assert.ok(!html.includes('حصة الوكيل'));
  }
  }
 }finally{await server.close();}
});


// Compile the real client template and mount with an in-memory Vue host. This
// verifies disabled controls/reactive text without a browser, API, or printer.
test('actual Topup sale controls show own funded money and keep unavailable offers visible without an enabled purchase',async()=>{
 const source=await readFile(new URL('../src/modules/digital/DigitalSell.vue',import.meta.url),'utf8');
 let compiled=compileScript(parse(source).descriptor,{id:'funded-topup-controls',inlineTemplate:true}).content;
 compiled=compiled.replace(/^import\s+{([^}]+)}\s+from\s+['"]vue['"];?/gm,(_,names)=>`const {${names.replace(/\s+as\s+/g,':')}}=Vue;`).replace(/^import .*;?\r?$/gm,'').replace('export default','globalThis.Component=');
 const context={...digitalModel,Vue,topupUnavailableReason,usePreferencesTranslator,registerPosBackLayer:()=>()=>{},DeviceSession:{render:()=>null}};runInNewContext(compiled,context);
 function node(tag,text=''){return {tag,tagName:String(tag).toUpperCase(),text,props:{},children:[],parent:null,events:{},addEventListener(name,handler){this.events[name]=handler;},removeEventListener(name){delete this.events[name];},setAttribute(name,value){this.props[name]=value;},removeAttribute(name){delete this.props[name];}};}
 const host=Vue.createRenderer({createElement:tag=>node(tag),createText:text=>node('#text',text),createComment:text=>node('#comment',text),setText:(el,text)=>el.text=text,setElementText:(el,text)=>{el.text=text;el.children=[];},parentNode:el=>el.parent,nextSibling:el=>{const list=el.parent?.children||[];return list[list.indexOf(el)+1]||null;},patchProp:(el,key,prev,next)=>{el.props[key]=next;el[key]=next;},insert:(el,parent,anchor=null)=>{if(el.parent){const old=el.parent.children;old.splice(old.indexOf(el),1);}el.parent=parent;const i=anchor?parent.children.indexOf(anchor):-1;if(i<0)parent.children.push(el);else parent.children.splice(i,0,el);},remove:el=>{if(el.parent){const list=el.parent.children;list.splice(list.indexOf(el),1);el.parent=null;}}});
 const focused=ref(offer({name:'رصيدي المتاح',wallet_available:'0.00',purchasable:false,availability_reason:'funding_required'})),writes=[];
 const vm={identity:ref({account:{id:12,type:'pos',name:'نقطة الفحص'}}),receipt:ref(null),state:reactive({connections:[],offers:[],busy:false,error:''}),can:()=>false,failure:async error=>error.message,api:{recovery:async()=>({data:{order:null}}),create:()=>writes.push('sale')}};
 const language=ref('ar'),root=node('root'),app=host.createApp({render:()=>h(context.Component,{vm,focusedOffer:focused.value,deviceSession:false})});app.provide(preferencesContextKey,{state:reactive({language}),t:value=>translate(value,language.value)});app.mount(root);
 const all=el=>[el,...el.children.flatMap(all)],text=el=>el.text+el.children.map(text).join('');
 try{
  await new Promise(resolve=>setImmediate(resolve));await nextTick();
  let controls=all(root),review=controls.find(el=>el.tag==='button'&&text(el).includes('مراجعة العملية'));
  assert.ok(controls.some(el=>el.props['data-topup-wallet']===''),text(root));assert.ok(text(root).includes('رصيدي المتاح'));assert.ok(text(root).includes('غير ممول'));assert.equal(review.props.disabled,true);assert.ok(!text(root).includes('سعر المدير'));assert.ok(!text(root).includes('ربح الوكيل'));
  for(const nextLanguage of ['en','ckb']){await prepareLanguage(nextLanguage);language.value=nextLanguage;await nextTick();assert.ok(text(root).includes(translate('رصيد Topup غير ممول؛ اطلب التمويل من الوكيل.',nextLanguage)));assert.ok(text(root).includes('رصيدي المتاح'),'The category name is business data, not an interface label');assert.ok(!text(root).includes(translate('رصيدي المتاح',nextLanguage)));assert.equal(all(root).find(el=>el.tag==='button'&&text(el).includes('مراجعة العملية')).props.disabled,true);}
  focused.value=offer({wallet_available:'6500.00'});await nextTick();review=all(root).find(el=>el.tag==='button'&&text(el).includes('مراجعة العملية'));assert.equal(review.props.disabled,false);assert.ok(text(root).includes('6,500'));assert.deepEqual(writes,[]);
 }finally{app.unmount();}
});


test('an edited direct-funding form cannot use an in-flight balance preview for the old amount or recipient',async()=>{
 const page=await funding();let release,reads=0;page.vm.loadWallets=()=>{reads++;return new Promise(resolve=>release=resolve);};
 try{const first=page.control.direct();await page.control.direct();assert.equal(reads,1);page.control.value.value='2000';await nextTick();release([{account_id:2,service:'topup',currency:'IQD',available:'2500.00'}]);await first;assert.equal(page.control.directConfirm.value,false);assert.equal(page.writes.length,0);}finally{page.stop();}
});

test('Topup invoice view retains collection while hiding manual invoice creation and rejecting its handler',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
 try{
  const {financeKey}=await server.ssrLoadModule('/src/modules/finance/finance-model.js'),component=(await server.ssrLoadModule('/src/modules/finance/WalletInvoices.vue')).default;
  const writes=[],vm={service:ref('topup'),currency:ref('IQD'),filters:reactive({}),accounts:ref([]),admin:ref(false),ownId:ref(2),busy:ref(false),error:ref(''),can:()=>true,options:{services:[{id:'cash',name:'نقد'},{id:'topup',name:'Topup'}]},key:()=>({}),name:()=> 'نقطة',currencyLabel:()=> 'د.ع',serviceName:()=> 'Topup',resource:()=>({state:{rows:[{id:8,account_id:3,creditor_account_id:2,kind:'receivable',service:'topup',source_type:'digital_topup',status:'unpaid',remaining:'6500.00',amount:'6500.00',currency:'IQD'}],loading:false,meta:{current_page:1,last_page:1,total:1}}}),execute:async()=>writes.push('invoice')};
  const app=createSSRApp({render:()=>h(component)});app.provide(financeKey,vm);const html=await renderToString(app);
  assert.ok(html.includes('6,500'));assert.ok(html.includes('تسجيل تحصيل'));assert.ok(!html.includes('>تسجيل فاتورة</button>'));assert.deepEqual(writes,[]);
  const scope=effectScope();let control;await scope.run(async()=>{control=await handlers('finance/WalletInvoices',{...moneyModel,computed,reactive,ref,watch,onMounted(){},inject:()=>vm,usePreferencesTranslator:()=> value=>value},['draft','create','openCreate','createOpen']);});
  try{control.openCreate();assert.equal(control.createOpen.value,false);control.draft.service='topup';await control.create();assert.match(vm.error.value,/عمليات البيع/);assert.deepEqual(writes,[]);}finally{scope.stop();}
 }finally{await server.close();}
});


test('Topup bulk funding uses fresh main wallet only, restricts point recipients, and cannot treat a grant as cash',async()=>{
 for(const [sourceType,targetType,available,allowed] of [['main_agent','pos','2500.00',true],['main_agent','pos','0.00',false],['sub_agent','pos','2500.00',false],['main_agent','sub_agent','2500.00',false]]){
  const scope=effectScope(),writes=[],reads=[],target={id:3,type:targetType,parent_id:2,status:'active'},vm={admin:ref(false),ownId:ref(2),balanceGeneration:ref(0),service:ref('topup'),currency:ref('IQD'),busy:ref(false),error:ref(''),balancesError:ref(''),options:reactive({accounts:[{id:2,type:sourceType},target],services:[{id:'topup'}],currencies:['IQD'],topup_summary:{allocation_balance:'100000.00'}}),key:()=>moneyModel.createMutationKey(()=> 'bulk-fixed'),resource:()=>({state:reactive({rows:[],meta:{}}),load:async()=>{},dispose(){}}),loadWallets:async(...args)=>{reads.push(args);return [{account_id:2,service:'topup',currency:'IQD',available}];},walletAmount:()=>available,api:{list:async()=>({data:[target]})},rememberAccounts(){},refresh:async()=>{},execute:async payload=>{writes.push(payload);return {data:[]};}};
  let control;await scope.run(async()=>{control=await handlers('finance/WalletBulk',{...moneyModel,computed,reactive,ref,watch,onMounted(){},onBeforeUnmount(){},inject:()=>vm},['selected','drafts','reference','prepare','preview','confirm']);});
  try{control.selected.value=[3];control.drafts[3]='1000';control.reference.value='bulk-ref';await nextTick();await control.prepare();assert.equal(reads[0][2].fresh,true);assert.equal(!!control.preview.value?.valid,allowed);await control.confirm();assert.equal(writes.length,allowed?1:0);if(allowed){assert.equal(writes[0].service,'topup');assert.equal(writes[0].rows[0].amount,'1000.00');}else if(available!=='0.00')assert.match(vm.error.value,/نقطة بيع/);}finally{scope.stop();}
 }
});


test('a POS requests Topup from its server-resolved main ancestor while ordinary funding still names its immediate parent',async()=>{
 const page=await funding({executeSuccess:false});try{page.vm.identity.value.account.type='pos';Object.assign(page.vm.options,{topup_funder_account_id:77,topup_funder_account_name:'الرئيسي الممول',parent_account_type:'sub_branch',parent_account_name:'الفرع المباشر'});assert.ok(page.control.tabs.value.some(row=>row.id==='request'));assert.equal(page.control.funderName.value,'الرئيسي الممول');await page.control.requestFunding();assert.equal(page.writes.length,1);assert.equal(page.writes[0].service,'topup');assert.ok(!Object.hasOwn(page.writes[0],'from_account_id'),'server resolves the main, not a client-supplied override');page.vm.service.value='voucher';await nextTick();assert.equal(page.control.funderName.value,'الفرع المباشر');}finally{page.stop();}
});

test('group wallet visibility does not imply administrator-cost or profit permission',()=>{
 const rows=topupWalletMetrics({own_available:'90000.00',own_held:'0.00'},{allocation_balance:'94000.00',funded_points_balance:'3500.00',funded_points_held:'0.00',agent_margin_accounting:'500.00'},{own:true,group:true,costs:false,profits:false});
 assert.ok(rows.some(row=>row.id==='funded-points'));assert.ok(!rows.some(row=>row.id==='allocation'));assert.ok(!rows.some(row=>row.id==='accounting-margin'));
});


test('digital-only POS loads and selects funded Topup without any wallet, dashboard, or stock-sale permission or finance request',async()=>{
 const allowed=new Set(['account.view','digital.view','digital.create']),reads=[];
 const vm={identity:ref({account:{id:12,type:'pos'}}),ownId:ref(12),pos:ref(true),seller:ref(true),state:reactive({products:[],options:{},busy:false,error:'',notice:''}),receipt:ref(null),receiptLoading:ref(false),can:key=>allowed.has(key),session:{api:{request:path=>assert.fail(`Unexpected general API ${path}`)}},api:{products:()=>assert.fail('digital-only account must not request stock products')},key:()=>moneyModel.createMutationKey(()=> 'unused'),failure:async error=>error.message};
 const control=await handlers('sales/SellPage',{ref,computed,defineAsyncComponent:Vue.defineAsyncComponent,watch(){},onMounted(){},onBeforeUnmount(){},AbortController,useRoute:()=>({query:{}}),useSalesRuntime:()=>vm,createDigitalApi:()=>({offers:async()=>{reads.push('/digital/offers');return {data:[offer()]};}}),digitalCatalogProduct,stockUnavailableReason,multiply,salePayload:stockSalePayload,catalogCompanyMatches},['refreshCatalog','catalogProducts','choose','digitalSelected']);
 await control.refreshCatalog();assert.deepEqual(reads,['/digital/offers']);assert.equal(control.catalogProducts.value.length,1);control.choose(control.catalogProducts.value[0]);assert.equal(control.digitalSelected.value.id,9);assert.equal(vm.state.error,'');assert.equal(vm.can('wallets.view'),false);assert.equal(vm.can('dashboard.view'),false);assert.equal(vm.can('sell.create'),false);
});


test('the funded Topup ledger labels every new posting kind instead of displaying raw English actions',()=>{
 for(const key of ['topup-opening','topup-allocation','topup-sale','topup-refund']){assert.ok(moneyModel.movementKinds[key]);assert.match(moneyModel.movementKinds[key],/[\u0600-\u06ff]/);assert.notEqual(moneyModel.movementKinds[key],key);}
});
