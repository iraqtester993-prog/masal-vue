const legacyBluetoothProfiles=['GOOJPRT','Feasycom','Z51'];
const bluetoothProfiles=new Set([...legacyBluetoothProfiles,'YHD-8006']);
export function enabledPrinters(rows=[]){return rows.filter(row=>row.enabled!==false&&row.enabled!==0);}
function identity(value){return String(value||'').normalize('NFKC').toUpperCase();}
function normalize(value){return identity(value).toLowerCase().replace(/[^a-z0-9]/g,'');}
function matches(name,pattern){
 const key=normalize(pattern);
 if(key==='mtp2'||key==='mtpii')return /(?:^|[^A-Z0-9])MTP[-_\s]*(?:2|II)(?![A-Z0-9])/.test(identity(name));
 if(key==='yhd8006')return /(?:^|[^A-Z0-9])YHD[-_\s]*8006(?![A-Z0-9])/.test(identity(name));
 return key.length>=3&&normalize(name).includes(key);
}
export function matchPrinter(name,rows=[]){
 return enabledPrinters(rows).find(row=>bluetoothProfiles.has(row.profile)&&String(row.match||'').split('|').some(pattern=>matches(name,pattern)))||null;
}
export function printerChoices(capability={},devices=[],registry=[]){
 const internalModes=['sunmi','rovoo','zcs'];
 const detected=capability.internalPrinterAvailable===true||(capability.internalPrinterAvailable===undefined&&internalModes.includes(capability.printerMode)&&(capability.nativePrintRoute||capability.printRoute)==='direct');
 const internal=enabledPrinters(registry).find(row=>row.profile===capability.internalPrinterMode);
 const choices=detected?[{key:'internal',name:capability.internalPrinterName||capability.nativePrinterName||capability.printerName||'طابعة الجهاز الداخلية',registryName:internal?.name||'',kind:'internal',route:'direct'}]:[];
 const supportedBluetoothProfiles=new Set(Array.isArray(capability.bluetoothPrinterProfiles)?capability.bluetoothPrinterProfiles:legacyBluetoothProfiles);
 const seen=new Set();
 for(const row of devices){if(!row.address||seen.has(row.address))continue;seen.add(row.address);const matched=row.transport==='le'?null:matchPrinter(row.name,registry),profile=matched&&supportedBluetoothProfiles.has(matched.profile)?matched:null;choices.push({key:'bluetooth:'+row.address,name:row.name||'طابعة بلوتوث',registryName:profile?.name||profile?.profile||'',kind:'bluetooth',route:profile?'direct':'browser',address:row.address,printerId:profile?.id||'',unavailable:row.bonded===false,bonded:row.bonded!==false});}
 for(const row of enabledPrinters(registry)){
  if(bluetoothProfiles.has(row.profile)){
   if(!choices.some(choice=>choice.printerId===row.id))choices.push({key:'registered:'+row.id,name:row.name||row.profile,kind:'registered',route:'direct',unavailable:true});
  }else if(!choices.some(choice=>choice.kind==='internal'&&(row.profile===capability.internalPrinterMode||String(capability.internalPrinterName||'').toLowerCase().includes(String(row.profile||'').toLowerCase()))))choices.push({key:'registered:'+row.id,name:row.name||row.profile,kind:'registered',route:'direct',unavailable:true});
 }
 choices.push({key:'browser',name:'الطباعة بالمتصفح',kind:'browser',route:'browser'});
 return choices;
}
export function mergePrinterDevices(previous=[],updates=[]){
 const result=new Map();
 for(const row of [...previous,...updates]){
  if(!/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/i.test(String(row?.address||'')))continue;
  const address=row.address.toUpperCase(),old=result.get(address);
  result.set(address,{...old,...row,address,name:row.name||old?.name||'جهاز بلوتوث',bonded:row.bonded??old?.bonded??true});
 }
 return [...result.values()].slice(0,100);
}

function availableDirectDevices(definition){
 const seen=new Set();
 return (definition.deviceChoices||[]).filter(choice=>{
  if(choice.kind!=='bluetooth'||choice.route!=='direct'||choice.printerId!==definition.printerId||choice.unavailable||choice.bonded!==true||!choice.address)return false;
  const address=String(choice.address).trim().toUpperCase();
  if(seen.has(address))return false;
  seen.add(address);return true;
 });
}

// Definitions are display options; their device candidates retain the original
// Bluetooth identities used by the existing selection and native routing code.
export function directPrinterDefinitions(choices=[],registry=[]){
 const direct=choices.filter(choice=>choice.route==='direct');
 const definitions=[],internal=direct.find(choice=>choice.kind==='internal');
 if(internal)definitions.push({key:'internal',printerId:internal.printerId||'',name:internal.registryName||internal.name,kind:'internal',route:'direct',unavailable:!!internal.unavailable,deviceChoices:[internal]});
 const seen=new Set();
 for(const row of enabledPrinters(registry)){
  if(!row.id||seen.has(row.id))continue;
  seen.add(row.id);
  const key='registered:'+row.id;
  const devices=direct.filter(choice=>choice.kind==='bluetooth'&&choice.printerId===row.id);
  // printerChoices already omits registered placeholders for a detected
  // internal definition. Do not recreate those omitted rows by display name.
  if(!devices.length&&!direct.some(choice=>choice.kind==='registered'&&choice.key===key))continue;
  const definition={key,printerId:row.id,name:row.name||row.profile,kind:'registered',route:'direct',unavailable:false,deviceChoices:devices};
  definition.unavailable=availableDirectDevices(definition).length===0;
  definitions.push(definition);
 }
 return definitions;
}

export function resolveDirectPrinterChoice(definition,preferredAddress=''){
 const result=(status,choice=null)=>({choice,status,ambiguous:status==='ambiguous'});
 if(!definition||definition.route!=='direct')return result('unavailable');
 if(definition.kind==='internal'){
  const choice=definition.deviceChoices?.find(row=>row.kind==='internal'&&row.route==='direct'&&!row.unavailable);
  return !definition.unavailable&&choice?result('ready',choice):result('unavailable');
 }
 if(definition.kind!=='registered'||!definition.printerId)return result('unavailable');
 const devices=definition.unavailable?[]:availableDirectDevices(definition);
 const address=String(preferredAddress||'').trim().toUpperCase();
 if(address){
  const choice=devices.find(row=>String(row.address).trim().toUpperCase()===address);
  return choice?result('ready',choice):result('stale');
 }
 if(devices.length===1)return result('ready',devices[0]);
 return result(devices.length>1?'ambiguous':'unavailable');
}
