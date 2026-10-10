import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp, h, reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createMemoryHistory, createRouter} from 'vue-router';

test('actual stock pages and import steps render with portal context without issuing writes during rendering', async () => {
  const server = await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const {portalContextKey} = await server.ssrLoadModule('/src/modules/auth/session.js');
    const permissions = ['import.view','import.preview','import.approve','inventory.view','inventory.details','inventory.edit','claims.view','claims.settle','exports.view','exports.request','data.pin'];
    const calls = [];
    const session = {state:reactive({identity:{account:{id:12,type:'system',name:'الإدارة'},user:{id:8},membership:{kind:'owner'},permissions}}),can:key=>permissions.includes(key),refresh:async()=>null,api:{request:path=>{calls.push(path);throw Error('Rendering must not fetch or invent data');},mutate:()=>{throw Error('Rendering must not mutate');}}};
    const workspace = (await server.ssrLoadModule('/src/modules/stock/StockWorkspace.vue')).default;
    const imports = (await server.ssrLoadModule('/src/modules/stock/ImportPage.vue')).default;
    const form = (await server.ssrLoadModule('/src/modules/stock/ImportForm.vue')).default;
    const router = createRouter({history:createMemoryHistory(),routes:[{path:'/inventory',name:'inventory',component:workspace},{path:'/claims',name:'claims',component:workspace},{path:'/exports',name:'exports',component:workspace},{path:'/import',name:'import',component:imports}]});
    for (const [path,component,label,props] of [
      ['/inventory',workspace,'طلبية جديدة',{}],
      ['/claims',workspace,'البطاقات التالفة',{}],
      ['/exports',workspace,'سجل الإرجاع',{}],
      ['/import',imports,'سجل الطلبيات متعددة الملفات',{}],
      ['/import',form,'المعاينة والإرسال',{options:{accounts:[{id:14,name:'وكيل',type:'main_agent'}],providers:[{id:15,name:'الشركة'}],products:[],sources:[],cities:['بغداد']}}],
    ]) {
      await router.push(path);
      const app = createSSRApp({render:()=>h(component,props)});
      app.use(router); app.provide(portalContextKey,{session,portal:{id:'admin'},config:{}});
      const html = await renderToString(app);
      assert.ok(html.includes(label), `${path} must render ${label}`);
      assert.ok(!html.includes('undefined'));
    }
    assert.deepEqual(calls,[]);
  } finally {await server.close();}
});
