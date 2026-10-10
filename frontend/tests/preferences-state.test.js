import test from 'node:test';
import assert from 'node:assert/strict';
import {reactive,nextTick} from 'vue';
import {createPreferencesApi} from '../src/modules/preferences/preferences-api.js';
import {createUserPreferences} from '../src/modules/preferences/preferences-state.js';
import {languages,translate,prepareLanguage} from '../src/modules/preferences/translate.js';

const flush=async()=>{await nextTick();for(let i=0;i<8;i++)await Promise.resolve();};
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const identity=id=>({user:{id},account:{id:40,type:'system'},membership:{kind:id===1?'owner':'employee'}});
const data=(language='ar',theme='light',version=0)=>({data:{language,theme,version}});
function fixture(id=1){const calls=[],surface={documentElement:{dataset:{}}},session={state:reactive({identity:id?identity(id):null}),refresh:async()=>{calls.push(['refresh']);},api:{request:async(path,options)=>{calls.push(['get',path,options]);return data();},mutate:async(path,method,body,signal)=>{calls.push(['put',path,method,body,signal]);return data(body.language,body.theme,body.version+1);}}};return {calls,surface,session};}

test('preferences API uses own authenticated endpoint, exact method/body, and cancellation',async()=>{
  const f=fixture(),api=createPreferencesApi(f.session.api),signal=new AbortController().signal,body={language:'en',theme:'dark',version:5};await api.read(signal);await api.save(body,signal);
  assert.deepEqual(f.calls[0],['get','/preferences',{signal}]);assert.deepEqual(f.calls[1],['put','/preferences','PUT',body,signal]);assert.ok(!Object.hasOwn(body,'user_id'));
});

test('original dictionaries preserve protected record names, digits and unknown values in all supported languages',async()=>{
  await prepareLanguage('en');await prepareLanguage('ckb');
  assert.deepEqual(languages.map(lang=>[lang.id,lang.direction]),[['ar','rtl'],['en','ltr'],['ckb','rtl']]);assert.equal(translate('  بحث  ','en'),'  Search  ');assert.equal(translate('حفظ','ckb'),'پاشەکەوتکردن');
  const original='بحث: المحفظة — حساب بحث ١٢٣';assert.equal(translate(original,'en',['المحفظة','حساب بحث']), 'Search: المحفظة — حساب بحث ١٢٣');assert.equal(translate(original,'ar'),original);assert.equal(translate(original,'unknown'),original);assert.equal(translate('اسم لا يوجد بالقاموس','en'),'اسم لا يوجد بالقاموس');assert.equal(translate(42,'en'),42);const record={name:'المحفظة'};assert.equal(translate(record,'en'),record);assert.equal(translate('بحثية','en'),'بحثية');
});

test('own preferences load server defaults, theme and direction without browser storage or impersonation',async()=>{
  const f=fixture();f.session.api.request=async()=>data('ckb','dark',7);const store=createUserPreferences(f.session,{surface:f.surface});await flush();assert.equal(store.state.language,'ckb');assert.equal(store.dark.value,true);assert.equal(f.surface.documentElement.lang,'ckb');assert.equal(f.surface.documentElement.dir,'rtl');assert.equal(f.surface.documentElement.dataset.theme,'dark');assert.equal(store.t('بحث'),'گەڕان');
  await store.setLanguage('en');assert.equal(f.surface.documentElement.dir,'ltr');assert.deepEqual(f.calls[0][3],{language:'en',theme:'dark',version:7});assert.equal(store.state.version,8);assert.ok(!f.calls[0][3].user_id);store.dispose();
});

