import test from 'node:test';
import assert from 'node:assert/strict';
import {createMapsApi} from '../src/modules/maps/maps-api.js';
import {branchIds,filterMapUsers,geolocationPayload,mapUser,requiresLocation} from '../src/modules/maps/maps-model.js';
import {createPresenceRuntime} from '../src/modules/maps/presence-runtime.js';
import {createMapRenderer,markerMarkup,popupNode} from '../src/modules/maps/maps-renderer.js';

const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};
const identity=(id=11,type='pos',kind='owner')=>({user:{id},account:{id:id+20,type},membership:{kind}});
function environment(){let clock=Date.parse('2026-10-06T09:00:00Z'),watch=0;const handlers=new Map(),timers=new Map(),cleared=[],watchers=new Map(),permission={state:'prompt',onchange:null};return {surface:{addEventListener:(name,fn)=>handlers.set(name,fn),removeEventListener:name=>handlers.delete(name)},document:{visibilityState:'visible',addEventListener:(name,fn)=>handlers.set(name,fn),removeEventListener:name=>handlers.delete(name)},geolocation:{watchPosition:(success,error,options)=>{watchers.set(++watch,{success,error,options});return watch;},clearWatch:id=>{cleared.push(id);watchers.delete(id);}},permissions:{query:async()=>permission},now:()=>clock,setInterval:(fn,delay)=>{timers.set(delay,fn);return delay;},clearInterval:delay=>timers.delete(delay),advance:ms=>{clock+=ms;},handlers,timers,watchers,cleared,permission};}
function apiFixture(){let consent=1;const calls=[];return {calls,own:async()=>{calls.push(['own']);return {data:{location_required:true,location_ready:false,consent_version:consent}};},heartbeat:async(body,signal)=>{calls.push(['heartbeat',body,signal]);},locate:async(body,signal)=>{calls.push(['locate',body,signal]);return {data:{location_ready:true}};},disconnect:async(signal,options)=>{calls.push(['disconnect',options]);consent++;}};}
function sample(env,lat=33.3){return {coords:{latitude:lat,longitude:44.4,accuracy:8},timestamp:env.now()};}

test('Maps API sends authoritative consent/version, optional actual metadata and keepalive without client-generated online flags',async()=>{
  const calls=[],client={request:async(path,options)=>{calls.push({path,...options});},mutate:async(path,method,body,signal,options)=>{calls.push({path,method,body,signal,options});}},api=createMapsApi(client),signal=new AbortController().signal;
  await api.users({user_ids:[8,9],branch_id:4},signal);assert.equal(calls[0].path,'/maps/users?user_ids%5B0%5D=8&user_ids%5B1%5D=9&branch_id=4');
  await api.heartbeat({device_model:'actual model',app_version:'actual version'},signal);assert.deepEqual(calls[1].body,{device_model:'actual model',app_version:'actual version'});
  const body={latitude:33,longitude:44,accuracy:10,recorded_at:'2026-10-06T09:00:00Z',consent_version:3};await api.locate(body,signal);assert.equal(calls[2].body,body);
  await api.disconnect(undefined,{keepalive:true});assert.deepEqual(calls[3].body,{});assert.equal(calls[3].options.keepalive,true);await api.saveLocation(7,{latitude:33,longitude:44,version:0},signal);assert.equal(calls[4].path,'/maps/accounts/7/location');assert.equal(calls[4].method,'PUT');
});

