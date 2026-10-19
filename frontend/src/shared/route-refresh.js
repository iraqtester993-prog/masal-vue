import {getCurrentInstance,inject,onBeforeUnmount} from 'vue';

export const routeRefreshContextKey=Symbol('dananeer.route-refresh');

// Registrations belong to the mounted page. A visible child can own its refresh
// without re-creating the router view or discarding a parent's form and filters.
export function createRouteRefreshCoordinator(){
 const entries=[];let flight=null,generation=0;
 const current=()=>entries.reduce((best,item)=>!best||item.priority>=best.priority?item:best,null);
 function register(callback,{canRefresh=()=>true,priority=0}={}){
  const entry={callback,canRefresh,priority:Number(priority)||0};entries.push(entry);
  return ()=>{const index=entries.indexOf(entry);if(index>=0)entries.splice(index,1);if(flight?.entry===entry)cancel();};
 }
 function canRefresh(){const entry=current();return !flight&&!!entry&&entry.canRefresh();}
 function cancel(){generation++;flight?.controller.abort();flight=null;}
 function refresh(){
  if(flight)return flight.promise;
  const entry=current();if(!entry||!entry.canRefresh())return Promise.resolve(false);
  const expected=++generation,controller=new AbortController(),request={entry,controller,promise:null};
  flight=request;
  request.promise=Promise.resolve().then(()=>{
   controller.signal.throwIfAborted();return entry?.callback({signal:controller.signal});
  }).then(result=>{controller.signal.throwIfAborted();return result!==false&&generation===expected;}).finally(()=>{if(flight===request)flight=null;});
  return request.promise;
 }
 return {register,canRefresh,refresh,cancel};
}

export function useRouteRefresh(callback,options){
 if(!getCurrentInstance())return ()=>{};
 const coordinator=inject(routeRefreshContextKey,null),release=coordinator?.register(callback,options)||(()=>{});
 onBeforeUnmount(release);return release;
}
