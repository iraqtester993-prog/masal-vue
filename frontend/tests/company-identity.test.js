import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {computed,effectScope,nextTick,reactive,ref,watch} from 'vue';
import {parse} from '@vue/compiler-sfc';
import {identityScope} from '../src/modules/auth/session.js';
import {createCompanyApi} from '../src/modules/company/company-api.js';
import * as companyModel from '../src/modules/company/company-model.js';
import {createNotificationCounts} from '../src/layouts/notification-counts.js';

const profile={name:'الشركة',slides:[],activities:[],offers:[],projects:[],social:[],visibility:{}};
const identity=()=>({portal:'admin',user:{id:1},account:{id:2,type:'system',name:'النظام'},membership:{kind:'owner'},permissions:['company.edit','notifications.view'],capabilities:{system_scope:true},brand:{color:'#123456'}});
const flush=async()=>{await nextTick();await Promise.resolve();};

async function editor(){
  const source=await readFile(new URL('../src/modules/company/CompanyPage.vue',import.meta.url),'utf8');
  const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
  const requests=[],writes=[],dispose=[];
  const session={state:reactive({identity:identity()}),can:key=>session.state.identity?.permissions.includes(key),api:{request:async path=>{requests.push(path);return {data:{profile:structuredClone(profile),version:1}};},mutate:async(path,method,payload)=>{writes.push({path,method,payload});return {data:{profile:payload.profile,version:2}};}}};
  const context={...companyModel,computed,ref,reactive,watch,nextTick,identityScope,createCompanyApi,AbortController,clearTimeout,clearInterval,defineProps:()=>({management:true,publicPage:false}),usePortal:()=>({session}),useRouter:()=>({}),inject:()=>null,inquiryNotificationsKey:Symbol(),useRouteRefresh(){},onMounted(){},onServerPrefetch(){},onBeforeUnmount:callback=>dispose.push(callback)};
  const effects=effectScope();effects.run(()=>runInNewContext(script+'\nglobalThis.controls={load,save,record,draft,editing,success,saving};',context));
  await context.controls.load();
  return {session,requests,writes,controls:context.controls,dispose(){for(const callback of dispose)callback();effects.stop();}};
}

test('a routine identity refresh preserves unsaved company edits, while a revoked grant clears them',async()=>{
  const page=await editor();
  try{
    const draft=page.controls.draft.value;draft.name='مسودة لم تحفظ';
    page.session.state.identity={...identity(),account:{...identity().account,name:'اسم محدث',version:8},brand:{color:'#556677'}};await flush();
    assert.equal(page.controls.draft.value,draft);assert.equal(page.controls.draft.value.name,'مسودة لم تحفظ');assert.equal(page.controls.editing.value,true);assert.equal(page.requests.length,1);
    page.session.state.identity={...identity(),permissions:['notifications.view']};await flush();
    assert.equal(page.controls.draft.value,null);assert.equal(page.controls.record.value,null);assert.equal(page.controls.editing.value,false);assert.equal(page.requests.length,1);
  }finally{page.dispose();}
});

test('an in-flight company save accepts the same authorized actor after a brand refresh',async()=>{
  const page=await editor();
  try{
    let finish;page.session.api.mutate=async()=>new Promise(resolve=>finish=resolve);
    page.controls.draft.value.name='بيانات محفوظة';const pending=page.controls.save();
    page.session.state.identity={...identity(),brand:{color:'#abcdef'}};await flush();
    assert.equal(page.controls.saving.value,true);finish({data:{profile:{...profile,name:'بيانات محفوظة'},version:2}});await pending;
    assert.equal(page.controls.record.value.version,2);assert.equal(page.controls.record.value.profile.name,'بيانات محفوظة');assert.equal(page.controls.draft.value,null);assert.equal(page.controls.success.value,'تم حفظ موقع الشركة.');
  }finally{page.dispose();}
});

test('identity scopes exclude appearance but include every authorization boundary and password reset state',()=>{
  const source=identity(),scope=identityScope(source);
  assert.equal(identityScope({...source,brand:{color:'#ffffff'},account:{...source.account,name:'Other',version:99}}),scope);
  for(const changed of [{portal:'agents'},{user:{id:9}},{account:{id:8,type:'system'}},{membership:{kind:'employee'}},{permissions:[]},{capabilities:{system_scope:false}},{password_reset:{required:true,version:1}}])assert.notEqual(identityScope({...source,...changed}),scope);
});

test('a slow notification response survives a presentation refresh under the same stable scope',async()=>{
  let actor=identity(),finish;const published=[];
  const counts=createNotificationCounts({api:{request:async()=>new Promise(resolve=>finish=resolve)},identity:()=>actor?identityScope(actor):null,allowed:()=>actor?.permissions.includes('notifications.view'),publish:value=>published.push(value)});
  try{
    const pending=counts.refresh();actor={...actor,brand:{color:'#556677'}};
    finish({data:{unread:3,sidebar_counts:{support:3}}});await pending;
    assert.equal(published.at(-1).unread,3);assert.equal(published.at(-1).counts.support,3);
  }finally{counts.dispose();}
});

test('an uploaded company image survives a brand refresh and stays hidden after an account change',async()=>{
 const source=await readFile(new URL('../src/modules/company/CompanyImage.vue',import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 for(const changedAccount of [false,true]){
  let finish;const events=[],session={state:reactive({identity:identity()}),api:{}};
  const context={ref,AbortController,identityScope,defineProps:()=>({disabled:false}),defineEmits:()=>((...event)=>events.push(event)),usePortal:()=>({session}),
   createCompanyApi:()=>({upload:()=>new Promise(resolve=>finish=resolve)}),watch(){},onBeforeUnmount(){},URL:{createObjectURL:()=> 'blob:preview',revokeObjectURL(){}}};
  runInNewContext(script+'\nglobalThis.controls={choose};',context);
  const pending=context.controls.choose({target:{files:[{type:'image/png',size:42}],value:'selected'}});
  session.state.identity={...identity(),brand:{color:'#aabbcc'},...(changedAccount?{account:{id:99,type:'system'}}:{})};
  finish({data:{id:17}});await pending;
  const accepted=events.find(event=>event[0]==='update:modelValue');
  assert.equal(!!accepted,!changedAccount);if(accepted)assert.equal(accepted[1].id,17);
 }
});
