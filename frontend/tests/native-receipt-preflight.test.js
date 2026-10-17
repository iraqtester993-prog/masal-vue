import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {parse} from '@vue/compiler-sfc';
import {ref,computed,reactive} from 'vue';
import {prepareNativeReceipt,printPreparedNativeReceipt,validateNativeReceiptBlocks,nativeReceiptLimitMessage,isNativeReceiptLimit} from '../src/shared/native-print.js';
import {pendingAttempt,createMutationKey} from '../src/modules/sales/sales-model.js';

function printSurface({texts=['PIN','SERIAL','EXPIRY'],image,route='direct',outcome}={}){
  const previous=new Map(['window','document','Node'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)])),requests=[];let decoded=0,draws=0;
  const children=texts.map(text=>({nodeType:3,textContent:text}));
  if(image)children.push({nodeType:1,tagName:'IMG',naturalWidth:image.width,naturalHeight:image.height,decode:async()=>{decoded++;},getBoundingClientRect:()=>({width:80})});
  const element={nodeType:1,tagName:'DIV',childNodes:children,getBoundingClientRect:()=>({width:384})};
  const bridge={postMessage(raw){const request=JSON.parse(raw);requests.push(request);queueMicrotask(()=>this.onmessage({data:JSON.stringify({requestId:request.requestId,ok:true,state:request.action==='capabilities'?'ready':'accepted',printRoute:route,browserPrintSupported:true,message:'submitted',...(request.action!=='capabilities'?outcome:{})})}));}};
  Object.defineProperty(globalThis,'window',{value:{navigator:{userAgent:'Android DananeerPOS/0.9'},DananeerPrint:bridge},configurable:true});
  Object.defineProperty(globalThis,'Node',{value:{TEXT_NODE:3,ELEMENT_NODE:1},configurable:true});
  Object.defineProperty(globalThis,'document',{value:{fonts:{ready:Promise.resolve()},querySelector:()=>element,getElementById:()=>null,createElement:()=>({width:0,height:0,getContext:()=>({drawImage(){draws++;}}),toDataURL:()=>`data:image/png;base64,${image?.encoded||'small-image'}`})},configurable:true});
  return {element,requests,decoded:()=>decoded,draws:()=>draws,restore(){for(const [key,descriptor]of previous){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}};
}

async function dialogFixture(surface){
  const source=await readFile(new URL('../src/modules/sales/ReceiptDialog.vue',import.meta.url),'utf8');
  const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
  const calls=[],sale={id:9,account_id:12,status:'Print Requested',version:1,print_pending:false,quantity:100,issued_at:'2026-10-14T00:00:00Z'};
  let keys=0;
  const vm={receipt:ref({sale,design:{width:58},cards:[]}),state:reactive({busy:false,error:'',notice:'',options:{}}),pos:ref(true),can:()=>true,key:()=>createMutationKey(()=>`print-key-${++keys}`),api:{action:async(id,action,payload)=>{calls.push({id,action,payload});return {data:{...vm.receipt.value.sale,version:vm.receipt.value.sale.version+1,...(action==='print/start'?{print_pending:true,attempt_id:77}:{status:action==='print/result'?'Print Failed':'Print Requested',print_pending:false})}};}},async execute(payload,key,action){const result=await action({...payload,idempotency_key:key.for(payload)},new AbortController().signal);key.clear();return result.data;}};
  const scope={ref,computed,watch(){},onBeforeUnmount(){},nextTick:async()=>{},document:globalThis.document,defineProps:()=>({vm}),defineEmits:()=>()=>{},usePrinterRoute:()=>({printRoute:ref('direct')}),pendingAttempt,hasNativePrinter:()=>true,prepareNativeReceipt,printPreparedNativeReceipt,isNativeReceiptLimit,nativeReceiptLimitMessage};
  runInNewContext(script+'\nglobalThis.handlers={print,retry,nativePrintBlocked};',scope);
  return {vm,calls,handlers:scope.handlers,surface};
}

