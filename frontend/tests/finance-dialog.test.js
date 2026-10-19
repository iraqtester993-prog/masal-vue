import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';
import {parse} from '@vue/compiler-sfc';
import {createSSRApp,h,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createServer} from 'vite';

test('the actual finance dialog separates preview actions while preserving form submission and inherited direction',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const Dialog=(await server.ssrLoadModule('/src/modules/finance/FinanceDialog.vue')).default;
  const context={};
  await renderToString(createSSRApp({render:()=>h(Dialog,{open:true,title:'معاينة',busy:true},{default:()=>h('form',{id:'funding-form'},[h('input',{required:true,name:'amount'}),h('button',{type:'submit'},'حفظ داخل النموذج')]),footer:()=>h('button',{type:'button'},'تأكيد المعاينة')})}),context);
  const html=context.teleports.body.replace(/<!--[\s\S]*?-->/g,'');
  assert.match(html,/<div class="finance-dialog-body"><form id="funding-form">/);
  assert.match(html,/<button type="submit">حفظ داخل النموذج<\/button><\/form><\/div>/);
  assert.match(html,/<footer class="finance-dialog-footer"><button type="button">تأكيد المعاينة<\/button><\/footer>/);
  assert.ok(!html.includes('dir="rtl"'),'dialogs inherit the active document direction');
  assert.match(html,/<button[^>]*disabled[^>]*aria-label="إغلاق معاينة"/);
  const noFooter={};
  await renderToString(createSSRApp({render:()=>h(Dialog,{open:true,title:'اختيار'},()=>h('p','قائمة'))}),noFooter);
  assert.ok(!noFooter.teleports.body.includes('finance-dialog-footer'),'ordinary menus do not reserve an empty action area');
 }finally{await server.close();}
});

test('finance dialog cancellation and controlled visibility retain busy and unmount protection',async()=>{
 const source=await readFile(new URL('../src/modules/finance/FinanceDialog.vue',import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const props={open:true,busy:true},events=[],callbacks=[];let unmount;
 const scope={ref,defineProps:()=>props,defineEmits:()=>event=>events.push(event),nextTick:async()=>{},watch:(_read,callback)=>callbacks.push(callback),onBeforeUnmount:callback=>{unmount=callback;}};
 runInNewContext(script+'\nglobalThis.controls={dialog,close};',scope);
 let shown=0,closed=0;
 scope.controls.dialog.value={open:false,showModal(){shown++;this.open=true;},close(){closed++;this.open=false;}};
 await callbacks[0](true);assert.equal(shown,1);
 await callbacks[0](true);assert.equal(shown,1,'repeated state may not reopen an already open native dialog');
 scope.controls.close();assert.equal(events.length,0);
 props.busy=false;scope.controls.close();assert.deepEqual(events,['close']);
 await callbacks[0](false);assert.equal(closed,1);
 scope.controls.dialog.value.open=true;unmount();assert.equal(closed,2);
});
