<script setup>

import {computed,onMounted,ref,watch} from 'vue';
import {RouterLink} from 'vue-router';
import {systemLabel} from '../preferences/system-labels.js';

import {useSalesRuntime} from './sales-runtime.js';

import {money,multiply,salePayload,digitalCatalogProduct,catalogCompanyMatches} from './sales-model.js';

import DeviceSession from './DeviceSession.vue';

import DigitalPage from '../digital/DigitalPage.vue';

import {createDigitalApi} from '../digital/digital-api.js';

import PrintPolicySummary from './PrintPolicySummary.vue';

import ReceiptDialog from './ReceiptDialog.vue';

import ReprintDialog from './ReprintDialog.vue';

import '../catalog/catalog.css';

import './sales.css';

const vm=useSalesRuntime(),selectedId=ref(''),quantity=ref(1),retail=ref(''),company=ref('all'),query=ref(''),kind=ref('all'),reviewed=ref(false),reprint=ref(null),uncertainSale=ref(null);

const digitalApi=createDigitalApi(vm.session.api),digitalOffers=ref([]),digitalSelected=ref(null);

const deviceVm={...vm,api:vm.can('sell.create')?vm.api:{heartbeat:digitalApi.heartbeat}};

const catalogProducts=computed(()=>[...vm.state.products.filter(row=>vm.can('sell.create')&&row.price!==null&&row.stock_available>0&&!digitalOffers.value.some(offer=>Number(offer.product_id)===Number(row.id))).map(row=>({...row,key:`stock:${row.id}`,kind:'card'})),...digitalOffers.value.map(digitalCatalogProduct)]);

const selected=computed(()=>vm.state.products.find(product=>Number(product.id)===Number(selectedId.value)));

const companies=computed(()=>[...new Map(catalogProducts.value.map(product=>[product.provider_id,{id:product.provider_id,name:product.provider_name,count:catalogProducts.value.filter(row=>row.provider_id===product.provider_id).length}])).values()]);

const shown=computed(()=>catalogProducts.value.filter(product=>catalogCompanyMatches(product,company.value)&&(kind.value==='all'||product.kind===kind.value)&&product.name.toLocaleLowerCase('ar').includes(query.value.trim().toLocaleLowerCase('ar'))));

const total=computed(()=>multiply(uncertainSale.value?.expected_price || selected.value?.price,quantity.value)),retailTotal=computed(()=>{try{return multiply(retail.value || uncertainSale.value?.expected_price || selected.value?.price,quantity.value);}catch{return null;}}),key=vm.key();

const currency=computed(()=>'د.ع');

function choose(product){if(uncertainSale.value)return;if(product.digital_offer){digitalSelected.value=product.digital_offer;selectedId.value='';return;}digitalSelected.value=null;selectedId.value=product.id;quantity.value=1;retail.value='';reviewed.value=false;vm.state.error='';}

function review(){try{salePayload(selected.value,quantity.value,retail.value);reviewed.value=true;vm.state.error='';}catch(error){vm.state.error=error.message;}}

async function sell(){let payload=uncertainSale.value;try{payload ||= salePayload(selected.value,quantity.value,retail.value);}catch(error){vm.state.error=error.message;return;}const sale=await vm.execute(payload,key,(value,signal)=>vm.api.create(value,false,signal),'تم الإصدار والخصم مرة واحدة؛ أكد نتيجة الطباعة.');if(sale){uncertainSale.value=null;reviewed.value=false;await vm.refreshProducts();await vm.openReceipt(sale);}else if(vm.state.uncertain){uncertainSale.value={...payload};}else{uncertainSale.value=null;}}

function openReprint(sale){vm.clearReceipt();reprint.value=sale;}

watch([quantity,retail],()=>reviewed.value=false);

async function refreshCatalog(){if(vm.can('sales.view'))await vm.refreshProducts();if(vm.pos.value&&vm.can('digital.view')){try{digitalOffers.value=(await digitalApi.offers()).data;}catch(error){vm.state.error=await vm.failure(error);}}}

