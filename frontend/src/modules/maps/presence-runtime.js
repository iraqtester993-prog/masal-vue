import {errorText} from '../finance/finance-model.js';
import {geolocationPayload,identityKey,requiresLocation} from './maps-model.js';

export function createPresenceRuntime({api,onState=()=>{},onUnauthorized=async()=>{},environment}) {
  const env=environment||{surface:window,document,geolocation:navigator.geolocation,permissions:navigator.permissions,now:()=>Date.now(),setInterval:(callback,delay)=>window.setInterval(callback,delay),clearInterval:id=>window.clearInterval(id)};
  let storage;
  try { storage = environment ? environment.storage : window.localStorage; } catch { /* Browser permission remains authoritative without storage. */ }
  const consentKey = () => `masal.location-sharing.v1:${key}`;
  function rememberedChoice(){try{return storage?.getItem(consentKey()) ?? null;}catch{return null;}}
  function rememberChoice(enabled){if(!key)return;try{storage?.setItem(consentKey(),enabled?'enabled':'disabled');}catch{/* Storage may be unavailable in private browsing. */}}
  let generation=0,key='',controller=null,watchId=null,watchGeneration=0,permissionObject=null,disposed=false,lastUpload=-Infinity,uploading=false,pendingLocation=null,heartbeatBusy=false,checking=false,consentVersion=null,stopPromise=null,pendingRevoke=false,autoResumeBlocked=false,autoResumeEligible=false;
  const state={required:false,ready:false,sharing:false,permission:'',status:'',checking:false,initializing:false};
  function publish(values){Object.assign(state,values);onState({...state});}
  function current(expected){return !disposed&&expected===generation&&!!key;}
  async function failure(cause,expected){if(!current(expected)||cause.name==='AbortError')return;publish({status:errorText(cause)});if([401,403].includes(cause.status))await onUnauthorized(cause);}
  function clearWatch(){watchGeneration++;if(watchId!==null)env.geolocation?.clearWatch(watchId);watchId=null;pendingLocation=null;uploading=false;lastUpload=-Infinity;}
  async function revoke(expected){if(!current(expected)||!pendingRevoke)return;await api.disconnect(controller.signal);if(current(expected)){pendingRevoke=false;consentVersion=null;}}
  async function heartbeat(){const expected=generation;if(!current(expected)||env.document.visibilityState==='hidden'||heartbeatBusy)return;heartbeatBusy=true;try{if(pendingRevoke)await revoke(expected);if(current(expected))await api.heartbeat({},controller.signal);}catch(cause){await failure(cause,expected);}finally{if(current(expected))heartbeatBusy=false;}}
  async function refreshOwn(){const expected=generation;if(!current(expected)||checking)return;checking=true;publish({checking:true});try{const response=await api.own(controller.signal);if(current(expected)){if(watchId!==null&&consentVersion!==response.data.consent_version){autoResumeBlocked=true;clearWatch();publish({sharing:false,status:'مشاركة الموقع متوقفة؛ اضغط تفعيل الموقع مجددًا'});}consentVersion=response.data.consent_version;const accepted=!pendingRevoke&&state.permission!=='denied'&&response.data.location_ready===true;publish({required:response.data.location_required===true,ready:accepted,status:accepted?'مشاركة الموقع مفعّلة':state.status});if(response.data.location_ready&&state.permission==='denied')stopLocationSharing('إذن الموقع محظور؛ اسمح به من إعدادات المتصفح');}}catch(cause){await failure(cause,expected);}finally{if(current(expected)){checking=false;publish({checking:false});}}}
  async function checkPermission(){const expected=generation;try{const permission=await env.permissions?.query({name:'geolocation'});if(!current(expected)||!permission)return;if(permissionObject)permissionObject.onchange=null;permissionObject=permission;const changed=()=>{if(!current(expected))return;publish({permission:permission.state});if((state.sharing||state.ready)&&(permission.state==='denied'||permission.state==='prompt'))stopLocationSharing(permission.state==='denied'?'إذن الموقع محظور؛ اسمح به من إعدادات المتصفح':'تم سحب إذن الموقع؛ اضغط تفعيل الموقع مجددًا');};permission.onchange=()=>{changed();resumeLocation(expected);};publish({permission:permission.state});if(permission.state==='denied'&&(state.sharing||state.ready))changed();}catch{/* Permission introspection is optional; the explicit geowatch remains authoritative. */}}
  async function upload(position,expected,watchExpected){
    if(!current(expected)||watchExpected!==watchGeneration||!state.sharing)return;
    pendingLocation=position;if(uploading||env.now()-lastUpload<20000)return;uploading=true;const sample=pendingLocation;pendingLocation=null;lastUpload=env.now();
    try{const body={...geolocationPayload(sample,env.now()),consent_version:consentVersion},response=await api.locate(body,controller.signal);if(current(expected)&&watchExpected===watchGeneration){if(response.data.location_ready===true)rememberChoice(true);publish({ready:response.data.location_ready===true,permission:'granted',status:'مشاركة الموقع مفعّلة — آخر موقع محفوظ في السيرفر'});}}
    catch(cause){if(current(expected)&&watchExpected===watchGeneration){if(cause.status===409){autoResumeBlocked=true;clearWatch();publish({sharing:false,ready:false});}await failure(cause,expected);}}finally{if(current(expected)&&watchExpected===watchGeneration)uploading=false;}
  }
  async function startLocationSharing(){
    if(disposed||!key||state.sharing)return;
    if(!env.geolocation){publish({status:'تحديد الموقع غير مدعوم'});return;}
    autoResumeBlocked=false;clearWatch();publish({sharing:true,status:state.permission==='granted'?'جارٍ تحديد الموقع تلقائيًا…':'جارٍ طلب إذن الموقع…'});const expected=generation,watchExpected=watchGeneration;
    try{if(stopPromise)await stopPromise;if(pendingRevoke)await revoke(expected);const response=await api.own(controller.signal);if(!current(expected)||watchExpected!==watchGeneration)return;consentVersion=response.data.consent_version;if(!Number.isInteger(consentVersion)||consentVersion<1)throw new Error('تعذر تأكيد مشاركة الموقع؛ أعد المحاولة.');}
    catch(cause){if(current(expected)&&watchExpected===watchGeneration){publish({sharing:false,ready:false});await failure(cause,expected);}return;}
    try{watchId=env.geolocation.watchPosition(position=>upload(position,expected,watchExpected),error=>{if(!current(expected)||watchExpected!==watchGeneration)return;publish({permission:error.code===1?'denied':state.permission});stopLocationSharing(error.code===1?'لم يُسمح بالوصول للموقع':error.code===3?'انتهت مهلة تحديد الموقع؛ أعد المحاولة':'الموقع غير متاح حاليًا',error.code===1);},{enableHighAccuracy:true,maximumAge:30000,timeout:20000});}
    catch(cause){stopLocationSharing(errorText(cause));}
  }
  function stopLocationSharing(status='مشاركة الموقع متوقفة',remember=true){
    if(remember)rememberChoice(false);
    autoResumeBlocked=true;clearWatch();publish({sharing:false,ready:false,status});const expected=generation;
    if(current(expected)){pendingRevoke=true;stopPromise=(async()=>{try{await revoke(expected);}catch(cause){await failure(cause,expected);}finally{if(current(expected))stopPromise=null;}})();return stopPromise;}
  }
  async function setIdentity(next){
    if(disposed)return;const nextKey=identityKey(next);if(nextKey===key)return;
    generation++;controller?.abort();clearWatch();if(permissionObject)permissionObject.onchange=null;permissionObject=null;key=nextKey;controller=new AbortController();checking=false;heartbeatBusy=false;consentVersion=null;pendingRevoke=false;stopPromise=null;autoResumeBlocked=rememberedChoice()==='disabled';autoResumeEligible=requiresLocation(next);
    publish({required:requiresLocation(next),ready:false,sharing:false,permission:'',status:'',checking:false,initializing:!!key});
    if(key){const expected=generation;await Promise.allSettled([refreshOwn(),heartbeat(),checkPermission()]);if(current(expected)){await resumeLocation(expected);if(current(expected))publish({initializing:false});}}
  }
  async function resumeLocation(expected){const allowed=state.permission==='granted'||(state.permission===''&&rememberedChoice()==='enabled');if(current(expected)&&autoResumeEligible&&state.required&&allowed&&!autoResumeBlocked&&!state.sharing&&env.document.visibilityState==='visible')await startLocationSharing();}
  async function visible(){if(env.document.visibilityState==='visible'){const expected=generation;await Promise.allSettled([heartbeat(),refreshOwn(),checkPermission()]);await resumeLocation(expected);}}
  function pagehide(){if(key&&!disposed)api.disconnect(undefined,{keepalive:true}).catch(()=>{});clearWatch();publish({sharing:false,ready:false});}
  const timer=env.setInterval(()=>{heartbeat();if(pendingLocation&&!uploading)upload(pendingLocation,generation,watchGeneration);},60000);
  env.document.addEventListener('visibilitychange',visible);env.surface.addEventListener('focus',visible);env.surface.addEventListener('pagehide',pagehide);
  function dispose(){if(disposed)return;pagehide();disposed=true;generation++;controller?.abort();clearWatch();if(permissionObject)permissionObject.onchange=null;permissionObject=null;env.clearInterval(timer);env.document.removeEventListener('visibilitychange',visible);env.surface.removeEventListener('focus',visible);env.surface.removeEventListener('pagehide',pagehide);key='';}
  return {state,setIdentity,refreshOwn,startLocationSharing,stopLocationSharing,dispose};
}