test('map hierarchy uses canonical branch levels and never invents locations, symbols or active presence',()=>{
  const now=Date.parse('2026-10-06T09:00:00Z'),base={name:'المستخدم',account:'الفرع',kind:'فرع',active:true,online:true,lastSeen:new Date(now-1000).toISOString(),location:{lat:33,lng:44}},users=[mapUser({...base,id:1,role:'main_agent',account_id:5,agent:5}),mapUser({...base,id:2,role:'sub_agent',account_id:6,agent:6}),mapUser({...base,id:3,role:'sub_branch',account_id:7,agent:7}),mapUser({...base,id:4,role:'pos',account_id:8,agent:7,lastSeen:new Date(now-120000).toISOString()}),mapUser({...base,id:5,role:'employee',account_id:6,agent:6,location:{lat:99,lng:NaN},networkColor:'" onclick="evil()',symbol:'<script>'})],branches=[{id:5,parent_id:null},{id:6,parent_id:5},{id:7,parent_id:6}];
  const filters={query:'',type:'sub',branch:'5',status:'',custom:false,selected:[]};assert.deepEqual(filterMapUsers(users,filters,branches,now).map(user=>user.id),[2,3]);assert.deepEqual([...branchIds(5,branches)],[5,6,7]);
  filters.type='';filters.status='offline';assert.deepEqual(filterMapUsers(users,filters,branches,now).map(user=>user.id),[4]);filters.status='';filters.custom=true;filters.selected=[5,999];assert.deepEqual(filterMapUsers(users,filters,branches,now).map(user=>user.id),[5]);assert.equal(users[4].location,null);assert.equal(users[4].networkColor,'#0898b5');assert.equal(users[4].symbol,'●');
  assert.equal(requiresLocation(identity(1,'system')),false);assert.equal(requiresLocation(identity(1,'pos','employee')),false);for(const type of ['main_agent','sub_agent','sub_branch','pos'])assert.equal(requiresLocation(identity(1,type)),true);
});

test('physical GPS payload validates coordinates, accuracy and recency without replacing recorded time',()=>{
  const now=Date.parse('2026-10-06T09:00:00Z'),position={coords:{latitude:33.12345,longitude:44.23456,accuracy:10},timestamp:now-1000};assert.deepEqual(geolocationPayload(position,now),{latitude:33.12345,longitude:44.23456,accuracy:10,recorded_at:'2026-10-06T08:59:59.000Z'});
  for(const extra of [{coords:{...position.coords,latitude:86}},{coords:{...position.coords,accuracy:-1}},{coords:{...position.coords,longitude:Infinity}},{timestamp:now-300001},{timestamp:now+60001}])assert.throws(()=>geolocationPayload({...position,...extra},now));
});

test('browser timers preserve their native Window receiver on mount and cleanup',async()=>{
  const env=environment(),previous=new Map(['window','document','navigator'].map(name=>[name,Object.getOwnPropertyDescriptor(globalThis,name)]));let started=0,cleared=0;
  env.surface.setInterval=function(callback,delay){assert.equal(this,env.surface);assert.equal(delay,60000);assert.equal(typeof callback,'function');started++;return 19;};env.surface.clearInterval=function(id){assert.equal(this,env.surface);assert.equal(id,19);cleared++;};
  Object.defineProperty(globalThis,'window',{value:env.surface,configurable:true});Object.defineProperty(globalThis,'document',{value:env.document,configurable:true});Object.defineProperty(globalThis,'navigator',{value:{geolocation:env.geolocation,permissions:env.permissions},configurable:true});
  try{const api=apiFixture(),runtime=createPresenceRuntime({api});await runtime.setIdentity(identity());assert.equal(started,1);assert.equal(env.watchers.size,0);runtime.dispose();assert.equal(cleared,1);assert.equal(env.handlers.size,0);}finally{for(const [name,descriptor] of previous){if(descriptor)Object.defineProperty(globalThis,name,descriptor);else delete globalThis[name];}}
});

test('presence starts no GPS watch before permission is granted, requires server consent epoch and throttles uploads at least 20 seconds',async()=>{
  const env=environment(),api=apiFixture(),states=[],runtime=createPresenceRuntime({api,environment:env,onState:s=>states.push(s)});await runtime.setIdentity(identity());assert.equal(env.watchers.size,0);assert.equal(runtime.state.required,true);assert.equal(runtime.state.ready,false);assert.equal(env.timers.has(60000),true);
  await runtime.startLocationSharing();assert.equal(env.watchers.size,1);const watcher=[...env.watchers.values()][0];assert.deepEqual(watcher.options,{enableHighAccuracy:true,maximumAge:30000,timeout:20000});await watcher.success(sample(env));assert.equal(runtime.state.ready,true);assert.equal(api.calls.find(call=>call[0]==='locate')[1].consent_version,1);
  env.advance(19000);await watcher.success(sample(env,33.4));assert.equal(api.calls.filter(call=>call[0]==='locate').length,1);env.advance(1000);await watcher.success(sample(env,33.5));assert.equal(api.calls.filter(call=>call[0]==='locate').length,2);assert.equal(api.calls.filter(call=>call[0]==='locate')[1][1].latitude,33.5);
  runtime.dispose();assert.equal(env.watchers.size,0);assert.equal(env.timers.size,0);assert.equal(env.handlers.size,0);assert.equal(env.permission.onchange,null);
});

