import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {computed,reactive,ref} from 'vue';
import {parse} from '@vue/compiler-sfc';
import {createMutationKey,receiptBlocks} from '../src/modules/sales/sales-model.js';

async function designer(type,configuration={},permissions=['branding.receipt','products.edit','branding.edit']) {
  const source=await readFile(new URL('../src/modules/sales/ReceiptDesignerPage.vue',import.meta.url),'utf8');
  const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
  const writes=[],layout={header:'Header',footer:'Footer',color:'#123456',agent_color:'#345678',agent_text:'Agent',display_order:Object.keys(receiptBlocks),version:7,personal_version:3,qr_enabled:true,recharge_template:'*123*{pin}#',qr_system_enabled:false,qr_system_template:'',qr_override:true,...configuration};
  const vm={identity:ref({account:{type}}),ownId:ref(42),can:key=>permissions.includes(key),state:reactive({busy:false,error:'',options:{products:[{id:2,provider_id:1,provider_name:'Company',name:'Category'}]}}),key:()=>createMutationKey(()=>'designer-qr-key'),api:{layout:async()=>({data:structuredClone(layout)}),saveLayout:async(id,payload)=>{writes.push({id,payload:JSON.parse(JSON.stringify(payload))});return {data:{version:8}};}},execute:async(payload,key,action)=>(await action({...payload,idempotency_key:key.for(payload)})).data,failure:async error=>error.message};
  let refreshRegistration;const scope={computed,reactive,ref,useSalesRuntime:()=>vm,receiptBlocks,watch(){},onMounted(){},onBeforeUnmount(){},AbortController,URL,useRouteRefresh:(callback,options)=>{refreshRegistration={callback,options};}};
  runInNewContext(script+'\nglobalThis.control={company,productId,draft,previewReceipt,loadLayout,save};',scope);
  scope.control.company.value=1;scope.control.productId.value=2;await scope.control.loadLayout();
  return {vm,writes,control:scope.control,refreshRegistration};
}

test('system editor uses system QR defaults rather than a main agent preview override',async()=>{
  const {control,writes}=await designer('system');
  assert.equal(control.draft.qrMode,'hide');assert.equal(control.draft.rechargeTemplate,'');
  assert.equal(control.previewReceipt.value.design.qr_enabled,false);
  control.draft.qrMode='show';control.draft.rechargeTemplate='*123*{pin}#';
  assert.equal(control.previewReceipt.value.design.qr_enabled,true);
  await control.save();
  assert.equal(writes.length,1);assert.equal(writes[0].payload.version,7);
  assert.equal(writes[0].payload.account_id,undefined);assert.equal(writes[0].payload.layout.qr_enabled,true);
  assert.equal(writes[0].payload.layout.recharge_template,'*123*{pin}#');
});

test('main agent can inherit, hide or show QR only in its own category layout',async()=>{
  const {control,writes}=await designer('main_agent',{qr_override:false,qr_system_enabled:true,qr_system_template:'*123*{pin}#'});
  assert.equal(control.draft.qrMode,'inherit');assert.equal(control.previewReceipt.value.design.qr_enabled,true);
  control.draft.text='Changed';await control.save();
  assert.equal(writes[0].payload.account_id,42);assert.equal(writes[0].payload.version,3);
  assert.equal(writes[0].payload.layout.qr_enabled,null);assert.equal(writes[0].payload.layout.recharge_template,null);
  control.draft.qrMode='hide';await control.save();
  assert.equal(writes[1].payload.layout.qr_enabled,false);
  control.draft.qrMode='show';control.draft.rechargeTemplate='*456*{pin}#';
  assert.equal(control.previewReceipt.value.design.qr_enabled,true);
  assert.equal(control.previewReceipt.value.design.recharge_template,'*456*{pin}#');
  await control.save();assert.equal(writes[2].payload.layout.qr_enabled,true);
  assert.equal(writes[2].payload.layout.recharge_template,'*456*{pin}#');
  assert.equal(writes.every(write=>write.id===2&&write.payload.account_id===42),true);
});

test('POS and an editor without branding permission cannot save QR configuration',async()=>{
  for(const type of ['pos','main_agent','system']){
    const {control,writes}=await designer(type,{},[]);
    control.draft.qrMode='show';control.draft.rechargeTemplate='*123*{pin}#';await control.save();
    assert.equal(writes.length,0);
  }
});

test('designer refresh keeps its visible QR and preserves an edit made during the live layout read',async()=>{
 const {control,vm,writes,refreshRegistration}=await designer('system');
 vm.session={api:{request:async(_path,options)=>{assert.equal(options.fresh,true);return {data:JSON.parse(JSON.stringify(vm.state.options))};}}};
 let finish;vm.api.layout=()=>new Promise(resolve=>finish=resolve);
 assert.equal(refreshRegistration.options.canRefresh(),true);
 const reading=refreshRegistration.callback({signal:new AbortController().signal});await new Promise(setImmediate);
 assert.equal(control.previewReceipt.value.design.header,'Header');assert.equal(control.previewReceipt.value.design.qr_enabled,false);
 control.draft.header='Local unsaved header';
 finish({data:{...control.previewReceipt.value.design,header:'New server header'}});await reading;
 assert.equal(control.draft.header,'Local unsaved header');assert.equal(control.previewReceipt.value.design.qr_enabled,false);
 assert.equal(refreshRegistration.options.canRefresh(),false);assert.equal(writes.length,0);
});
