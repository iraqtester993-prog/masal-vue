import {rememberNativeAppVersion} from './client-version.js';
import {enabledPrinters} from './printer-registry.js';
let registryApi=null,registryFlight=null,registrySignature='';
const routePreferenceKey='dananeer:printer-route:v1';
let routePreference;
export function preferBrowserPrinting(){if(routePreference!==undefined)return routePreference==='browser';try{return window.localStorage?.getItem(routePreferenceKey)==='browser';}catch{return false;}}
export function rememberPrinterRoute(route){routePreference=route;try{if(route==='browser')window.localStorage?.setItem(routePreferenceKey,route);else window.localStorage?.removeItem(routePreferenceKey);}catch{}}
export function configurePrinterRegistry(api){registryApi=api;registrySignature='';return()=>{if(registryApi===api){registryApi=null;registrySignature='';}};}
export async function syncNativePrinterRegistry(){
 if(!registryApi||!hasNativePrinter())return;
 if(registryFlight)return registryFlight;
 const api=registryApi;
 registryFlight=(async()=>{
  const response=await api.request('/company/app-config',{fresh:true});
  const printers=enabledPrinters(response.data.config.printers||[]),signature=JSON.stringify(printers);
  if(api!==registryApi)return;
  if(signature!==registrySignature){await nativeRequest({action:'printerRegistry',printers},{timeoutMs:5000});registrySignature=signature;}
 })().finally(()=>{registryFlight=null;});
 return registryFlight;
}
let sequence = 0;
const pending = new Map();
export const hasNativePrinter = () => typeof window !== 'undefined' && typeof window.DananeerPrint?.postMessage === 'function';
export const requiresNativePrinter = () => hasNativePrinter() || typeof window !== 'undefined' && /DananeerPOS\//.test(window.navigator?.userAgent || '');

// Control requests never contain a receipt or initiate a sale. "opened" only confirms the native dialog opened.
export const getNativePrinterCapabilities = async () => {const response=await nativeRequest({ action:'capabilities' },{timeoutMs:3000});rememberNativeAppVersion(response);return preferBrowserPrinting()?{...response,nativePrinterName:response.printerName,nativePrintRoute:response.printRoute,printRoute:'browser',printerName:'الطباعة بالمتصفح'}:response;};
export async function selectPrinterChoice(choice){
 if(!choice)throw Error('اختر الطابعة أولًا.');
 if(choice.unavailable)throw Error('اقترن بالطابعة وتحقق من اتصالها أولًا.');
 if(choice.kind==='browser'){
  const capability=await getNativePrinterCapabilities();
  if(!capability.browserPrintSupported)throw Error('حدّث تطبيق دنانير لتفعيل طباعة المتصفح / النظام.');
  if(capability.printerSelectionVersion>=1)await nativeRequest({action:'printerSelect',browser:true},{timeoutMs:5000});
  rememberPrinterRoute('browser');return 'تم حفظ الطباعة بالمتصفح. اختر الطابعة من نافذة طباعة النظام عند الطباعة.';
 }
 const result=await nativeRequest({action:'printerSelect',internal:choice.kind==='internal',other:choice.route==='browser',printerId:choice.printerId||'',address:choice.address||''},{timeoutMs:10000});
 rememberPrinterRoute(choice.route);return result.message;
}
export const openNativePrinterSettings = () => nativeRequest({ action:'printerSettings' });
export const openNativePrinterTest = () => nativeRequest({ action:'printerTest' });
export const openNativePrinterDiagnostics = () => nativeRequest({ action:'diagnostics' });