test('rapid independent choices serialize using acknowledged versions and preserve both settings',async()=>{
  const f=fixture(),first=deferred();let writes=0;f.session.api.mutate=async(path,method,body,signal)=>{f.calls.push(['put',path,method,body,signal]);if(++writes===1)return first.promise;return data(body.language,body.theme,body.version+1);};const store=createUserPreferences(f.session,{surface:f.surface});await flush();const language=store.setLanguage('en'),theme=store.setTheme('dark');await flush();assert.equal(f.calls.filter(call=>call[0]==='put').length,1);assert.equal(store.state.busy,true);first.resolve(data('en','light',1));assert.equal(await language,true);assert.equal(await theme,true);assert.deepEqual(f.calls.filter(call=>call[0]==='put').map(call=>call[3]),[{language:'en',theme:'light',version:0},{language:'en',theme:'dark',version:1}]);assert.equal(store.state.busy,false);assert.equal(store.state.theme,'dark');assert.equal(store.state.version,2);store.dispose();
});

test('identity change aborts an old employee preference write and late response cannot overwrite another user',async()=>{
  const f=fixture(),old=deferred();let reads=0;f.session.api.request=async()=>++reads===1?data('en','dark',3):data('ckb','light',8);f.session.api.mutate=async(path,method,body,signal)=>{f.calls.push(['put',path,method,body,signal]);return old.promise;};const store=createUserPreferences(f.session,{surface:f.surface});await flush();const update=store.setTheme('light');await flush();const oldSignal=f.calls[0][4];f.session.state.identity=identity(2);await flush();assert.equal(oldSignal.aborted,true);old.resolve(data('en','light',4));assert.equal(await update,false);assert.equal(store.state.language,'ckb');assert.equal(store.state.version,8);assert.equal(store.state.busy,false);assert.equal(f.surface.documentElement.lang,'ckb');store.dispose();
});

test('stale parallel reads and a read started during a write cannot overwrite its acknowledged version',async()=>{
  const f=fixture(),first=deferred(),second=deferred(),write=deferred();let reads=0;f.session.api.request=async()=>{if(++reads===1)return data('ar','light',1);return reads===2?first.promise:second.promise;};f.session.api.mutate=()=>write.promise;const store=createUserPreferences(f.session,{surface:f.surface});await flush();const oldRead=store.refresh(),freshRead=store.refresh();second.resolve(data('en','dark',2));await freshRead;first.resolve(data('ar','light',1));await oldRead;assert.equal(store.state.version,2);assert.equal(store.state.language,'en');
  const inFlight=deferred();f.session.api.request=()=>inFlight.promise;const save=store.setLanguage('ckb');await flush();const readDuringWrite=store.refresh();write.resolve(data('ckb','dark',3));assert.equal(await save,true);inFlight.resolve(data('en','dark',2));await readDuringWrite;assert.equal(store.state.version,3);assert.equal(store.state.language,'ckb');assert.equal(store.state.loading,false);store.dispose();
});

test('conflicting server update refreshes actual settings without silently retrying the rejected choice',async()=>{
  const f=fixture();let reads=0,writes=0;f.session.api.request=async()=>++reads===1?data():data('ckb','dark',9);f.session.api.mutate=async()=>{writes++;const error=Error('stale');error.status=409;throw error;};const store=createUserPreferences(f.session,{surface:f.surface});await flush();assert.equal(await store.setLanguage('en'),false);assert.equal(writes,1);assert.equal(store.state.version,9);assert.equal(store.state.language,'ckb');assert.equal(store.state.theme,'dark');assert.match(store.state.error,/جلسة أخرى/);assert.equal(store.state.busy,false);store.dispose();
});

test('invalid choices and malformed server DTO do not apply arbitrary direction/theme; auth failure refreshes session',async()=>{
  const f=fixture();f.session.api.request=async()=>data('bad','injected',1);const store=createUserPreferences(f.session,{surface:f.surface});await flush();assert.equal(store.state.theme,'light');assert.equal(f.surface.documentElement.dir,'rtl');assert.match(store.state.error,/تعذر قراءة/);assert.equal(await store.setLanguage('bad'),false);assert.equal(await store.setTheme('bad'),false);assert.equal(f.calls.length,0);
  f.session.api.request=async()=>{const error=Error('expired');error.status=401;throw error;};await store.refresh();assert.deepEqual(f.calls,[['refresh']]);assert.equal(store.state.loading,false);store.dispose();
});

