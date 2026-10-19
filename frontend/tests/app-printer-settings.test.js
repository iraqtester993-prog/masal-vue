import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import * as Vue from 'vue';
import {parse,compileScript} from '@vue/compiler-sfc';

// Vue's text-input directive checks these DOM constructors during a host update.
globalThis.Document??=class Document {};
globalThis.ShadowRoot??=class ShadowRoot {};

async function settingsSurface(permissions=['app.settings','printers.manage'],capabilities,kind='owner'){
 const original={version:5,config:{name:'دنانير',about:'عن الشركة',terms:'الشروط',privacy:'الخصوصية',printers:[
  {id:'existing-custom',name:'طابعة الوكيل',profile:'GOOJPRT',match:'YHD-5801',enabled:true},
  {id:'wide-printer',name:'طابعة المكتب',profile:'YHD-8006',match:'YHD-8006',enabled:false},
  {id:'bluetooth-printer',name:'طابعة البلوتوث',profile:'Feasycom',match:'Feasycom',enabled:0},
 ]}},server=structuredClone(original),calls=[],grants=Vue.ref(permissions);
 const visibleConfig=()=>Object.fromEntries(Object.entries(server.config).filter(([key])=>grants.value.includes(key==='printers'?'printers.manage':'app.settings')));
 const session={state:Vue.reactive({identity:{user:{id:12},account:{id:3,type:'system'},membership:{kind},...(capabilities?{capabilities}:{})}}),can:key=>grants.value.includes(key),api:{
  request:async(path,options)=>{calls.push({path,method:'GET',signal:options?.signal});return{data:{version:server.version,config:structuredClone(visibleConfig())}};},
  mutate:async(path,method,payload,signal)=>{calls.push({path,method,payload:structuredClone(Vue.toRaw(payload)),signal});server.version++;server.config={...server.config,...structuredClone(Vue.toRaw(payload.config))};return{data:{version:server.version,config:structuredClone(visibleConfig())}};},
 }};
 const source=await readFile(new URL('../src/modules/company/AppSettings.vue',import.meta.url),'utf8');
 const parsed=parse(source);assert.deepEqual(parsed.errors,[]);
 let compiled=compileScript(parsed.descriptor,{id:'app-printer-settings',inlineTemplate:true}).content;
 compiled=compiled.replace(/^import\s+{([^}]+)}\s+from\s+['"]vue['"];?/gm,(_,names)=>`const {${names.replace(/\s+as\s+/g,':')}}=Vue;`).replace(/^import .*;?\r?$/gm,'').replace('export default','globalThis.Component=');
 let refreshRegistration;
 const context={Vue,AbortController,crypto:globalThis.crypto,usePortal:()=>({session}),useRouteRefresh:(callback,options)=>{refreshRegistration={callback,options};}};
 runInNewContext(compiled,context);
 const descendants=el=>[el,...el.children.flatMap(descendants)],text=el=>el.text+el.children.map(text).join('');
 const node=(tag,text='')=>({tag,tagName:tag.toUpperCase(),text,props:{},children:[],parent:null,events:{},getRootNode(){return{activeElement:null};},addEventListener(name,handler){this.events[name]=handler;},removeEventListener(name){delete this.events[name];}});
 const host=Vue.createRenderer({createElement:tag=>node(tag),createText:text=>node('#text',text),createComment:text=>node('#comment',text),setText:(el,value)=>el.text=value,setElementText:(el,value)=>{el.text=value;el.children=[];},parentNode:el=>el.parent,nextSibling:el=>el.parent?.children[el.parent.children.indexOf(el)+1]||null,patchProp:(el,key,previous,next)=>{el.props[key]=next;el[key]=next;if(key==='value')el._value=next;},insert:(el,parent,anchor=null)=>{if(el.parent)el.parent.children.splice(el.parent.children.indexOf(el),1);el.parent=parent;const at=anchor?parent.children.indexOf(anchor):-1;if(at<0)parent.children.push(el);else parent.children.splice(at,0,el);},remove:el=>{if(el.parent)el.parent.children.splice(el.parent.children.indexOf(el),1);el.parent=null;}});
 const root=node('root'),app=host.createApp(context.Component);app.mount(root);await new Promise(setImmediate);await Vue.nextTick();
 return {root,app,calls,session,grants,original,server,refreshRegistration,all:()=>descendants(root),text};
}

const tabFor=(page,name)=>page.all().find(node=>node.props.role==='tab'&&page.text(node)===name);
async function selectTab(page,name){const tab=tabFor(page,name);assert.ok(tab,`Missing tab ${name}`);await tab.props.onClick();await Vue.nextTick();return tab;}
const printerTab='الطابعات المدعومة',settingsTab='إعدادات تطبيق دنانير';
const currentPanel=page=>page.all().find(node=>node.props.role==='tabpanel');
const setValue=(field,value)=>{assert.ok(field,'Missing editable field');if(field.props['onUpdate:modelValue'])field.props['onUpdate:modelValue'](value);else{field.value=value;field.events.input({target:field});}};
const submit=page=>page.all().find(node=>node.tag==='form').props.onSubmit({preventDefault(){}});

test('the permitted panels are accessible tabs and switching reuses data without a request',async()=>{
 const page=await settingsSurface();
 try{
  const tabs=page.all().filter(node=>node.props.role==='tab');
  assert.deepEqual(tabs.map(node=>page.text(node)),[settingsTab,printerTab]);assert.ok(page.all().some(node=>node.props.role==='tablist'));
  assert.equal(tabFor(page,settingsTab).props['aria-selected'],true);assert.equal(tabFor(page,printerTab).props['aria-selected'],false);
  for(const tab of tabs){assert.equal(tab.props.type,'button');assert.equal(tab.props.id,`app-settings-tab-${page.text(tab)===settingsTab?'settings':'printers'}`);assert.ok(tab.props['aria-controls']);}
  assert.equal(currentPanel(page).props['aria-labelledby'],tabFor(page,settingsTab).props.id);assert.ok(page.all().some(node=>node.tag==='form'));
  await selectTab(page,printerTab);
  assert.equal(tabFor(page,printerTab).props['aria-selected'],true);assert.equal(currentPanel(page).props['aria-labelledby'],tabFor(page,printerTab).props.id);assert.equal(currentPanel(page).props.id,tabFor(page,printerTab).props['aria-controls']);
  await selectTab(page,settingsTab);assert.equal(page.calls.length,1);assert.equal(page.calls[0].method,'GET');
 }finally{page.app.unmount();}
});

test('supported printers retain display, engine, match and status as read-only information',async()=>{
 const page=await settingsSurface();
 try{
  await selectTab(page,printerTab);const nodes=page.all(),content=page.text(currentPanel(page));
  assert.ok(content.includes('58 و80 ملم'));
  for(const printer of page.original.config.printers){assert.ok(content.includes(printer.name));assert.ok(content.includes(printer.match));}
  for(const label of ['اسم العرض','المحرك','عبارة المطابقة','GOOJPRT / MTP-2','YHD-8006','Feasycom','مفعّل','معطّل'])assert.ok(content.includes(label),`Missing ${label}`);
  assert.ok(nodes.some(node=>node.tag==='table'));assert.deepEqual(nodes.filter(node=>node.tag==='th'&&node.props.scope==='col').map(node=>page.text(node)),['اسم العرض','المحرك','عبارة المطابقة','الحالة']);assert.equal(nodes.filter(node=>node.tag==='th'&&node.props.scope==='row').length,page.original.config.printers.length);assert.equal(nodes.some(node=>['input','select','textarea','form'].includes(node.tag)),false);assert.equal(nodes.some(node=>node.props.role==='switch'),false);
  for(const label of ['إضافة طابعة','حذف','حفظ إعدادات التطبيق','حفظ الطابعات'])assert.equal(content.includes(label),false);
  assert.equal(nodes.filter(node=>node.tag==='button').length,2,'Only the tab buttons remain');assert.deepEqual(page.server.config.printers,page.original.config.printers);assert.equal(page.calls.length,1);
 }finally{page.app.unmount();}
});

test('application settings still save without submitting or changing printer definitions',async()=>{
 const page=await settingsSurface();
 try{
  setValue(page.all().find(node=>node.tag==='input'),'اسم شركة محدث');await Vue.nextTick();assert.ok(page.all().some(node=>node.tag==='button'&&page.text(node)==='حفظ إعدادات التطبيق'));
  await submit(page);await Vue.nextTick();const call=page.calls.at(-1);assert.equal(call.method,'PUT');assert.equal(call.path,'/company/app-config');assert.equal(call.payload.version,5);
  assert.deepEqual(call.payload.config,{name:'اسم شركة محدث',about:'عن الشركة',terms:'الشروط',privacy:'الخصوصية'});assert.deepEqual(page.server.config.printers,page.original.config.printers);assert.ok(page.text(page.root).includes('تم حفظ إعدادات تطبيق دنانير.'));
  await selectTab(page,printerTab);assert.ok(page.text(currentPanel(page)).includes('طابعة الوكيل'));await selectTab(page,settingsTab);assert.equal(page.all().find(node=>node.tag==='input').value,'اسم شركة محدث');assert.equal(page.calls.length,2);
 }finally{page.app.unmount();}
});

test('a stale settings submit cannot write while the printer panel is selected',async()=>{
 const page=await settingsSurface();
 try{const staleSubmit=page.all().find(node=>node.tag==='form').props.onSubmit;await selectTab(page,printerTab);await staleSubmit({preventDefault(){}});await Vue.nextTick();assert.equal(page.calls.filter(call=>call.method==='PUT').length,0);assert.equal(page.all().some(node=>node.tag==='form'),false);}
 finally{page.app.unmount();}
});

test('each permission shows only its own tab and the filtered response remains usable',async()=>{
 for(const permission of ['app.settings','printers.manage']){
  const page=await settingsSurface([permission]);
  try{
   const settings=permission==='app.settings',content=page.text(page.root);assert.deepEqual(page.all().filter(node=>node.props.role==='tab').map(node=>page.text(node)),[settings?settingsTab:printerTab]);
   assert.equal(page.all().some(node=>node.tag==='form'),settings);assert.equal(content.includes('اسم الشركة في التطبيق'),settings);assert.equal(content.includes('عبارة المطابقة'),!settings);assert.equal(content.includes('إضافة طابعة'),false);
   await page.refreshRegistration.callback({signal:new AbortController().signal});await Vue.nextTick();assert.equal(page.calls[0].path,'/company/app-config?manage=1');assert.equal(page.calls[1].method,'GET');
   if(settings){await submit(page);await Vue.nextTick();assert.deepEqual(Object.keys(page.calls.at(-1).payload.config).sort(),['about','name','privacy','terms']);}else assert.equal(page.calls.some(call=>call.method!=='GET'),false);
  }finally{page.app.unmount();}
 }
});

test('no granted settings capability renders no heading, action or read request',async()=>{
 for(const [permissions,capabilities] of [[[],undefined],[[],{app_settings:true,printers_manage:true}],[['company.edit'],undefined],[['app.settings','printers.manage'],{app_settings:false,printers_manage:false}],[['app.settings','printers.manage'],{system_scope:false,app_settings:true,printers_manage:true}]]){
  const page=await settingsSurface(permissions,capabilities);
  try{assert.equal(page.calls.length,0);assert.equal(page.all().some(node=>['section','form','button','input','select','textarea','h2'].includes(node.tag)),false);assert.equal(page.refreshRegistration.options.canRefresh(),false);}finally{page.app.unmount();}
 }
});

test('system employees need explicit system scope even when their individual capabilities are granted',async()=>{
 for(const capabilities of [undefined,{app_settings:true,printers_manage:true}]){
  const page=await settingsSurface(['app.settings','printers.manage'],capabilities,'employee');
  try{assert.equal(page.calls.length,0);assert.equal(page.all().some(node=>['section','form','button','input','select','textarea','h2'].includes(node.tag)),false);assert.equal(page.refreshRegistration.options.canRefresh(),false);}finally{page.app.unmount();}
 }
 const page=await settingsSurface(['printers.manage'],{system_scope:true,printers_manage:true},'employee');
 try{assert.equal(page.calls.length,1);assert.ok(page.text(page.root).includes('عبارة المطابقة'));assert.equal(page.all().some(node=>['form','input','select','fieldset'].includes(node.tag)),false);}finally{page.app.unmount();}
});

test('legacy system owners retain their permitted settings when capability metadata is absent',async()=>{
 const page=await settingsSurface(['app.settings'],undefined,'owner');
 try{assert.equal(page.calls.length,1);assert.ok(page.all().some(node=>node.tag==='fieldset'));assert.equal(tabFor(page,printerTab),undefined);}finally{page.app.unmount();}
});

test('permission revocation selects the remaining permitted tab and stale submit cannot save',async()=>{
 const page=await settingsSurface();
 try{
  const staleSubmit=page.all().find(node=>node.tag==='form').props.onSubmit;page.grants.value=['printers.manage'];await new Promise(setImmediate);await Vue.nextTick();await staleSubmit({preventDefault(){}});await Vue.nextTick();
  assert.equal(tabFor(page,settingsTab),undefined);assert.equal(tabFor(page,printerTab).props['aria-selected'],true);assert.equal(page.all().some(node=>node.tag==='form'),false);assert.ok(page.text(page.root).includes('طابعة الوكيل'));assert.equal(page.calls.some(call=>call.method==='PUT'),false);
  page.grants.value=[];await Vue.nextTick();await staleSubmit({preventDefault(){}});assert.equal(page.all().some(node=>['section','form','button','input','select','textarea','h2'].includes(node.tag)),false);assert.equal(page.refreshRegistration.options.canRefresh(),false);
 }finally{page.app.unmount();}
});

test('revoking the selected printer tab never exposes its previously loaded definitions',async()=>{
 const page=await settingsSurface();
 try{
  const staleTab=await selectTab(page,printerTab);page.grants.value=['app.settings'];await new Promise(setImmediate);await Vue.nextTick();await staleTab.props.onClick();await Vue.nextTick();
  assert.equal(tabFor(page,printerTab),undefined);assert.equal(tabFor(page,settingsTab).props['aria-selected'],true);assert.equal(page.text(page.root).includes('طابعة الوكيل'),false);assert.ok(page.all().some(node=>node.tag==='form'));
 }finally{page.app.unmount();}
});

test('an old identity read is aborted and cannot populate the new account printer panel',async()=>{
 const page=await settingsSurface(['printers.manage']);
 try{
  const pending=[];page.session.api.request=(path,options)=>new Promise(resolve=>pending.push({path,options,resolve}));const stale=page.refreshRegistration.callback({signal:new AbortController().signal});page.session.state.identity.user.id=99;await Vue.nextTick();
  assert.equal(pending.length,2);assert.equal(pending[0].options.signal.aborted,true);const response=label=>({data:{version:5,config:{printers:[{...page.original.config.printers[0],name:label}]}}});
  pending[0].resolve(response('طابعة الحساب السابق'));await stale;await Vue.nextTick();assert.equal(page.text(page.root).includes('طابعة الحساب السابق'),false);
  pending[1].resolve(response('طابعة الحساب الجديد'));await new Promise(setImmediate);await Vue.nextTick();assert.ok(page.text(currentPanel(page)).includes('طابعة الحساب الجديد'));assert.equal(page.calls.some(call=>call.method==='PUT'),false);
 }finally{page.app.unmount();}
});

test('a save response received after access revocation cannot restore controls or a success notice',async()=>{
 const page=await settingsSurface();
 try{let finish;page.session.api.mutate=()=>new Promise(resolve=>finish=resolve);const saving=submit(page);page.grants.value=[];await Vue.nextTick();finish({data:structuredClone(page.original)});await saving;await Vue.nextTick();assert.equal(page.all().some(node=>node.tag==='section'),false);assert.equal(page.text(page.root).includes('تم حفظ'),false);}finally{page.app.unmount();}
});

test('refresh authorization failures discard old details and request an identity refresh',async()=>{
 for(const status of [401,403]){
  const page=await settingsSurface(['printers.manage']);
  try{
   let refreshed=0;page.session.refresh=async options=>{assert.equal(options.background,true);refreshed++;};page.session.api.request=async()=>{throw Object.assign(new Error('تعذر قراءة الإعدادات'),{status});};
   assert.equal(await page.refreshRegistration.callback({signal:new AbortController().signal}),false);await Vue.nextTick();assert.equal(refreshed,1);assert.equal(page.text(page.root).includes('طابعة الوكيل'),false);assert.equal(page.all().some(node=>['form','table','input','textarea'].includes(node.tag)),false);assert.equal(page.refreshRegistration.options.canRefresh(),false);
  }finally{page.app.unmount();}
 }
});

test('refresh keeps the selected printer tab and existing rows until fresh details arrive',async()=>{
 const page=await settingsSurface();
 try{
  await selectTab(page,printerTab);assert.equal(page.refreshRegistration.options.canRefresh(),true);let finish,signal;
  page.session.api.request=(_path,options)=>{assert.equal(options.fresh,true);signal=options.signal;return new Promise(resolve=>finish=resolve);};const reading=page.refreshRegistration.callback({signal:new AbortController().signal});await Vue.nextTick();
  assert.equal(page.refreshRegistration.options.canRefresh(),false);assert.ok(page.text(currentPanel(page)).includes('طابعة الوكيل'));assert.equal(tabFor(page,printerTab).props['aria-selected'],true);
  finish({data:{version:6,config:{...structuredClone(page.original.config),printers:[{...page.original.config.printers[0],name:'طابعة محدثة من الخادم'}]}}});assert.equal(await reading,true);await Vue.nextTick();
  assert.equal(signal.aborted,false);assert.ok(page.text(currentPanel(page)).includes('طابعة محدثة من الخادم'));assert.equal(tabFor(page,printerTab).props['aria-selected'],true);assert.equal(page.refreshRegistration.options.canRefresh(),true);assert.equal(page.calls.some(call=>call.method!=='GET'),false);
 }finally{page.app.unmount();}
});

test('settings drafts survive tab switches and a pull response cannot overwrite an edit made while reading',async()=>{
 const page=await settingsSurface();
 try{
  assert.equal(page.refreshRegistration.options.canRefresh(),true);let finish;page.session.api.request=(_path,options)=>{assert.equal(options.fresh,true);return new Promise(resolve=>finish=resolve);};const reading=page.refreshRegistration.callback({signal:new AbortController().signal});
  setValue(page.all().find(node=>node.tag==='input'),'مسودة الشركة');await Vue.nextTick();await selectTab(page,printerTab);await selectTab(page,settingsTab);assert.equal(page.all().find(node=>node.tag==='input').value,'مسودة الشركة');
  finish({data:{...structuredClone(page.original),config:{...structuredClone(page.original.config),name:'اسم جديد من الخادم'}}});assert.equal(await reading,false);await Vue.nextTick();assert.equal(page.refreshRegistration.options.canRefresh(),false);assert.equal(page.calls.some(call=>call.method!=='GET'),false);assert.equal(page.all().find(node=>node.tag==='input').value,'مسودة الشركة');
  await submit(page);await Vue.nextTick();assert.equal(page.calls.at(-1).payload.config.name,'مسودة الشركة');assert.equal('printers' in page.calls.at(-1).payload.config,false);assert.deepEqual(page.server.config.printers,page.original.config.printers);
 }finally{page.app.unmount();}
});