test('refresh resumes previously granted location without a click and obtains the current server consent',async()=>{
  const api=apiFixture(),firstEnv=environment();firstEnv.permission.state='granted';
  const first=createPresenceRuntime({api,environment:firstEnv});await first.setIdentity(identity());
  assert.equal(firstEnv.watchers.size,1);await [...firstEnv.watchers.values()][0].success(sample(firstEnv));assert.equal(first.state.ready,true);
  firstEnv.handlers.get('pagehide')();await flush();first.dispose();await flush();
  const env=environment();env.permission.state='granted';const runtime=createPresenceRuntime({api,environment:env});
  await runtime.setIdentity(identity());assert.equal(env.watchers.size,1);assert.equal(runtime.state.sharing,true);assert.equal(runtime.state.initializing,false);
  await [...env.watchers.values()][0].success(sample(env));assert.equal(runtime.state.ready,true);
  assert.ok(api.calls.filter(call=>call[0]==='locate').at(-1)[1].consent_version>1);runtime.dispose();
});

test('successful location choice survives reload when permission introspection is unavailable without storing coordinates',async()=>{
  const values=new Map(),storage={getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)},api=apiFixture();
  const firstEnv=environment();firstEnv.storage=storage;firstEnv.permissions=undefined;
  const first=createPresenceRuntime({api,environment:firstEnv});await first.setIdentity(identity());
  assert.equal(firstEnv.watchers.size,0);await first.startLocationSharing();
  await [...firstEnv.watchers.values()][0].success(sample(firstEnv));
  assert.deepEqual([...values.values()],['enabled']);first.dispose();await flush();
  const nextEnv=environment();nextEnv.storage=storage;nextEnv.permissions=undefined;
  const next=createPresenceRuntime({api,environment:nextEnv});await next.setIdentity(identity());
  assert.equal(nextEnv.watchers.size,1);assert.equal(next.state.ready,false);
  await [...nextEnv.watchers.values()][0].success(sample(nextEnv));assert.equal(next.state.ready,true);
  next.dispose();
});

test('explicit stop persists across reload and remembered permission belongs only to that user',async()=>{
  const values=new Map(),storage={getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)};
  const env=environment();env.storage=storage;env.permission.state='granted';const api=apiFixture();
  const first=createPresenceRuntime({api,environment:env});await first.setIdentity(identity());await first.stopLocationSharing();first.dispose();await flush();
  const nextEnv=environment();nextEnv.storage=storage;nextEnv.permission.state='granted';
  const next=createPresenceRuntime({api,environment:nextEnv});await next.setIdentity(identity());assert.equal(nextEnv.watchers.size,0);
  await next.setIdentity(identity(22));assert.equal(nextEnv.watchers.size,1);next.dispose();
  const blockedEnv=environment();blockedEnv.storage=storage;blockedEnv.permission.state='prompt';values.set('masal.location-sharing.v1:11:31:owner','enabled');
  const blocked=createPresenceRuntime({api,environment:blockedEnv});await blocked.setIdentity(identity());assert.equal(blockedEnv.watchers.size,0);blocked.dispose();
});

test('granted permission never restarts explicitly stopped sharing on focus or enables system and employee tracking',async()=>{
  const env=environment();env.permission.state='granted';const api=apiFixture(),runtime=createPresenceRuntime({api,environment:env});
  await runtime.setIdentity(identity());assert.equal(env.watchers.size,1);await runtime.stopLocationSharing();
  await env.handlers.get('focus')();assert.equal(env.watchers.size,0);assert.equal(runtime.state.sharing,false);
  await runtime.startLocationSharing();assert.equal(env.watchers.size,1);
  await runtime.setIdentity(identity(22,'system'));assert.equal(env.watchers.size,0);
  await runtime.setIdentity(identity(33,'pos','employee'));assert.equal(env.watchers.size,0);runtime.dispose();
});

