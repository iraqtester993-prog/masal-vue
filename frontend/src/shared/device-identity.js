import {hasNativePrinter,nativeRequest} from './native-print.js';
const key='dananeer:installation-id:v1';let memoryId,identityFlight,deviceSigner;
export function browserDeviceInfo(userAgent=''){
 const os=String(userAgent).match(/Android\s+([0-9.]+)/i)?.[1]||String(userAgent).match(/(?:iPhone OS|CPU OS)\s+([0-9_]+)/)?.[1]?.replaceAll('_','.');
 const model=String(userAgent).match(/Android[^;]*;\s*(?:[a-z]{2}(?:[-_][A-Z]{2})?;\s*)?([^;)]+?)\s+Build\//i)?.[1];
 const mobile=/Android|iPhone|iPad/i.test(userAgent);
 return {kind:mobile?'mobile':'browser',model:model||(/iPhone/i.test(userAgent)?'iPhone':/iPad/i.test(userAgent)?'iPad':mobile?'جهاز موبايل':'متصفح الويب'),...(os?{os_version:os}:{})};
}
export function installationId(storage,randomUUID=()=>crypto.randomUUID()){
 if(memoryId)return memoryId;
 try{const saved=storage?.getItem(key);if(/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(saved||''))return memoryId=saved;}catch{}
 memoryId=randomUUID();try{storage?.setItem(key,memoryId);}catch{}return memoryId;
}
const to64=bytes=>btoa(String.fromCharCode(...new Uint8Array(bytes)));
// WebCrypto returns IEEE P1363 r||s; Android and OpenSSL verify ASN.1 DER.
export function ecdsaSignatureDer(raw){
 const bytes=new Uint8Array(raw);if(bytes.length!==64)throw Error('توقيع الجهاز غير صالح.');
 const integer=value=>{let at=0;while(at<value.length-1&&value[at]===0)at++;const body=Array.from(value.slice(at));if(body[0]&128)body.unshift(0);return [2,body.length,...body];};
 const body=[...integer(bytes.slice(0,32)),...integer(bytes.slice(32))];return new Uint8Array([48,body.length,...body]);
}
async function browserProofKey(cryptoApi,create=true){
 if(typeof indexedDB==='undefined')throw Error('هذا المتصفح لا يدعم مخزن اعتماد الجهاز.');
 const database=await new Promise((resolve,reject)=>{let ended=false;const timer=setTimeout(()=>{ended=true;reject(Error('تأخر فتح مخزن اعتماد الجهاز.'));},5000),request=indexedDB.open('dananeer-device-proof-v1',1);const fail=error=>{clearTimeout(timer);ended=true;reject(error);};request.onupgradeneeded=()=>request.result.createObjectStore('keys');request.onsuccess=()=>{clearTimeout(timer);if(ended){request.result.close();return;}ended=true;resolve(request.result);};request.onerror=()=>fail(request.error);request.onblocked=()=>fail(Error('تعذر فتح مخزن اعتماد الجهاز.'));});
 try{
  const existing=await new Promise((resolve,reject)=>{const tx=database.transaction('keys','readonly'),request=tx.objectStore('keys').get('identity');request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(request.error);});
  if(existing)return existing;
  if(!create)return null;
  const pair=await cryptoApi.subtle.generateKey({name:'ECDSA',namedCurve:'P-256'},false,['sign','verify']);
  // One transaction prevents simultaneous first-use tabs replacing each other's key.
  return await new Promise((resolve,reject)=>{const tx=database.transaction('keys','readwrite'),store=tx.objectStore('keys'),request=store.get('identity');let record;request.onsuccess=()=>{record=request.result||pair;if(!request.result)store.put(pair,'identity');};tx.oncomplete=()=>resolve(record);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error||Error('تعذر حفظ اعتماد الجهاز.'));});
 }finally{database.close();}
}
export function createBrowserDeviceSigner({cryptoApi=globalThis.crypto,loadKey=browserProofKey}={}){
 let flight;const pair=()=>flight??=(async()=>{if(!cryptoApi?.subtle)throw Error('هذا المتصفح لا يدعم اعتماد الجهاز الآمن.');const value=await loadKey(cryptoApi);if(!value?.privateKey||value.privateKey.extractable||value.privateKey.algorithm?.name!=='ECDSA'||value.privateKey.algorithm?.namedCurve!=='P-256')throw Error('مفتاح اعتماد الجهاز غير صالح.');return value;})();
 return {async identity(){return {proof_public_key:to64(await cryptoApi.subtle.exportKey('spki',(await pair()).publicKey)),proof_key_kind:'webcrypto'};},async sign(challenge){if(typeof challenge!=='string'||!challenge||challenge.length>2048)throw Error('تحدي اعتماد الجهاز غير صالح.');const signature=await cryptoApi.subtle.sign({name:'ECDSA',hash:'SHA-256'},(await pair()).privateKey,new TextEncoder().encode(challenge));return to64(ecdsaSignatureDer(signature));}};
}
// Creating a replacement key after a transient native timeout would strand an
// already approved installation. Only a positively identified older bridge may
// enroll a new browser key; unknown or modern failing bridges must retry.
export function browserProofFallbackAllowed(capability){
 return capability?.ok===true&&Number.isSafeInteger(capability.bridgeVersion)&&capability.bridgeVersion>=1&&capability.deviceProofVersion===undefined;
}
export function readDeviceIdentity(){
 if(identityFlight)return identityFlight;
 identityFlight=(async()=>{
  let storage;try{storage=window.localStorage;}catch{}
  const id=installationId(storage),fallback={installation_id:id,...browserDeviceInfo(window.navigator?.userAgent||'')};
  if(hasNativePrinter()){
   let value;
   try{value=await nativeRequest({action:'deviceIdentity',installationId:id},{timeoutMs:5000});}catch{/* Preserve the approved signer when a native response is temporarily unavailable. */}
   if(/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(value?.installationId||'')){
    memoryId=value.installationId;try{storage?.setItem(key,memoryId);}catch{}
    Object.assign(fallback,{installation_id:memoryId,kind:value.kind==='pos'?'pos':'mobile',model:String(value.model||fallback.model).slice(0,190),manufacturer:String(value.manufacturer||'').slice(0,100),...(value.serial?{serial:String(value.serial).slice(0,190)}:{}),...(value.osVersion?{os_version:String(value.osVersion).slice(0,30)}:{})});
    // A PWA/older APK may already have enrolled its nonexportable browser key.
    // Keep that signer on upgrade: silently replacing it would strand an approved
    // device or require an insecure server-side key replacement exception.
    let previousKey;try{previousKey=await browserProofKey(globalThis.crypto,false);}catch{}
    if(previousKey){try{const signer=createBrowserDeviceSigner({loadKey:async()=>previousKey});const proof=await signer.identity();deviceSigner=signer;return {...fallback,...proof};}catch{return {...fallback,proof_unavailable:true};}}
    if(value.proofKeyKind==='android-keystore'&&typeof value.proofPublicKey==='string'){
     deviceSigner={sign:async challenge=>(await nativeRequest({action:'deviceProof',installationId:memoryId,challenge},{timeoutMs:5000})).signature};
     return {...fallback,proof_public_key:value.proofPublicKey,proof_key_kind:'android-keystore'};
    }
   }
   let capability;try{capability=await nativeRequest({action:'capabilities'},{timeoutMs:3000});}catch{}
   if(!browserProofFallbackAllowed(capability))return {...fallback,proof_unavailable:true};
  }
  try{const signer=createBrowserDeviceSigner();const proof=await signer.identity();deviceSigner=signer;return {...fallback,...proof};}
  catch{return {...fallback,proof_unavailable:true};}
 })();identityFlight.then(info=>{if(info.proof_unavailable)identityFlight=null;});return identityFlight;
}
export async function signDeviceChallenge(challenge){await readDeviceIdentity();if(!deviceSigner)throw Error('تعذر اعتماد الجهاز الآمن؛ استخدم متصفحًا يدعم التخزين الآمن أو حدّث التطبيق.');return deviceSigner.sign(challenge);}
