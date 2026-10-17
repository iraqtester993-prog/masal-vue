import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {createServer} from 'vite';
import * as Vue from 'vue';
import {parse,compileScript,compileTemplate} from '@vue/compiler-sfc';

// Exercise Vue's real teleport/lifecycle handling and native dialog calls without
// a live account, business writes, printer connection or browser print request.
function modalSurface(){
 const node=(tag,text='')=>({tag,text,props:{},children:[],parent:null,style:{display:''},open:false,showModal(){this.open=true;this.openCount=(this.openCount||0)+1;},close(){this.open=false;this.closeCount=(this.closeCount||0)+1;}}),root=node('root'),body=node('body');
 const renderer=Vue.createRenderer({
  createElement:tag=>node(tag),createText:text=>node('#text',text),createComment:text=>node('#comment',text),
  setText:(target,text)=>target.text=text,setElementText:(target,text)=>{target.text=text;target.children=[];},patchProp:(target,key,previous,value)=>target.props[key]=value,
  insert(target,parent,anchor=null){if(target.parent){const old=target.parent.children.indexOf(target);if(old>=0)target.parent.children.splice(old,1);}target.parent=parent;const at=anchor?parent.children.indexOf(anchor):-1;if(at>=0)parent.children.splice(at,0,target);else parent.children.push(target);},
  remove(target){if(target.parent){const at=target.parent.children.indexOf(target);if(at>=0)target.parent.children.splice(at,1);target.parent=null;}},
  parentNode:target=>target.parent,nextSibling:target=>target.parent?.children[target.parent.children.indexOf(target)+1]||null,querySelector:selector=>selector==='body'?body:null,
 });
 const find=(target,predicate)=>predicate(target)?target:target.children.map(child=>find(child,predicate)).find(Boolean);
 return {renderer,root,body,find};
}

async function addClientTemplate(component){
 const source=await readFile(new URL('../src/modules/catalog/CatalogModal.vue',import.meta.url),'utf8'),descriptor=parse(source).descriptor;
 const bindings=compileScript(descriptor,{id:'receipt-modal-fixture'}).bindings;
 const template=compileTemplate({source:descriptor.template.content,filename:'CatalogModal.vue',id:'receipt-modal-fixture',compilerOptions:{bindingMetadata:bindings}}),scope={};
 assert.deepEqual(template.errors,[]);
 const code=template.code.replace(/^import \{([^}]+)\} from "vue"$/gm,(_,names)=>{for(const pair of names.split(',')){const [name,alias]=pair.trim().split(/\s+as\s+/);scope[alias||name]=Vue[name];}return '';}).replace('export function render','function render');
 component.render=runInNewContext(code+'\nrender',scope);
}

test('receipt modal enters the native top layer, keeps its header outside the content scroller, and blocks dismissal while busy',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
 let app;
 try{
  const Modal=(await server.ssrLoadModule('/src/modules/catalog/CatalogModal.vue')).default;await addClientTemplate(Modal);
  const visible=Vue.ref(true),busy=Vue.ref(false),surface=modalSurface();let closes=0;
  app=surface.renderer.createApp({render:()=>Vue.h('main',[Vue.h('section',{class:'history-details'},'Underlying history details'),visible.value?Vue.h(Modal,{title:'وصل العملية',contentClass:'sales-receipt-dialog pos-receipt-dialog',bodyClass:'receipt-dialog-body',busy:busy.value,onClose:()=>{closes++;visible.value=false;}},{default:()=>Vue.h('article',{class:'receipt'},'Stored receipt content')}):null])});
  app.provide(Vue.ssrContextKey,{modules:new Set()});app.mount(surface.root);await Vue.nextTick();
  const dialog=surface.find(surface.body,node=>node.tag==='dialog'),header=surface.find(dialog,node=>node.props.class?.includes('dialog-heading')),scroller=surface.find(dialog,node=>node.props.class==='receipt-dialog-body');
  assert.equal(dialog.open,true);assert.equal(dialog.openCount,1);assert.ok(dialog.props['aria-labelledby']);
  assert.equal(header.parent,dialog);assert.equal(scroller.parent,dialog);assert.notEqual(header.parent,scroller);
  assert.equal(surface.find(surface.root,node=>node.props.class==='receipt'),undefined,'Receipt must not render below underlying history');
  assert.ok(surface.find(scroller,node=>node.props.class==='receipt'));
  busy.value=true;await Vue.nextTick();let prevented=0;dialog.props.onCancel({preventDefault(){prevented++;}});
  assert.equal(prevented,1);assert.equal(closes,0);assert.equal(dialog.open,true);
  busy.value=false;await Vue.nextTick();dialog.props.onCancel({preventDefault(){prevented++;}});await Vue.nextTick();
  assert.equal(closes,1);assert.equal(dialog.open,false);assert.equal(dialog.closeCount,1);
  assert.ok(surface.find(surface.root,node=>node.props.class==='history-details'),'Closing the receipt preserves its existing history view');
 }finally{app?.unmount();await server.close();}
});
