import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {computed,reactive,ref} from 'vue';
import {parse} from '@vue/compiler-sfc';
import {createReportApi} from '../src/modules/reports/report-api.js';
import {createSalesApi} from '../src/modules/sales/sales-api.js';
import {createDigitalApi} from '../src/modules/digital/digital-api.js';
import {dashboardCompanies} from '../src/modules/dashboard/pos-home.js';
import {dashboardSummaryPath,dashboardActivityPath,dashboardReadScope,dashboardCatalogSnapshot,dashboardTopupAvailable} from '../src/modules/dashboard/dashboard-cache.js';

const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const product=id=>({provider_id:id,provider_name:`Company ${id}`});
const offer=(id,balance='94000.00')=>({provider:'topup',product_provider_id:id,product_provider_name:`Digital ${id}`,retail:'5000',wallet_available:balance});
async function dashboard({cache=new Map(),request}={},activity=false){
 const watchers=[],calls=[],session={state:reactive({identity:{user:{id:9},account:{id:12,type:'pos'},membership:{kind:'owner'},permissions:['dashboard.view','sell.create','digital.view']}}),can:key=>session.state.identity?.permissions.includes(key)||false,api:{peekRequest:path=>structuredClone(cache.get(path)??null),hasFreshRequest:path=>cache.has(path),request:(path,options)=>{calls.push({path,options});return request?request(path,options):Promise.resolve(cache.get(path)??{data:{cards:[]}});}}};
 const source=await readFile(new URL(`../src/modules/dashboard/${activity?'DashboardActivity':'DashboardPage'}.vue`,import.meta.url),'utf8'),script=parse(source).descriptor.scriptSetup.content.replace(/^import[\s\S]*?;\r?$/gm,'');
 const scope={computed,reactive,ref,watch:(get,callback)=>watchers.push({get,callback}),onMounted(){},onBeforeUnmount(){},onServerPrefetch(){},defineAsyncComponent:()=>({}),usePortal:()=>({session}),useBalancePrivacy:()=>({hidden:ref(false),toggle(){}}),useUiLabelTranslator:()=>value=>value,usePreferencesTranslator:()=>value=>value,usePreferences:()=>({state:{language:'ar'}}),useRouter:()=>({getRoutes:()=>[]}),defineProps:()=>({compact:true,operations:[]}),createReportApi,createSalesApi,createDigitalApi,dashboardCompanies,dashboardSummaryPath,dashboardActivityPath,dashboardReadScope,dashboardCatalogSnapshot,dashboardTopupAvailable,AbortController,errorText:error=>error.message};
 runInNewContext(script+`\nglobalThis.controls={${activity?'rows,nextCursor,loading,load':'data,companies,topupAvailable,companyError,busy,detail,load,loadCompanies,openDetails'}};`,scope);
 return {control:scope.controls,watchers,calls,session,cache};
}

test('POS home immediately restores company logos and Topup funds while background requests keep those values visible',async()=>{
 const stock=deferred(),digital=deferred(),cache=new Map([['/sales/products',{data:[product(1)]}],['/digital/offers?',{data:[offer(2)]}]]);
 const {control}=await dashboard({cache,request:path=>path==='/sales/products'?stock.promise:digital.promise});
 assert.deepEqual(Array.from(control.companies.value,row=>row.key),['stock:1','digital:2']);assert.equal(control.topupAvailable.value,'94000.00');
 const loading=control.loadCompanies();assert.equal(control.companies.value.length,2);assert.equal(control.topupAvailable.value,'94000.00');
 stock.resolve({data:[product(3)]});digital.resolve({data:[offer(4,'83000.00')]});await loading;
 assert.deepEqual(Array.from(control.companies.value,row=>row.key),['stock:3','digital:4']);assert.equal(control.topupAvailable.value,'83000.00');
});

test('an older home catalog completion cannot replace a newer request and one failed source preserves its known companies',async()=>{
 const requests=[deferred(),deferred(),deferred(),deferred()];let next=0;
 const {control}=await dashboard({cache:new Map([['/sales/products',{data:[product(1)]}]]),request:()=>requests[next++].promise});
 const first=control.loadCompanies(),second=control.loadCompanies();
 requests[2].reject(Error('offline'));requests[3].resolve({data:[offer(4)]});await second;
 assert.deepEqual(Array.from(control.companies.value,row=>row.key),['stock:1','digital:4']);assert.ok(control.companyError.value);
 requests[0].resolve({data:[product(9)]});requests[1].resolve({data:[offer(9)]});await first;
 assert.deepEqual(Array.from(control.companies.value,row=>row.key),['stock:1','digital:4']);
});

test('fresh summary reads suppress the loading flash, while explicit refresh and empty invalidated cache still load',async()=>{
 const pending=deferred(),path=dashboardSummaryPath(),cache=new Map([[path,{data:{cards:[{key:'balance',value:'5000'}]}}]]);
 const {control}=await dashboard({cache,request:()=>pending.promise});
 const initial=control.load();assert.equal(control.busy.value,false);assert.equal(control.data.value.cards[0].value,'5000');
 pending.resolve(cache.get(path));await initial;
 const refreshed=control.load(true);assert.equal(control.busy.value,true);await refreshed;
 cache.clear();const cleared=await dashboard({cache});assert.equal(cleared.control.companies.value.length,0);assert.equal(cleared.control.data.value.cards.length,0);
});

test('activity rows and cursor hydrate before mounting and permission/logout changes clear home data without extra logout reads',async()=>{
 const path=dashboardActivityPath('10'),cache=new Map([[path,{data:[{key:'sale:1',product_name:'Remembered activity'}],meta:{next_cursor:'next-page'}}]]);
 const activity=await dashboard({cache},true);assert.equal(activity.control.rows.value[0].product_name,'Remembered activity');assert.equal(activity.control.nextCursor.value,'next-page');
 const home=await dashboard({cache:new Map([['/sales/products',{data:[product(1)]}],['/digital/offers?',{data:[offer(2)]}]])});
 home.cache.clear();home.session.state.identity=null;home.watchers[0].callback();
 assert.equal(home.control.companies.value.length,0);assert.equal(home.control.topupAvailable.value,null);assert.equal(home.control.data.value.cards.length,0);assert.equal(home.calls.length,0);
 cache.clear();activity.session.state.identity=null;activity.watchers.find(row=>typeof row.get==='function').callback();assert.equal(activity.control.rows.value,null);assert.equal(activity.calls.length,0);
});

test('home snapshot reads respect permissions and unknown or conflicting Topup balances remain unknown',()=>{
 const calls=[],api={peekRequest:path=>{calls.push(path);return {data:path==='/sales/products'?[product(1)]:[offer(2)]};}};
 assert.deepEqual(dashboardCatalogSnapshot(api,()=>false),{products:[],offers:[]});assert.deepEqual(calls,[]);
 assert.equal(dashboardCatalogSnapshot(api,key=>key==='digital.view').offers.length,1);assert.deepEqual(calls,['/digital/offers?']);
 assert.equal(dashboardTopupAvailable([offer(2,null)]),null);assert.equal(dashboardTopupAvailable([offer(2),offer(3,'0.00')]),null);assert.equal(dashboardTopupAvailable([offer(2,'0.00')]),'0.00');
});
