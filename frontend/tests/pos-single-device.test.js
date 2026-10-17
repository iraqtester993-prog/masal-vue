import test from 'node:test';
import assert from 'node:assert/strict';
import {createSession,loginErrorMessage} from '../src/modules/auth/session.js';
import {ApiError,createApiClient} from '../src/shared/api/client.js';
import {signedPosDevicePayload,posDeviceState} from '../src/shared/pos-device-session.js';

const info={installation_id:'00000000-0000-4000-8000-000000000001',proof_public_key:'fixture-public',proof_key_kind:'android-keystore',kind:'pos',model:'SUNMI V2',manufacturer:'SUNMI',os_version:'7.1',serial:'SERIAL'};
const identity={data:{user:{id:1},account:{id:10,type:'pos'},portal:'pos',permissions:['sell.create']}};
function fixture(overrides={}){
 const calls=[],api={csrf:async()=>{calls.push(['csrf']);},me:async()=>{calls.push(['me']);return identity;},login:async payload=>{calls.push(['login',payload]);return identity;},logout:async()=>{calls.push(['logout']);},mutate:async(path,method,payload)=>{calls.push([path,method,payload]);return {data:path.endsWith('challenge')?{challenge_id:'fixture-challenge',challenge:'exact server bytes',expires_in:120}:{allowed:true}};},...overrides};
 const device={readIdentity:async()=>{calls.push(['identity']);return info;},signChallenge:async challenge=>{calls.push(['sign',challenge]);return 'DER-signature';}};
 return {api,device,calls};
}

test('POS login signs a credential-bound prelogin challenge before submitting the complete device identity',async()=>{
 const {api,device,calls}=fixture(),session=createSession('pos',api,device);
 await session.login('point','password',true);
 assert.deepEqual(calls.map(value=>value[0]),['csrf','identity','/auth/device-challenge','sign','login']);
 assert.deepEqual(calls[2],['/auth/device-challenge','POST',{login:'point',password:'password',installation_id:info.installation_id,proof_public_key:info.proof_public_key,proof_key_kind:'android-keystore'}]);
 assert.deepEqual(calls[3],['sign','exact server bytes']);
 assert.deepEqual(calls[4][1],{login:'point',password:'password',...info,app_version:'1.0.0',proof_challenge_id:'fixture-challenge',proof_signature:'DER-signature',remember:true});
 assert.equal(session.can('sell.create'),true);
});

test('remembered POS sessions prove their device before requesting identity, without needing cached account data',async()=>{
 const {api,device,calls}=fixture(),session=createSession('pos',api,device);
 await Promise.all([session.refresh(),session.refresh()]);
 assert.deepEqual(calls.map(value=>value[0]),['csrf','identity','/device/challenge','sign','/device/heartbeat','me']);
 assert.equal(calls[2][2].login,undefined);assert.equal(calls[4][2].proof_signature,'DER-signature');
 assert.equal(session.can('sell.create'),true);
});

test('first-open POS replaces a stale CSRF cookie before its signed bootstrap after server session revocation',async()=>{
 const previousFetch=globalThis.fetch,previousDocument=globalThis.document,calls=[];
 globalThis.document={cookie:'XSRF-TOKEN=stale'};
 globalThis.fetch=async(url,options)=>{
  calls.push(url);
  if(url==='/sanctum/csrf-cookie'){globalThis.document.cookie='XSRF-TOKEN=fresh';return new Response(null,{status:204});}
  if(options.method==='POST'&&options.headers['X-XSRF-TOKEN']!=='fresh')return Response.json({message:'CSRF token mismatch.'},{status:419});
  if(url==='/api/v1/device/challenge')return Response.json({data:{challenge_id:'new-session-challenge',challenge:'new session proof'}});
  if(url==='/api/v1/device/heartbeat')return Response.json({data:{allowed:true}});
  if(url==='/api/v1/auth/me')return Response.json(identity);
  throw Error('Unexpected bootstrap request: '+url);
 };
 try{
  const {device}=fixture(),session=createSession('pos',createApiClient('/api/v1'),device);
  await session.refresh();
  assert.deepEqual(calls,['/sanctum/csrf-cookie','/api/v1/device/challenge','/api/v1/device/heartbeat','/api/v1/auth/me']);
  assert.equal(session.can('sell.create'),true);assert.equal(session.state.error,'');assert.equal(session.state.checked,true);
 }finally{globalThis.fetch=previousFetch;if(previousDocument===undefined)delete globalThis.document;else globalThis.document=previousDocument;}
});

test('a transferred-away device is denied by the identity check and its actionable Arabic message is retained',async()=>{
 const message='هذه النقطة مرتبطة بجهاز آخر. اطلب من الوكيل أو مدير النظام نقل الدخول إلى هذا الجهاز.';
 const {api,device,calls}=fixture(),session=createSession('pos',api,device);
 await session.login('point','password');calls.length=0;
 api.me=async()=>{calls.push(['me']);throw new ApiError(message,423,{},'POS_LOGIN_DEVICE_MISMATCH');};
 await session.refresh();
 assert.deepEqual(calls.map(value=>value[0]),['me']);
 assert.equal(session.state.identity,null);assert.equal(session.state.checked,true);assert.equal(session.can('sell.create'),false);
 assert.equal(session.state.error,message);
 assert.equal(loginErrorMessage(new ApiError(message,423,{},'POS_LOGIN_DEVICE_MISMATCH')),message);
});

