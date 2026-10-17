import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import * as Vue from 'vue';
import {parse,compileScript} from '@vue/compiler-sfc';

async function settingsSurface(){
 const original={version:5,config:{name:'دنانير',about:'',terms:'',privacy:'',printers:[{id:'existing-custom',name:'طابعة الوكيل',profile:'GOOJPRT',match:'YHD-5801',enabled:true}]}};
 const calls=[],session={api:{request:async path=>{calls.push({path,method:'GET'});return{data:structuredClone(original)};},mutate:async(path,method,payload)=>{calls.push({path,method,payload:structuredClone(Vue.toRaw(payload))});return{data:{...payload,version:6}};}}};
 const source=await readFile(new URL('../src/modules/company/AppSettings.vue',import.meta.url),'utf8');
 const parsed=parse(source);assert.deepEqual(parsed.errors,[]);
 let compiled=compileScript(parsed.descriptor,{id:'app-printer-settings',inlineTemplate:true}).content;
 compiled=compiled.replace(/^import\s+{([^}]+)}\s+from\s+['"]vue['"];?/gm,(_,names)=>`const {${names.replace(/\s+as\s+/g,':')}}=Vue;`).replace(/^import .*;?\r?$/gm,'').replace('export default','globalThis.Component=');
 const context={Vue,usePortal:()=>({session})};runInNewContext(compiled,context);
 const descendants=el=>[el,...el.children.flatMap(descendants)],text=el=>el.text+el.children.map(text).join('');
 const node=(tag,text='')=>({tag,tagName:tag.toUpperCase(),text,props:{},children:[],parent:null,events:{},get options(){return this.children.flatMap(descendants).filter(el=>el.tag==='option');},addEventListener(name,handler){this.events[name]=handler;},removeEventListener(name){delete this.events[name];}});
 const host=Vue.createRenderer({createElement:tag=>node(tag),createText:text=>node('#text',text),createComment:text=>node('#comment',text),setText:(el,value)=>el.text=value,setElementText:(el,value)=>{el.text=value;el.children=[];},parentNode:el=>el.parent,nextSibling:el=>el.parent?.children[el.parent.children.indexOf(el)+1]||null,patchProp:(el,key,previous,next)=>{el.props[key]=next;el[key]=next;if(key==='value')el._value=next;},insert:(el,parent,anchor=null)=>{if(el.parent)el.parent.children.splice(el.parent.children.indexOf(el),1);el.parent=parent;const at=anchor?parent.children.indexOf(anchor):-1;if(at<0)parent.children.push(el);else parent.children.splice(at,0,el);},remove:el=>{if(el.parent)el.parent.children.splice(el.parent.children.indexOf(el),1);el.parent=null;}});
 const root=node('root'),app=host.createApp(context.Component);app.mount(root);await new Promise(setImmediate);await Vue.nextTick();
 return {root,app,calls,all:()=>descendants(root),text};
}

test('actual app settings offers both paper widths and keeps GOOJPRT canonical when its display includes MTP-2',async()=>{
 const page=await settingsSurface();
 try{
  const nodes=page.all(),select=nodes.find(el=>el.tag==='select'),options=select.options;
  assert.ok(page.text(page.root).includes('58 و80 ملم'));
  assert.equal(page.text(options.find(el=>el.props.value==='GOOJPRT')),'GOOJPRT / MTP-2');
  assert.ok(options.some(el=>el.props.value==='YHD-8006'&&page.text(el)==='YHD-8006'));
  await nodes.find(el=>el.tag==='form').props.onSubmit({preventDefault(){}});await Vue.nextTick();
  assert.equal(page.calls.length,2);assert.equal(page.calls[1].method,'PUT');assert.equal(page.calls[1].payload.version,5);
  assert.deepEqual(page.calls[1].payload.config.printers,[{id:'existing-custom',name:'طابعة الوكيل',profile:'GOOJPRT',match:'YHD-5801',enabled:true}]);
 }finally{page.app.unmount();}
});

test('the real model selector saves the new YHD profile without renaming the existing printer id or match',async()=>{
 const page=await settingsSurface();
 try{
  page.all().find(el=>el.tag==='select').props['onUpdate:modelValue']('YHD-8006');await Vue.nextTick();
  await page.all().find(el=>el.tag==='form').props.onSubmit({preventDefault(){}});await Vue.nextTick();
  assert.equal(page.calls[1].payload.config.printers[0].profile,'YHD-8006');
  assert.equal(page.calls[1].payload.config.printers[0].id,'existing-custom');
  assert.equal(page.calls[1].payload.config.printers[0].match,'YHD-5801');
  assert.ok(page.calls.every(call=>call.path==='/company/app-config'));
 }finally{page.app.unmount();}
});
