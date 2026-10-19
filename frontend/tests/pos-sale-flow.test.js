import {topupKind,topupKinds} from '../src/modules/digital/pos-topup-context.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {ref,computed,defineAsyncComponent,reactive} from 'vue';
import {parse} from '@vue/compiler-sfc';
import {createMutationKey,salePayload,multiply,money,stockUnavailableReason} from '../src/modules/sales/sales-model.js';
import {readCategoryFavorites,saveCategoryFavorites,readFavorites,saveFavorites} from '../src/modules/sales/pos-favorites.js';

test('category favorites distinguish services and accounts while corrupted or unavailable storage never breaks the catalog',()=>{
 const data=new Map(),storage={getItem:key=>data.get(key),setItem:(key,value)=>data.set(key,value)};
 saveFavorites(storage,12,['2']);
 saveCategoryFavorites(storage,12,['stock:2','digital:2:4','digital:9:4','stock:2','bad','javascript:1']);
 assert.deepEqual(readCategoryFavorites(storage,12),['stock:2','digital:2:4','digital:9:4']);
 assert.deepEqual(readCategoryFavorites(storage,13),[]);assert.deepEqual(readFavorites(storage,12),['2']);
 data.set('dananeer:favorite-categories:12','{broken');assert.deepEqual(readCategoryFavorites(storage,12),[]);
 data.set('dananeer:favorite-categories:12','{"stock:2":true}');assert.deepEqual(readCategoryFavorites(storage,12),[]);
 const blocked={getItem(){throw Error('blocked');},setItem(){throw Error('blocked');}};
 assert.deepEqual(readCategoryFavorites(blocked,12),[]);assert.doesNotThrow(()=>saveCategoryFavorites(blocked,12,['stock:2']));
});

// Exercise the actual page handlers with a server-confirmed issue and a failed receipt read.
// Mount/SSR rendering is covered separately; no real API, sale, or print is dispatched here.
async function salePage({create,refreshProducts}={}){
 const source=await readFile(new URL('../src/modules/sales/SellPage.vue',import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const creates=[],opens=[],sale={id:99,status:'Print Requested'},product={id:3,price:'100.00',price_version:1,stock_available:5,wallet_available:'500.00',print_policy:{max_cards:5}};
 let nextKey=0;
 const runtime={
  session:{api:{}},state:reactive({products:[product],options:{},busy:false,error:'',notice:''}),receipt:ref(null),receiptLoading:ref(false),pos:ref(true),ownId:ref(12),can:()=>true,
  key:()=>createMutationKey(()=>`issue-key-${++nextKey}`),
  api:{create:async payload=>{creates.push(payload);if(create)await create();return {data:sale};}},
  async execute(payload,key,action){
   if(this.state.busy)return null;
   this.state.busy=true;
   try{const result=await action({...payload,idempotency_key:key.for(payload)});key.clear();return result.data;}
   finally{this.state.busy=false;}
  },
  refreshProducts:refreshProducts||(async()=>{}),
  async openReceipt(value){opens.push(value.id);this.state.error='تعذر تحميل الوصل';this.receipt.value=null;},
  clearReceipt(){},
 };
 const context={usePreferencesTranslator:()=>value=>value,ref,computed,defineAsyncComponent,watch(){},onMounted(){},onBeforeUnmount(){},useRoute:()=>({query:{}}),useSalesRuntime:()=>runtime,createDigitalApi:()=>({}),salePayload,multiply,money,stockUnavailableReason,readCategoryFavorites:()=>[],saveCategoryFavorites(){},digitalCatalogProduct:()=>({}),catalogCompanyMatches:()=>true};
 context.useRouteRefresh=()=>{};context.topupKind=topupKind;context.topupKinds=topupKinds;
 runInNewContext(script+'\nglobalThis.control={printSelected,backToCatalog,choose,selectedId,issuedSale,quantity,retail,catalogProducts};',context);
 context.control.selectedId.value=3;
 return {control:context.control,runtime,product,creates,opens};
}

test('successful card issue followed by a failed receipt read only reopens the same sale, even after the mutation key clears',async()=>{
 const page=await salePage();
 await page.control.printSelected();
 assert.equal(page.creates.length,1);assert.equal(page.creates[0].product_id,3);assert.equal(page.control.issuedSale.value.id,99);
 await page.control.printSelected();await page.control.printSelected();
 assert.equal(page.creates.length,1,'Reopening an issued receipt must never call create again');
 assert.deepEqual(page.opens,[99,99,99]);
 assert.equal(page.runtime.state.error,'تعذر تحميل الوصل');
});

test('a second purchase requires leaving the issued receipt and explicitly choosing a category again',async()=>{
 const page=await salePage();await page.control.printSelected();
 page.control.backToCatalog();page.control.choose(page.product);await page.control.printSelected();
 assert.equal(page.creates.length,2);assert.notEqual(page.creates[0].idempotency_key,page.creates[1].idempotency_key);
});

test('a second click while the first issue is pending cannot dispatch another purchase',async()=>{
 let release;const pending=new Promise(resolve=>release=resolve),page=await salePage({create:()=>pending});
 const first=page.control.printSelected();
 await page.control.printSelected();assert.equal(page.creates.length,1);
 release();await first;assert.equal(page.creates.length,1);assert.deepEqual(page.opens,[99]);
});

test('a confirmed card issue opens its original receipt while the stock refresh is still pending',async()=>{
 let finishRefresh,refreshCompleted=false;
 const page=await salePage({refreshProducts:()=>new Promise(resolve=>{finishRefresh=()=>{refreshCompleted=true;resolve();};})});
 try{
  await page.control.printSelected();
  assert.equal(refreshCompleted,false,'An unrelated catalog GET must not delay the issued receipt');
  assert.deepEqual(page.opens,[99]);assert.equal(page.creates.length,1);
  await page.control.printSelected();assert.equal(page.creates.length,1);assert.deepEqual(page.opens,[99,99]);
 }finally{finishRefresh?.();}
});

test('assigned vouchers with a rejected price stay visible with a reason and cannot start a purchase',async()=>{
 const page=await salePage();page.control.backToCatalog();
 const blocked={...page.product,id:4,name:'15,000',price:null,price_version:null,availability_reason:'price_below_minimum',stock_available:84};
 page.runtime.state.products.push(blocked);
 assert.equal(page.control.catalogProducts.value.length,2);
 const item=page.control.catalogProducts.value.find(row=>row.id===4);
 assert.equal(stockUnavailableReason(item),'سعر الوكيل أقل من الحد الأدنى للفئة');
 page.control.choose(item);await page.control.printSelected();
 assert.equal(page.control.selectedId.value,'');assert.equal(page.creates.length,0);
 assert.equal(stockUnavailableReason({...blocked,availability_reason:'price_unset'}),'يجب اعتماد سعر الفئة من الوكيل');
 assert.equal(stockUnavailableReason({...page.product,stock_available:0}),'الكمية نافدة');
 page.control.choose({...page.product,wallet_available:'0.00'});await page.control.printSelected();
 assert.equal(page.creates.length,0);assert.equal(page.runtime.state.error,'رصيدك غير كافٍ');
 assert.equal(stockUnavailableReason({digital_offer:{},price:null}),'');
});
