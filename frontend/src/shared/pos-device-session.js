import {reactive} from 'vue';
import {readDeviceIdentity,signDeviceChallenge} from './device-identity.js';
import {deviceHeartbeatPayload} from '../modules/sales/sales-model.js';
import {hasNativePrinter,getNativePrinterCapabilities} from './native-print.js';
import {ApiError} from './api/client.js';
export const posDeviceState=reactive({busy:false,error:'',data:null});
let flight,scope=null,last=0,revision=0;
export function clearPosDeviceSession(){revision++;scope=null;last=0;flight=null;posDeviceState.busy=false;posDeviceState.data=null;posDeviceState.error='';}
export async function signedPosDevicePayload(api,{credentials=null,readIdentity=readDeviceIdentity,signChallenge=signDeviceChallenge}={}){
 const info=await readIdentity();
 if(!info?.installation_id||!info.proof_public_key||!['android-keystore','webcrypto'].includes(info.proof_key_kind))throw new ApiError('تعذر التحقق من هوية هذا الجهاز؛ أعد المحاولة أو حدّث التطبيق.',403,{},'POS_LOGIN_PROOF_REQUIRED');
 const metadata=deviceHeartbeatPayload(info);
 const challenge=await api.mutate(credentials?'/auth/device-challenge':'/device/challenge','POST',{...(credentials||{}),installation_id:info.installation_id,proof_public_key:info.proof_public_key,proof_key_kind:info.proof_key_kind});
 if(!challenge?.data?.challenge_id||typeof challenge.data.challenge!=='string'||!challenge.data.challenge)throw new ApiError('استجابة اعتماد الجهاز غير صالحة؛ أعد المحاولة.',403,{},'POS_LOGIN_PROOF_REQUIRED');
 const signature=await signChallenge(challenge.data.challenge);
 if(typeof signature!=='string'||!signature)throw new ApiError('تعذر توقيع اعتماد الجهاز؛ أعد المحاولة.',403,{},'POS_LOGIN_PROOF_REQUIRED');
 return {...info,...metadata,proof_challenge_id:challenge.data.challenge_id,proof_signature:signature};
}
export async function bootstrapPosDeviceSession(api,options={}){
 const payload=await signedPosDevicePayload(api,options);
 return (await api.mutate('/device/heartbeat','POST',payload)).data;
}
export async function ensurePosDeviceSession(api,accountId,{force=false}={}){
 if(scope!==accountId){clearPosDeviceSession();scope=accountId;}
 if(flight)return flight;
 if(!force&&posDeviceState.data&&Date.now()-last<20000)return posDeviceState.data;
 const expected=revision;posDeviceState.busy=true;posDeviceState.error='';
 const work=(async()=>{
  try{
   if(hasNativePrinter())await getNativePrinterCapabilities().catch(()=>{});
   const data=await bootstrapPosDeviceSession(api);
   if(expected===revision){posDeviceState.data=data;last=Date.now();}return data;
  }catch(e){if(expected===revision)posDeviceState.error=e.message;throw e;}
  finally{if(expected===revision){posDeviceState.busy=false;flight=null;}}
 })();flight=work;return work;
}