test('denied or unavailable browser permission remains explicit and granting it later resumes only the current identity',async()=>{
  const env=environment();env.permission.state='denied';const runtime=createPresenceRuntime({api:apiFixture(),environment:env});
  await runtime.setIdentity(identity());assert.equal(env.watchers.size,0);
  env.permission.state='granted';env.permission.onchange();await flush();assert.equal(env.watchers.size,1);
  env.permission.state='prompt';env.permission.onchange();await flush();assert.equal(env.watchers.size,0);assert.equal(runtime.state.ready,false);runtime.dispose();
  const unsupported=environment();unsupported.permissions=undefined;
  const manual=createPresenceRuntime({api:apiFixture(),environment:unsupported});await manual.setIdentity(identity());assert.equal(unsupported.watchers.size,0);await manual.startLocationSharing();assert.equal(unsupported.watchers.size,1);manual.dispose();
});

test('foreground heartbeat stops in background and late own or GPS responses cannot restore an old identity',async()=>{
  const env=environment(),api=apiFixture(),old=deferred(),runtime=createPresenceRuntime({api,environment:env});api.own=()=>old.promise;
  const loading=runtime.setIdentity(identity());await flush();await runtime.setIdentity(null);old.resolve({data:{location_required:true,location_ready:true,consent_version:9}});await loading;assert.equal(runtime.state.ready,false);assert.equal(runtime.state.required,false);
  api.own=async()=>({data:{location_required:true,location_ready:false,consent_version:2}});await runtime.setIdentity(identity(22));const count=api.calls.filter(call=>call[0]==='heartbeat').length;env.document.visibilityState='hidden';env.timers.get(60000)();await flush();assert.equal(api.calls.filter(call=>call[0]==='heartbeat').length,count);
  env.document.visibilityState='visible';env.timers.get(60000)();await flush();assert.equal(api.calls.filter(call=>call[0]==='heartbeat').length,count+1);await runtime.startLocationSharing();const pending=deferred();api.locate=()=>pending.promise;const watcher=[...env.watchers.values()][0],upload=watcher.success(sample(env));await runtime.setIdentity(identity(33,'system'));pending.resolve({data:{location_ready:true}});await upload;assert.equal(runtime.state.ready,false);assert.equal(env.watchers.size,0);runtime.dispose();
});

test('revocation cleans GPS immediately, old upload acknowledgement cannot reopen readiness, and explicit restart obtains a new consent epoch',async()=>{
  const env=environment(),api=apiFixture(),runtime=createPresenceRuntime({api,environment:env});await runtime.setIdentity(identity());await runtime.startLocationSharing();const stale=deferred();api.locate=body=>{api.calls.push(['locate',body]);return stale.promise;};const watch=[...env.watchers.values()][0],upload=watch.success(sample(env));await runtime.stopLocationSharing();assert.equal(runtime.state.ready,false);assert.equal(env.watchers.size,0);stale.resolve({data:{location_ready:true}});await upload;assert.equal(runtime.state.ready,false);
  api.locate=async body=>{api.calls.push(['locate',body]);return {data:{location_ready:true}};};await runtime.startLocationSharing();await [...env.watchers.values()][0].success(sample(env));assert.equal(api.calls.filter(call=>call[0]==='locate').at(-1)[1].consent_version,2);assert.equal(runtime.state.ready,true);
  env.permission.state='denied';env.permission.onchange();await flush();assert.equal(runtime.state.sharing,false);assert.equal(runtime.state.ready,false);assert.equal(env.watchers.size,0);assert.equal(runtime.state.permission,'denied');runtime.dispose();
});

test('server rejects obsolete consent without silent retry, and authentication failure triggers refresh instead of fabricated online status',async()=>{
  const env=environment(),api=apiFixture();let refresh=0;const runtime=createPresenceRuntime({api,environment:env,onUnauthorized:async()=>refresh++});await runtime.setIdentity(identity());await runtime.startLocationSharing();api.locate=async()=>{const error=Error('تغيرت مشاركة الموقع');error.status=409;throw error;};await [...env.watchers.values()][0].success(sample(env));assert.equal(runtime.state.ready,false);assert.equal(runtime.state.sharing,false);assert.equal(env.watchers.size,0);
  api.heartbeat=async()=>{const error=Error('Session expired');error.status=401;throw error;};env.timers.get(60000)();await flush();assert.equal(refresh,1);runtime.dispose();
});

