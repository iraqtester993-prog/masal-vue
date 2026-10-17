import test from 'node:test';
import assert from 'node:assert/strict';
import {enabledPrinters,matchPrinter,printerChoices} from '../src/shared/printer-registry.js';
import {configurePrinterRegistry,printPreparedNativeReceipt,selectPrinterChoice,rememberPrinterRoute,getNativePrinterCapabilities} from '../src/shared/native-print.js';

test('legacy printer types stay enabled and only active matching Bluetooth profiles are selected',()=>{
 const rows=[{id:'disabled',enabled:false,profile:'GOOJPRT',match:'JingPu'},{id:'internal',profile:'sunmi',match:'SUNMI'},{id:'active',profile:'Feasycom',match:'Feasycom|FSC'},{id:'zero',enabled:0,profile:'Z51',match:'Z51'}];
 assert.equal(enabledPrinters(rows).length,2);
 assert.equal(matchPrinter('JingPu Printer',rows),null);
 assert.equal(matchPrinter('SUNMI',rows),null);
 assert.equal(matchPrinter('FSC-BT_123',rows).id,'active');
 assert.equal(matchPrinter('Unknown Thermal',rows),null);
});

test('the browser choice is permanent even with no Bluetooth or internal printer, and internal detection survives a saved browser route',()=>{
 assert.deepEqual(printerChoices({},[],[]).map(row=>row.key),['browser']);
 const capability={printRoute:'browser',internalPrinterAvailable:true,internalPrinterName:'SUNMI V2'};
 const registry=[{id:'go',profile:'GOOJPRT',match:'JingPu'}];
 const choices=printerChoices(capability,[{name:'JingPu',address:'one'},{name:'Unknown',address:'two'},{name:'duplicate',address:'two'}],registry);
 assert.deepEqual(choices.map(row=>row.key),['internal','bluetooth:one','bluetooth:two','browser']);
 assert.equal(choices[1].route,'direct');assert.equal(choices[2].route,'browser');
 assert.ok(!printerChoices({internalPrinterAvailable:false,printerMode:'sunmi',printRoute:'direct'},[],[]).some(row=>row.kind==='internal'));
});

test('browser selection and printing never require a Bluetooth device, including the existing 1.1 bridge',async()=>{
 const oldWindow=globalThis.window,calls=[],values=new Map();let modern=true;
 globalThis.window={localStorage:{getItem:k=>values.get(k),setItem:(k,v)=>values.set(k,v),removeItem:k=>values.delete(k)},DananeerPrint:{postMessage(raw){const p=JSON.parse(raw);calls.push(p);queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:p.requestId,ok:true,printerSelectionVersion:modern?1:0,printRoute:'direct',printerMode:'sunmi',printerName:'SUNMI',browserPrintSupported:true,message:'saved'})}));}}};
 try{
  await selectPrinterChoice({kind:'browser'});
  assert.deepEqual(calls.map(row=>row.action),['capabilities','printerSelect']);assert.equal(calls[1].browser,true);assert.ok(!calls[1].address);
  calls.length=0;await printPreparedNativeReceipt([{text:'existing receipt'}],'sale-9-1');
  assert.deepEqual(calls.map(row=>row.action),['capabilities','browserPrint']);
  modern=false;calls.length=0;await selectPrinterChoice({kind:'browser'});
  assert.deepEqual(calls.map(row=>row.action),['capabilities']);
  assert.equal((await getNativePrinterCapabilities()).printRoute,'browser');
  calls.length=0;await selectPrinterChoice({kind:'internal',route:'direct'});
  assert.equal(calls[0].internal,true);assert.equal((await getNativePrinterCapabilities()).printRoute,'direct');
 }finally{rememberPrinterRoute('direct');if(oldWindow===undefined)delete globalThis.window;else globalThis.window=oldWindow;}
});

test('disabled types are removed before printing and a registry failure never dispatches a receipt',async()=>{
 const oldWindow=globalThis.window,calls=[];let offline=false;
 globalThis.window={DananeerPrint:{postMessage(raw){const row=JSON.parse(raw);calls.push(row);queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:row.requestId,ok:true,printRoute:'browser',browserPrintSupported:true,message:'accepted'})}));}}};
 const dispose=configurePrinterRegistry({async request(){if(offline)throw Error('offline');return{data:{config:{printers:[{id:'one',enabled:false},{id:'two',enabled:true}]}}};}});
 try{
  await printPreparedNativeReceipt([{text:'same receipt'}],'sale-8-copy-00000000-0000-4000-8000-000000000000');
  assert.deepEqual(calls.map(row=>row.action),['printerRegistry','capabilities','browserPrint']);
  assert.deepEqual(calls[0].printers,[{id:'two',enabled:true}]);
  assert.equal(calls[2].jobId,'sale-8-copy-00000000-0000-4000-8000-000000000000');
  offline=true;calls.length=0;
  await assert.rejects(printPreparedNativeReceipt([{text:'same receipt'}],'sale-8-1'),e=>e.printState==='not_sent');
  assert.equal(calls.length,0);
 }finally{dispose();if(oldWindow===undefined)delete globalThis.window;else globalThis.window=oldWindow;}
});

