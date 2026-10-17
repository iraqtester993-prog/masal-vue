import test from 'node:test';
import assert from 'node:assert/strict';
import {hasNativePrinter,requiresNativePrinter,nativeRequest,getNativePrinterCapabilities,openNativePrinterSettings,openNativePrinterTest,openNativePrinterDiagnostics,printNativeReceipt} from '../src/shared/native-print.js';
test('APK with unavailable native bridge requires direct printing and reports not-sent',async()=>{
  globalThis.window={navigator:{userAgent:'Android DananeerPOS/0.7'}};
  try {
    assert.equal(hasNativePrinter(),false);
    assert.equal(requiresNativePrinter(),true);
    await assert.rejects(nativeRequest({action:'print',jobId:'sale-1-1'}),error=>error.printState==='not_sent' && /WebView/.test(error.message));
  } finally {delete globalThis.window;}
});
test('ordinary browser retains its explicit browser print path',()=>{
  globalThis.window={navigator:{userAgent:'Chrome'}};
  try {assert.equal(requiresNativePrinter(),false);} finally{delete globalThis.window;}
});
test('native printer is absent during SSR',()=>assert.equal(hasNativePrinter(),false));
test('unsupported native printers select system printing before any dispatch; unknown direct results never cause a second dispatch',async()=>{
 const saved={window:globalThis.window,document:globalThis.document,Node:globalThis.Node};globalThis.document={fonts:{ready:Promise.resolve()}};globalThis.Node={TEXT_NODE:3,ELEMENT_NODE:1};
 const element={nodeType:1,tagName:'DIV',childNodes:[{nodeType:3,textContent:'TEST RECEIPT'}]},calls=[];let route='browser';
 const bridge={postMessage(raw){const request=JSON.parse(raw);calls.push(request.action);const response=request.action==='capabilities'?{ok:true,printRoute:route,browserPrintSupported:true}:{ok:request.action==='browserPrint',state:request.action==='browserPrint'?'accepted':'unknown',message:'result'};queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:request.requestId,...response})}));}};globalThis.window={DananeerPrint:bridge};
 try{await printNativeReceipt(element,'sale-1-1');assert.deepEqual(calls,['capabilities','browserPrint']);calls.length=0;route='direct';await assert.rejects(printNativeReceipt(element,'sale-1-2'),error=>error.printState==='unknown');assert.deepEqual(calls,['capabilities','print']);}finally{for(const [key,value] of Object.entries(saved))if(value===undefined)delete globalThis[key];else globalThis[key]=value;}
});
test('native transport preserves acceptance vs failure without treating acceptance as physical success',async()=>{
  const bridge={postMessage(raw){const request=JSON.parse(raw);queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:request.requestId,ok:request.action==='accepted',state:'accepted',message:'sent'})}));}};
  globalThis.window={DananeerPrint:bridge};
  try {
    assert.equal(hasNativePrinter(),true);
    const response=await nativeRequest({action:'accepted',jobId:'sale-1-2'});
    assert.equal(response.state,'accepted');
    await assert.rejects(nativeRequest({action:'failed'}),/sent/);
  } finally {delete globalThis.window;}
});
test('native rejection preserves not-sent versus unknown and duplicate outcomes',async()=>{
  const bridge={postMessage(raw){const request=JSON.parse(raw);queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:request.requestId,ok:false,state:request.action,message:'printer unavailable'})}));}};
  globalThis.window={DananeerPrint:bridge};
  try {
    for(const state of ['not_sent','unknown','duplicate'])await assert.rejects(nativeRequest({action:state}),error=>error.printState===state);
  } finally {delete globalThis.window;}
});

test('PWA printer controls carry no sale or receipt and opened is not paper success',async()=>{
  const requests=[];
  const bridge={postMessage(raw){const request=JSON.parse(raw);requests.push(request);queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:request.requestId,ok:true,state:request.action==='capabilities'?'ready':'opened',bridgeVersion:1,paperWidthMm:58})}));}};
  globalThis.window={DananeerPrint:bridge};
  try {
    const capabilities=await getNativePrinterCapabilities();assert.equal(capabilities.bridgeVersion,1);assert.equal(capabilities.paperWidthMm,58);
    for(const control of [openNativePrinterSettings,openNativePrinterTest,openNativePrinterDiagnostics])assert.equal((await control()).state,'opened');
    assert.deepEqual(requests.map(request=>request.action),['capabilities','printerSettings','printerTest','diagnostics']);
    assert.ok(requests.every(request=>Object.keys(request).sort().join(',')==='action,requestId'));
  } finally {delete globalThis.window;}
});
