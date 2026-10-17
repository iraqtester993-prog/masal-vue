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
