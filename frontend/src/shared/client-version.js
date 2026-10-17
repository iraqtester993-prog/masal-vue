// Version metadata describes the client; it never grants permissions or verifies a device.
export const webAppVersion='1.0.0';
let reportedNativeVersion=null;
function numericVersion(value){const version=typeof value==='string'&&value.length<=60?value.match(/^(\d+(?:\.\d+){0,3})(?:[-+][0-9A-Za-z.-]+)?$/)?.[1]:null;return version&&version.length<=30?version:null;}
export function rememberNativeAppVersion(capabilities){reportedNativeVersion=numericVersion(capabilities?.appVersion);}
export function clientAppVersion({
  userAgent=typeof window==='undefined'?'':window.navigator?.userAgent||'',
  native=typeof window!=='undefined'&&typeof window.DananeerPrint?.postMessage==='function',
  capabilities=null,browserVersion=webAppVersion,
}={}){
  const marker=String(userAgent).match(/\bDananeerPOS\/([^\s;)]*)/);
  if(!native&&!marker)return numericVersion(browserVersion);
  return numericVersion(capabilities?.appVersion)||reportedNativeVersion||numericVersion(marker?.[1]);
}
