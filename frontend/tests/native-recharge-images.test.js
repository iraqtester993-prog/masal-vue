import test from 'node:test';
import assert from 'node:assert/strict';
import {prepareNativeReceipt} from '../src/shared/native-print.js';
import {rechargeQr} from '../src/modules/sales/recharge-qr.js';

function surface(children){
  const saved=new Map(['window','document','Node'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
  const canvases=[],requests=[];
  const element={nodeType:1,tagName:'DIV',childNodes:children,getBoundingClientRect:()=>({width:330})};
  Object.defineProperty(globalThis,'Node',{value:{TEXT_NODE:3,ELEMENT_NODE:1},configurable:true});
  Object.defineProperty(globalThis,'window',{value:{navigator:{userAgent:'Android DananeerPOS/1.4'},DananeerPrint:{postMessage:value=>requests.push(value)}},configurable:true});
  Object.defineProperty(globalThis,'document',{value:{fonts:{ready:Promise.resolve()},createElement:()=>{
    const calls=[];
    const context={drawImage(...args){calls.push(['draw',...args]);},measureText:value=>({width:value.length*16}),fillRect(...args){calls.push(['fill',...args]);},strokeRect(...args){calls.push(['border',...args]);},fillText(...args){calls.push(['text',...args]);}};
    const canvas={width:0,height:0,getContext:()=>context,toDataURL:()=>'data:image/png;base64,cGl4ZWxz'};
    canvases.push({canvas,context,calls});return canvas;
  }},configurable:true});
  return{element,canvases,requests,restore(){for(const[key,value]of saved){if(value)Object.defineProperty(globalThis,key,value);else delete globalThis[key];}}};
}

test('native QR preserves its integer bitmap dimensions even when its CSS display width is different or hidden',async()=>{
  const qr=rechargeQr('1234567890123456','*123*{pin}#');let decodes=0;
  const image={nodeType:1,tagName:'IMG',dataset:{nativeWidthPixels:String(qr.width)},naturalWidth:qr.width,naturalHeight:qr.width,decode:async()=>{decodes++;},getBoundingClientRect:()=>({width:0})};
  const fixture=surface([image]);
  try{
    const blocks=await prepareNativeReceipt(fixture.element);
    assert.deepEqual(blocks,[{image:'cGl4ZWxz',widthFraction:qr.width/360}]);
    assert.equal(fixture.canvases[0].canvas.width,qr.width);assert.equal(fixture.canvases[0].canvas.height,qr.width);
    assert.equal(fixture.canvases[0].context.imageSmoothingEnabled,false);assert.equal(decodes,1);assert.deepEqual(fixture.requests,[]);
    assert.equal(image.dataset.nativeWidthPixels,String(qr.width));
  }finally{fixture.restore();}
});

test('highlighted native PIN is one large image block with the complete original code and no duplicate plain text',async()=>{
  const text='0'+'123456789'.repeat(7);
  assert.equal(text.length,64);
  const pin={nodeType:1,tagName:'DIV',dataset:{nativePin:''},textContent:text,childNodes:[{nodeType:3,textContent:text}]};
  const fixture=surface([pin]);
  try{
    const blocks=await prepareNativeReceipt(fixture.element);
    assert.deepEqual(blocks,[{image:'cGl4ZWxz',widthFraction:1}]);
    const {canvas,context,calls}=fixture.canvases[0];
    assert.equal(canvas.width,360);assert.ok(canvas.height>60&&canvas.height<=1200);
    assert.equal(context.font,'bold 28px monospace');assert.equal(context.direction,'ltr');
    for(const call of calls.filter(call=>call[0]==='text')){assert.ok(context.measureText(call[1]).width<=328);assert.ok(call[3]>14&&call[3]+14<canvas.height);}
    assert.equal(calls.filter(call=>call[0]==='text').map(call=>call[1]).join('').replaceAll(' ',''),text);
    assert.ok(calls.some(call=>call[0]==='border'));assert.equal(pin.textContent,text);assert.deepEqual(fixture.requests,[]);
  }finally{fixture.restore();}
});

test('bad native QR dimensions fail before any mutation or printer job',async()=>{
  for(const nativeWidthPixels of ['0','361','1.5','+222','bad','221']){
    const image={nodeType:1,tagName:'IMG',dataset:{nativeWidthPixels},naturalWidth:222,naturalHeight:222,decode:async()=>{},getBoundingClientRect:()=>({width:120})};
    const fixture=surface([image]);
    try{await assert.rejects(prepareNativeReceipt(fixture.element),error=>error.printState==='not_sent'&&error.code==='native_receipt_limit');assert.deepEqual(fixture.requests,[]);}
    finally{fixture.restore();}
  }
  const oversized={nodeType:1,tagName:'IMG',dataset:{nativeWidthPixels:'366'},naturalWidth:366,naturalHeight:366,decode:async()=>{},getBoundingClientRect:()=>({width:120})};
  const fixture=surface([oversized]);
  try{await assert.rejects(prepareNativeReceipt(fixture.element),error=>error.printState==='not_sent'&&error.code==='native_receipt_limit');assert.deepEqual(fixture.requests,[]);}
  finally{fixture.restore();}
});

test('native PIN highlighting obeys original preflight limits and never dispatches impossible content',async()=>{
  const pin={nodeType:1,tagName:'DIV',dataset:{nativePin:''},textContent:'0'.repeat(2001)};
  const fixture=surface([pin]);
  try{await assert.rejects(prepareNativeReceipt(fixture.element),error=>error.printState==='not_sent');assert.deepEqual(fixture.requests,[]);}
  finally{fixture.restore();}
});
