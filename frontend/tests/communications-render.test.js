import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createMemoryHistory,createRouter} from 'vue-router';

async function withModules(run) {
  const server = await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const {portalContextKey} = await server.ssrLoadModule('/src/modules/auth/session.js');
    const support = (await server.ssrLoadModule('/src/modules/support/SupportPage.vue')).default;
    const notifications = (await server.ssrLoadModule('/src/modules/notifications/NotificationsPage.vue')).default;
    const conversation = (await server.ssrLoadModule('/src/modules/support/SupportConversation.vue')).default;
    const details = (await server.ssrLoadModule('/src/modules/notifications/NoticeDetails.vue')).default;
    await run({portalContextKey,support,notifications,conversation,details});
  } finally {await server.close();}
}

test('support and notifications render in all three portals with current grants, without client storage, fabricated records or mutations',async () => {
  await withModules(async ({portalContextKey,support,notifications}) => {
    for (const [type,kind,permissions] of [
      ['system','owner',['support.view','support.create','support.broadcast','support.attach','notifications.view','notifications.send','notifications.attach','notifications.translations','notifications.export']],
      ['main_agent','employee',['support.view','support.create','support.reply','notifications.view']],
      ['pos','owner',['support.view','support.create','support.reply','notifications.view','notifications.export']],
    ]) {
      const requests = [];
      const session = {state:reactive({identity:{account:{id:18,type,name:'الحساب'},user:{id:41},membership:{kind},permissions}}),can:permission => permissions.includes(permission),refresh:async () => null,api:{request:path => {requests.push(path); throw Error('Rendering must not invent an API response');},mutate:() => {throw Error('Rendering must not write');}}};
      const router = createRouter({history:createMemoryHistory(),routes:[{path:'/support',name:'support',component:support},{path:'/notifications',name:'notifications',component:notifications}]});
      for (const [name,page,label] of [['support',support,'المحادثات'],['notifications',notifications,'لا توجد إشعارات']]) {
        await router.push({name}); const app = createSSRApp({render:() => h(page)}); app.use(router); app.provide(portalContextKey,{session,portal:{id:type === 'system' ? 'admin' : type === 'pos' ? 'pos':'agents'},config:{}});
        const html = await renderToString(app);
        assert.ok(html.includes(label)); assert.ok(!html.includes('undefined')); assert.ok(!html.includes('localStorage')); assert.ok(!html.includes('بطاقات تجريبية'));
        if (name === 'support') {assert.ok(html.includes('رسالة جديدة')); assert.equal(html.includes('مستخدمو رسالة الدعم'),permissions.includes('support.broadcast'));}
        if (name === 'notifications') {assert.equal(html.includes('عنوان الإشعار العربي'),permissions.includes('notifications.send')); assert.equal(html.includes('>تصدير<'),permissions.includes('notifications.export'));}
      }
      assert.deepEqual(requests,[]);
    }
  });
});

test('conversation actions require both server hierarchy decisions and current grants, and authorized text remains escaped',async () => {
  await withModules(async ({portalContextKey,conversation}) => {
    const ticket = {id:8,title:'<script>alert(1)</script>',origin_name:'الفرع',recipient_name:'المدير',status:'escalated',can_reply:false,can_close:false,can_escalate:false,messages:[{id:7,body:'<img src=x onerror=alert(1)>',name:'المستخدم',mine:false,time:'2026-10-06T09:00:00Z',attachment_id:42,pin:'PIN_SECRET',cost_minor:'COST_SECRET'}],staff_scope_ids:['FOREIGN_NETWORK_SECRET']};
    for (const [flags,permissions,canAct] of [
      [false,['support.reply','support.close','support.escalate'],false],
      [true,[],false],
      [true,['support.reply','support.close','support.escalate'],true],
    ]) {
      Object.assign(ticket,{can_reply:flags,can_close:flags,can_escalate:flags});
      const app = createSSRApp({render:() => h(conversation,{ticket,modelValue:'رد مصرح'})});
      app.provide(portalContextKey,{session:{can:permission => permissions.includes(permission)}});
      const html = await renderToString(app);
      assert.equal(html.includes('إرسال الرد'),canAct); assert.equal(html.includes('إغلاق المحادثة'),canAct); assert.equal(html.includes('>تصعيد<'),canAct);
      assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;')); assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;')); assert.ok(!html.includes('<img src=x'));
      assert.ok(html.includes('/api/v1/support/attachments/42')); assert.ok(!html.includes('data:image'));
      for (const secret of ['PIN_SECRET','COST_SECRET','FOREIGN_NETWORK_SECRET']) assert.ok(!html.includes(secret));
    }
    ticket.status = 'closed'; ticket.can_reply = false;
    const app = createSSRApp({render:() => h(conversation,{ticket})}); app.provide(portalContextKey,{session:{can:() => true}});
    const html = await renderToString(app); assert.ok(html.includes('المحادثة مغلقة')); assert.ok(!html.includes('إرسال الرد'));
  });
});

test('notice details show only authorized text and a private attachment endpoint, never raw audience, PIN, cost or filesystem fields',async () => {
  await withModules(async ({details}) => {
    const notice = {title:'<svg onload=alert(1)>',body:'<script>alert(2)</script>',sender_name:'<b>المستخدم</b>',time:'2026-10-06T09:00:00Z',attachment_id:19,recipient_ids:['PRIVATE_AUDIENCE'],path:'PRIVATE_FILE_PATH',pin:'PIN_SECRET',cost:'COST_SECRET',translations:{en:{title:'UNREQUESTED_TRANSLATION',body:'PRIVATE_LANGUAGE_BODY'}}};
    const html = await renderToString(createSSRApp({render:() => h(details,{notice})}));
    assert.ok(html.includes('&lt;svg onload=alert(1)&gt;')); assert.ok(html.includes('&lt;script&gt;alert(2)&lt;/script&gt;')); assert.ok(html.includes('&lt;b&gt;المستخدم&lt;/b&gt;'));
    assert.ok(html.includes('/api/v1/support/attachments/19')); assert.ok(!html.includes('<script>'));
    for (const secret of ['PRIVATE_AUDIENCE','PRIVATE_FILE_PATH','PIN_SECRET','COST_SECRET','UNREQUESTED_TRANSLATION']) assert.ok(!html.includes(secret));
  });
});
