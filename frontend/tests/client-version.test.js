import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {parse} from '@vue/compiler-sfc';
import {reactive,computed,ref} from 'vue';
import {clientAppVersion,rememberNativeAppVersion} from '../src/shared/client-version.js';
import {deviceHeartbeatPayload} from '../src/modules/sales/sales-model.js';
import {getNativePrinterCapabilities,hasNativePrinter,nativeRequest} from '../src/shared/native-print.js';

test('the normal browser reports its web version while every recognized APK reports its own actual version',()=>{
  rememberNativeAppVersion(null);
  assert.equal(clientAppVersion({userAgent:'Chrome',native:false}),'1.0.0');
  for(const version of ['0.4','0.9'])assert.equal(clientAppVersion({userAgent:`Android Chrome DananeerPOS/${version}`,native:false}),version);
  assert.equal(clientAppVersion({userAgent:'Android DananeerPOS/0.9',native:true,capabilities:{appVersion:'0.9.0-test'}}),'0.9.0');
  for(const token of ['', 'unknown','0.9.0.1.2','javascript:1'])assert.equal(clientAppVersion({userAgent:`Android DananeerPOS/${token}`,native:true}),null);
  assert.equal(clientAppVersion({userAgent:'Android',native:true}),null);
});

test('actual bridge capabilities refine the device and presence metadata without extending any permission',async()=>{
  const previous=Object.getOwnPropertyDescriptor(globalThis,'window'),requests=[];
  const bridge={postMessage(raw){const request=JSON.parse(raw);requests.push(request);queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:request.requestId,ok:true,appVersion:'0.9.0-test',state:'ready'})}));}};
  Object.defineProperty(globalThis,'window',{value:{navigator:{userAgent:'Android DananeerPOS/0.9'},DananeerPrint:bridge},configurable:true});
  try{
    assert.equal(deviceHeartbeatPayload({serial:null,os_version:null}).app_version,'0.9');
    await getNativePrinterCapabilities();
    assert.equal(deviceHeartbeatPayload({serial:' A ',os_version:' 7.0 '}).app_version,'0.9.0');
    assert.deepEqual(requests.map(request=>request.action),['capabilities']);
    assert.deepEqual(Object.keys(requests[0]).sort(),['action','requestId']);
    assert.ok(!Object.hasOwn(deviceHeartbeatPayload({}),'permissions'));
  }finally{rememberNativeAppVersion(null);if(previous)Object.defineProperty(globalThis,'window',previous);else delete globalThis.window;}
});

test('an APK with no version metadata cannot silently renew its required device session using the web version',()=>{
  const previous=Object.getOwnPropertyDescriptor(globalThis,'window');rememberNativeAppVersion(null);
  Object.defineProperty(globalThis,'window',{value:{navigator:{userAgent:'Android'},DananeerPrint:{postMessage(){throw Error('No native request is needed for metadata');}}},configurable:true});
  try{assert.throws(()=>deviceHeartbeatPayload({}),/تعذر تحديد إصدار/);}finally{if(previous)Object.defineProperty(globalThis,'window',previous);else delete globalThis.window;}
});

