import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';
import {parse} from '@vue/compiler-sfc';
import {createServer} from 'vite';
import {createSSRApp,h,onServerPrefetch,reactive,ref,computed,defineAsyncComponent} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {routeLocationKey} from 'vue-router';
import {catalogFamilies,visibleCatalogFamilies} from '../src/modules/sales/catalog-families.js';
import {createMutationKey,salePayload,multiply,money,stockUnavailableReason,digitalCatalogProduct,catalogCompanyMatches} from '../src/modules/sales/sales-model.js';

const products=()=>[5000,15000].map((value,index)=>({id:51+index,provider_id:3,provider_name:'الشركة المعيّنة',name:`البطاقة الأصلية ${value}`,kind:'prepaid',currency:'IQD',face_value:`${value}.00`,price:`${value+250}.00`,price_version:4+index,stock_available:8,wallet_available:'200000.00',print_policy:{max_cards:5}}));

test('actual POS sell renders one safe family then explicit denomination, quantity, retail and receipt controls without extra reads',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),page=(await server.ssrLoadModule('/src/modules/sales/SellPage.vue')).default;
  const cached=products(),session={state:reactive({identity:{user:{id:7},account:{id:12,type:'pos'},membership:{kind:'owner'}}}),can:()=>true,api:{peekRequest:path=>path==='/sales/products'?{data:cached,pending_print:null}:path==='/digital/offers?'?{data:[]}:null,request(){throw Error('presentation must not fetch');},mutate(){throw Error('presentation must not issue');}}};
  async function render(prepare=()=>{}){
   const inspected={...page,setup(props,context){const controls=page.setup(props,context);onServerPrefetch(()=>prepare(controls));return controls;}};
   return renderToString(createSSRApp({render:()=>h(inspected)}).provide(portalContextKey,{session,portal:{id:'pos'},config:{}}).provide(routeLocationKey,reactive({name:'sell',query:{}})));
  }
  const browse=await render();
  assert.equal((browse.match(/<article[^>]+class="pos-sale-category/g)||[]).length,1);
  assert.ok(browse.includes('2 فئات للاختيار'));assert.ok(!browse.includes('ابحث عن فئة أو شركة'));assert.ok(!browse.includes('ماذا تريد أن تبيع'));
  const searching=await render(controls=>{controls.searchVisible.value=true;});
  assert.ok(searching.includes('id="pos-sale-search"'));assert.ok(searching.includes('role="search"'));assert.ok(searching.includes('ابحث عن فئة أو شركة'));assert.ok(searching.includes('aria-label="إغلاق البحث ومسح النص"'));
  const filtered=await render(controls=>{controls.query.value='15000';});
  assert.ok(filtered.includes('>15,250<'));assert.ok(!filtered.includes('>5,250<'),'filtered tile cannot advertise another denomination price');
  const picker=await render(controls=>{controls.openFamily(controls.shownFamilies.value[0]);assert.equal(controls.selectedId.value,'','a family never implicitly selects a denomination');});
  assert.ok(picker.includes('البطاقة الأصلية 5000'));assert.ok(picker.includes('البطاقة الأصلية 15000'));
  assert.ok(picker.includes('اختر الفئة أعلاه'));assert.ok(!picker.includes('pos-sale-checkout-dock'));
  const checkout=await render(controls=>{controls.openFamily(controls.shownFamilies.value[0]);controls.choose(controls.familyChoices.value[1]);controls.quantity.value=2;controls.retail.value='16000.00';});
  assert.ok(checkout.includes('عدد البطاقات'));assert.ok(checkout.includes('سعر البيع للزبون'));
  assert.ok(checkout.includes('30,500'));assert.ok(checkout.includes('32,000'));
  assert.ok(checkout.includes('إصدار ومعاينة الوصل'));assert.ok(checkout.includes('تتوفر خيارات الطباعة داخل الوصل'));
  assert.match(checkout,/<form id="pos-voucher-sale-form"[\s>]/);
  assert.match(checkout,/<button type="submit" form="pos-voucher-sale-form" class="btn primary pos-sale-print"/,'the persistent action still uses native form validation and the original submit handler');
  assert.ok(checkout.indexOf('</form>')<checkout.indexOf('class="pos-sale-checkout-dock"'),'the tablet sticky dock is bounded by the full checkout, not the lower form start');
 }finally{await server.close();}
});

