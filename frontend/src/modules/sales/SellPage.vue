<script setup>
import {computed,defineAsyncComponent,nextTick,onMounted,onBeforeUnmount,ref,watch} from 'vue';
import {useRoute} from 'vue-router';
import {systemLabel} from '../preferences/system-labels.js';
import {usePreferencesTranslator} from '../preferences/preferences-state.js';
import {readCategoryFavorites,saveCategoryFavorites} from './pos-favorites.js';
import {catalogFamilies,visibleCatalogFamilies} from './catalog-families.js';
import CatalogArtwork from './CatalogArtwork.vue';
import {useSalesRuntime} from './sales-runtime.js';
import {money,multiply,salePayload,digitalCatalogProduct,catalogCompanyMatches,stockUnavailableReason} from './sales-model.js';
import DeviceSession from './DeviceSession.vue';
import {createDigitalApi} from '../digital/digital-api.js';
import PrintPolicySummary from './PrintPolicySummary.vue';
import ReceiptDialog from './ReceiptDialog.vue';
import ReprintDialog from './ReprintDialog.vue';
import '../catalog/catalog.css';
import './sales.css';
import './pos-sale.css';
import {registerPosBackLayer} from '../../shared/pos-back-navigation.js';
import {useRouteRefresh} from '../../shared/route-refresh.js';
import {topupKinds,topupKind} from '../digital/pos-topup-context.js';
const DigitalPage=defineAsyncComponent(()=>import('../digital/DigitalPage.vue'));