onMounted(async()=>{await refreshCatalog();if(vm.pos.value&&vm.can('digital.create')){try{const previous=(await digitalApi.recovery()).data.order;if(previous)digitalSelected.value=digitalOffers.value.find(offer=>offer.id===previous.offer_id)||{id:previous.offer_id,connection_id:previous.connection_id,name:previous.product_name,provider:previous.provider,retail:previous.retail,gateway:{configured:false,purchases_enabled:false,missing:[]}};}catch(error){vm.state.error=await vm.failure(error);}}if(!vm.pos.value && vm.state.products.length)choose(vm.state.products[0]);});

</script>

<template>

  <div class="catalog-workspace sales-workspace"><div v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</div><div v-if="vm.state.notice" class="notice" role="status">{{vm.state.notice}}</div><DeviceSession :vm="deviceVm"/><PrintPolicySummary :policy="selected?.print_policy" :daily="selected?.daily_print" :wait="selected?.print_wait_seconds"/>

    <div v-if="!vm.seller.value" class="card empty">البيع من حساب الوكيل الفرعي أو الفرع الفرعي أو نقطة البيع ضمن حسابه.</div>

    <section v-else-if="vm.pos.value" class="pos-phone-catalog" data-no-pagination><template v-if="!selected&&!digitalSelected">
      <div class="pos-catalog-heading"><div><h2>اختر الفئة للبيع</h2><p>التعبئة المباشرة وبطاقات الطباعة والرابعة</p></div><button type="button" class="pos-refresh-button" :disabled="vm.state.loading" aria-label="تحديث الفئات" @click="refreshCatalog"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 3M4 15l2 3a7 7 0 0 0 12-1"/></svg></button></div>
      <div class="pos-catalog-filters"><label class="pos-catalog-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg><input v-model="query" type="search" placeholder="بحث عن فئة" aria-label="بحث عن فئة"></label><label class="pos-company-filter"><span>الشركة</span><select v-model="company" aria-label="الشركة"><option value="all">كل الشركات</option><option v-for="provider in companies" :key="provider.id" :value="String(provider.id)">{{provider.name}}</option></select></label></div>
      <div class="pos-service-tabs" role="group" aria-label="نوع الخدمة"><button v-for="item in [{id:'all',name:'الكل'},{id:'topup',name:'التعبئة'},{id:'card',name:'الطباعة'},{id:'rabiaa',name:'الرابعة'}]" :key="item.id" type="button" :aria-pressed="kind===item.id" :class="{active:kind===item.id}" @click="kind=item.id">{{item.name}}</button></div>
      <RouterLink v-if="kind==='topup'&&vm.can('digital.view')" class="pos-category-access" to="/topup/available">عرض فئات Topup الممنوحة من الوكيل <span aria-hidden="true">←</span></RouterLink>
      <div class="pos-products-heading"><h3>الفئات المتاحة</h3><span>{{shown.length}} فئة</span></div>
      <div class="pos-phone-products"><button v-for="product in shown" :key="product.key" type="button" class="pos-product-card" :data-service="product.kind" @click="choose(product)"><span class="pos-product-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><template v-if="product.kind==='topup'"><rect x="7" y="2" width="10" height="20" rx="3"/><path d="M10 18h4M10 7h4M12 5v4"/></template><template v-else><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h4"/></template></svg></span><span class="pos-product-info"><strong dir="auto">{{product.name}}</strong><small dir="auto">{{product.provider_name}}</small><span>{{product.kind==='topup'?'تعبئة الهاتف':product.kind==='rabiaa'?'بطاقات الرابعة':'بطاقات الطباعة'}}</span></span><span class="pos-product-price"><b>{{money(product.price)}}</b><small>د.ع</small></span><svg class="pos-product-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button></div>
      <p v-if="!shown.length" class="empty">{{vm.state.loading?'جارٍ تحميل الفئات…':'لا توجد فئات متاحة'}}</p>
    </template><DigitalPage v-else-if="digitalSelected" :focused-offer="digitalSelected" :device-session="false" @back="digitalSelected=null;refreshCatalog()"/><section v-else class="card pos-phone-sale"><div class="pos-phone-title"><h2>{{selected.name}}</h2><button type="button" class="btn small" :disabled="vm.state.busy || !!uncertainSale" @click="selectedId='';reviewed=false">رجوع</button></div><form @submit.prevent="review"><div class="formgrid"><label>عدد البطاقات<input :disabled="vm.state.busy || !!uncertainSale" v-model="quantity" type="number" min="1" :max="selected.print_policy.max_cards" required></label><label>سعر البيع · {{currency}}<input :disabled="vm.state.busy || !!uncertainSale" v-model="retail" type="text" inputmode="decimal" :placeholder="selected.price" maxlength="30"></label></div><div class="pos-phone-sale-total"><span>الإجمالي</span><strong>{{money(retailTotal)}} {{currency}}</strong></div><p class="help">المتاح: {{selected.stock_available}} بطاقة · رصيدك: {{money(selected.wallet_available)}} {{currency}}</p><button class="btn primary" :disabled="vm.state.busy || !!uncertainSale || selected.price===null">مراجعة البيع</button></form><div v-if="reviewed" class="pos-phone-confirm"><p>تأكيد بيع وطباعة البطاقات</p><button type="button" class="btn primary" :disabled="vm.state.busy" @click="sell">{{uncertainSale ? 'متابعة نفس الإصدار':'تأكيد البيع'}}</button></div></section></section>

    <div v-else class="two"><div class="card"><div class="cardhead"><span class="badge neutral">مفرد وجملة</span><button type="button" class="btn small" :disabled="vm.state.loading" @click="refreshCatalog">تحديث</button></div><div class="rowline"><span>{{vm.identity.value?.account.name}}</span><b>{{money(selected?.wallet_available)}} {{currency}}</b></div><div class="products" style="margin-top:20px"><button v-for="product in vm.state.products" :key="product.id" type="button" class="product" :class="{selected:Number(selectedId)===product.id}" @click="choose(product)"><span class="badge neutral">{{product.provider_name}}</span><b>{{product.name}}</b><small>{{money(product.price)}} د.ع · {{systemLabel(product.kind,'productKind')}}</small></button></div><p v-if="!vm.state.products.length" class="empty">{{vm.state.loading?'جارٍ تحميل الفئات…':'لا توجد فئات متاحة'}}</p></div><form class="card" @submit.prevent="sell"><div class="cardhead"><span class="badge">سحب من مخزن الرئيسي</span></div><div class="rowline"><span>الفئة</span><b>{{selected?.name || '—'}}</b></div><div class="rowline"><span>سعر البطاقة</span><b>{{money(selected?.price)}} {{currency}}</b></div><label style="margin-top:18px">سعر البيع للزبون • {{currency}}<input :disabled="vm.state.busy || !!uncertainSale" v-model="retail" type="text" inputmode="decimal" :placeholder="selected?.price" maxlength="30"></label><label style="margin-top:18px">عدد البطاقات<input :disabled="vm.state.busy || !!uncertainSale" v-model="quantity" type="number" min="1" :max="selected?.print_policy.max_cards" required></label><div class="rowline"><span>الإجمالي</span><strong class="metricvalue" style="font-size:26px">{{money(total)}} <small>{{currency}}</small></strong></div><div class="help">الحد الأقصى للبطاقات في الطلب الواحد {{selected?.print_policy.max_cards ?? '—'}} بطاقات · الحد اليومي {{money(selected?.daily_amount)}} {{currency}}</div><button class="btn primary" style="width:100%;margin-top:22px" :disabled="vm.state.busy || !selected || selected.price===null || !vm.can('data.pin')">{{uncertainSale ? 'متابعة نفس الإصدار':'إصدار البطاقات ومعاينة الوصل ←'}}</button></form></div>

    <p v-if="vm.receiptLoading.value" class="read-loading notice" role="status">جارٍ تحميل الوصل…</p><ReceiptDialog :vm="vm" @changed="vm.refreshProducts" @reprint="openReprint"/><ReprintDialog v-if="reprint" :vm="vm" :sale="reprint" @close="reprint=null"/>

  </div>

</template>