async function handlers(){
 const source=await readFile(new URL('../src/modules/sales/SellPage.vue',import.meta.url),'utf8'),script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const calls=[],runtime={session:{api:{}},pos:ref(true),ownId:ref(12),can:()=>true,receipt:ref(null),receiptLoading:ref(false),state:reactive({products:products(),options:{},busy:false,error:'',notice:'',uncertain:false}),key:()=>createMutationKey(()=>'same-attempt'),api:{create:async payload=>{calls.push(payload);return {data:{id:91}};}},refreshProducts:async()=>{},openReceipt:async()=>{},clearReceipt(){},async execute(payload,key,action){if(this.state.busy)return null;this.state.busy=true;try{return (await action({...payload,idempotency_key:key.for(payload)})).data;}finally{this.state.busy=false;}}};
 const searchEvents=[],focus={input:0,header:0,scroll:0},context={usePreferencesTranslator:()=>value=>value,ref,computed,defineAsyncComponent,nextTick:async()=>{},watch(){},onMounted(){},onBeforeUnmount(){},useRoute:()=>({query:{}}),useSalesRuntime:()=>runtime,createDigitalApi:()=>({}),salePayload,multiply,money,stockUnavailableReason,digitalCatalogProduct,catalogCompanyMatches,catalogFamilies,visibleCatalogFamilies,readCategoryFavorites:()=>[],saveCategoryFavorites(){},CustomEvent:class{constructor(type,{detail}){this.type=type;this.detail=detail;}},window:{dispatchEvent:event=>searchEvents.push({type:event.type,expanded:event.detail.expanded})},document:{querySelector:selector=>{assert.equal(selector,'.pos-search-toggle');return {focus(){focus.header++;}};}}};
 runInNewContext(script+'\nglobalThis.controls={families,shownFamilies,familyChoices,openFamily,choose,selectedId,quantity,retail,query,searchVisible,searchInput,toggleSaleSearch,closeSaleSearch,printSelected,backToCatalog,uncertainSale,issuedSale,digitalSelected,saleBackState,saleBack};',context);
 context.controls.searchInput.value={focus(){focus.input++;},scrollIntoView(){focus.scroll++;}};
 return {...context.controls,runtime,calls,searchEvents,focus};
}

test('family selection issues the exact chosen stock ID/version with quantity and retail, and issued work cannot switch in the tablet pane',async()=>{
 const page=await handlers();page.openFamily(page.shownFamilies.value[0]);page.choose(page.familyChoices.value[1]);page.quantity.value=2;page.retail.value='16000.00';
 await page.printSelected();
 assert.equal(page.calls.length,1);assert.deepEqual({...page.calls[0]},{product_id:52,quantity:2,price_version:5,expected_price:'15250.00',retail_price:'16000.00',idempotency_key:'same-attempt'});
 page.openFamily(page.shownFamilies.value[0]);page.choose(page.familyChoices.value[0]);
 assert.equal(page.selectedId.value,52);assert.equal(page.quantity.value,2,'issued receipt controls cannot silently become a new denomination');
 await page.printSelected();assert.equal(page.calls.length,1);
});

