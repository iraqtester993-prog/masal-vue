import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {scanNativePrinters,pairNativePrinter,nativeRequest,getNativePrinterCapabilities} from '../src/shared/native-print.js';
import {printerChoices,mergePrinterDevices} from '../src/shared/printer-registry.js';

function withBridge(implementation){
 const original=globalThis.window,calls=[];
 const bridge={postMessage(raw){const payload=JSON.parse(raw);calls.push(payload);implementation(payload,value=>this.onmessage({data:JSON.stringify({requestId:payload.requestId,...value})}));}};
 globalThis.window={DananeerPrint:bridge};
 return{calls,restore(){if(original===undefined)delete globalThis.window;else globalThis.window=original;}};
}

test('native scan streams unpaired nearby devices and resolves only once at the final response without any receipt or purchase',async()=>{
 const events=[];let finish;
 const fixture=withBridge((payload,reply)=>{if(payload.action==='capabilities')queueMicrotask(()=>reply({ok:true,bluetoothDiscoveryVersion:1}));else if(payload.action==='bluetoothScan'){queueMicrotask(()=>reply({ok:true,progress:true,state:'scanning',devices:[{name:'Unknown',address:'AA:BB:CC:DD:EE:01',bonded:false}]}));finish=reply;}});
 try{
  let completed=false;const pending=scanNativePrinters({onProgress:value=>events.push(value)}).then(value=>{completed=true;return value;});
  await new Promise(setImmediate);assert.equal(events.length,1);assert.equal(completed,false);
  assert.equal(events[0].devices[0].bonded,false);
  finish({ok:true,state:'complete',devices:events[0].devices});assert.equal((await pending).state,'complete');
  assert.deepEqual(fixture.calls.map(row=>row.action),['capabilities','bluetoothScan']);
  assert.match(fixture.calls[1].operationId,/^[A-Za-z0-9-]{16,80}$/);
  assert.ok(fixture.calls.every(row=>!('blocks'in row)&&!('jobId'in row)&&!('pin'in row)));
 }finally{fixture.restore();}
});

test('cancelling a scan targets only its operation and ignores late results while other native requests still complete',async()=>{
 let finish;
 const fixture=withBridge((payload,reply)=>{if(payload.action==='capabilities')queueMicrotask(()=>reply({ok:true,bluetoothDiscoveryVersion:1,printerName:'unchanged'}));else if(payload.action==='bluetoothScan')finish=reply;else if(payload.action==='bluetoothCancel')queueMicrotask(()=>reply({ok:true,state:'cancelled'}));});
 try{
  const controller=new AbortController(),pending=scanNativePrinters({signal:controller.signal});await new Promise(setImmediate);
  controller.abort();await assert.rejects(pending,error=>error.name==='AbortError'&&error.printState==='not_sent');
  const scan=fixture.calls.find(row=>row.action==='bluetoothScan'),cancel=fixture.calls.find(row=>row.action==='bluetoothCancel');assert.equal(cancel.operationId,scan.operationId);
  finish({ok:true,state:'complete',devices:[]});assert.equal((await getNativePrinterCapabilities()).printerName,'unchanged');
  assert.equal(fixture.calls.filter(row=>row.action==='bluetoothCancel').length,1);
 }finally{fixture.restore();}
});

test('legacy APKs truthfully require update and cannot open Android settings or attempt unsupported discovery',async()=>{
 const fixture=withBridge((_payload,reply)=>queueMicrotask(()=>reply({ok:true,appVersion:'1.4.1',printerSelectionVersion:1})));
 try{
  await assert.rejects(scanNativePrinters(),error=>error.code==='printer_discovery_unsupported'&&error.message.includes('1.4.2'));
  await assert.rejects(pairNativePrinter('AA:BB:CC:DD:EE:01'),error=>error.code==='printer_discovery_unsupported');
  assert.deepEqual(fixture.calls.map(row=>row.action),['capabilities','capabilities']);
 }finally{fixture.restore();}
});

