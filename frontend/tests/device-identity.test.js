import test from 'node:test';
import assert from 'node:assert/strict';
import {browserDeviceInfo,installationId,createBrowserDeviceSigner,ecdsaSignatureDer,browserProofFallbackAllowed,readDeviceIdentity} from '../src/shared/device-identity.js';
import {webcrypto,createPublicKey,verify} from 'node:crypto';
test('mobile metadata is optional and Android and iPhone versions are numeric',()=>{
 assert.deepEqual(browserDeviceInfo('Mozilla Android 7.1; SUNMI V2 Build/ABC'),{kind:'mobile',model:'SUNMI V2',os_version:'7.1'});
 assert.equal(browserDeviceInfo('iPhone OS 18_1 like Mac OS X').os_version,'18.1');
 assert.equal(browserDeviceInfo('Chrome desktop').kind,'browser');
});
test('device possession signs an exact server challenge with a nonexportable P256 private key',async()=>{
 const pair=await webcrypto.subtle.generateKey({name:'ECDSA',namedCurve:'P-256'},false,['sign','verify']);let loads=0;
 const signer=createBrowserDeviceSigner({cryptoApi:webcrypto,loadKey:async()=>{loads++;return pair;}});
 const identity=await signer.identity(),challenge='dananeer-device-proof-v1\naccount=8\nnonce=1234';
 const signature=await signer.sign(challenge);
 assert.equal(identity.proof_key_kind,'webcrypto');assert.equal(pair.privateKey.extractable,false);
 await assert.rejects(webcrypto.subtle.exportKey('pkcs8',pair.privateKey));
 const publicKey=createPublicKey({key:Buffer.from(identity.proof_public_key,'base64'),format:'der',type:'spki'});
 assert.equal(verify('sha256',Buffer.from(challenge),publicKey,Buffer.from(signature,'base64')),true);
 assert.equal(verify('sha256',Buffer.from(challenge+'changed'),publicKey,Buffer.from(signature,'base64')),false);
 assert.equal(loads,1);
 await assert.rejects(signer.sign('x'.repeat(2049)));
});
test('extractable or wrong-algorithm stored keys never claim device proof',async()=>{
 const pair=await webcrypto.subtle.generateKey({name:'ECDSA',namedCurve:'P-256'},true,['sign','verify']);
 await assert.rejects(createBrowserDeviceSigner({cryptoApi:webcrypto,loadKey:async()=>pair}).identity());
 assert.throws(()=>ecdsaSignatureDer(new Uint8Array(10)));
});
test('a native timeout or unavailable modern signer never silently enrolls a replacement browser key',()=>{
 assert.equal(browserProofFallbackAllowed(null),false);
 assert.equal(browserProofFallbackAllowed({ok:true,bridgeVersion:3,deviceProofVersion:1}),false);
 assert.equal(browserProofFallbackAllowed({ok:false,bridgeVersion:3}),false);
 assert.equal(browserProofFallbackAllowed({ok:true}),false);
 assert.equal(browserProofFallbackAllowed({ok:true,bridgeVersion:3}),true);
});
test('DER signature encoding preserves signed integers and leading zeros',()=>{
 const raw=new Uint8Array(64);raw[0]=128;raw[31]=1;raw[63]=1;
 const der=ecdsaSignatureDer(raw);
 assert.equal(der[0],48);assert.equal(der[2],2);assert.equal(der[3],33);assert.equal(der[4],0);assert.equal(der[5],128);
 assert.deepEqual(Array.from(der.slice(-3)),[2,1,1]);
});
test('installation identity survives normal reloads without reading a phone identifier',()=>{
 const data=new Map(),storage={getItem:k=>data.get(k),setItem:(k,v)=>data.set(k,v)};
 const id=installationId(storage,()=> '00000000-0000-4000-8000-000000000001');
 assert.equal(installationId(storage,()=>assert.fail('must reuse identity')),id);
 assert.equal(data.size,1);
});
test('failed modern native identity returns unavailable and never enrolls a web replacement',async()=>{
 const previous=globalThis.window,actions=[];
 const bridge={postMessage(raw){const request=JSON.parse(raw);actions.push(request.action);const response=request.action==='capabilities'?{ok:true,bridgeVersion:3,deviceProofVersion:1}:{ok:false,state:'not_sent',message:'Native temporarily unavailable'};bridge.onmessage({data:JSON.stringify({...response,requestId:request.requestId})});}};
 globalThis.window={DananeerPrint:bridge,navigator:{userAgent:'DananeerPOS/1.4.0'},localStorage:{getItem:()=>null,setItem:()=>{}}};
 try{const result=await readDeviceIdentity();assert.equal(result.proof_unavailable,true);assert.equal(result.proof_public_key,undefined);assert.deepEqual(actions,['deviceIdentity','capabilities']);}
 finally{if(previous===undefined)delete globalThis.window;else globalThis.window=previous;}
});