test('page hide immediately closes readiness and GPS, sends keepalive revocation, and ignores in-flight acknowledgement',async()=>{
  const env=environment(),api=apiFixture(),runtime=createPresenceRuntime({api,environment:env});await runtime.setIdentity(identity());await runtime.startLocationSharing();const pending=deferred();api.locate=()=>pending.promise;const upload=[...env.watchers.values()][0].success(sample(env));env.handlers.get('pagehide')();assert.equal(runtime.state.ready,false);assert.equal(runtime.state.sharing,false);assert.equal(env.watchers.size,0);assert.equal(api.calls.filter(call=>call[0]==='disconnect').at(-1)[1].keepalive,true);pending.resolve({data:{location_ready:true}});await upload;assert.equal(runtime.state.ready,false);runtime.dispose();
});

test('map popup never assigns HTML from metadata and marker glyph/color sanitize hostile DTO fields',async()=>{
  const created=[];class Node{constructor(tag){this.tag=tag;this.children=[];this.events={};this.style={setProperty:()=>{}};created.push(this);}append(...children){this.children.push(...children);}addEventListener(name,fn){this.events[name]=fn;}set innerHTML(_){throw Error('Unsafe HTML assignment');}}
  const doc={createElement:tag=>new Node(tag)},user=mapUser({name:'<script>evil()</script>',kind:'موظف',account:'<img onerror=x>',role:'employee',networkColor:'";evil()',symbol:'<svg>',location:{lat:33,lng:44},online:false,lastSeen:null,pin:'PIN_SECRET',cost:'COST_SECRET',login:'HIDDEN_LOGIN'}),copied=[];let details=false;
  const node=popupNode(user,{document:doc,onDetails:()=>details=true,copy:async link=>copied.push(link)});assert.equal(node.tag,'article');assert.ok(created.some(node=>node.textContent==='<script>evil()</script>'));assert.ok(!markerMarkup(user).includes('evil'));assert.ok(markerMarkup(user).includes('●'));const buttons=created.filter(node=>node.tag==='button');buttons[0].events.click({stopPropagation(){}});assert.equal(details,true);await buttons[1].events.click({stopPropagation(){}});assert.deepEqual(copied,['https://www.google.com/maps?q=33%2C44']);assert.ok(!created.some(node=>['PIN_SECRET','COST_SECRET','HIDDEN_LOGIN'].includes(node.textContent)));
});

test('overlap marker renderer separates equal coordinates, preserves network symbols, retries tiles and disposes Leaflet observers once',()=>{
  const markers=[],events=new Map();let redraws=0,removed=0,disconnected=0;const map={setView(){return this;},createPane(){},getPane:()=>({style:{}}),on:(names,fn)=>events.set(names,fn),latLngToLayerPoint:point=>({x:point[0],y:point[1]}),layerPointToLatLng:point=>point,fitBounds(){},getZoom:()=>6,invalidateSize(){},remove(){removed++;}},layer={addTo(){return this;},clearLayers(){}},tiles={on(){return this;},addTo(){return this;},redraw(){redraws++;}},L={map:()=>map,layerGroup:()=>layer,geoJSON:()=>({addTo(){}}),tileLayer:()=>tiles,divIcon:options=>options,marker:(point,options)=>{const marker={point,options,addTo(){markers.push(this);return this;},bindPopup(){},on(){},openPopup(){}};return marker;}},Resize=class{observe(){}disconnect(){disconnected++;}};
  const renderer=createMapRenderer({L,canvas:{},countries:{type:'FeatureCollection',features:[]},Resize});renderer.draw([mapUser({id:1,role:'main_agent',location:{lat:33,lng:44},online:true,networkColor:'#0898b5'}),mapUser({id:2,role:'pos',location:{lat:33,lng:44},online:false,networkColor:'#112233'})]);assert.equal(markers.length,2);assert.notDeepEqual(markers[0].point,markers[1].point);assert.ok(markers[0].options.icon.html.includes('◆'));assert.ok(markers[1].options.icon.html.includes('▲'));renderer.retryTiles();assert.equal(redraws,1);renderer.dispose();renderer.dispose();assert.equal(removed,1);assert.equal(disconnected,1);
});