test('the actual presence heartbeat wrapper shares the APK version resolver and omits unknown native metadata',async()=>{
  const source=(await readFile(new URL('../src/modules/maps/PresenceController.vue',import.meta.url),'utf8'));
  const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
  const calls=[],previous=Object.getOwnPropertyDescriptor(globalThis,'window');rememberNativeAppVersion(null);
  const base={heartbeat:async(body,signal)=>{calls.push({body,signal});}};
  const scope={reactive,usePortal:()=>({session:{api:{},state:{identity:null}}}),defineProps:()=>({deviceModel:'actual-model',appVersion:'1.0.0'}),defineEmits:()=>()=>{},createMapsApi:()=>base,clientAppVersion,onMounted(){},onBeforeUnmount(){},watch(){},defineExpose(){}};
  runInNewContext(script+'\nglobalThis.heartbeat=api.heartbeat;',scope);
  const signal=new AbortController().signal;
  try{
    Object.defineProperty(globalThis,'window',{value:{navigator:{userAgent:'Android DananeerPOS/0.4'}},configurable:true});await scope.heartbeat({},signal);
    assert.deepEqual({...calls[0].body},{device_model:'actual-model',app_version:'0.4'});assert.equal(calls[0].signal,signal);
    Object.defineProperty(globalThis,'window',{value:{navigator:{userAgent:'Android'},DananeerPrint:{postMessage(){}}},configurable:true});await scope.heartbeat({},signal);
    assert.deepEqual({...calls[1].body},{device_model:'actual-model'});
    Object.defineProperty(globalThis,'window',{value:{navigator:{userAgent:'Chrome'}},configurable:true});await scope.heartbeat({},signal);
    assert.deepEqual({...calls[2].body},{device_model:'actual-model',app_version:'1.0.0'});
  }finally{if(previous)Object.defineProperty(globalThis,'window',previous);else delete globalThis.window;}
});

test('automatic registration waits for bridge version metadata and never fabricates a native version',async()=>{
 const {ensurePosDeviceSession,clearPosDeviceSession,posDeviceState}=await import('../src/shared/pos-device-session.js');
 const previous=Object.getOwnPropertyDescriptor(globalThis,'window');
 try{
  for(const version of ['1.1.0-test',null]){
   clearPosDeviceSession();rememberNativeAppVersion(null);const calls=[],controls=[];
   const bridge={postMessage(raw){const request=JSON.parse(raw);controls.push(request.action);const result=request.action==='deviceIdentity'?{installationId:'00000000-0000-4000-8000-000000000001',proofPublicKey:'fixture-public',proofKeyKind:'android-keystore'}:request.action==='deviceProof'?{signature:'fixture-signature'}:{appVersion:version,bridgeVersion:3,deviceProofVersion:1};queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:request.requestId,ok:true,...result})}));}};
   Object.defineProperty(globalThis,'window',{value:{navigator:{userAgent:'Android'},DananeerPrint:bridge},configurable:true});
   const api={mutate:async(path,method,body)=>{calls.push({path,method,body});return{data:path==='/device/challenge'?{challenge_id:'fixture-proof',challenge:'exact-challenge'}:{allowed:true}};}};
   if(version){await ensurePosDeviceSession(api,12);assert.deepEqual(calls.map(call=>call.path),['/device/challenge','/device/heartbeat']);assert.equal(calls[1].body.app_version,'1.1.0');assert.ok(calls[1].body.installation_id);assert.equal(calls[1].body.proof_signature,'fixture-signature');}
   else{await assert.rejects(ensurePosDeviceSession(api,12),/تعذر تحديد إصدار/);assert.equal(calls.length,0);assert.match(posDeviceState.error,/تعذر تحديد إصدار/);}
   assert.ok(controls.includes('capabilities'));assert.ok(!controls.includes('print'));
  }
 }finally{clearPosDeviceSession();rememberNativeAppVersion(null);if(previous)Object.defineProperty(globalThis,'window',previous);else delete globalThis.window;}
});

test('an unanswered capabilities request proves no printer dispatch, while a print request timeout remains unknown',async()=>{
  const previous=Object.getOwnPropertyDescriptor(globalThis,'window');const actions=[];
  Object.defineProperty(globalThis,'window',{value:{DananeerPrint:{postMessage:raw=>actions.push(JSON.parse(raw).action)}},configurable:true});
  try{
    await assert.rejects(nativeRequest({action:'capabilities'},{timeoutMs:5}),error=>error.printState==='not_sent');
    await assert.rejects(nativeRequest({action:'print',jobId:'sale-1-1',blocks:[{text:'TEST'}]},{timeoutMs:5}),error=>error.printState==='unknown');
    assert.deepEqual(actions,['capabilities','print']);
  }finally{if(previous)Object.defineProperty(globalThis,'window',previous);else delete globalThis.window;}
});
