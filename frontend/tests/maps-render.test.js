import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';

test('map SSR preserves original filters, legends and permission gate without fetching locations or starting browser consent',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
  try{const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),page=(await server.ssrLoadModule('/src/modules/maps/MapPage.vue')).default,presence=(await server.ssrLoadModule('/src/modules/maps/PresenceController.vue')).default;
    for(const [type,kind,granted] of [['system','owner',true],['system','employee',true],['main_agent','owner',true],['pos','owner',false]]){const requests=[],session={state:reactive({identity:{user:{id:3},account:{id:7,type},membership:{kind},permissions:granted?['map.view']:[]}}),can:()=>granted,api:{request:()=>{requests.push('read');throw Error('SSR cannot fabricate map data');},mutate:()=>{requests.push('mutate');throw Error('SSR cannot trigger GPS');}}},app=createSSRApp({render:()=>h(page)});app.provide(portalContextKey,{session});const html=await renderToString(app);assert.equal(html.includes('خريطة الحسابات والمستخدمين'),granted);if(granted){for(const label of ['نوع الحساب','الحساب وتابعوه','فلترة حالة الاتصال','اختيار مخصص','عرض نتائج البحث','◆ وكيل · ■ فرع · ▲ نقطة · ● موظف','خريطة مواقع المستخدمين','السابق','التالي'])assert.ok(html.includes(label));}else assert.ok(html.includes('ليس لديك صلاحية عرض المواقع'));assert.ok(!html.includes('localStorage'));assert.ok(!html.includes('sessionStorage'));assert.ok(!html.includes('undefined'));const runtimeApp=createSSRApp({render:()=>h(presence)});runtimeApp.provide(portalContextKey,{session});await renderToString(runtimeApp,{});assert.deepEqual(requests,[]);}
  }finally{await server.close();}
});

test('map detail renders only authorized escaped metadata and original Baghdad/location fields',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
  try{const component=(await server.ssrLoadModule('/src/modules/maps/MapUserDetails.vue')).default,user={name:'<script>evil()</script>',kind:'موظف',account:'الحساب',active:false,online:false,parentName:'الفرع',phone:'<img onerror=x>',city:'بغداد',address:'العنوان',source:'آخر موقع للجهاز',lastSeen:'2026-10-06T09:00:00Z',locationTime:'2026-10-06T08:00:00Z',accuracy:8.4,location:{lat:33.12345,lng:44.23456},device_model:'جهاز حقيقي',app_version:'إصدار حقيقي',pin:'PIN_SECRET',cost:'COST_SECRET',login:'LOGIN_SECRET',scope_roots:['FOREIGN_SCOPE_SECRET']},html=await renderToString(createSSRApp({render:()=>h(component,{user})}));assert.ok(html.includes('&lt;script&gt;evil()&lt;/script&gt;'));assert.ok(html.includes('&lt;img onerror=x&gt;'));for(const label of ['الحساب موقوف','آخر ظهور — بغداد','وقت آخر موقع — بغداد','حوالي 8 متر','33.12345','44.23456','جهاز حقيقي'])assert.ok(html.includes(label));for(const secret of ['PIN_SECRET','COST_SECRET','LOGIN_SECRET','FOREIGN_SCOPE_SECRET','<script>'])assert.ok(!html.includes(secret));}
  finally{await server.close();}
});

test('saved location permission shows automatic acquisition without another enable confirmation',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
  try{const gate=(await server.ssrLoadModule('/src/modules/maps/LocationGate.vue')).default;
    for(const props of [{initializing:true},{permission:'granted',sharing:true}]){
      const context={};await renderToString(createSSRApp({render:()=>h(gate,{required:true,ready:false,...props})}),context);
      const html=context.teleports.body;assert.ok(html.includes('جارٍ تحديد الموقع تلقائيًا'));assert.ok(!html.includes('تفعيل الموقع</button>'));assert.ok(html.includes('تسجيل الخروج'));
    }
  }finally{await server.close();}
});
