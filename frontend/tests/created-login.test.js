import test from 'node:test';
import assert from 'node:assert/strict';
import {createdLoginDetails} from '../src/modules/accounts/login-details.js';
import {createServer} from 'vite';
import {fileURLToPath} from 'node:url';
import {createSSRApp,h,reactive} from 'vue';
import {createRouter,createMemoryHistory} from 'vue-router';
import {renderToString} from '@vue/server-renderer';

test('new credentials use the correct portal and preserve the exact submitted secret',()=>{
  for(const [type,portal] of [['system','admin'],['main_agent','agents'],['sub_agent','agents'],['sub_branch','agents'],['pos','pos']]){
    const details=createdLoginDetails(type,'fixture@example.test',' 012345678 ');
    assert.equal(details.url,`https://${portal}.dananir-iq.com/login`);
    assert.equal(details.password,' 012345678 ');
    assert.equal(details.login,'fixture@example.test');
  }
});

test('agent and POS header follows actual router navigation including POS operation history',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const layout=(await server.ssrLoadModule('/src/layouts/PortalLayout.vue')).default;
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    for(const type of ['main_agent','pos']){
      const router=createRouter({history:createMemoryHistory(),routes:[{path:'/dashboard',name:'dashboard',meta:{title:'لوحة التحكم'},component:{render:()=>null}},{path:'/sell',name:'sell',meta:{title:'البيع والطباعة'},component:{render:()=>null}},{path:'/sales',name:'sales',meta:{title:'المبيعات'},component:{render:()=>null}}]});
      const session={state:reactive({identity:{account:{type,id:1},user:{id:1,name:'Fixture'},membership:{kind:'owner'}},busy:false}),can:()=>true,api:{}};
      for(const [path,title] of [['/dashboard','لوحة التحكم'],['/sell',type==='pos'?'البيع':'البيع والطباعة'],['/sales',type==='pos'?'سجل العمليات':'المبيعات']]){
        await router.push(path);
        const app=createSSRApp({render:()=>h(layout,{scopeLabel:'Fixture'})});app.use(router);app.provide(portalContextKey,{session});
        const html=await renderToString(app);
        assert.match(html,new RegExp(`<div class="breadcrumb"><strong>${title}</strong>`));
      }
    }
  } finally {await server.close();}
});

test('one-time login panel escapes credentials and renders no inputs that resubmit account creation',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const panel=(await server.ssrLoadModule('/src/modules/accounts/CreatedLoginDetails.vue')).default;
    const html=await renderToString(createSSRApp({render:()=>h(panel,{details:createdLoginDetails('pos','test@example.test','<img src=x onerror=bad()>')})}));
    assert.ok(html.includes('https://pos.dananir-iq.com/login'));
    assert.ok(html.includes('&lt;img'));
    assert.ok(!html.includes('<img'));
    assert.ok(!html.includes('<form'));
    assert.ok(!html.includes('<input'));
    assert.ok(html.includes('تظهر بيانات الدخول مرة واحدة'));
  } finally {await server.close();}
});
