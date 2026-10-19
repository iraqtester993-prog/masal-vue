import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createSSRApp,h,onServerPrefetch,reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createServer} from 'vite';

const definition={id:'receipt-go',name:'تعريف طابعة الإيصال',profile:'GOOJPRT',match:'MTP-2',enabled:true};
const paired=[1,2,3].map(index=>({name:'MTP-2',address:`AA:BB:CC:DD:EE:0${index}`,bonded:true}));

async function settings({devices=paired,capabilities={},registry=[definition]}={},inspect=async()=>{}){
 const previousWindow=globalThis.window,calls=[],reads=[],mutations=[],events=[],storage=new Map(),state={registry:structuredClone(registry)};
 const capability={appVersion:'1.4.12',printRoute:'direct',printerMode:'bluetooth',printerName:'MTP-2',printerSelectionVersion:1,browserPrintSupported:true,bluetoothDiscoveryVersion:1,bluetoothPrinterProfiles:['GOOJPRT'],...capabilities};
 const bridge={postMessage(raw){
  const payload=JSON.parse(raw);calls.push(payload);
  assert.ok(['capabilities','printerRegistry','printerDevices','printerSelect'].includes(payload.action),`Unexpected native action: ${payload.action}`);
  assert.ok(!('blocks' in payload)&&!('jobId' in payload),'Selection must never send a receipt or print job');
  let response={ok:true,message:'تم حفظ الاختيار.'};
  if(payload.action==='capabilities')response={...response,...capability};
  if(payload.action==='printerDevices')response={...response,enabled:true,devices:structuredClone(devices)};
  if(payload.action==='printerSelect'){
   capability.printRoute=payload.browser||payload.other?'browser':'direct';
   capability.printerMode=payload.internal?'sunmi':'bluetooth';
   capability.printerName=payload.browser?'الطباعة بالمتصفح':devices.find(row=>row.address===payload.address)?.name||'SUNMI';
  }
  queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:payload.requestId,...response})}));
 }};
 globalThis.window={DananeerPrint:bridge,navigator:{userAgent:'Offline component test'},localStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},dispatchEvent:event=>{events.push(event.type);return true;}};
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const Page=(await server.ssrLoadModule('/src/layouts/PosPrinterSettings.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const session={state:reactive({identity:{account:{id:12,type:'pos'}}}),can:()=>true,api:{async request(path,options){reads.push({path,fresh:options?.fresh});assert.equal(path,'/company/app-config');assert.equal(options.fresh,true);return{data:{config:{printers:structuredClone(state.registry)}}};},mutate(...args){mutations.push(args);assert.fail('Printer selection cannot mutate a business API');}}};
  let controls,prefetchError;
  const inspected={...Page,setup(props,ctx){controls=Page.setup(props,ctx);onServerPrefetch(async()=>{try{await controls.initialize();await inspect(controls,{calls,reads,events,state});}catch(error){prefetchError=error;}});return controls;}};
  const app=createSSRApp({render:()=>h(inspected)});app.provide(portalContextKey,{session,portal:{id:'pos'},config:{}});
  const context={};await renderToString(app,context);if(prefetchError)throw prefetchError;
  assert.equal(mutations.length,0);assert.ok(reads.every(row=>row.path==='/company/app-config'&&row.fresh===true));
  assert.ok(calls.every(row=>['capabilities','printerRegistry','printerDevices','printerSelect'].includes(row.action)&&!('blocks' in row)&&!('jobId' in row)),'Every native call must remain a selection/configuration control');
  return{html:context.teleports.body,controls,calls,reads,events};
 }finally{await server.close();if(previousWindow===undefined)delete globalThis.window;else globalThis.window=previousWindow;}
}

const selects=page=>page.calls.filter(row=>row.action==='printerSelect');

