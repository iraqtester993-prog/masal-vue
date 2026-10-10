import test from 'node:test';
import assert from 'node:assert/strict';
import {readdir,readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {resolve,join} from 'node:path';
import {parse,compileScript,compileTemplate} from '@vue/compiler-sfc';
import vue from '@vitejs/plugin-vue';
import {createServer} from 'vite';
import {createSSRApp,h,reactive,computed} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createRouter,createMemoryHistory} from 'vue-router';
import {localizeVueSource,uiLocalizationPlugin} from '../src/modules/preferences/ui-localization-plugin.js';
import {translate,prepareLanguage} from '../src/modules/preferences/translate.js';
import {translateKnown} from '../src/modules/preferences/translate.js';
import dictionaries from '../src/modules/preferences/original-dictionaries.js';

const root=fileURLToPath(new URL('../',import.meta.url));

test('dynamic controls and fixed collections localize without translating live business names',()=>{
  const source=`<script setup>const tabs=[{id:'one',name:'الفئات الممنوحة'}];const rows=liveRecords;</script><template><button>{{busy?'جارٍ التحميل…':'حفظ'}}</button><span v-for="tab in tabs">{{tab.name}}</span><span v-for="row in rows">{{row.name}}</span><span>{{saleStatuses[row.status]}}</span><p>{{state.error}}</p><input :placeholder="busy?'جارٍ التحميل…':'بحث'"/></template>`;
  const result=localizeVueSource(source);
  assert.ok(result.includes('__masalUiTr(tab.name)'));
  assert.ok(result.includes('__masalUiTr(saleStatuses[row.status])'));
  assert.ok(result.includes('__masalUiMsg(state.error)'));
  assert.ok(result.includes('{{row.name}}'));
  assert.ok(result.includes('busy?__masalUiTr('));
});

test('current static labels and report schema captions have both supported translations',async()=>{
  const schemas=JSON.parse(await readFile(resolve(root,'../backend/app/Services/Reports/schemas.json'),'utf8'));
  const collect=value=>typeof value==='string'?[value]:Array.isArray(value)?value.flatMap(collect):value&&typeof value==='object'?Object.values(value).flatMap(collect):[];
  for(const label of collect(schemas).filter(value=>/[\u0600-\u06ff]/.test(value)))for(const language of ['en','ckb'])assert.ok(dictionaries[language][label.trim()],`${language}: ${label}`);
  await prepareLanguage('en');
  assert.equal(translateKnown('رقم الهاتف مستخدم في حساب آخر؛ أدخل رقمًا مختلفًا.','en'),'This phone number is used by another account. Enter a different number.');
  assert.equal(translateKnown('اسم الزبون: المحفظة','en'),'اسم الزبون: المحفظة');
});
const source=`<script setup>defineProps(['record','message']);</script>
<template><section><h1>لوحة التحكم</h1><p>  بحث &amp; حفظ  </p><button title="حفظ &amp; إلغاء" aria-label="بحث">حفظ</button><input placeholder="اسم المستخدم"/><span>{{ record.name }}</span><p>{{ message }}</p><p :title="record.name">{{ record.note }}</p><span v-pre>بحث {{ اسم }}</span><code>بحث</code><span data-no-translate>المحفظة</span></section></template>`;

test('template localization transforms static UI only, keeps business expressions and protected/inert text untouched',()=>{
  const converted=localizeVueSource(source);assert.ok(converted.includes('__masalUiTr("لوحة التحكم")'));assert.ok(converted.includes('record.name'));assert.ok(converted.includes('{{ message }}'));assert.ok(converted.includes(':title="record.name"'));assert.ok(converted.includes('<span v-pre>بحث {{ اسم }}</span>'));assert.ok(converted.includes('<code>بحث</code>'));assert.ok(converted.includes('<span data-no-translate>المحفظة</span>'));assert.ok(converted.includes(':title="__masalUiTr(&quot;حفظ &amp; إلغاء&quot;)"'));
  const plugin=uiLocalizationPlugin();assert.equal(plugin.transform(source,'/frontend/legacy/example.vue'),null);assert.equal(plugin.transform(source,'/frontend/src/example.vue?vue&type=template'),null);assert.equal(plugin.transform('const name="بحث"','module.js'),null);assert.equal(localizeVueSource('<template><span>{{ record.name }}</span></template>'),'<template><span>{{ record.name }}</span></template>');
});

test('every current portal SFC compiles after the static transform without editing source or writing build output',async()=>{
  async function files(folder){let found=[];for(const item of await readdir(folder,{withFileTypes:true})){const path=join(folder,item.name);if(item.isDirectory())found.push(...await files(path));else if(item.name.endsWith('.vue'))found.push(path);}return found;}
  const paths=await files(resolve(root,'src'));assert.ok(paths.length>80);let translated=0;for(const path of paths){const original=await readFile(path,'utf8'),code=localizeVueSource(original,path);if(code!==original)translated++;const {descriptor,errors}=parse(code,{filename:path});assert.deepEqual(errors,[],path);let bindings;if(descriptor.script||descriptor.scriptSetup)bindings=compileScript(descriptor,{id:path}).bindings;const template=compileTemplate({source:descriptor.template?.content||'',filename:path,id:path,compilerOptions:{bindingMetadata:bindings}});assert.deepEqual(template.errors,[],path);}assert.ok(translated>70);
});

