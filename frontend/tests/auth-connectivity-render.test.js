import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {readFile,mkdtemp,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {dirname,join,resolve} from 'node:path';
import {runInNewContext} from 'node:vm';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import * as Vue from 'vue';
import {createRenderer,h,ref,onBeforeUnmount,nextTick} from 'vue';
import {parse,compileScript,compileTemplate} from '@vue/compiler-sfc';
import {createRouter,createMemoryHistory} from 'vue-router';
import {createMutationKey} from '../src/modules/sales/sales-model.js';
import {uiLocalizationPlugin,localizeVueSource} from '../src/modules/preferences/ui-localization-plugin.js';

async function isolatedViteServer({presence=false}={}){
  // Parallel render files must not race while renaming Vite's shared client optimizer cache.
  const temporaryRoot=resolve(tmpdir()),cacheDir=await mkdtemp(join(temporaryRoot,'dananeer-auth-render-'));
  assert.equal(dirname(cacheDir),temporaryRoot);
  try{
    const server=await createServer({configFile:false,root:fileURLToPath(new URL('../',import.meta.url)),cacheDir,logLevel:'error',optimizeDeps:{noDiscovery:true,include:[]},plugins:[
      ...presence?[{name:'isolated-presence',load(id){if(id.replaceAll('\\','/').endsWith('/modules/maps/PresenceController.vue'))return '<template><span></span></template>'; }}]:[],
      uiLocalizationPlugin(),vue(),
    ],server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
    const close=server.close.bind(server);let closing;
    server.close=()=>closing??=(async()=>{try{await close();}finally{await rm(cacheDir,{recursive:true,force:true});}})();
    return server;
  }catch(error){await rm(cacheDir,{recursive:true,force:true});throw error;}
}

// A Vue renderer fixture exercises component lifetimes and native-dialog calls;
// it does not launch a browser, send printer commands or create financial data.
function isolatedSurface(){
  const node=(tag,text='')=>({tag,text,props:{},children:[],parent:null,style:{display:''},open:false,showModal(){this.open=true;},close(){this.open=false;}});
  const root=node('root'),body=node('body');
  const renderer=createRenderer({
    createElement:tag=>node(tag),createText:text=>node('#text',text),createComment:text=>node('#comment',text),
    setText:(target,text)=>{target.text=text;},setElementText:(target,text)=>{target.text=text;target.children=[];},
    patchProp:(target,key,previous,value)=>{target.props[key]=value;},
    insert:(target,parent,anchor=null)=>{if(target.parent){const old=target.parent.children.indexOf(target);if(old>=0)target.parent.children.splice(old,1);}target.parent=parent;const at=anchor?parent.children.indexOf(anchor):-1;if(at>=0)parent.children.splice(at,0,target);else parent.children.push(target);},
    remove:target=>{if(target.parent){const at=target.parent.children.indexOf(target);if(at>=0)target.parent.children.splice(at,1);target.parent=null;}},
    parentNode:target=>target.parent,nextSibling:target=>target.parent?.children[target.parent.children.indexOf(target)+1]||null,
    querySelector:selector=>selector==='body'?body:null,
  });
  const find=(target,tag)=>target.tag===tag?target:target.children.map(child=>find(child,tag)).find(Boolean);
  return {renderer,root,body,find};
}

async function clientRender(component,path){
  const source=localizeVueSource(await readFile(new URL('../'+path,import.meta.url),'utf8'),path),descriptor=parse(source).descriptor;
  const bindings=compileScript(descriptor,{id:path}).bindings;
  const result=compileTemplate({source:descriptor.template.content,filename:path,id:path,compilerOptions:{bindingMetadata:bindings}});
  assert.deepEqual(result.errors,[]);
  const scope={};
  const code=result.code.replace(/^import \{([^}]+)\} from "vue"$/gm,(_,names)=>{for(const pair of names.split(',')){const [name,alias]=pair.trim().split(/\s+as\s+/);scope[alias||name]=Vue[name];}return '';}).replace('export function render','function render');
  component.render=runInNewContext(code+'\nrender',scope);
}

test('an outage blocks the rendered sale with a modal while retaining its uncertain mutation key, and retry restores the same instance',{timeout:30000},async()=>{
  const server=await isolatedViteServer({presence:true});
  let app;
  try{
    const PortalRoot=(await server.ssrLoadModule('/src/app/PortalRoot.vue')).default;
    const ConnectionIssue=(await server.ssrLoadModule('/src/modules/auth/SessionConnectionIssue.vue')).default;
    await clientRender(PortalRoot,'src/app/PortalRoot.vue');await clientRender(ConnectionIssue,'src/modules/auth/SessionConnectionIssue.vue');
    (await server.ssrLoadModule('/src/modules/maps/PresenceController.vue')).default.render=()=>null;
    const {createSession,portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const {createSessionNavigationGuard}=await server.ssrLoadModule('/src/router/access.js');
    const identity={data:{user:{id:1},account:{id:10,type:'pos'},portal:'pos',permissions:['sell.create']}};
    let unavailable=false,writes=0,instances=0,destroyed=0,keyCount=0,retained;
    const session=createSession('pos',{csrf:async()=>{},login:async()=>identity,me:async()=>{if(unavailable){const error=Error('Connection interrupted');error.status=503;throw error;}return identity;},mutate(path){if(['/auth/device-challenge','/device/challenge'].includes(path))return {data:{challenge_id:'fixture-proof',challenge:'exact-challenge'}};if(path==='/device/heartbeat')return {data:{allowed:true}};writes++;throw Error('Business writes are forbidden');}},{readIdentity:async()=>({installation_id:'00000000-0000-4000-8000-000000000001',proof_public_key:'fixture-public',proof_key_kind:'webcrypto'}),signChallenge:async()=>'fixture-signature'});
    const sale={setup(){instances++;const pending=ref({product_id:3,quantity:1}),key=createMutationKey(()=>`uncertain-${++keyCount}`);retained={pending,key,keyValue:key.for(pending.value)};onBeforeUnmount(()=>destroyed++);return()=>h('div','UNCERTAIN_SALE');}};
    const router=createRouter({history:createMemoryHistory(),routes:[{path:'/sell',name:'sell',component:sale,meta:{requiresAuth:true,permission:'sell.create'}},{path:'/login',name:'login',component:{render:()=>null}},{path:'/no-access',name:'no-access',component:{render:()=>null}}]});
    router.beforeEach(createSessionNavigationGuard(session,router));
    await session.login('test','password');await router.push('/sell');
    const surface=isolatedSurface(),layout={render(){return h('main',this.$slots.default?.());}};
    app=surface.renderer.createApp(PortalRoot,{layout});app.use(router);app.provide(Vue.ssrContextKey,{modules:new Set()});app.provide(portalContextKey,{session,portal:{id:'pos'}});app.mount(surface.root);await nextTick();
    assert.equal(instances,1);const initial=retained;
    unavailable=true;await session.refresh();await nextTick();
    const dialog=surface.find(surface.body,'dialog'),button=surface.find(dialog,'button');
    assert.equal(dialog.open,true,'Native modal focus must block access to any underlying form');
    assert.equal(session.can('sell.create'),false);
    assert.equal(router.currentRoute.value.path,'/sell');
    assert.equal(instances,1);assert.equal(destroyed,0);assert.equal(retained,initial);
    assert.equal(retained.key.for(retained.pending.value),initial.keyValue);
    unavailable=false;await button.props.onClick();await nextTick();
    assert.equal(dialog.open,false);assert.equal(session.can('sell.create'),true);
    assert.equal(instances,1);assert.equal(destroyed,0);assert.equal(retained,initial);
    assert.equal(retained.key.for(retained.pending.value),initial.keyValue);
    assert.equal(keyCount,1);assert.equal(writes,0);
  }finally{app?.unmount();await server.close();}
});

test('moving POS login access requires explicit confirmation and sends both concurrency versions independently of sale approval',{timeout:30000},async()=>{
 const server=await isolatedViteServer();
 let app;
 try{
  const Devices=(await server.ssrLoadModule('/src/modules/accounts/AccountDevices.vue')).default;
  await clientRender(Devices,'src/modules/accounts/AccountDevices.vue');
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const data=[{id:1,device_id:'DN-ONE',model:'Current phone',kind:'mobile',status:'approved',proof_enrolled:true,login_device:true,version:2},{id:2,device_id:'DN-TWO',model:'Replacement POS',kind:'pos',status:'pending',proof_enrolled:true,login_device:false,version:4}];
  let bindingVersion=9,reads=0;const mutations=[];
  const session={state:{identity:{account:{type:'main_agent'}}},can:permission=>permission==='pos.device',api:{request:async()=>{reads++;return {data:structuredClone(data),meta:{current_page:1,last_page:1,login_binding:{device_id:data.find(row=>row.login_device).id,version:bindingVersion}}};},mutate:async(path,method,payload)=>{mutations.push({path,method,payload});data[0].login_device=false;data[1].login_device=true;bindingVersion++;}}};
  const surface=isolatedSurface(),text=node=>node.text+node.children.map(text).join(''),find=(node,tag,caption)=>node.tag===tag&&(tag==='button'?text(node).trim()===caption:text(node).includes(caption))?node:node.children.map(child=>find(child,tag,caption)).find(Boolean);
  app=surface.renderer.createApp(Devices,{account:{id:27,device_lock_enabled:false}});app.provide(Vue.ssrContextKey,{modules:new Set()});app.provide(portalContextKey,{session});app.mount(surface.root);
  await new Promise(resolve=>setImmediate(resolve));await nextTick();
  assert.ok(find(surface.root,'span','جهاز الدخول الحالي'));assert.ok(find(surface.root,'small','دخول النقطة متاح من جهاز واحد فقط'));
  await find(surface.root,'button','نقل دخول النقطة إلى هذا الجهاز').props.onClick();await nextTick();
  assert.equal(mutations.length,0);assert.ok(find(surface.root,'p','سيُسجَّل خروج الجهاز السابق'));
  await find(surface.root,'button','إلغاء').props.onClick();await nextTick();assert.equal(find(surface.root,'button','تأكيد النقل'),undefined);assert.equal(mutations.length,0);
  await find(surface.root,'button','نقل دخول النقطة إلى هذا الجهاز').props.onClick();await nextTick();
  await find(surface.root,'button','تأكيد النقل').props.onClick();await nextTick();
  assert.deepEqual(mutations,[{path:'/accounts/27/devices/2/login-access',method:'POST',payload:{version:4,binding_version:9}}]);
  assert.equal(reads,2);assert.ok(find(surface.root,'p','تم نقل دخول النقطة'));
  assert.ok(find(surface.root,'button','اعتماد الجهاز'),'Login transfer does not silently approve the replacement for selling');
 }finally{app?.unmount();await server.close();}
});

test('a live forced change removes the business shell and validates two password fields before a single versioned save',{timeout:30000},async()=>{
 const server=await isolatedViteServer({presence:true});
 let app;
 try{
  const PortalRoot=(await server.ssrLoadModule('/src/app/PortalRoot.vue')).default;
  const PasswordPage=(await server.ssrLoadModule('/src/modules/auth/PasswordChangePage.vue')).default;
  const Field=(await server.ssrLoadModule('/src/modules/accounts/FormField.vue')).default;
  for(const [component,path] of [[PortalRoot,'src/app/PortalRoot.vue'],[PasswordPage,'src/modules/auth/PasswordChangePage.vue'],[Field,'src/modules/accounts/FormField.vue']])await clientRender(component,path);
  (await server.ssrLoadModule('/src/modules/maps/PresenceController.vue')).default.render=()=>null;
  const {createSession,portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const {createSessionNavigationGuard}=await server.ssrLoadModule('/src/router/access.js');
  const identity=required=>({data:{user:{id:1},account:{id:10,type:'main_agent'},membership:{kind:'owner'},portal:'agents',permissions:['dashboard.view'],password_reset:{required,version:required?3:4}}});
  let resolveSave,rejectSave,disposed=0,serverVersion=5;const writes=[];
  const session=createSession('agents',{csrf:async()=>{},login:async()=>identity(false),me:async()=>{const next=identity(true);next.data.password_reset.version=serverVersion;return next;},completePasswordReset:body=>{writes.push(body);return new Promise((done,reject)=>{resolveSave=done;rejectSave=reject;});}});
  const business={setup(){onBeforeUnmount(()=>disposed++);return()=>h('div','BUSINESS_PAGE');}};
  const router=createRouter({history:createMemoryHistory(),routes:[{name:'dashboard',path:'/dashboard',component:business,meta:{requiresAuth:true,permission:'dashboard.view'}},{name:'password-change',path:'/change-password',component:PasswordPage,meta:{requiresAuth:true}},{name:'login',path:'/login',component:{render:()=>null}}]});router.beforeEach(createSessionNavigationGuard(session,router));
  await session.login('agent','secret');await router.push('/dashboard');
  const surface=isolatedSurface(),text=node=>node.text+node.children.map(text).join(''),all=(node,tag)=>[...(node.tag===tag?[node]:[]),...node.children.flatMap(child=>all(child,tag))];
  app=surface.renderer.createApp(PortalRoot,{layout:{render(){return h('main',this.$slots.default?.());}}});app.use(router);app.provide(Vue.ssrContextKey,{modules:new Set()});app.provide(portalContextKey,{session,portal:{id:'agents'}});app.mount(surface.root);await nextTick();assert.ok(text(surface.root).includes('BUSINESS_PAGE'));
  session.requirePasswordChange({required:true,version:5});
  for(let attempt=0;attempt<20&&!all(surface.root,'input').length;attempt++){await new Promise(done=>setImmediate(done));await nextTick();}
  assert.equal(disposed,1);assert.ok(!text(surface.root).includes('BUSINESS_PAGE'));assert.equal(session.state.identity.user.id,1);
  const inputs=all(surface.root,'input');assert.equal(inputs.length,2);
  inputs[0].props.onInput({target:{value:'newpass123'}});inputs[1].props.onInput({target:{value:'mismatch123'}});await nextTick();
  let form=surface.find(surface.root,'form');await form.props.onSubmit({preventDefault(){}});await nextTick();assert.equal(writes.length,0);assert.ok(text(surface.root).includes('تأكيد كلمة المرور غير مطابق'));
  inputs[1].props.onInput({target:{value:'newpass123'}});await nextTick();const pending=form.props.onSubmit({preventDefault(){}});await form.props.onSubmit({preventDefault(){}});assert.equal(writes.length,1);assert.deepEqual(writes[0],{version:5,password:'newpass123',password_confirmation:'newpass123'});
  serverVersion=6;rejectSave(Object.assign(new Error('تغير طلب إعادة التعيين. راجع الطلب الجديد.'),{status:409}));await pending;await nextTick();assert.equal(session.can('dashboard.view'),false);assert.equal(session.state.identity.password_reset.version,6);assert.equal(writes.length,1,'A concurrency conflict must not retry or submit another credential change automatically');
  const retried=form.props.onSubmit({preventDefault(){}});assert.equal(writes.length,2);assert.equal(writes[1].version,6);
  const completed=identity(false);completed.data.password_reset.version=7;resolveSave(completed);await retried;await nextTick();assert.equal(session.can('dashboard.view'),true);assert.ok(text(surface.root).includes('BUSINESS_PAGE'));
 }finally{app?.unmount();await server.close();}
});

test('manager reset requires confirmation, locks duplicate submits and includes only the selected device request and account version',{timeout:30000},async()=>{
 const server=await isolatedViteServer();let app;
 try{
  const Dialog=(await server.ssrLoadModule('/src/modules/accounts/AccountPasswordResetDialog.vue')).default,Modal=(await server.ssrLoadModule('/src/modules/accounts/AccountModal.vue')).default,Field=(await server.ssrLoadModule('/src/modules/accounts/FormField.vue')).default;
  for(const [component,path] of [[Dialog,'src/modules/accounts/AccountPasswordResetDialog.vue'],[Modal,'src/modules/accounts/AccountModal.vue'],[Field,'src/modules/accounts/FormField.vue']])await clientRender(component,path);
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');const writes=[];let finish;
  const session={state:{identity:{account:{id:1,type:'system'},membership:{kind:'owner'},permissions:['account.password_reset']}},api:{resetAccountPassword:(id,body)=>{writes.push({id,body});return new Promise(done=>finish=done);}},can:()=>true};
  const account={id:27,name:'نقطة تجريبية',type:'pos',version:11,can_reset_password:true,password_reset_requests:[{id:'new-device-request',request_label:'DEVICE',trusted:false,device_label:'جهاز جديد'}]};
  const router=createRouter({history:createMemoryHistory(),routes:[{path:'/',component:{render:()=>null}}]});const surface=isolatedSurface(),text=node=>node.text+node.children.map(text).join(''),all=(node,tag)=>[...(node.tag===tag?[node]:[]),...node.children.flatMap(child=>all(child,tag))];
  app=surface.renderer.createApp(Dialog,{account});app.use(router);app.provide(Vue.ssrContextKey,{modules:new Set()});app.provide(portalContextKey,{session});app.mount(surface.root);await nextTick();assert.equal(writes.length,0);
  const select=surface.find(surface.root,'select');select.props.onChange({target:{value:'new-device-request'}});surface.find(surface.root,'textarea').props.onInput({target:{value:' طلب صاحب النقطة '}});await nextTick();
  const form=surface.find(surface.root,'form'),pending=form.props.onSubmit({preventDefault(){}});await form.props.onSubmit({preventDefault(){}});assert.deepEqual(writes,[{id:27,body:{version:11,reason:'طلب صاحب النقطة',request_id:'new-device-request'}}]);finish({data:{}});await pending;await nextTick();assert.ok(text(surface.root).includes('تم طلب تغيير كلمة المرور'));assert.equal(all(surface.root,'form').length,0);
 }finally{app?.unmount();await server.close();}
});

test('an approved same-device login recovery opens new-password fields automatically without a request, link, OTP or auto-login',{timeout:30000},async()=>{
 const previousWindow=globalThis.window,previousDocument=globalThis.document;
 globalThis.window={addEventListener(){},removeEventListener(){}};globalThis.document={visibilityState:'visible',addEventListener(){},removeEventListener(){}};
 const server=await isolatedViteServer();let app;
 try{
  const Recovery=(await server.ssrLoadModule('/src/modules/auth/ManagerRecovery.vue')).default,Modal=(await server.ssrLoadModule('/src/modules/accounts/AccountModal.vue')).default,Field=(await server.ssrLoadModule('/src/modules/accounts/FormField.vue')).default;
  for(const [component,path] of [[Recovery,'src/modules/auth/ManagerRecovery.vue'],[Modal,'src/modules/accounts/AccountModal.vue'],[Field,'src/modules/accounts/FormField.vue']])await clientRender(component,path);
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');const writes=[];let completed=0;
  const session={api:{passwordRecoveryStatus:async()=>({data:{status:'approved',version:7,trusted:true}}),requestPasswordRecovery:()=>{throw Error('A familiar device must not require another request');},completePasswordRecovery:async body=>{writes.push(body);return {data:{completed:true}};},login:()=>{throw Error('Recovery may not sign in automatically');}}};
  const surface=isolatedSurface(),text=node=>node.text+node.children.map(text).join(''),all=(node,tag)=>[...(node.tag===tag?[node]:[]),...node.children.flatMap(child=>all(child,tag))];
  app=surface.renderer.createApp(Recovery,{open:false,login:'remembered-user',onComplete:()=>completed++});app.provide(Vue.ssrContextKey,{modules:new Set()});app.provide(portalContextKey,{session,portal:{id:'agents'}});app.mount(surface.root);
  await new Promise(done=>setImmediate(done));await nextTick();assert.equal(surface.find(surface.root,'dialog').open,true);const inputs=all(surface.root,'input');assert.equal(inputs.length,2);assert.ok(inputs.every(input=>input.props.type==='password'));assert.ok(!text(surface.root).includes('مرجع الطلب'));
  inputs[0].props.onInput({target:{value:'newpass123'}});inputs[1].props.onInput({target:{value:'newpass123'}});await nextTick();await surface.find(surface.root,'form').props.onSubmit({preventDefault(){}});await nextTick();assert.deepEqual(writes,[{version:7,password:'newpass123',password_confirmation:'newpass123'}]);assert.equal(completed,1);
 }finally{app?.unmount();await server.close();globalThis.window=previousWindow;globalThis.document=previousDocument;}
});