const vm=useSalesRuntime(),catalogViewScope=vm.session.api.viewStateScope?.(),savedCatalog=vm.session.api.peekViewState?.('pos-sell-catalog');
const selectedId=ref(''),quantity=ref(1),retail=ref(''),company=ref(savedCatalog?.company||'all'),query=ref(savedCatalog?.query||''),kind=ref(['all','topup','favorites'].includes(savedCatalog?.kind)?savedCatalog.kind:'all'),reviewed=ref(false),reprint=ref(null),uncertainSale=ref(null);
const uiLabel=usePreferencesTranslator();
const digitalApi=createDigitalApi(vm.session.api),digitalOffers=ref(vm.pos.value&&vm.can('digital.view')?digitalApi.peekOffers?.()?.data??[]:[]),digitalSelected=ref(null);
const catalogLoading=ref(false),favorites=ref([]),productLimit=ref(24),brokenImages=ref(new Set()),issuedSale=ref(null);
const familyKey=ref(''),familyMembers=ref([]),searchInput=ref(null),searchVisible=ref(!!savedCatalog?.searchVisible);
const topupType=ref(topupKind(savedCatalog?.topupType));
function notifySaleSearch(){if(typeof window!=='undefined')window.dispatchEvent(new CustomEvent('dananeer:sale-search-state',{detail:{expanded:searchVisible.value}}));}
async function setSaleSearch(open,{restoreFocus=true}={}){
 if(searchVisible.value===open){if(!open)query.value='';return;}
 searchVisible.value=open;if(!open)query.value='';notifySaleSearch();
 await nextTick();
 if(open&&searchVisible.value){searchInput.value?.focus();searchInput.value?.scrollIntoView?.({block:'nearest'});}
 else if(!open&&!searchVisible.value&&restoreFocus&&typeof document!=='undefined')document.querySelector('.pos-search-toggle')?.focus();
}
function toggleSaleSearch(){return setSaleSearch(!searchVisible.value);}
function closeSaleSearch(){return setSaleSearch(false);}
let catalogReader,catalogRevision=0,catalogTimer,releaseBack;
const deviceVm={...vm,api:vm.can('sell.create')?vm.api:{heartbeat:digitalApi.heartbeat}};
const route=useRoute();
const key=vm.key(),currency=computed(()=>'د.ع');
const catalogProducts=computed(()=>[
 ...vm.state.products.filter(row=>vm.can('sell.create')&&!digitalOffers.value.some(offer=>Number(offer.product_id)===Number(row.id))).map(row=>({...row,key:`stock:${row.id}`,catalog_kind:row.kind,kind:'card'})),
 ...digitalOffers.value.map(offer=>{
  const product=digitalCatalogProduct(offer),local=vm.state.products.find(row=>Number(row.id)===Number(offer.product_id)),provider=vm.state.products.find(row=>String(row.provider_id)===String(product.provider_id));
  return {...product,image_url:offer.image_url||local?.image_url||null,provider_image_url:offer.provider_image_url||local?.provider_image_url||provider?.provider_image_url||null};
 })
]);
const selected=computed(()=>vm.state.products.find(product=>Number(product.id)===Number(selectedId.value)));
function matchesService(product){return (kind.value==='all'||kind.value==='favorites'||product.kind===kind.value)&&(kind.value!=='topup'||!topupType.value||product.digital_offer?.type===topupType.value);}
const topupTabs=computed(()=>topupKinds.filter(item=>!item.id||catalogProducts.value.some(row=>row.kind==='topup'&&row.digital_offer?.type===item.id)));
function isFavorite(product){return favorites.value.includes(product.key);}
function toggleFavorite(product){favorites.value=isFavorite(product)?favorites.value.filter(value=>value!==product.key):[...favorites.value,product.key];}
const companies=computed(()=>{
 const map=new Map();
 for(const product of catalogProducts.value.filter(row=>matchesService(row)&&(kind.value!=='favorites'||isFavorite(row)))){
  const id=String(product.provider_id);let provider=map.get(id);
  if(!provider){provider={id,name:product.provider_name,count:0,image:product.provider_image_url||null};map.set(id,provider);}
  if(!provider.image&&product.provider_image_url)provider.image=product.provider_image_url;
  provider.count++;
 }
 return [...map.values()];
});
const shown=computed(()=>{
 const search=query.value.trim().toLocaleLowerCase('ar');
 return catalogProducts.value.filter(product=>catalogCompanyMatches(product,company.value)&&matchesService(product)&&(kind.value!=='favorites'||isFavorite(product))&&[product.name,product.provider_name].some(value=>String(value||'').toLocaleLowerCase('ar').includes(search)));
});
const selectedCompany=computed(()=>companies.value.find(row=>row.id===company.value));
const families=computed(()=>catalogFamilies(catalogProducts.value));
const shownFamilies=computed(()=>visibleCatalogFamilies(families.value,shown.value));
const selectedFamily=computed(()=>families.value.find(row=>row.key===familyKey.value));
const familyChoices=computed(()=>selectedFamily.value?.products.filter(row=>familyMembers.value.includes(row.key))||[]);
const choiceLocked=computed(()=>vm.state.busy||!!uncertainSale.value||!!issuedSale.value||!!digitalSelected.value);
const detailOpen=computed(()=>!!selectedFamily.value||!!selected.value||!!digitalSelected.value);
const total=computed(()=>multiply(uncertainSale.value?.expected_price||selected.value?.price,quantity.value));
const retailTotal=computed(()=>{try{return multiply(retail.value||uncertainSale.value?.expected_price||selected.value?.price,quantity.value);}catch{return null;}});
const saleDisabled=computed(()=>vm.state.busy||vm.receiptLoading.value||!vm.can('data.pin')||!vm.can('sell.receipt')||!issuedSale.value&&(!selected.value||selected.value.price===null||!vm.can('sell.create')||!!vm.state.options.pending_print&&!uncertainSale.value));
function imageUrl(url){return url&&!brokenImages.value.has(url)?url:null;}
function imageFailed(url){brokenImages.value=new Set([...brokenImages.value,url]);}
function serviceName(product){return product.kind==='topup'?'Topup':product.kind==='rabiaa'?'الرابعة':['bundle','bill','topup'].includes(product.catalog_kind)?'بطاقات الطباعة · '+systemLabel(product.catalog_kind,'productKind'):'بطاقات الطباعة';}
function currencyName(product){return product?.currency==='IQD'?'د.ع':product?.currency||'—';}
function familyUnavailable(family){return family.products.every(row=>!!stockUnavailableReason(row));}
function openFamily(family){
 if(choiceLocked.value)return;
 familyKey.value=family.key;familyMembers.value=family.products.map(row=>row.key);
 selectedId.value='';reviewed.value=false;vm.state.error='';
 if(family.products.length===1)choose(family.products[0]);
 void setSaleSearch(false,{restoreFocus:false});
}
function choose(product){
 if(vm.state.busy||uncertainSale.value||issuedSale.value||digitalSelected.value)return;
 if(product.digital_offer&&!vm.can('digital.create'))return;
 if(stockUnavailableReason(product)){vm.state.error=stockUnavailableReason(product);return;}
 issuedSale.value=null;
 vm.state.error='';
 if(product.digital_offer){digitalSelected.value=product.digital_offer;selectedId.value='';return;}
 digitalSelected.value=null;selectedId.value=product.id;quantity.value=1;retail.value='';reviewed.value=false;
}
function review(){try{salePayload(selected.value,quantity.value,retail.value);reviewed.value=true;vm.state.error='';return true;}catch(error){vm.state.error=error.message;return false;}}
async function printSelected(){if(saleDisabled.value)return;if(issuedSale.value||uncertainSale.value||review())await sell();}
async function sell(){
 // Once the server issues cards, a failed receipt read must never trigger a second purchase.
 if(issuedSale.value&&!uncertainSale.value){await vm.openReceipt(issuedSale.value);return;}
 if(vm.state.options.pending_print&&!uncertainSale.value){vm.state.error=`افتح محاولة الطباعة المعلقة للعملية #${vm.state.options.pending_print.id} وسجل نتيجة الورقة قبل إصدار بطاقات جديدة.`;return;}
 let payload=uncertainSale.value;
 try{payload ||= salePayload(selected.value,quantity.value,retail.value);}catch(error){vm.state.error=error.message;return;}
 const sale=await vm.execute(payload,key,(value,signal)=>vm.api.create(value,false,signal),'تم إصدار البطاقات؛ الوصل جاهز للطباعة.');
 if(sale){issuedSale.value=sale;uncertainSale.value=null;reviewed.value=false;void vm.refreshProducts();await vm.openReceipt(sale);}
 else if(vm.state.uncertain){uncertainSale.value={...payload};}
 else{uncertainSale.value=null;}
}
function openReprint(sale){vm.clearReceipt();reprint.value=sale;}
function backToCatalog(){if(vm.state.busy||uncertainSale.value)return;selectedId.value='';familyKey.value='';familyMembers.value=[];reviewed.value=false;issuedSale.value=null;}
function backFromDigital(){digitalSelected.value=null;familyKey.value='';familyMembers.value=[];void refreshCatalog();}
function receiptChanged(){
 if(['Printed','Reprinted','Delivered'].includes(vm.receipt.value?.sale.status)){issuedSale.value=null;selectedId.value='';familyKey.value='';familyMembers.value=[];reviewed.value=false;}
 return vm.refreshProducts();
}
async function refreshCatalog({fresh=false}={}){
 catalogReader?.abort();catalogReader=new AbortController();const expected=++catalogRevision;catalogLoading.value=true;
 await Promise.all([
  (async()=>{if(vm.can('sales.view')){try{const result=await vm.api.products(catalogReader.signal,{fresh});if(expected===catalogRevision){vm.state.products=result.data;vm.state.options.pending_print=result.pending_print||null;}}catch(error){if(error.name!=='AbortError'&&expected===catalogRevision)vm.state.error=await vm.failure(error);}}})(),
  (async()=>{if(vm.pos.value&&vm.can('digital.view')){try{const result=await digitalApi.offers(catalogReader.signal,{fresh});if(expected===catalogRevision)digitalOffers.value=result.data;}catch(error){if(error.name!=='AbortError'&&expected===catalogRevision)vm.state.error=await vm.failure(error);}}})()
 ]);
 if(expected===catalogRevision)catalogLoading.value=false;
}
function refreshVisibleCatalog(){if(document.visibilityState==='visible'&&!selected.value&&!familyKey.value&&!digitalSelected.value&&!vm.state.busy&&!vm.receipt.value&&!catalogLoading.value)void refreshCatalog();}
function restoreFavorites(){try{favorites.value=readCategoryFavorites(window.localStorage,vm.ownId.value);}catch{favorites.value=[];}}
function saleBackState(){return !vm.pos.value?'none':vm.state.busy||vm.receiptLoading.value||uncertainSale.value?'blocked':searchVisible.value||detailOpen.value&&!digitalSelected.value?'open':'none';}
function saleBack(){if(searchVisible.value)void closeSaleSearch();else backToCatalog();}
watch([quantity,retail],()=>reviewed.value=false);
watch(kind,()=>{company.value='all';if(kind.value!=='topup')topupType.value='';productLimit.value=24;},{flush:'sync'});
watch(topupType,()=>{company.value='all';productLimit.value=24;});
watch(()=>[route.query.service,route.query.type,route.query.q],([service,type,search])=>{if(service!=='topup'||choiceLocked.value)return;kind.value='topup';topupType.value=topupKind(type??savedCatalog?.topupType);company.value='all';if(typeof search==='string'){query.value=search.slice(0,160);searchVisible.value=!!query.value;}else if(typeof type==='string'){query.value='';searchVisible.value=false;}},{immediate:true});
watch([query,company],()=>productLimit.value=24);
watch(()=>route.query.company,value=>{
 if(typeof value!=='string'||vm.state.busy||uncertainSale.value||issuedSale.value||digitalSelected.value)return;
 const link=value.match(/^(?:(stock|digital):)?([1-9]\d*|topup|rabiaa)$/);
 if(!link)return;
 selectedId.value='';digitalSelected.value=null;familyKey.value='';familyMembers.value=[];query.value='';
 kind.value=route.query.service==='topup'?'topup':link[1]==='digital'&&['topup','rabiaa'].includes(link[2])?link[2]:'all';
 company.value=['topup','rabiaa'].includes(link[2])?'all':link[2];
},{immediate:true});
watch(favorites,rows=>{try{if(typeof window!=='undefined')saveCategoryFavorites(window.localStorage,vm.ownId.value,rows);}catch{}});
watch(()=>[vm.ownId.value,vm.can('digital.view'),vm.can('sell.create')],()=>{
 catalogRevision++;catalogReader?.abort();digitalOffers.value=[];digitalSelected.value=null;selectedId.value='';familyKey.value='';familyMembers.value=[];issuedSale.value=null;catalogLoading.value=false;searchVisible.value=false;query.value='';notifySaleSearch();restoreFavorites();
 if(vm.pos.value)void refreshCatalog();
});
useRouteRefresh(async()=>{await refreshCatalog({fresh:true});return !vm.state.error;},{canRefresh:()=>!vm.state.busy&&!vm.receiptLoading.value&&!uncertainSale.value&&!issuedSale.value&&!vm.receipt.value&&!reprint.value&&!digitalSelected.value});
onMounted(async()=>{
 releaseBack=registerPosBackLayer({state:saleBackState,back:saleBack});
 window.addEventListener('dananeer:toggle-sale-search',toggleSaleSearch);
 restoreFavorites();
 if(vm.pos.value){window.addEventListener('focus',refreshVisibleCatalog);document.addEventListener('visibilitychange',refreshVisibleCatalog);catalogTimer=setInterval(refreshVisibleCatalog,30000);}
 await refreshCatalog();
 if(vm.pos.value&&vm.can('digital.create')){
  try{const previous=(await digitalApi.recovery()).data.order;if(previous)digitalSelected.value=digitalOffers.value.find(offer=>offer.id===previous.offer_id)||{id:previous.offer_id,connection_id:previous.connection_id,name:previous.product_name,provider:previous.provider,retail:previous.retail,gateway:{configured:false,purchases_enabled:false,missing:[]}};}
  catch(error){vm.state.error=await vm.failure(error);}
 }
 if(!vm.pos.value&&vm.state.products.length)choose(vm.state.products[0]);
});
onBeforeUnmount(()=>{vm.session.api.rememberViewState?.('pos-sell-catalog',{kind:kind.value,topupType:topupType.value,company:company.value,query:query.value,searchVisible:searchVisible.value},catalogViewScope);releaseBack?.();searchVisible.value=false;notifySaleSearch();window.removeEventListener('dananeer:toggle-sale-search',toggleSaleSearch);catalogRevision++;catalogReader?.abort();clearInterval(catalogTimer);window.removeEventListener('focus',refreshVisibleCatalog);document.removeEventListener('visibilitychange',refreshVisibleCatalog);});
</script>

