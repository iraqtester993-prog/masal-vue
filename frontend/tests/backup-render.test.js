import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';
test('actual Backup page retains original two cards and restricts server actions to System owner',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),page=(await server.ssrLoadModule('/src/modules/backups/BackupPage.vue')).default;
  for(const actor of [{type:'system',kind:'owner',calls:1},{type:'system',kind:'employee',calls:0},{type:'main_agent',kind:'owner',calls:0}]){
   const calls=[],session={state:reactive({identity:{user:{id:1},account:{id:1,type:actor.type},membership:{kind:actor.kind}}}),can:()=>true,api:{request:async(path)=>{calls.push(path);return{data:[{id:'existing',status:'completed',bytes:1048576,created_at:'2026-10-06T10:00:00Z'}],capabilities:{prepare:false,switch:false,reason:'provider pending'}};},mutate:()=>{throw new Error('Rendering must not create snapshots or restore');}}};
   const app=createSSRApp({render:()=>h(page)});app.provide(portalContextKey,{session});const html=await renderToString(app);
   assert.equal(calls.length,actor.calls);assert.ok(!html.includes('undefined'));assert.ok(!html.includes('NaN'));
   if(actor.calls){assert.ok(html.includes('class="two"'));assert.ok(html.includes('اختيار نسخة احتياطية من الحاسبة'));assert.ok(html.includes('نسخ احتياطي'));assert.ok(html.includes('/api/v1/backups/existing/download'));assert.ok(html.includes('فحص للاسترجاع'));assert.ok(html.includes('15 ميغابايت'));}
   else{assert.ok(html.includes('مالك حساب إدارة النظام فقط'));assert.ok(!html.includes('type="file"'));assert.ok(!html.includes('تنزيل النسخة'));}
  }
 }finally{await server.close();}
});