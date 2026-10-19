import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import * as Vue from 'vue';
import {parse,compileScript} from '@vue/compiler-sfc';
import {canResetAccountPassword} from '../src/modules/auth/password-reset.js';

const request=(id,trusted=false)=>({id,request_label:id.toUpperCase(),trusted,device_label:`جهاز ${id}`});
const clone=value=>JSON.parse(JSON.stringify(value));
async function surface({requests=[request('first')],staff=false,readFailure=null}={}){
 const account={id:27,name:'حساب تجريبي',type:'main_agent',version:1,can_reset_password:true,password_reset_requests:[request('stale')]};
 const server={current:staff?{version:7,password_reset_requests:requests}:{...account,version:7,password_reset_requests:requests},readFailure},reads=[],writes=[];
 const session={state:Vue.reactive({identity:{account:{id:1,type:'system'},membership:{kind:'owner'},permissions:['account.password_reset','staff.password_reset']}}),can:()=>true,api:{
  async request(path,options){reads.push({path,...options});if(server.readFailure)throw server.readFailure;return{data:clone(server.current)};},
  async resetAccountPassword(id,payload,signal){writes.push({path:`/accounts/${id}/password-reset`,payload:clone(payload),signal});return{data:{}};},
  async mutate(path,method,payload,signal){assert.equal(method,'POST');writes.push({path,payload:clone(payload),signal});return{data:{}};},
 }};
 const source=await readFile(new URL('../src/modules/accounts/AccountPasswordResetDialog.vue',import.meta.url),'utf8'),parsed=parse(source);assert.deepEqual(parsed.errors,[]);
 let compiled=compileScript(parsed.descriptor,{id:'account-password-reset-dialog-regression',inlineTemplate:true}).content;
 compiled=compiled.replace(/^import\s+{([^}]+)}\s+from\s+['"]vue['"];?/gm,(_,names)=>`const {${names.replace(/\s+as\s+/g,':')}}=Vue;`).replace(/^import .*;?\r?$/gm,'').replace('export default','globalThis.Component=');
 const AccountModal={setup(_props,{slots}){return()=>Vue.h('section',slots.default?.());}},FormField={props:['modelValue','name','label','type','options','error','hint'],emits:['update:modelValue'],setup(props,{emit}){return()=>Vue.h('div',[
  Vue.h('label',props.label),props.options?Vue.h('select',{name:props.name,value:props.modelValue,onChange:event=>emit('update:modelValue',event.target.value)},props.options.map(option=>Vue.h('option',{value:option.value},option.label))):Vue.h('textarea',{name:props.name,value:props.modelValue,onInput:event=>emit('update:modelValue',event.target.value)}),Vue.h('small',props.hint),Vue.h('span',props.error),
 ]);}};
 const context={Vue,AbortController,Error,AccountModal,FormField,canResetAccountPassword,useAccountAccess:()=>({session,handleFailure:async error=>error.message})};runInNewContext(compiled,context);
 const node=(tag,text='')=>({tag,text,props:{},children:[],parent:null}),all=node=>[node,...node.children.flatMap(all)],text=node=>node.text+node.children.map(text).join('');
 const renderer=Vue.createRenderer({createElement:tag=>node(tag),createText:text=>node('#text',text),createComment:text=>node('#comment',text),setText:(node,value)=>node.text=value,setElementText:(node,value)=>{node.text=value;node.children=[];},parentNode:node=>node.parent,nextSibling:node=>node.parent?.children[node.parent.children.indexOf(node)+1]||null,patchProp:(node,key,_previous,next)=>node.props[key]=next,insert:(node,parent,anchor=null)=>{if(node.parent)node.parent.children.splice(node.parent.children.indexOf(node),1);node.parent=parent;const index=anchor?parent.children.indexOf(anchor):-1;if(index<0)parent.children.push(node);else parent.children.splice(index,0,node);},remove:node=>{if(node.parent)node.parent.children.splice(node.parent.children.indexOf(node),1);node.parent=null;}});
 const root=node('root'),app=renderer.createApp(context.Component,{account,...(staff?{staff:{id:8,name:'موظف تجريبي'}}:{})});app.mount(root);
 const flush=async()=>{await new Promise(setImmediate);await Vue.nextTick();};await flush();
 const page={root,app,server,session,reads,writes,flush,all:()=>all(root),text:()=>text(root)};
 page.field=name=>page.all().find(node=>node.props.name===name);page.choose=value=>page.field('password-reset-device').props.onChange({target:{value}});page.reason=value=>page.field('password-reset-reason').props.onInput({target:{value}});
 page.submit=()=>page.all().find(node=>node.tag==='form').props.onSubmit({preventDefault(){}});page.refresh=()=>page.all().find(node=>node.tag==='button'&&text(node)==='تحديث طلبات الأجهزة').props.onClick();
 page.confirm=()=>page.all().find(node=>node.tag==='button'&&text(node)==='تأكيد إعادة التعيين');return page;
}

test('opening reads fresh account requests and confirmation uses a fresh version and the selected request',async()=>{
 const page=await surface();try{
  assert.equal(page.reads.length,1);assert.equal(page.reads[0].path,'/accounts/27');assert.equal(page.reads[0].fresh,true);assert.ok(page.reads[0].signal instanceof AbortSignal);assert.ok(!page.text().includes('STALE'));
  page.choose('first');page.reason(' سبب صاحب الحساب ');page.server.current.version=9;await page.submit();await page.flush();
  assert.equal(page.reads.length,2);assert.deepEqual(page.writes.map(row=>row.payload),[{version:9,reason:'سبب صاحب الحساب',request_id:'first'}]);assert.ok(page.text().includes('FIRST'));assert.ok(page.text().includes('الموافقة لهذا الجهاز فقط'));assert.ok(page.text().includes('لا تُرسل برسالة نصية أو بريد إلكتروني'));
 }finally{page.app.unmount();}
});

test('refreshing newly created requests keeps the reason and explicit selected device',async()=>{
 const page=await surface();try{
  page.choose('first');page.reason('مسودة السبب');page.server.current.password_reset_requests.push(request('second'));page.server.current.version=10;
  await page.refresh();await page.flush();assert.equal(page.field('password-reset-reason').props.value,'مسودة السبب');assert.equal(page.field('password-reset-device').props.value,'first');assert.ok(page.text().includes('SECOND'));
  await page.submit();assert.deepEqual(page.writes[0].payload,{version:10,reason:'مسودة السبب',request_id:'first'});
 }finally{page.app.unmount();}
});

test('a consumed explicit request remains visibly invalid and never becomes automatic approval of another device',async()=>{
 const page=await surface();try{
  page.choose('first');page.reason('سبب محفوظ');page.server.current.password_reset_requests=[request('second',true)];await page.refresh();await page.flush();
  assert.equal(page.field('password-reset-device').props.value,'first');assert.equal(page.field('password-reset-reason').props.value,'سبب محفوظ');assert.equal(page.confirm().props.disabled,true);assert.ok(page.text().includes('الطلب المختار لم يعد متاحًا'));
  await page.submit();assert.equal(page.writes.length,0);
 }finally{page.app.unmount();}
});

test('confirmation rechecks consumption even when the manager has not manually refreshed the dialog',async()=>{
 const page=await surface();try{
  page.choose('first');page.reason('سبب التأكيد');page.server.current.password_reset_requests=[];page.server.current.version=11;
  await page.submit();await page.flush();assert.equal(page.writes.length,0);assert.equal(page.reads.length,2);assert.ok(page.text().includes('طلب الجهاز المختار لم يعد متاحًا'));assert.equal(page.field('password-reset-device').props.value,'first');
 }finally{page.app.unmount();}
});

test('one trusted device is pinned and its exact reference is submitted and shown on success',async()=>{
 const page=await surface({requests:[request('trusted',true)]});try{
  page.reason('سبب موثوق');await page.submit();await page.flush();assert.equal(page.writes[0].payload.request_id,'trusted');assert.ok(page.text().includes('TRUSTED'));assert.ok(page.text().includes('جهاز trusted'));
 }finally{page.app.unmount();}
});

test('replacing the sole trusted device cannot silently change the automatic approval target',async()=>{
 const page=await surface({requests:[request('trusted',true)]});try{
  page.reason('سبب ثابت');page.server.current.password_reset_requests=[request('replacement',true)];await page.submit();await page.flush();assert.equal(page.writes.length,0);assert.ok(page.text().includes('طلب الجهاز المعروف تغيّر'));
  page.choose('replacement');await page.submit();assert.equal(page.writes[0].payload.request_id,'replacement');
 }finally{page.app.unmount();}
});

test('a trusted request appearing after an empty opening snapshot needs explicit confirmation',async()=>{
 const page=await surface({requests:[]});try{
  page.reason('سبب محفوظ');page.server.current.password_reset_requests=[request('arrived',true)];await page.submit();await page.flush();
  assert.equal(page.writes.length,0);assert.equal(page.field('password-reset-device').props.value,'auto');assert.ok(page.text().includes('اختر طلب الجهاز المقصود'));
  page.choose('arrived');await page.submit();assert.equal(page.writes[0].payload.request_id,'arrived');
 }finally{page.app.unmount();}
});

test('multiple trusted requests require explicit selection and new untrusted requests are never approved automatically',async()=>{
 for(const requests of [[request('one',true),request('two',true)],[request('one')]]){
  const page=await surface({requests});try{
   page.reason('اختيار الجهاز');await page.flush();assert.equal(page.confirm().props.disabled,true);await page.submit();assert.equal(page.writes.length,0);
   const selected=requests.at(-1);page.choose(selected.id);await page.submit();assert.equal(page.writes[0].payload.request_id,selected.id);
  }finally{page.app.unmount();}
 }
});

test('staff reset retains its own read path and uses the freshly loaded membership version',async()=>{
 const page=await surface({staff:true});try{
  assert.equal(page.reads[0].path,'/accounts/27/staff/8/password-reset');page.choose('first');page.reason('طلب الموظف');page.server.current.version=12;await page.submit();
  assert.equal(page.writes[0].path,'/accounts/27/staff/8/password-reset');assert.equal(page.writes[0].payload.version,12);assert.equal(page.writes[0].payload.request_id,'first');
 }finally{page.app.unmount();}
});

test('no recovery request retains the forced-change flow without claiming approval of a device',async()=>{
 const page=await surface({requests:[]});try{
  page.reason('طلب تغيير للجلسة الحالية');await page.submit();await page.flush();assert.deepEqual(page.writes[0].payload,{version:7,reason:'طلب تغيير للجلسة الحالية'});
  assert.ok(page.text().includes('إذا كان صاحب الحساب خارج التطبيق'));assert.ok(!page.text().includes('تم اعتماد طلب الجهاز:'));
 }finally{page.app.unmount();}
});

test('failed fresh reads and revoked account reset access cannot submit an approval',async()=>{
 const page=await surface({readFailure:new Error('تعذر التحديث')});try{
  assert.equal(page.confirm().props.disabled,true);await page.submit();assert.equal(page.writes.length,0);
  page.server.readFailure=null;await page.refresh();page.choose('first');page.reason('طلب الحساب');page.server.current.can_reset_password=false;await page.submit();await page.flush();assert.equal(page.writes.length,0);assert.ok(page.text().includes('لم تعد متاحة لهذا الحساب'));
 }finally{page.app.unmount();}
});