test('a known POS identity checks the server on navigation without repeating its device proof round trips',async()=>{
 const {api,device,calls}=fixture(),session=createSession('pos',api,device);
 await session.login('point','password');calls.length=0;
 await session.refresh();await session.refresh();
 assert.deepEqual(calls,[['me'],['me']]);assert.equal(session.can('sell.create'),true);
});

test('a known POS identity renews missing proof once only when the server explicitly requests it',async()=>{
 const {api,device,calls}=fixture(),session=createSession('pos',api,device);
 await session.login('point','password');calls.length=0;let reads=0;
 api.me=async()=>{calls.push(['me']);if(++reads===1)throw new ApiError('يجب إثبات الجهاز.',428,{},'POS_LOGIN_PROOF_REQUIRED');return identity;};
 await session.refresh();
 assert.deepEqual(calls.map(value=>value[0]),['me','identity','/device/challenge','sign','/device/heartbeat','me']);
 assert.equal(session.can('sell.create'),true);
 calls.length=0;api.me=async()=>{calls.push(['me']);throw new ApiError('يجب إثبات الجهاز.',428,{},'POS_LOGIN_PROOF_REQUIRED');};
 await session.refresh();
 assert.deepEqual(calls.map(value=>value[0]),['me','identity','/device/challenge','sign','/device/heartbeat','me']);
 assert.equal(session.state.identity,null);assert.equal(session.can('sell.create'),false);
});

test('missing device signing support fails closed and does not submit credentials',async()=>{
 const {api,device,calls}=fixture(),session=createSession('pos',api,{...device,readIdentity:async()=>({...info,proof_public_key:undefined,proof_unavailable:true})});
 await assert.rejects(session.login('point','password'),error=>error.code==='POS_LOGIN_PROOF_REQUIRED');
 assert.deepEqual(calls.map(value=>value[0]),['csrf']);assert.equal(session.state.identity,null);assert.equal(session.state.busy,false);
});

test('a malformed or unsigned challenge is never sent to the login endpoint',async()=>{
 for(const response of [{data:{}},{data:{challenge_id:'id',challenge:''}}]){
  const {api,device}=fixture({mutate:async()=>response});
  await assert.rejects(signedPosDevicePayload(api,device),error=>error.code==='POS_LOGIN_PROOF_REQUIRED');
 }
 const {api,device}=fixture();
 await assert.rejects(signedPosDevicePayload(api,{...device,signChallenge:async()=>''}),error=>error.code==='POS_LOGIN_PROOF_REQUIRED');
});

test('stale POS bootstrap cannot request or restore an identity after logout',async()=>{
 let release;const {api,device,calls}=fixture(),mutate=api.mutate;
 api.mutate=async(...args)=>args[0]==='/device/heartbeat'?new Promise(resolve=>{release=resolve;}):mutate(...args);
 const session=createSession('pos',api,device),pending=session.refresh();
 while(!release)await new Promise(resolve=>setImmediate(resolve));
 await session.logout();release({data:{allowed:true}});await pending;
 assert.equal(session.state.identity,null);assert.equal(calls.some(value=>value[0]==='me'),false);
});

test('changing a POS password discards the heartbeat tied to the previous session ID',async()=>{
 const {api,device}=fixture({updateOwnProfile:async()=>identity}),session=createSession('pos',api,device);
 await session.login('point','password');posDeviceState.data={allowed:true,expires_at:'future'};
 await session.updateProfile('Point',2,undefined,{password:'new-password'});
 assert.equal(posDeviceState.data,null);assert.equal(session.can('sell.create'),true);
});

test('device-related API error codes survive transport without masking credential failures',async()=>{
 const previousFetch=globalThis.fetch;
 globalThis.fetch=async()=>Response.json({message:'اطلب نقل الدخول من الوكيل.',code:'POS_LOGIN_DEVICE_MISMATCH'},{status:423});
 try{await assert.rejects(createApiClient('/api/v1').request('/auth/me'),error=>error.code==='POS_LOGIN_DEVICE_MISMATCH'&&error.status===423&&loginErrorMessage(error)==='اطلب نقل الدخول من الوكيل.');}
 finally{globalThis.fetch=previousFetch;}
 assert.equal(loginErrorMessage(new ApiError('Internal detail',422)),'تحقق من اسم الدخول وكلمة المرور.');
 assert.equal(loginErrorMessage(new ApiError('افتح التطبيق من جهازك المعتمد.',428,{},'POS_LOGIN_PROOF_REQUIRED')),'افتح التطبيق من جهازك المعتمد.');
});