// Responses carry no receipt data. The bridge is injected only into the trusted main frame.
export function nativeRequest(payload,{timeoutMs=480000,signal,onProgress}={}) {
  if (!hasNativePrinter()) {
    const error=Error(requiresNativePrinter()?'محرك الطباعة غير متاح داخل التطبيق؛ حدّث Android System WebView وأعد فتح التطبيق.':'افتح نقطة البيع داخل تطبيق دنانير.');
    error.printState='not_sent';
    return Promise.reject(error);
  }
  window.DananeerPrint.onmessage = event => {
    let response;
    try { response = JSON.parse(event.data); } catch { return; }
    const request = pending.get(response.requestId);
    if (!request) return;
    if(response.progress===true){try{request.onProgress?.(response);}catch{/* A view callback cannot strand the native operation. */}return;}
    request.cleanup(); pending.delete(response.requestId);
    if(response.ok) request.resolve(response);
    else {
      const error=Error(response.message || 'تعذر إرسال الوصل للطابعة.');
      error.printState=response.state;
      error.code=response.state;
      request.reject(error);
    }
  };
  const requestId = `${Date.now()}-${++sequence}`;
  return new Promise((resolve, reject) => {
    let dispatched=false;
    const aborted=()=>{cleanup();pending.delete(requestId);const printing=dispatched&&['print','browserPrint'].includes(payload.action),error=Error(printing?'تم إيقاف انتظار نتيجة الطباعة. تحقق من الورقة قبل إعادة المحاولة.':'تم إيقاف متابعة البلوتوث.');error.name='AbortError';error.printState=printing?'unknown':'not_sent';reject(error);};
    const cleanup=()=>{clearTimeout(timer);signal?.removeEventListener('abort',aborted);};
    const timer = setTimeout(() => { cleanup();pending.delete(requestId);const control=!['print','browserPrint'].includes(payload.action);const error=Error(control?'لم يصل رد التطبيق ضمن المهلة. أعد المحاولة.':'لم تصل نتيجة الطباعة. تحقق من الورقة قبل إعادة المحاولة.');error.code='native_timeout';error.printState=control?'not_sent':'unknown';reject(error); }, timeoutMs);
    if(signal?.aborted){aborted();return;}
    signal?.addEventListener('abort',aborted,{once:true});
    pending.set(requestId, { resolve, reject, cleanup, onProgress });
    try { dispatched=true;window.DananeerPrint.postMessage(JSON.stringify({ ...payload, requestId })); }
    catch (error) { cleanup(); pending.delete(requestId); reject(error); }
  });
}

async function nativeBluetoothOperation(action,address,{signal,onProgress}={}) {
  const capability=await getNativePrinterCapabilities();
  if(capability.bluetoothDiscoveryVersion!==1){const error=Error('البحث والإقران داخل التطبيق يحتاجان تحديث تطبيق دنانير إلى 1.4.2 أو أحدث.');error.code='printer_discovery_unsupported';throw error;}
  if(signal?.aborted){const error=Error('تم إيقاف متابعة البلوتوث.');error.name='AbortError';throw error;}
  const operationId=globalThis.crypto?.randomUUID?.()||`bluetooth-${Date.now()}-${++sequence}`;
  const cancel=()=>{void nativeRequest({action:'bluetoothCancel',operationId},{timeoutMs:5000}).catch(()=>{});};
  signal?.addEventListener('abort',cancel,{once:true});
  try{return await nativeRequest({action,operationId,...(address?{address}:{})},{timeoutMs:125000,signal,onProgress});}
  catch(error){if(error.name!=='AbortError')cancel();throw error;}
  finally{signal?.removeEventListener('abort',cancel);}
}
export const scanNativePrinters=options=>nativeBluetoothOperation('bluetoothScan',null,options);
export function pairNativePrinter(address,options){if(!/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/i.test(String(address||'')))return Promise.reject(Error('عنوان جهاز البلوتوث غير صالح.'));return nativeBluetoothOperation('bluetoothPairDevice',address.toUpperCase(),options);}