test('busy, uncertain and focused digital operations prevent family/denomination switches without altering the original payload',async()=>{
 const page=await handlers();page.openFamily(page.shownFamilies.value[0]);page.choose(page.familyChoices.value[0]);
 const other=page.familyChoices.value[1];
 page.runtime.state.busy=true;page.choose(other);page.openFamily(page.shownFamilies.value[0]);page.backToCatalog();assert.equal(page.selectedId.value,51);
 page.runtime.state.busy=false;page.uncertainSale.value=Object.freeze({product_id:51,quantity:1,price_version:4,expected_price:'5250.00'});
 page.choose(other);page.openFamily(page.shownFamilies.value[0]);page.backToCatalog();assert.equal(page.selectedId.value,51);assert.equal(page.uncertainSale.value.product_id,51);
 page.uncertainSale.value=null;page.digitalSelected.value={id:8,connection_id:9};page.choose(other);page.openFamily(page.shownFamilies.value[0]);assert.equal(page.selectedId.value,51);assert.equal(page.digitalSelected.value.id,8);
 assert.equal(page.calls.length,0);
});

test('header search toggles focus, filters exact products, and closes without clearing the checkout or uncertain issue',async()=>{
 const page=await handlers();page.openFamily(page.shownFamilies.value[0]);page.choose(page.familyChoices.value[1]);page.quantity.value=2;page.retail.value='16000.00';
 await page.toggleSaleSearch();assert.equal(page.searchVisible.value,true);assert.equal(page.focus.input,1);assert.equal(page.focus.scroll,1);
 page.query.value='15000';assert.equal(page.shownFamilies.value.length,1);assert.deepEqual(Array.from(page.shownFamilies.value[0].products,row=>row.id),[52]);
 await page.closeSaleSearch();assert.equal(page.searchVisible.value,false);assert.equal(page.query.value,'');assert.equal(page.focus.header,1);assert.equal(page.selectedId.value,52);assert.equal(page.quantity.value,2);assert.equal(page.retail.value,'16000.00');
 assert.deepEqual(page.searchEvents,[{type:'dananeer:sale-search-state',expanded:true},{type:'dananeer:sale-search-state',expanded:false}]);
 page.uncertainSale.value=Object.freeze({product_id:52,quantity:2,price_version:5,expected_price:'15250.00'});
 await page.toggleSaleSearch();page.query.value='5000';page.openFamily(page.shownFamilies.value[0]);
 assert.equal(page.selectedId.value,52);assert.equal(page.uncertainSale.value.product_id,52);assert.equal(page.searchVisible.value,true,'uncertain family selection remains blocked');
 await page.closeSaleSearch();assert.equal(page.quantity.value,2);assert.equal(page.retail.value,'16000.00');assert.equal(page.calls.length,0,'presentation search cannot issue or print');
});

test('selecting a searched family preserves its exact filtered members and dismisses search without stealing focus',async()=>{
 const page=await handlers();await page.toggleSaleSearch();page.query.value='15000';page.openFamily(page.shownFamilies.value[0]);
 assert.equal(page.searchVisible.value,false);assert.equal(page.query.value,'');assert.equal(page.focus.header,0);assert.equal(page.selectedId.value,52);assert.deepEqual(Array.from(page.familyChoices.value,row=>row.id),[52]);
});

test('phone back closes search then stock detail, preserves uncertain payload and does not trap a server-only pending receipt',async()=>{
 const page=await handlers();page.runtime.state.options.pending_print={id:90};assert.equal(page.saleBackState(),'none');
 page.openFamily(page.shownFamilies.value[0]);page.choose(page.familyChoices.value[0]);page.quantity.value=2;
 await page.toggleSaleSearch();assert.equal(page.saleBackState(),'open');page.saleBack();assert.equal(page.searchVisible.value,false);assert.equal(page.quantity.value,2);
 page.uncertainSale.value=Object.freeze({product_id:51,quantity:2});assert.equal(page.saleBackState(),'blocked');assert.equal(page.calls.length,0);
 page.uncertainSale.value=null;page.issuedSale.value={id:91};assert.equal(page.saleBackState(),'open');page.saleBack();assert.equal(page.selectedId.value,'');assert.equal(page.issuedSale.value,null);assert.equal(page.runtime.state.options.pending_print.id,90);assert.equal(page.calls.length,0);
});
