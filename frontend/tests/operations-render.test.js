import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';

test('operations original content renders safely, account times require the System owner, and archive confirmation cannot approve itself',async ()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const security=(await server.ssrLoadModule('/src/modules/security/SecurityPage.vue')).default,time=(await server.ssrLoadModule('/src/modules/account-times/AccountTimePage.vue')).default,archive=(await server.ssrLoadModule('/src/modules/archive/ArchivePage.vue')).default,confirm=(await server.ssrLoadModule('/src/modules/archive/ArchiveConfirm.vue')).default;
    for (const [type,kind] of [['system','owner'],['system','employee'],['main_agent','owner'],['pos','owner']]) {
      const requests=[],session={state:reactive({identity:{account:{id:41,type},membership:{kind},user:{id:11}}}),can:()=>true,refresh:async()=>null,api:{request:path=>{requests.push(path);throw Error('Rendering cannot invent records');},mutate:()=>{throw Error('Rendering cannot mutate');}}};
      async function render(component,props={}) {const app=createSSRApp({render:()=>h(component,props)});app.provide(portalContextKey,{session,portal:{id:type==='system'?'admin':type==='pos'?'pos':'agents'}});return renderToString(app);}
      const securityHtml=await render(security);assert.ok(securityHtml.includes('التحكم بالتوقيف'));assert.ok(securityHtml.includes('قرارات التوقيف الفعّالة'));assert.ok(securityHtml.includes('الحسابات المعطّلة أو المقيّدة'));assert.ok(!securityHtml.includes('تطبيق التوقيف'));
      const timeHtml=await render(time);assert.equal(timeHtml.includes('بحث مستخدمي الأوقات'),type==='system'&&kind==='owner');
      const archiveHtml=await render(archive);assert.ok(archiveHtml.includes('أرشيف المحذوفات'));assert.ok(archiveHtml.includes('لا توجد حسابات مؤرشفة'));
      const confirmHtml=await render(confirm,{account:{id:41,type,name:'<script>do not render</script>',password:'PASSWORD_SECRET',pin:'PIN_SECRET',cost:'COST_SECRET'}});assert.ok(confirmHtml.includes('كلمة مرور حسابك'));assert.ok(confirmHtml.includes('autocomplete="current-password"'));assert.ok(confirmHtml.includes('تأكيد الحذف الأرشيفي'));assert.ok(confirmHtml.includes('disabled'));
      for (const html of [securityHtml,timeHtml,archiveHtml,confirmHtml]) for (const secret of ['PASSWORD_SECRET','PIN_SECRET','COST_SECRET','localStorage','sessionStorage','<script>','undefined']) assert.ok(!html.includes(secret));
      assert.deepEqual(requests,[]);
    }
  } finally {await server.close();}
});
