import {nextTick,shallowRef,watchEffect} from 'vue';
import {landingRoute,routeAllowed} from '../router/access.js';

const layers=shallowRef([]),scopeKey='dananeerPosBack',layerKey='dananeerPosLayer';
// Only UI callbacks and opaque navigation metadata; never receipts or sale payloads.
export function registerPosBackLayer({state,back,priority=0}){
 if(typeof window==='undefined')return ()=>{};
 const layer={state,back,priority:Number(priority)||0};layers.value=[...layers.value,layer];
 return ()=>{layers.value=layers.value.filter(value=>value!==layer);};
}

export function installPosBackNavigation({router,session,target=typeof window==='undefined'?null:window,surface=typeof document==='undefined'?null:document}={}){
 if(!target||!surface)return {dispose(){}};
 const history=router.options.history,originalListen=history.listen,originalDestroy=history.destroy;
 let disposed=false,scope=null,floor=null,guard=null,navigating=false,locked=false,queued=false,suppressPop=false,backDelta=0,pendingRemoval=null;
 const isPos=()=>session.state.identity?.account.type==='pos'||surface.documentElement?.dataset.portal==='pos';
 const identityScope=()=>{const value=session.state.identity;return value?.account?.type==='pos'&&value.user?.id&&value.account?.id?`${value.user.id}:${value.account.id}`:null;};
 const state=()=>history.state||{};
 function topDialog(){const values=[...surface.querySelectorAll('dialog[open]')],focused=surface.activeElement?.closest?.('dialog[open]');return values.includes(focused)?focused:values.filter(value=>{try{return value.matches(':modal');}catch{return true;}}).at(-1)||values.at(-1);}
 function currentLayer(){return [...layers.value].reverse().sort((a,b)=>b.priority-a.priority).find(value=>value.state()!=='none');}
 const anyBlockedLayer=()=>layers.value.some(value=>value.state()==='blocked');
 const protectedWork=()=>!!session.api.hasPendingMutation?.();
 function tag(){if(scope&&Number.isInteger(state().position))history.replace(history.location,{[scopeKey]:{scope,floor}});}
 function updateScope(){
  const next=identityScope();if(next===scope){if(!scope&&session.state.checked&&!session.state.checking&&(state()[scopeKey]||state()[layerKey]))history.replace(history.location,{[scopeKey]:null,[layerKey]:null});return;}scope=next;guard=null;floor=null;
  if(scope){const saved=state()[scopeKey];floor=saved?.scope===scope&&Number.isInteger(saved.floor)?saved.floor:state().position;const savedGuard=state()[layerKey];if(savedGuard?.scope===scope)guard=savedGuard;tag();}
  else if(session.state.checked&&!session.state.checking)history.replace(history.location,{[scopeKey]:null,[layerKey]:null});
 }
 function hasLayer(){return !!topDialog()||!!currentLayer();}
 function arm(){
  if(disposed||!isPos()||!scope||navigating||locked||pendingRemoval||guard||!hasLayer())return;
  const position=state().position;if(!Number.isInteger(position))return;
  guard={scope,base:position,path:history.location};history.push(history.location,{[scopeKey]:{scope,floor},[layerKey]:guard});
 }
 function removeGuard(){
  if(pendingRemoval)return pendingRemoval.promise;if(!guard)return Promise.resolve(true);
  if(state()[layerKey]?.scope!==scope){guard=null;return Promise.resolve(true);}
  let resolve;const promise=new Promise(done=>resolve=done);pendingRemoval={promise,resolve,position:guard.base};guard=null;history.go(-1,false);return promise;
 }
 function refresh(){if(disposed||!isPos())return;updateScope();if(navigating||locked||pendingRemoval)return;if(hasLayer())arm();else if(guard)void removeGuard();}
 function schedule(){if(queued||disposed)return;queued=true;void nextTick(()=>{queued=false;refresh();});}
 function settle(){void nextTick(()=>{locked=false;schedule();});}
 function closeLayer(){
  if(protectedWork())return true;
  const dialog=topDialog();
  if(dialog){if(typeof dialog.requestClose==='function')dialog.requestClose();else{const event=new target.Event('cancel',{cancelable:true});if(dialog.dispatchEvent(event))dialog.close();}return true;}
  const layer=currentLayer();if(!layer)return false;if(layer.state()==='open')layer.back();return true;
 }
 function safeBack(){
  const value=state(),back=value.back;
  if(!scope||!Number.isInteger(value.position)||value.position<=floor||typeof back!=='string'||!back.startsWith('/')||back.startsWith('//'))return false;
  const route=router.resolve(back);return route.name!=='login'&&route.name!=='session-unavailable'&&route.meta.requiresAuth&&routeAllowed(route,session);
 }
 function onNative(event){
  if(disposed||!isPos())return;
  const handled=result=>{event.preventDefault();if(event.detail&&typeof event.detail==='object')event.detail.result=result;};updateScope();
  if(locked||navigating||pendingRemoval||session.state.busy||session.state.checking||!router.currentRoute.value.matched.length){handled('handled');return;}
  locked=true;if(closeLayer()){handled('handled');settle();return;}
  if(scope&&router.currentRoute.value.name==='dashboard'&&event.detail?.exitConfirmationVersion===1){locked=false;handled('exit');return;}
  if(safeBack()){handled('handled');router.back();return;}
  const landing=scope?landingRoute(router,session):null;
  if(landing&&router.currentRoute.value.name!==landing.name){handled('handled');void router.replace(landing);return;}
  locked=false;handled('exit');
 }
 function onHistoryPop(to,from,info){
  suppressPop=false;backDelta=info.delta;if(disposed||!isPos())return;
  if(guard&&info.delta<0&&state().position===guard.base&&to===from){suppressPop=true;guard=null;locked=true;closeLayer();settle();return;}
  if(state()[layerKey]?.scope===scope&&to===from&&!hasLayer()){suppressPop=true;guard=state()[layerKey];void removeGuard();}
 }
 const stopHistory=originalListen.call(history,onHistoryPop);
 history.listen=callback=>originalListen.call(history,(...args)=>{if(!suppressPop||disposed||!isPos())callback(...args);});
 function windowPop(){if(pendingRemoval&&state().position===pendingRemoval.position){const pending=pendingRemoval;pendingRemoval=null;history.replace(history.location,{[layerKey]:null});pending.resolve(true);schedule();}}
 const stopBefore=router.beforeEach(async(to,from)=>{
  if(!isPos())return;updateScope();navigating=true;
  if(from.matched.length&&(protectedWork()||anyBlockedLayer()))return false;
  if(backDelta<0&&scope&&(to.name==='login'||state().position<floor))return false;
  backDelta=0;if(guard)await removeGuard();
 });
 const stopAfter=router.afterEach((_to,_from,failure)=>{navigating=false;locked=false;backDelta=0;if(!failure){updateScope();tag();}schedule();});
 const stopError=router.onError(()=>{navigating=false;locked=false;backDelta=0;schedule();});
 const stopWatch=watchEffect(()=>{identityScope();session.state.checked;for(const layer of layers.value)layer.state();schedule();});
 const observer=typeof target.MutationObserver==='function'?new target.MutationObserver(schedule):null;
 observer?.observe(surface.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:['open']});
 target.addEventListener('dananeer:native-back',onNative);target.addEventListener('popstate',windowPop);
 function dispose(){
  if(disposed)return;disposed=true;stopWatch();stopHistory();stopBefore();stopAfter();stopError();observer?.disconnect();
  target.removeEventListener('dananeer:native-back',onNative);target.removeEventListener('popstate',windowPop);
  history.listen=originalListen;history.destroy=originalDestroy;pendingRemoval?.resolve(false);pendingRemoval=null;
 }
 history.destroy=()=>{dispose();originalDestroy.call(history);};schedule();return {dispose};
}