test('three paired devices sharing one definition render one direct option while every device remains listed',async()=>{
 const page=await settings();
 assert.equal((page.html.match(/<option\b[^>]*value="registered:receipt-go"/g)||[]).length,1);
 assert.equal((page.html.match(/<option\b[^>]*value="bluetooth:/g)||[]).length,0,'A native device address is not a separate direct-printer definition');
 assert.equal((page.html.match(/<li\b/g)||[]).length,3);
 for(const device of paired)assert.ok(page.html.includes(`title="${device.address}"`),device.address);
 assert.ok(page.html.includes('تشغيل البلوتوث والبحث'));
 assert.equal(selects(page).length,0);
});

test('explicitly choosing the second paired device saves its address rather than the first matching definition',async()=>{
 const page=await settings({},async(c,{calls})=>{
  c.selectDevice(paired[1]);assert.equal(c.selected.value,'registered:receipt-go');assert.equal(c.selectedAddress.value,paired[1].address);
  await c.save();assert.equal(c.error.value,'');assert.equal(c.selectedAddress.value,paired[1].address);
  const sent=calls.filter(row=>row.action==='printerSelect');assert.equal(sent.length,1);assert.equal(sent[0].address,paired[1].address);assert.equal(sent[0].printerId,definition.id);assert.equal(sent[0].internal,false);assert.equal(sent[0].other,false);
 });
 assert.ok(page.reads.length>=2,'Saving must re-read the current registry');assert.deepEqual(page.events,['dananeer:printer-change']);
});

test('a definition with one paired device resolves and saves that device automatically',async()=>{
 const page=await settings({devices:[paired[2]]},async c=>{c.selected.value='registered:receipt-go';await c.save();assert.equal(c.error.value,'');});
 assert.equal(selects(page).length,1);assert.equal(selects(page)[0].address,paired[2].address);assert.equal(selects(page)[0].printerId,definition.id);
});

test('a definition matching multiple devices cannot save until a device is explicitly chosen',async()=>{
 const page=await settings({},async(c,{calls})=>{
  c.selected.value='registered:receipt-go';c.selectedAddress.value='';await c.save();
  assert.equal(calls.some(row=>row.action==='printerSelect'),false);assert.match(c.error.value,/(?:جهاز|أجهزة)/,'The ambiguity must explain choosing a device rather than silently choosing one');
 });
 assert.ok(page.html.includes('role="alert"'));assert.ok(page.html.includes('أجهزة البلوتوث'));
});

test('a fresh device snapshot cannot replace a vanished explicit address with another matching printer',async()=>{
 const page=await settings({},async(c,{calls})=>{
  c.selectDevice(paired[1]);c.discoveryProgress({devices:[paired[0],paired[2]]},{replace:true});
  await c.save();assert.equal(calls.some(row=>row.action==='printerSelect'),false);
  assert.equal(c.selectedAddress.value,paired[1].address,'A vanished explicit address must not silently change');assert.match(c.error.value,/(?:جهاز|أجهزة)/);
 });
 assert.equal((page.html.match(/<li\b/g)||[]).length,2);assert.ok(!page.html.includes(`title="${paired[1].address}"`));assert.equal(page.events.length,0);
});

test('the browser route remains available without a paired device and saves without a direct address',async()=>{
 const page=await settings({devices:[],capabilities:{printRoute:'browser',printerName:'الطباعة بالمتصفح'}},async c=>{
  assert.equal(c.selected.value,'browser');await c.save();assert.equal(c.error.value,'');
 });
 assert.equal(selects(page).length,1);assert.equal(selects(page)[0].browser,true);assert.ok(!selects(page)[0].address);assert.ok(!selects(page)[0].printerId);
 assert.equal(page.reads.length,1,'Browser selection does not need a direct-printer registry refresh');assert.ok(page.html.includes('الطباعة بالمتصفح'));
});

test('disabling a chosen definition in the fresh registry prevents any native selection',async()=>{
 const page=await settings({},async(c,{state,calls})=>{
  c.selectDevice(paired[1]);state.registry[0].enabled=false;await c.save();
  assert.ok(c.error.value);assert.equal(calls.some(row=>row.action==='printerSelect'),false);
 });
 assert.equal(page.reads.length,2);assert.equal(selects(page).length,0);assert.equal(page.events.length,0);
});

test('restoring a duplicated native device name retains its definition without guessing an address',async()=>{
 const page=await settings({},async c=>{
  assert.equal(c.selected.value,'registered:receipt-go');assert.equal(c.selectedAddress.value,'');
 });
 assert.equal(selects(page).length,0);assert.ok(page.html.includes('تعريف طابعة الإيصال'));
});