test('native preflight uses the renderer boundaries rather than an invented quantity or paper-height limit',async()=>{
  for(const parameters of [{texts:Array(181).fill('same-card')},{texts:['x'.repeat(2001)]},{image:{width:384,height:1201}},{image:{width:384,height:30,encoded:'x'.repeat(1800001)}}]){
    const surface=printSurface(parameters);
    try{await assert.rejects(prepareNativeReceipt(surface.element),error=>error.code==='native_receipt_limit'&&error.printState==='not_sent');assert.equal(surface.requests.length,0);}finally{surface.restore();}
  }
  const surface=printSurface({texts:Array(179).fill('x'.repeat(2000)),image:{width:384,height:1200}});
  try{const blocks=await prepareNativeReceipt(surface.element);assert.equal(blocks.length,180);assert.equal(surface.requests.length,0);assert.equal(surface.decoded(),1);assert.equal(surface.draws(),1);}finally{surface.restore();}
  assert.throws(()=>validateNativeReceiptBlocks([]),error=>error.code==='native_receipt_limit');
});

test('an impossible stored receipt creates no print attempt, failure result, retry transition or printer job',async()=>{
  for(const route of ['direct','browser']){
    const surface=printSurface({texts:Array(181).fill('stored-card'),route});
    try{
      const fixture=await dialogFixture(surface);
      await fixture.handlers.print();await fixture.handlers.print();
      assert.equal(fixture.vm.state.error,nativeReceiptLimitMessage);assert.equal(fixture.handlers.nativePrintBlocked.value,true);
      assert.equal(fixture.vm.receipt.value.sale.print_pending,false);assert.equal(fixture.vm.receipt.value.sale.version,1);
      assert.deepEqual(fixture.calls,[]);assert.deepEqual(surface.requests,[]);
      fixture.vm.receipt.value.sale.status='Print Failed';await fixture.handlers.retry();
      assert.deepEqual(fixture.calls,[]);assert.equal(fixture.vm.receipt.value.sale.status,'Print Failed');
    }finally{surface.restore();}
  }
});

test('successful preflight prepares the exact stored content once, then starts one real attempt and dispatches the same job',async()=>{
  for(const route of ['direct','browser']){
    const surface=printSurface({image:{width:384,height:80},route});
    try{
      const fixture=await dialogFixture(surface);await fixture.handlers.print();await fixture.handlers.print();
      assert.deepEqual(fixture.calls.map(call=>call.action),['print/start']);
      assert.deepEqual(surface.requests.map(request=>request.action),['capabilities',route==='browser'?'browserPrint':'print']);
      assert.equal(surface.requests[1].jobId,'sale-9-77');assert.equal(surface.requests[1].blocks[0].text,'PIN');
      assert.equal(surface.decoded(),1);assert.equal(surface.draws(),1);
      assert.equal(fixture.vm.receipt.value.sale.print_pending,true);assert.equal(fixture.handlers.nativePrintBlocked.value,false);
      assert.equal(fixture.vm.state.notice,'submitted');
    }finally{surface.restore();}
  }
});

test('a native renderer height rejection keeps the same sale and offers the safe history path, while an unknown result stays pending',async()=>{
  for(const state of ['not_sent','unknown']){
    const surface=printSurface({outcome:{ok:false,state,message:'لم يتم إرسال الوصل. الوصل طويل جدًا؛ قسّم البطاقات إلى عمليات أصغر.'}});
    try{
      const fixture=await dialogFixture(surface);await fixture.handlers.print();await fixture.handlers.print();
      assert.equal(fixture.vm.receipt.value.sale.id,9);
      assert.deepEqual(surface.requests.map(request=>request.action),['capabilities','print']);
      if(state==='not_sent'){
        assert.equal(fixture.handlers.nativePrintBlocked.value,true);assert.equal(fixture.vm.state.error,nativeReceiptLimitMessage);
        assert.deepEqual(fixture.calls.map(call=>call.action),['print/start','print/result']);
        assert.equal(fixture.calls[1].payload.reason,nativeReceiptLimitMessage);assert.equal(fixture.calls[1].payload.success,false);
      }else{
        assert.equal(fixture.handlers.nativePrintBlocked.value,false);assert.equal(fixture.vm.receipt.value.sale.print_pending,true);
        assert.deepEqual(fixture.calls.map(call=>call.action),['print/start']);
      }
    }finally{surface.restore();}
  }
});