export const nativeReceiptLimitMessage='هذا الوصل يتجاوز حدود محرك الطباعة داخل التطبيق. لم يُرسل الوصل للطابعة.';
function receiptLimit(){const error=Error(nativeReceiptLimitMessage);error.code='native_receipt_limit';error.printState='not_sent';return error;}
export function isNativeReceiptLimit(error){
  if(error?.printState!=='not_sent')return false;
  return error.code==='native_receipt_limit'||['محتوى الوصل غير صالح أو كبير جدًا.','صورة الوصل كبيرة جدًا.','قياس صورة الوصل غير صالح.','نص الوصل كبير جدًا.','الوصل طويل جدًا؛ قسّم البطاقات إلى عمليات أصغر.'].some(message=>String(error.message||'').endsWith(message));
}
export function validateNativeReceiptBlocks(blocks){
  if(!Array.isArray(blocks)||blocks.length<1||blocks.length>180)throw receiptLimit();
  for(const block of blocks){
    if(Object.hasOwn(block,'image')){if(typeof block.image!=='string'||block.image.length>1800000)throw receiptLimit();}
    else if(typeof block.text!=='string'||block.text.length>2000)throw receiptLimit();
  }
}
function nativePinBlock(node) {
  const text=String(node.textContent||'').trim();
  if(!text||text.length>2000)throw receiptLimit();
  const canvas=document.createElement('canvas');canvas.width=360;
  const context=canvas.getContext('2d');
  context.font='bold 28px monospace';
  const display=/^[0-9]+$/.test(text)?text.match(/.{1,4}/g).join(' '):text;
  const lines=[];let line='';
  for(const character of display){
    if(character==='\n'){lines.push(line);line='';continue;}
    if(line&&context.measureText(line+character).width>328){lines.push(line.trimEnd());line=character===' '?'':character;}
    else line+=character;
  }
  if(line||!lines.length)lines.push(line);
  canvas.height=24+lines.length*36;
  if(canvas.height>1200)throw receiptLimit();
  // Reapply drawing state after changing canvas.height resets the context.
  context.fillStyle='#eeeeee';context.fillRect(0,0,360,canvas.height);
  context.strokeStyle='#000000';context.lineWidth=2;context.strokeRect(1,1,358,canvas.height-2);
  context.fillStyle='#000000';context.font='bold 28px monospace';context.textAlign='center';context.textBaseline='middle';context.direction='ltr';
  lines.forEach((value,index)=>context.fillText(value,180,30+index*36));
  return {image:canvas.toDataURL('image/png').split(',')[1],widthFraction:1};
}
export async function prepareNativeReceipt(element) {
  if(!hasNativePrinter()){const error=Error('محرك الطباعة غير متاح داخل التطبيق؛ حدّث Android System WebView وأعد فتح التطبيق.');error.printState='not_sent';throw error;}
  if (!element) throw Error('الوصل غير جاهز للطباعة.');
  await document.fonts?.ready;
  const blocks = [];
  async function visit(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      const text = node.textContent.trim();
      if (text){if(text.length>2000)throw receiptLimit();blocks.push({ text });}
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    if(node.hasAttribute?.('data-native-pin')||node.dataset?.nativePin!==undefined){blocks.push(nativePinBlock(node));return;}
    if (node.tagName === 'IMG') {
      await node.decode();
      if (!Number.isFinite(node.naturalWidth)||!Number.isFinite(node.naturalHeight)||node.naturalWidth<1||node.naturalHeight<1)throw receiptLimit();
      const nativePixels=node.dataset?.nativeWidthPixels;
      if(nativePixels!==undefined&&(!/^[1-9][0-9]{0,2}$/.test(nativePixels)||Number(nativePixels)>360||Number(nativePixels)!==node.naturalWidth))throw receiptLimit();
      const canvas = document.createElement('canvas');
      const ratio = Math.min(1, 576 / node.naturalWidth);
      canvas.width = Math.max(1, Math.round(node.naturalWidth * ratio));
      canvas.height = Math.max(1, Math.round(node.naturalHeight * ratio));
      if (canvas.height>1200)throw receiptLimit();
      const context=canvas.getContext('2d');
      if(nativePixels!==undefined)context.imageSmoothingEnabled=false;
      context.drawImage(node, 0, 0, canvas.width, canvas.height);
      const receiptWidth=element.getBoundingClientRect().width;
      const imageWidth=node.getBoundingClientRect().width;
      blocks.push({ image: canvas.toDataURL('image/png').split(',')[1],widthFraction:nativePixels!==undefined?Number(nativePixels)/360:receiptWidth>0?Math.max(0.05,Math.min(1,imageWidth/receiptWidth)):1 });
      return;
    }
    for (const child of node.childNodes){await visit(child);if(blocks.length>180)throw receiptLimit();}
  }
  await visit(element);
  validateNativeReceiptBlocks(blocks);
  return blocks;
}
export async function printPreparedNativeReceipt(blocks,jobId){
  validateNativeReceiptBlocks(blocks);
  try{await syncNativePrinterRegistry();}catch{const error=Error('تعذر التحقق من إعدادات الطابعة؛ أعد الاتصال وحاول مرة أخرى. لم يُرسل الوصل.');error.printState='not_sent';throw error;}
  const capabilities=await getNativePrinterCapabilities().catch(error=>{if(error.printState==='not_sent')return {};throw error;});
  if(preferBrowserPrinting()&&!capabilities.browserPrintSupported){const error=Error('طباعة المتصفح غير متاحة؛ حدّث التطبيق أو تحقق من اتصال الجسر. لم يُرسل الوصل.');error.printState='not_sent';throw error;}
  const action=capabilities.printRoute==='browser'&&capabilities.browserPrintSupported?'browserPrint':'print';
  const response = await nativeRequest({ action, jobId: String(jobId), blocks });
  return response.message;
}
export async function printNativeReceipt(element,jobId){return printPreparedNativeReceipt(await prepareNativeReceipt(element),jobId);}