test('SSR preserves original Arabic whitespace/entities and localizes static labels while keeping real names/messages escaped and untranslated',async()=>{
  await prepareLanguage('en');
  const server=await createServer({configFile:false,root,logLevel:'error',resolve:{alias:{'/src':resolve(root,'src')}},plugins:[{name:'private-preferences-fixture',resolveId:id=>id.startsWith('/__prefs__/')?id:null,load:id=>id.startsWith('/__prefs__/')?source:null},uiLocalizationPlugin(),vue()],server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
  try{const component=(await server.ssrLoadModule('/__prefs__/fixture.vue')).default,original=(await server.ssrLoadModule('/__prefs__/legacy/fixture.vue')).default,{preferencesContextKey}=await server.ssrLoadModule('/src/modules/preferences/preferences-state.js'),locale=await server.ssrLoadModule('/src/modules/preferences/translate.js');const props={record:{name:'المحفظة',note:'اسم المستخدم'},message:'<script>بحث & حفظ</script>'};
    const raw=await renderToString(createSSRApp({render:()=>h(original,props)}));for(const language of ['ar','en','ckb']){const state=reactive({language}),app=createSSRApp({async setup(){await locale.prepareLanguage(language);return ()=>h(component,props);}});app.provide(preferencesContextKey,{state,t:value=>locale.translate(value,state.language)});const html=await renderToString(app);if(language==='ar')assert.equal(html,raw);else{assert.ok(html.includes(translate('لوحة التحكم',language)));assert.ok(html.includes(`placeholder="${translate('اسم المستخدم',language)}"`));assert.ok(html.includes(`${translate('حفظ',language)} &amp; ${translate('إلغاء',language)}`));}assert.ok(html.includes('<span>المحفظة</span>'));assert.ok(html.includes('<p title="المحفظة">اسم المستخدم</p>'));assert.ok(html.includes('&lt;script&gt;بحث &amp; حفظ&lt;/script&gt;'));assert.ok(html.includes('<span>بحث {{ اسم }}</span>'));assert.ok(html.includes('<code>بحث</code>'));assert.ok(html.includes('<span data-no-translate>المحفظة</span>'));assert.ok(!html.includes('<script>'));}
  }finally{await server.close();}
});

test('language picker SSR uses only server/in-memory state for every portal and exposes original native language names',async()=>{
  const server=await createServer({configFile:false,root,logLevel:'error',resolve:{alias:{'/src':resolve(root,'src')}},plugins:[uiLocalizationPlugin(),vue()],server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
  try{const component=(await server.ssrLoadModule('/src/modules/preferences/LanguagePicker.vue')).default,{preferencesContextKey}=await server.ssrLoadModule('/src/modules/preferences/preferences-state.js');for(const [portal,language] of [['admin','ar'],['agents','en'],['pos','ckb']]){const calls=[],app=createSSRApp({render:()=>h(component)}),state=reactive({language,loading:false,busy:false});app.provide(preferencesContextKey,{state,t:value=>translate(value,language),setLanguage:value=>calls.push(value)});const html=await renderToString(app);for(const label of ['العربية','English','کوردی'])assert.ok(html.includes(label));assert.ok(html.includes(`value="${language}" selected`),portal);assert.deepEqual(calls,[]);assert.ok(!html.includes('disabled'));assert.ok(!html.includes('localStorage'));assert.ok(!html.includes('sessionStorage'));}
    const guest=await renderToString(createSSRApp({render:()=>h(component)}));assert.ok(guest.includes('disabled'));
  }finally{await server.close();}
});

test('integrated portal layouts and theme render server language without translating account names or starting requests',async()=>{
  const server=await createServer({root,mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
  try{const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),{preferencesContextKey}=await server.ssrLoadModule('/src/modules/preferences/preferences-state.js'),locale=await server.ssrLoadModule('/src/modules/preferences/translate.js');
    for(const [portal,type,componentName,language] of [['admin','system','AdminLayout','ar'],['agents','main_agent','AgentsLayout','en'],['pos','pos','PosLayout','ckb']]){const layout=(await server.ssrLoadModule(`/src/layouts/${componentName}.vue`)).default,calls=[],session={state:reactive({identity:{user:{id:8,name:'المحفظة <script>'},account:{id:11,type},membership:{kind:'owner'}},busy:false}),can:()=>true,api:{request:()=>{calls.push('read');throw Error('SSR cannot request data');},mutate:()=>{calls.push('write');throw Error('SSR cannot update user choices');}}},router=createRouter({history:createMemoryHistory(),routes:[{path:'/dashboard',name:'dashboard',component:{render:()=>null},meta:{requiresAuth:true,title:'لوحة التحكم'}},{path:'/profile',name:'profile',component:{render:()=>null},meta:{requiresAuth:true,title:'حسابي'}}]});await router.push('/dashboard');await router.isReady();const state=reactive({language,theme:'dark',busy:false,loading:false,error:''}),app=createSSRApp({async setup(){await locale.prepareLanguage(language);return ()=>h(layout,{},()=>h('p','ACTUAL_BUSINESS_MESSAGE'));}});app.use(router);app.provide(portalContextKey,{portal:{id:portal},session});app.provide(preferencesContextKey,{state,dark:computed(()=>state.theme==='dark'),t:value=>locale.translate(value,language),toggleTheme:()=>calls.push('theme'),setLanguage:()=>calls.push('language')});const html=await renderToString(app);assert.ok(html.includes('المحفظة &lt;script&gt;'));assert.ok(html.includes('ACTUAL_BUSINESS_MESSAGE'));assert.ok(html.includes('aria-pressed="true"'));assert.ok(html.includes(`value="${language}" selected`));assert.ok(html.includes(locale.translate('الحساب الحالي',language)));assert.ok(html.includes(locale.translate('لوحة التحكم',language)));assert.ok(!html.includes('<script>'));assert.ok(!html.includes('undefined'));assert.deepEqual(calls,[]);}
  }finally{await server.close();}
});