test('guest choices are transient, logout clears actor settings, and disposed reads cannot touch document or state',async()=>{
  const f=fixture(null),late=deferred(),store=createUserPreferences(f.session,{surface:f.surface});await store.setLanguage('en');await store.toggleTheme();assert.equal(store.dark.value,true);assert.equal(f.surface.documentElement.dir,'ltr');assert.deepEqual(f.calls,[]);f.session.state.identity=identity(1);await flush();assert.equal(store.state.language,'ar');assert.equal(store.state.theme,'light');
  f.session.api.request=()=>late.promise;const read=store.refresh();f.session.state.identity=null;await flush();assert.equal(store.state.version,0);assert.equal(store.state.language,'ar');store.dispose();late.resolve(data('ckb','dark',22));await read;assert.equal(store.state.theme,'light');assert.equal(f.surface.documentElement.lang,'ar');assert.equal(await store.setTheme('dark'),false);
});

test('Arabic startup and theme do not load dictionaries; selecting another language waits before persisting or displaying it',async()=>{
  const f=fixture(),chunk=deferred(),prepared=[],store=createUserPreferences(f.session,{surface:f.surface,prepare:language=>{prepared.push(language);return chunk.promise;}});await flush();assert.deepEqual(prepared,[]);await store.setTheme('dark');assert.deepEqual(prepared,[]);const save=store.setLanguage('en');await flush();assert.deepEqual(prepared,['en']);assert.equal(f.calls.filter(call=>call[0]==='put').length,1);assert.equal(store.state.language,'ar');assert.equal(f.surface.documentElement.dir,'rtl');assert.equal(store.state.busy,true);chunk.resolve();assert.equal(await save,true);assert.equal(store.state.language,'en');assert.equal(f.surface.documentElement.dir,'ltr');assert.equal(f.calls.filter(call=>call[0]==='put').length,2);store.dispose();
});

test('failed language download cannot persist an unseen choice, and explicit retry can prepare and save it',async()=>{
  const f=fixture();let attempts=0;const store=createUserPreferences(f.session,{surface:f.surface,prepare:async()=>{if(++attempts===1)throw Error('تعذر تحميل لغة الواجهة؛ أعد المحاولة.');}});await flush();assert.equal(await store.setLanguage('en'),false);assert.equal(store.state.language,'ar');assert.equal(store.state.version,0);assert.equal(store.state.busy,false);assert.equal(f.calls.filter(call=>call[0]==='put').length,0);assert.match(store.state.error,/تعذر تحميل/);assert.equal(await store.setLanguage('en'),true);assert.equal(store.state.language,'en');assert.equal(store.state.error,'');store.dispose();
});

test('server non-Arabic preferences wait for their dictionary and identity changes cancel applying or saving prepared language',async()=>{
  const f=fixture(),chunk=deferred();let reads=0;f.session.api.request=async()=>++reads===1?data('ckb','dark',4):data('ar','light',1);const store=createUserPreferences(f.session,{surface:f.surface,prepare:()=>chunk.promise});await flush();assert.equal(store.state.loading,true);assert.equal(store.state.language,'ar');f.session.state.identity=identity(2);await flush();assert.equal(store.state.loading,false);chunk.resolve();await flush();assert.equal(store.state.language,'ar');assert.equal(store.state.version,1);
  const anotherChunk=deferred(),g=fixture(),guest=createUserPreferences(g.session,{surface:g.surface,prepare:()=>anotherChunk.promise});await flush();const save=guest.setLanguage('en');await flush();g.session.state.identity=null;await flush();anotherChunk.resolve();assert.equal(await save,false);assert.equal(guest.state.language,'ar');assert.equal(g.calls.filter(call=>call[0]==='put').length,0);store.dispose();guest.dispose();
});