test('registered names remain visible when switching away from internal or Bluetooth is off',()=>{
 const registry=[{id:'sun',name:'SUNMI',profile:'sunmi'},{id:'goo',name:'GOOJPRT',profile:'GOOJPRT',match:'JingPu'},{id:'off',name:'disabled',profile:'Z51',enabled:false}];
 const result=printerChoices({printRoute:'browser',internalPrinterAvailable:true,internalPrinterMode:'sunmi',internalPrinterName:'V2'},[],registry);
 assert.deepEqual(result.map(row=>row.key),['internal','registered:goo','browser']);
 assert.equal(result[1].unavailable,true);
 const disconnected=printerChoices({internalPrinterAvailable:false},[],registry);
 assert.deepEqual(disconnected.map(row=>row.name),['SUNMI','GOOJPRT','الطباعة بالمتصفح']);
 assert.ok(disconnected.slice(0,2).every(row=>row.unavailable));
});

test('MTP-2 and YHD-8006 aliases normalize compatibility characters without matching adjacent model numbers',()=>{
 const rows=[{id:'go',profile:'GOOJPRT',match:'GOOJPRT|JingPu|MTP-2|MTP-II'},{id:'yhd',profile:'YHD-8006',match:'YHD-8006|YHD8006'}];
 for(const name of ['MTP-2','MTP-II','MTP-Ⅱ','ｍｔｐ－２','Printer MTP_II BT','MTP 2'])assert.equal(matchPrinter(name,rows)?.id,'go',name);
 for(const name of ['YHD-8006','YHD8006','ｙｈｄ－８００６','Printer YHD_8006 BT','YHD 8006'])assert.equal(matchPrinter(name,rows)?.id,'yhd',name);
 for(const name of ['MTP-20','MTP-3','MTP-II0','MTP-2A','MTP-2ß','XMTP-2','YHD80060','YHD-8006A','XYHD8006','Unknown Printer001'])assert.equal(matchPrinter(name,rows),null,name);
 assert.equal(matchPrinter('MTP-Ⅱ',[{id:'alias',profile:'GOOJPRT',match:'MTP-2'}])?.id,'alias');
 assert.equal(matchPrinter('MTP-2',[{id:'alias',profile:'GOOJPRT',match:'MTP-Ⅱ'}])?.id,'alias');
});

test('explicit existing administrator aliases and legacy brand substrings keep their original routing',()=>{
 const rows=[{id:'custom',profile:'GOOJPRT',match:'YHD-5801'},{id:'fsc',profile:'Feasycom',match:'FSC'},{id:'brand',profile:'GOOJPRT',match:'GOOJPRT|JingPu'}];
 for(const [name,id] of [['YHD-5801','custom'],['FSC-BT_123','fsc'],['GOOJPRTPrinter','brand'],['JingPuPrinter','brand']]){
  assert.equal(matchPrinter(name,rows)?.id,id);
  assert.equal(printerChoices({},[{name,address:id}],rows).find(choice=>choice.address===id)?.route,'direct');
 }
});

test('new YHD direct routing requires native advertised support while old APKs retain the registered type and browser option',()=>{
 const rows=[{id:'yhd',name:'YHD-8006',profile:'YHD-8006',match:'YHD-8006|YHD8006'}],device={name:'YHD-8006',address:'AA:BB:CC:DD:EE:01',bonded:true};
 const old=printerChoices({appVersion:'1.4.6'},[device],rows);
 assert.equal(old.find(choice=>choice.address===device.address).route,'browser');
 assert.ok(old.some(choice=>choice.key==='registered:yhd'&&choice.unavailable));
 assert.ok(old.some(choice=>choice.key==='browser'));
 const supported={bluetoothPrinterProfiles:['GOOJPRT','Feasycom','Z51','YHD-8006']};
 const modern=printerChoices(supported,[device],rows);
 assert.equal(modern.find(choice=>choice.address===device.address).route,'direct');
 assert.equal(modern.find(choice=>choice.address===device.address).printerId,'yhd');
 assert.ok(!modern.some(choice=>choice.key==='registered:yhd'));
 assert.equal(printerChoices({bluetoothPrinterProfiles:[]},[device],rows).find(choice=>choice.address===device.address).route,'browser');
 assert.equal(printerChoices({bluetoothPrinterProfiles:['GOOJPRT']},[device],rows).find(choice=>choice.address===device.address).route,'browser');
 assert.equal(printerChoices(supported,[{...device,transport:'le'}],rows).find(choice=>choice.address===device.address).route,'browser');
 assert.equal(printerChoices(supported,[{...device,name:'Printer001'}],rows).find(choice=>choice.address===device.address).route,'browser');
 assert.equal(printerChoices(supported,[{...device,bonded:false}],rows).find(choice=>choice.address===device.address).unavailable,true);
 assert.equal(matchPrinter(device.name,[{...rows[0],enabled:false}]),null);
});