test('pair failure preserves permission/state error, cancels monitoring, and never selects or prints the device',async()=>{
 const fixture=withBridge((payload,reply)=>queueMicrotask(()=>reply(payload.action==='capabilities'?{ok:true,bluetoothDiscoveryVersion:1}:payload.action==='bluetoothPairDevice'?{ok:false,state:'permission_denied',message:'permission denied'}:{ok:true,state:'cancelled'})));
 try{
  await assert.rejects(pairNativePrinter('aa:bb:cc:dd:ee:01'),error=>error.code==='permission_denied');
  assert.equal(fixture.calls.find(row=>row.action==='bluetoothPairDevice').address,'AA:BB:CC:DD:EE:01');
  assert.deepEqual(fixture.calls.map(row=>row.action),['capabilities','bluetoothPairDevice','bluetoothCancel']);
  await assert.rejects(pairNativePrinter('not-an-address'),/غير صالح/);
 }finally{fixture.restore();}
});

test('aborting an already dispatched print remains unknown; an undispatched aborted print remains not sent',async()=>{
 const fixture=withBridge(()=>{});
 try{
  const first=new AbortController(),pending=nativeRequest({action:'print'},{signal:first.signal});first.abort();
  await assert.rejects(pending,error=>error.name==='AbortError'&&error.printState==='unknown');
  const second=new AbortController();second.abort();await assert.rejects(nativeRequest({action:'browserPrint'},{signal:second.signal}),error=>error.printState==='not_sent');
  assert.equal(fixture.calls.length,1);
 }finally{fixture.restore();}
});

test('unknown, unpaired, disabled and LE-only devices never become an enabled direct print choice',()=>{
 const registry=[{id:'approved',name:'Feasycom',profile:'Feasycom',match:'Feasycom'},{id:'disabled',profile:'Z51',match:'Z51',enabled:false}];
 const devices=[{name:'Feasycom',address:'AA:BB:CC:DD:EE:01',bonded:false},{name:'Feasycom',address:'AA:BB:CC:DD:EE:02',bonded:true,transport:'le'},{name:'Unknown',address:'AA:BB:CC:DD:EE:03',bonded:true},{name:'Z51',address:'AA:BB:CC:DD:EE:04',bonded:true}];
 const choices=printerChoices({},devices,registry);
 assert.equal(choices.find(row=>row.address===devices[0].address).unavailable,true);
 for(const device of devices.slice(1))assert.equal(choices.find(row=>row.address===device.address).route,'browser');
 assert.equal(choices.filter(row=>row.route==='direct'&&!row.unavailable).length,0);
 const merged=mergePrinterDevices(devices,[{address:'aa:bb:cc:dd:ee:01',bonded:true,name:'Feasycom'}]);
 assert.equal(merged.length,4);assert.equal(merged[0].bonded,true);
 assert.equal(printerChoices({},merged,registry).find(row=>row.address===devices[0].address).route,'direct');
 assert.equal(mergePrinterDevices([],[{address:'invalid'}]).length,0);
 assert.equal(mergePrinterDevices([],[{name:'legacy paired',address:'AA:BB:CC:DD:EE:09'}])[0].bonded,true);
});

test('actual printer settings renders separate named direct/system sections and an in-app search area without fetching or opening settings',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),page=(await server.ssrLoadModule('/src/layouts/PosPrinterSettings.vue')).default;
  const session={state:reactive({identity:{account:{id:12,type:'pos'}}}),can:()=>true,api:{request(){throw Error('render must not fetch');}}};
  const context={};await renderToString(createSSRApp({render:()=>h(page)}).provide(portalContextKey,{session,portal:{id:'pos'},config:{}}),context);
  const html=context.teleports.body;
  assert.ok(html.includes('الطباعة المباشرة'));assert.ok(html.includes('طابعات المتصفح والنظام'));assert.ok(html.includes('أجهزة البلوتوث'));
  assert.ok(html.includes('تشغيل البلوتوث والبحث'));assert.ok(!html.includes('إعدادات بلوتوث الجهاز'));
  const source=await readFile(new URL('../src/layouts/PosPrinterSettings.vue',import.meta.url),'utf8');
  assert.ok(source.includes('cancelDiscovery();emit(\'close\')'));assert.ok(!source.includes("action:'bluetoothPair'"));
 }finally{await server.close();}
});