<template>
 <div class="catalog-workspace sales-workspace" :class="{'pos-sale-workspace':vm.pos.value,'pos-sale-refined':vm.pos.value}" :data-operation-uncertain="!!uncertainSale||!!issuedSale">
  <div v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</div>
  <div v-if="vm.state.notice" class="notice" role="status">{{vm.state.notice}}</div>
  <DeviceSession :vm="deviceVm"/>
  <PrintPolicySummary v-if="!vm.pos.value" :policy="selected?.print_policy" :daily="selected?.daily_print" :wait="selected?.print_wait_seconds"/>
  <div v-if="vm.state.options.pending_print" class="notice warn pos-pending-print" role="alert">
   <span>الوصل #{{vm.state.options.pending_print.id}} بانتظار تأكيد الطباعة.</span>
   <button v-if="vm.can('sell.receipt')" type="button" class="btn primary" :disabled="vm.state.busy||vm.receiptLoading.value" @click="vm.openReceipt(vm.state.options.pending_print)">عرض الوصل</button>
  </div>
  <div v-if="!vm.seller.value" class="card empty">البيع من حساب الوكيل الفرعي أو الفرع الفرعي أو نقطة البيع ضمن حسابه.</div>
  <section v-else-if="vm.pos.value" class="pos-sale-catalog pos-sale-layout" :class="{'has-detail':detailOpen,'is-digital-detail':!!digitalSelected,'is-searching':searchVisible}" data-no-pagination>
   <div class="pos-sale-browser">
    <div v-if="searchVisible" id="pos-sale-search" class="pos-sale-search" role="search" aria-label="بحث الفئات" @keydown.esc.stop.prevent="closeSaleSearch">
     <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg>
     <input ref="searchInput" v-model="query" type="search" placeholder="ابحث عن فئة أو شركة" aria-label="ابحث عن فئة أو شركة">
     <button type="button" class="pos-sale-search-close" aria-label="إغلاق البحث ومسح النص" @click="closeSaleSearch"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </div>
    <div class="pos-sale-tabs" role="group" aria-label="عرض الفئات">
     <button v-for="item in [{id:'all',name:'الكل'},{id:'topup',name:'Topup'},{id:'favorites',name:'المفضلة'}]" :key="item.id" type="button" :aria-pressed="kind===item.id" :class="{active:kind===item.id}" @click="kind=item.id">
      <svg v-if="item.id==='favorites'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></svg>{{item.name}}
     </button>
    </div>
    <div v-if="kind==='topup'&&topupTabs.length>1" class="pos-sale-tabs pos-topup-type-tabs" role="group" aria-label="نوع خدمة Topup"><button v-for="item in topupTabs" :key="item.id" type="button" :aria-pressed="topupType===item.id" :class="{active:topupType===item.id}" @click="topupType=item.id">{{uiLabel(item.name)}}</button></div>
    <div v-if="companies.length" class="pos-sale-company-track" role="group" aria-label="الشركات">
     <div class="pos-sale-company-option"><button type="button" class="pos-sale-company pos-sale-company-all" :class="{active:company==='all'}" :aria-pressed="company==='all'" aria-label="كل الشركات" @click="company='all'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg></button><small>الكل</small></div>
     <div v-for="provider in companies" :key="provider.id" class="pos-sale-company-option">
      <button type="button" class="pos-sale-company" :class="{active:company===provider.id}" :aria-pressed="company===provider.id" :aria-label="provider.name" :title="provider.name" @click="company=provider.id">
       <img v-if="imageUrl(provider.image)" :src="provider.image" alt="" loading="lazy" decoding="async" @error="imageFailed(provider.image)">
       <span v-else aria-hidden="true">{{Array.from(provider.name||'')[0]||'•'}}</span>
      </button><small dir="auto">{{provider.name}}</small>
     </div>
    </div>
    <div class="pos-sale-section-heading"><h2 v-if="selectedCompany">{{selectedCompany.name}}</h2><small>{{shownFamilies.length}} خيار</small></div>
    <div class="pos-sale-product-grid">
     <article v-for="family in shownFamilies.slice(0,productLimit)" :key="family.key" class="pos-sale-category" :class="{'is-unavailable':familyUnavailable(family),'is-chosen':selectedFamily?.key===family.key}" :data-service="family.kind">
      <button type="button" class="pos-sale-category-select" :disabled="choiceLocked||!!family.products[0]?.digital_offer&&!vm.can('digital.create')||!family.grouped&&familyUnavailable(family)" :aria-label="family.name+' · '+(family.grouped?family.products.length+' فئات':money(family.price)+' '+currencyName(family))" @click="openFamily(family)">
       <CatalogArtwork :src="family.image_url" :label="family.provider_name||family.name" :service="family.kind"/>
       <span class="pos-sale-category-info"><span class="pos-sale-category-title"><strong dir="auto">{{family.name}}</strong><small :title="family.grouped?family.products.length+' فئات للاختيار':serviceName(family)">{{family.grouped?family.products.length+' فئات':family.kind==='card'?'بطاقة':'خدمة إلكترونية'}}</small></span><span class="pos-sale-category-price"><small v-if="family.grouped">من</small><b>{{money(family.price)}}</b><small v-if="family.price!=null">{{currencyName(family)}}</small></span><span class="pos-sale-family-count">{{family.grouped?'اختيار الفئة':family.kind==='card'?'عرض التفاصيل':'عرض الخدمة'}} <span aria-hidden="true">←</span></span></span>
      </button>
      <p v-if="!family.grouped&&stockUnavailableReason(family.products[0])" class="pos-sale-unavailable-reason">{{uiLabel(stockUnavailableReason(family.products[0]))}}</p>
      <button v-if="!family.grouped" type="button" class="pos-sale-favorite" :class="{active:isFavorite(family.products[0])}" :aria-pressed="isFavorite(family.products[0])" :aria-label="(isFavorite(family.products[0])?'إزالة من المفضلة: ':'إضافة إلى المفضلة: ')+family.name" @click="toggleFavorite(family.products[0])"><svg viewBox="0 0 24 24" :fill="isFavorite(family.products[0])?'currentColor':'none'" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></svg></button>
     </article>
    </div>
    <p v-if="!shown.length" class="pos-sale-empty" role="status">{{catalogLoading?'جارٍ تحميل الفئات…':query.trim()?'لا توجد نتائج مطابقة للبحث.':kind==='favorites'?'لا توجد فئات مفضلة.':'لا توجد فئات متاحة حاليًا.'}}</p>
    <button v-if="shownFamilies.length>productLimit" type="button" class="btn pos-sale-more" @click="productLimit+=24">عرض المزيد</button>
   </div>
   <div class="pos-sale-family-detail">
    <DigitalPage v-if="digitalSelected" :focused-offer="digitalSelected" :device-session="false" @back="backFromDigital"/>
    <section v-else-if="selectedFamily||selected" class="pos-sale-checkout" :class="{'is-grouped':selectedFamily?.grouped}">
     <div class="pos-sale-checkout-body">
     <div class="pos-sale-checkout-heading"><div><small>{{serviceName(selectedFamily||{kind:'card'})}}</small></div><button type="button" class="btn small" :disabled="vm.state.busy||!!uncertainSale" @click="backToCatalog"><span aria-hidden="true">→</span> رجوع للفئات</button></div>
     <div class="pos-sale-selected-category">
      <CatalogArtwork :src="selectedFamily?.image_url||selected?.image_url" :label="selectedFamily?.provider_name||selected?.provider_name"/>
      <div><small v-if="!selectedFamily?.grouped">{{selectedFamily?.provider_name||selected?.provider_name}}</small><h3 dir="auto">{{selectedFamily?.name||selected?.name}}</h3></div>
     </div>
     <div v-if="familyChoices.length" class="pos-sale-denominations">
      <div class="pos-sale-section-heading"><h3>قيمة البطاقة</h3><small>{{familyChoices.length}} {{familyChoices.length===1?'خيار':'خيارات'}}</small></div>
      <div class="pos-sale-denomination-grid" :class="{'is-grouped':selectedFamily.grouped}" role="group" aria-label="اختيار فئة البطاقة">
       <div v-for="product in familyChoices" :key="product.key" class="pos-sale-denomination" :class="{'is-selected':Number(selectedId)===Number(product.id),'is-unavailable':!!stockUnavailableReason(product)}">
        <button type="button" class="pos-sale-denomination-select" :aria-pressed="Number(selectedId)===Number(product.id)" :disabled="choiceLocked||!!stockUnavailableReason(product)" :title="product.name" :aria-label="product.name+' · '+money(product.price)+' '+currencyName(product)" @click="choose(product)"><strong>{{selectedFamily.grouped?money(product.face_value):product.name}}</strong><span v-if="selectedFamily.grouped" class="pos-sale-denomination-name" :title="product.name" dir="auto">{{product.name}}</span><small>السعر {{money(product.price)}} {{currencyName(product)}}</small><small v-if="stockUnavailableReason(product)" class="pos-sale-choice-reason">{{uiLabel(stockUnavailableReason(product))}}</small></button>
        <button type="button" class="pos-sale-choice-favorite" :class="{active:isFavorite(product)}" :aria-pressed="isFavorite(product)" :aria-label="(isFavorite(product)?'إزالة من المفضلة: ':'إضافة إلى المفضلة: ')+product.name" @click="toggleFavorite(product)"><svg viewBox="0 0 24 24" :fill="isFavorite(product)?'currentColor':'none'" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></svg></button>
       </div>
      </div>
     </div>
     <form v-if="selected" id="pos-voucher-sale-form" @submit.prevent="printSelected">
      <div class="pos-sale-selected-name"><span>الفئة المختارة</span><strong dir="auto">{{selected.name}}</strong></div>
      <div class="pos-sale-summary-grid"><div><span>سعر البطاقة</span><strong>{{money(selected.price)}} <small>{{currencyName(selected)}}</small></strong></div><div><span>المخزون المتاح</span><strong>{{selected.stock_available}} <small>بطاقة</small></strong></div></div>
      <div class="pos-sale-order-fields">
       <label class="pos-sale-quantity-label">عدد البطاقات<div class="pos-quantity-stepper"><button type="button" aria-label="تقليل عدد البطاقات" :disabled="Number(quantity)<=1||choiceLocked" @click="quantity=Math.max(1,Number(quantity)-1)">−</button><input lang="en" v-model="quantity" type="number" aria-label="عدد البطاقات" inputmode="numeric" min="1" :max="Math.min(selected.print_policy?.max_cards??0,selected.stock_available)" :disabled="choiceLocked" required><button type="button" aria-label="زيادة عدد البطاقات" :disabled="Number(quantity)>=Math.min(selected.print_policy?.max_cards??0,selected.stock_available)||choiceLocked" @click="quantity=Math.min(selected.print_policy?.max_cards??0,selected.stock_available,Number(quantity)+1)">+</button></div></label>
       <label>سعر البيع للزبون · {{currencyName(selected)}}<input v-model="retail" type="text" inputmode="decimal" :placeholder="selected.price" :disabled="choiceLocked" maxlength="30"></label>
      </div>
      <div class="pos-sale-wallet"><span>رصيد البطاقات المتاح</span><strong>{{money(selected.wallet_available)}} <small>{{currencyName(selected)}}</small></strong></div>
      <div class="pos-sale-cost"><span>تكلفة البطاقات</span><strong>{{money(total)}} {{currencyName(selected)}}</strong></div>
     </form>
     
     </div>
      <div v-if="selected" class="pos-sale-checkout-dock">
       <div class="pos-sale-total"><span>إجمالي بيع الزبون <small>{{quantity}} بطاقة</small></span><strong>{{money(retailTotal)}} <small>{{currencyName(selected)}}</small></strong></div>
       <button type="submit" form="pos-voucher-sale-form" class="btn primary pos-sale-print" :disabled="saleDisabled">{{vm.state.busy||vm.receiptLoading.value?'جارٍ تجهيز الوصل…':issuedSale?'عرض نفس الوصل':uncertainSale?'متابعة نفس العملية':'إصدار ومعاينة الوصل'}} <span v-if="!vm.state.busy&&!vm.receiptLoading.value" aria-hidden="true">←</span></button>
       
      </div>
    </section>
    <section v-else class="pos-sale-detail-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h4"/></svg></section>
   </div>
  </section>
    <div v-else class="two"><div class="card"><div class="cardhead"><span class="badge neutral">مفرد وجملة</span><button type="button" class="btn small" :disabled="vm.state.loading" @click="refreshCatalog({fresh:true})">تحديث</button></div><div class="rowline"><span>{{vm.identity.value?.account.name}}</span><b>{{money(selected?.wallet_available)}} {{currency}}</b></div><div class="products" style="margin-top:20px"><button v-for="product in vm.state.products" :key="product.id" type="button" class="product" :class="{selected:Number(selectedId)===product.id}" :disabled="!!stockUnavailableReason(product)" @click="choose(product)"><span class="badge neutral">{{product.provider_name}}</span><b>{{product.name}}</b><small>{{money(product.price)}} د.ع · {{systemLabel(product.kind,'productKind')}}</small><small v-if="stockUnavailableReason(product)">{{uiLabel(stockUnavailableReason(product))}}</small></button></div><p v-if="!vm.state.products.length" class="empty">{{catalogLoading?'جارٍ تحميل الفئات…':'لا توجد فئات متاحة'}}</p></div><form class="card" @submit.prevent="sell"><div class="cardhead"><span class="badge">سحب من مخزن الرئيسي</span></div><div class="rowline"><span>الفئة</span><b>{{selected?.name || '—'}}</b></div><div class="rowline"><span>سعر البطاقة</span><b>{{money(selected?.price)}} {{currency}}</b></div><label style="margin-top:18px">سعر البيع للزبون • {{currency}}<input :disabled="vm.state.busy || !!uncertainSale" v-model="retail" type="text" inputmode="decimal" :placeholder="selected?.price" maxlength="30"></label><label style="margin-top:18px">عدد البطاقات<input lang="en" :disabled="vm.state.busy || !!uncertainSale" v-model="quantity" type="number" min="1" :max="selected?.print_policy.max_cards" required></label><div class="rowline"><span>الإجمالي</span><strong class="metricvalue" style="font-size:26px">{{money(total)}} <small>{{currency}}</small></strong></div><div class="help">الحد الأقصى للبطاقات في الطلب الواحد {{selected?.print_policy.max_cards ?? '—'}} بطاقات · الحد اليومي {{money(selected?.daily_amount)}} {{currency}}</div><button class="btn primary" style="width:100%;margin-top:22px" :disabled="vm.state.busy || !selected || selected.price===null || !vm.can('data.pin') || (!!vm.state.options.pending_print && !uncertainSale)">{{uncertainSale ? 'متابعة نفس الإصدار':'إصدار البطاقات ومعاينة الوصل ←'}}</button></form></div>

  <p v-if="vm.receiptLoading.value" class="read-loading notice" role="status">جارٍ تحميل الوصل…</p>
  <ReceiptDialog :vm="vm" @changed="receiptChanged" @reprint="openReprint"/>
  <ReprintDialog v-if="reprint" :vm="vm" :sale="reprint" @close="reprint=null"/>
 </div>
</template>
