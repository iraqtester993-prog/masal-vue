import test from 'node:test';
import assert from 'node:assert/strict';
import {createRouter,createWebHistory} from 'vue-router';
import {nextTick,reactive,ref} from 'vue';
import {installPosBackNavigation,registerPosBackLayer} from '../src/shared/pos-back-navigation.js';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {parse} from '@vue/compiler-sfc';
import {computed} from 'vue';

const flush=async()=>{for(let i=0;i<8;i++){await nextTick();await new Promise(resolve=>setImmediate(resolve));}};
function browser(){
 const target=new EventTarget(),surface=new EventTarget(),location=new URL('https://pos.local/dashboard');
 const entries=[{url:location.href,state:null}];let at=0;
 Object.assign(target,{Event,location,pageXOffset:0,pageYOffset:0});
 surface.documentElement={dataset:{portal:'pos'}};surface.querySelector=()=>null;surface.dialogs=[];surface.querySelectorAll=()=>surface.dialogs.filter(value=>value.open);
 const history={get state(){return entries[at].state;},get length(){return entries.length;},replaceState(state,_title,url){entries[at]={state:structuredClone(state),url:url?new URL(url,location).href:location.href};location.href=entries[at].url;},pushState(state,_title,url){entries.splice(at+1);entries.push({state:structuredClone(state),url:new URL(url,location).href});at++;location.href=entries[at].url;},go(delta){const next=at+delta;if(next<0||next>=entries.length)return;queueMicrotask(()=>{at=next;location.href=entries[at].url;const event=new Event('popstate');event.state=history.state;target.dispatchEvent(event);});}};
 target.history=history;
 const saved=new Map(['window','document','history','location'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 for(const[key,value]of Object.entries({window:target,document:surface,history,location}))Object.defineProperty(globalThis,key,{value,configurable:true});
 return {target,surface,history,entries,get index(){return at;},restore(){for(const[key,value]of saved){if(value)Object.defineProperty(globalThis,key,value);else delete globalThis[key];}}};
}
async function fixture({type='pos',ready=true,exitConfirmationVersion=1}={}){
 const env=browser(),session={state:reactive({identity:ready?{user:{id:7},account:{id:12,type}}:null,checked:ready,checking:!ready,busy:false}),api:{hasPendingMutation:()=>false},can:()=>true};env.surface.documentElement.dataset.portal=type==='pos'?'pos':'admin';
 const router=createRouter({history:createWebHistory('/'),routes:['dashboard','sell','sales','reports','login'].map(name=>({path:'/'+name,name,meta:{requiresAuth:name!=='login'},component:{render:()=>null}}))});
 const control=installPosBackNavigation({router,session,target:env.target,surface:env.surface});
 if(ready){await router.push('/dashboard');await flush();}
 const native=()=>{const event=new Event('dananeer:native-back',{cancelable:true});event.detail={result:'unhandled',exitConfirmationVersion};env.target.dispatchEvent(event);return{prevented:event.defaultPrevented,result:event.detail.result};};
 return {...env,router,session,native,dispose(){control.dispose();router.options.history.destroy();env.restore();}};
}

test('actual web history is sequential, preserves query strings and exits at the authenticated floor without login history',async()=>{
 const f=await fixture();try{
  const floor=f.history.state.position;await f.router.push('/sell?company=stock:4');await f.router.push('/sales?q=91');
  assert.equal(f.history.state.position,floor+2);assert.equal(f.history.state.back,'/sell?company=stock:4');
  assert.equal(f.native().result,'handled');assert.equal(f.native().result,'handled');await flush();assert.equal(f.router.currentRoute.value.fullPath,'/sell?company=stock:4');
  f.native();await flush();assert.equal(f.router.currentRoute.value.name,'dashboard');assert.deepEqual(f.native(),{prevented:true,result:'exit'});
 }finally{f.dispose();}
});

test('home requests native exit confirmation even with earlier pages, preserving account and history',async()=>{
 const f=await fixture();try{
  await f.router.push('/sell');await f.router.push('/dashboard');await flush();
  const position=f.history.state.position,identity=f.session.state.identity;
  assert.equal(f.native().result,'exit');await flush();
  assert.equal(f.router.currentRoute.value.name,'dashboard');assert.equal(f.history.state.position,position);
  assert.equal(f.session.state.identity,identity);assert.equal(f.session.state.checked,true);
 }finally{f.dispose();}
 const old=await fixture({exitConfirmationVersion:0});try{
  await old.router.push('/sell');await old.router.push('/dashboard');await flush();
  assert.equal(old.native().result,'handled');await flush();assert.equal(old.router.currentRoute.value.name,'sell');
 }finally{old.dispose();}
});

test('double-back exit carries a stable generation only while the same authenticated navigation state remains active',async()=>{
 const f=await fixture();
 function exitGeneration(){const event=new Event('dananeer:native-back',{cancelable:true});event.detail={result:'unhandled',exitConfirmationVersion:1,doubleBackExitVersion:1};f.target.dispatchEvent(event);assert.equal(event.detail.result,'exit');return event.detail.exitGeneration;}
 try{
  const initial=exitGeneration();assert.equal(exitGeneration(),initial);
  await f.router.push('/sell');await f.router.push('/dashboard');await flush();
  assert.ok(exitGeneration()>initial,'leaving and returning home invalidates the prior first Back');
 }finally{f.dispose();}
});

test('home closes its open layer first and blocks exit while work is pending',async()=>{
 const f=await fixture(),menu=ref(true),blocked=ref(false),release=registerPosBackLayer({state:()=>blocked.value?'blocked':menu.value?'open':'none',back:()=>menu.value=false});
 try{
  await flush();assert.equal(f.native().result,'handled');await flush();assert.equal(menu.value,false);
  blocked.value=true;await flush();assert.equal(f.native().result,'handled');await flush();
  blocked.value=false;await flush();f.session.api.hasPendingMutation=()=>true;assert.equal(f.native().result,'handled');await flush();
  f.session.api.hasPendingMutation=()=>false;assert.equal(f.native().result,'exit');assert.equal(f.router.currentRoute.value.name,'dashboard');
 }finally{release();f.dispose();}
});

test('PWA back closes temporary UI before router navigation and programmatic navigation leaves no duplicate chain',async()=>{
 const f=await fixture(),detail=ref(false),search=ref(false),release=registerPosBackLayer({state:()=>detail.value||search.value?'open':'none',back:()=>{if(search.value)search.value=false;else detail.value=false;}});
 try{
  await f.router.push('/sell');const base=f.history.state.position;detail.value=true;search.value=true;await flush();assert.equal(f.history.state.position,base+1);assert.equal(f.history.state.back,'/sell');
  f.history.go(-1);await flush();assert.equal(search.value,false);assert.equal(detail.value,true);assert.equal(f.router.currentRoute.value.name,'sell');
  f.history.go(-1);await flush();assert.equal(detail.value,false);assert.equal(f.history.state.position,base);
  f.history.go(1);await flush();assert.equal(f.history.state.position,base,'Forward cannot resurrect a closed UI guard as a duplicate page');
  detail.value=true;await flush();const stop=f.router.afterEach(to=>{if(to.name==='reports')release();});await f.router.push('/reports');await flush();stop();assert.equal(f.history.state.back,'/sell');
  release();await flush();f.native();await flush();assert.equal(f.router.currentRoute.value.name,'sell');f.native();await flush();assert.equal(f.router.currentRoute.value.name,'dashboard');
 }finally{release();f.dispose();}
});

test('modal cancellation respects busy cancel.prevent and synchronous double native back cannot close a second layer',async()=>{
 const f=await fixture(),detail=ref(true),release=registerPosBackLayer({state:()=>detail.value?'open':'none',back:()=>detail.value=false});
 const dialog=new EventTarget();dialog.open=true;dialog.matches=()=>true;dialog.close=()=>dialog.open=false;let busy=true,cancels=0;
 dialog.addEventListener('cancel',event=>{event.preventDefault();cancels++;if(!busy)void nextTick(()=>dialog.open=false);});f.surface.dialogs=[dialog];
 try{
  await flush();f.native();await flush();assert.equal(dialog.open,true);assert.equal(cancels,1);assert.equal(detail.value,true);
  busy=false;f.native();f.native();await flush();assert.equal(dialog.open,false);assert.equal(detail.value,true);assert.equal(cancels,2);
  f.native();await flush();assert.equal(detail.value,false);
 }finally{release();f.dispose();}
});

test('priority closes the sidebar first; protected mutations and uncertain layers prevent native and PWA navigation',async()=>{
 const f=await fixture(),detail=ref(true),menu=ref(true),blocked=ref(false),release=registerPosBackLayer({state:()=>blocked.value?'blocked':detail.value?'open':'none',back:()=>detail.value=false}),releaseMenu=registerPosBackLayer({priority:100,state:()=>menu.value?'open':'none',back:()=>menu.value=false});
 try{
  await f.router.push('/sell');await flush();f.native();await flush();assert.equal(menu.value,false);assert.equal(detail.value,true);
  blocked.value=true;menu.value=true;assert.ok(await f.router.push('/reports'));assert.equal(f.router.currentRoute.value.name,'sell','an open sidebar cannot hide an uncertain sale below it');f.native();await flush();assert.equal(menu.value,false);assert.equal(detail.value,true);f.history.go(-1);await flush();assert.equal(f.router.currentRoute.value.name,'sell');assert.equal(detail.value,true);
  blocked.value=false;f.session.api.hasPendingMutation=()=>true;f.native();await flush();assert.equal(detail.value,true);assert.ok(await f.router.push('/reports'));assert.equal(f.router.currentRoute.value.name,'sell');
 }finally{releaseMenu();release();f.dispose();}
});

test('async bootstrap is consumed; identity changes cannot back into the preceding account and admin remains untouched',async()=>{
 const f=await fixture({ready:false});try{
  assert.equal(f.native().result,'handled');f.session.state.identity={user:{id:7},account:{id:12,type:'pos'}};f.session.state.checked=true;f.session.state.checking=false;await f.router.push('/dashboard');await f.router.push('/sales');await flush();
  f.session.state.identity={user:{id:8},account:{id:13,type:'pos'}};await flush();f.native();await flush();assert.equal(f.router.currentRoute.value.name,'dashboard');assert.equal(f.native().result,'exit');
 }finally{f.dispose();}
 const admin=await fixture({type:'system'});try{await admin.router.push('/sales');assert.deepEqual(admin.native(),{prevented:false,result:'unhandled'});assert.equal(admin.history.state.dananeerPosBack,undefined);}finally{admin.dispose();}
});

test('actual digital sale back hook steps out of confirmation and protects recovering, pending and reserved orders without mutation',async()=>{
 const source=await readFile(new URL('../src/modules/digital/DigitalSell.vue',import.meta.url),'utf8'),script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const emitted=[],vm={pos:ref(true),state:reactive({busy:false,offers:[],connections:[]})},props={vm,focusedOffer:{id:1,connection_id:2}};
 const context={computed,reactive,ref,watch(){},onMounted(){},onBeforeUnmount(){},defineProps:()=>props,defineEmits:()=>event=>emitted.push(event),usePreferencesTranslator:()=>value=>value};
 runInNewContext(script+'\nglobalThis.controls={digitalBackState,digitalBack,form,confirmation,pending,order,recovering,quoteLoading};',context);const c=context.controls;
 assert.equal(c.digitalBackState(),'blocked');c.recovering.value=false;c.form.mobile='07701234567';c.confirmation.value={expected_retail:'5000'};assert.equal(c.digitalBackState(),'open');c.digitalBack();assert.equal(c.confirmation.value,null);assert.equal(c.form.mobile,'07701234567');assert.equal(emitted.length,0);
 c.pending.value=Object.freeze({request_id:'same-request'});assert.equal(c.digitalBackState(),'blocked');c.pending.value=null;c.order.value={reservation_active:true};assert.equal(c.digitalBackState(),'blocked');
 c.order.value={reservation_active:false};assert.equal(c.digitalBackState(),'open');c.digitalBack();assert.deepEqual(emitted,['back']);assert.equal(c.form.mobile,'');
});
