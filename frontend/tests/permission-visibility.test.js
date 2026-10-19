import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,onServerPrefetch,reactive,ref} from 'vue';
import {createRouter,createMemoryHistory} from 'vue-router';
import {renderToString} from '@vue/server-renderer';
import {initialAccountContext} from '../src/modules/accounts/account-model.js';
import {routeAllowed,landingRoute} from '../src/router/access.js';
import {navigationGroups} from '../src/layouts/navigation.js';
import {allowedReportRoute} from '../src/modules/reports/report-navigation.js';
import {normalizeReportFilters,reportFilterFlags} from '../src/modules/reports/report-model.js';

const actor=(permissions,type='system',capabilities={})=>({user:{id:7},account:{id:1,type,name:'الحساب'},membership:{kind:'employee'},permissions,capabilities});
const mockSession=(permissions,api={},type='system',capabilities={})=>{
 const state=reactive({identity:actor(permissions,type,capabilities),accessConfirmed:true});
 return {state,can:key=>state.identity.permissions.includes(key),api,refresh:async()=>state.identity};
};
async function serverFixture(){return createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});}
async function renderPage(server,path,session,props={},prepare,routeName='sales'){
 const Page=(await server.ssrLoadModule(path)).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
 const inspected={...Page,setup(input,context){const controls=Page.setup(input,context);if(prepare)onServerPrefetch(()=>prepare(controls));return controls;}};
 const router=createRouter({history:createMemoryHistory(),routes:[{path:'/',name:routeName,component:{render:()=>null}}]});await router.push('/');
 const app=createSSRApp({render:()=>h(inspected,props)});app.use(router);app.provide(portalContextKey,{session});
 const context={},html=await renderToString(app,context);return html+Object.values(context.teleports||{}).join('');
}

test('management metadata opens a profile-only scoped account without requesting account directories',async()=>{
 const management_account={id:44,name:'الجهة المكلف بها',type:'main_agent',status:'active'};
 const api={accounts:()=>assert.fail('No account.view was granted')};
 assert.deepEqual(await initialAccountContext(actor(['permission_profile.view'],'system',{management_account_id:44,management_account}),api),management_account);
 await assert.rejects(initialAccountContext(actor(['permission_profile.view'],'system',{management_account_id:null}),api));
 await assert.rejects(initialAccountContext(actor(['permission_profile.view']),api));
});

test('routes, menus, report links and landing agree on explicit system scope and any-permission pages',()=>{
 const settings={name:'app-settings',path:'/app-settings',meta:{requiresAuth:true,navigationId:'app-settings',systemScope:true,accountTypes:['system'],anyPermissions:['app.settings','printers.manage']}};
 const routes=[settings],unscoped=mockSession(['printers.manage']),scoped=mockSession(['printers.manage'],{},'system',{system_scope:true});
 for(const session of [unscoped,scoped]){
  const permitted=session===scoped;
  assert.equal(routeAllowed(settings,session),permitted);
  assert.equal(!!allowedReportRoute(routes,'app-settings',session.state.identity,session.can),permitted);
  assert.deepEqual(navigationGroups({routes,identity:session.state.identity,can:session.can}).flatMap(group=>group.items.map(item=>item.id)),permitted?['app-settings']:[]);
  assert.deepEqual(landingRoute({getRoutes:()=>routes},session),{name:permitted?'app-settings':'no-access'});
 }
 scoped.state.identity={...scoped.state.identity,permissions:[]};assert.equal(routeAllowed(settings,scoped),false);
});

test('server backup coverage is required in addition to global scope and the explicit backup grant',()=>{
 const route={name:'backup',meta:{requiresAuth:true,permission:'backup.view',systemScope:true,backupAccess:true}};
 for(const [system_scope,backup_access,permitted] of [[true,true,true],[true,false,false],[false,true,false]]){
  const session=mockSession(['backup.view'],{},'system',{system_scope,backup_access});assert.equal(routeAllowed(route,session),permitted);
  session.state.identity={...session.state.identity,permissions:[]};assert.equal(routeAllowed(route,session),false);
 }
});

test('POS digital-only history is reachable while a non-POS account still needs the stock-history grant',()=>{
 const route={name:'sales',path:'/sales',meta:{requiresAuth:true,navigationId:'sales',permission:'sales.view',permissionsByAccountType:{pos:{anyPermissions:['sales.view','digital.view']}}}};
 for(const [permissions,type,permitted] of [[['digital.view'],'pos',true],[['sales.view'],'pos',true],[[],'pos',false],[['digital.view'],'main_agent',false]]){
  const session=mockSession(permissions,{},type);assert.equal(routeAllowed(route,session),permitted);
  if(type==='pos')assert.deepEqual(navigationGroups({routes:[route],identity:session.state.identity,can:session.can}).flatMap(group=>group.items.map(item=>item.id)),permitted?['sales']:[]);
 }
});

test('actual profile creation offers only delegable controls and never fetches or copies account privileges',async()=>{
 const server=await serverFixture();try{
  const calls=[],writes=[],catalog=[{key:'printers.manage',label:'إدارة الطابعات',group:'printers',dependencies:[],employee_allowed:true},{key:'backup.view',label:'نسخ ممنوعة',group:'backup',dependencies:[],employee_allowed:true}];
  const session=mockSession(['permission_profile.create','printers.manage'],{
   permissionCatalog:async(signal,parameters)=>{calls.push(parameters);return {data:catalog,meta:{account_version:9,grantable_permissions:['printers.manage']}};},
   account:()=>assert.fail('A permission editor cannot request an ungranted account endpoint'),
   createProfile:async(id,payload)=>{writes.push({id,payload});return {data:{}};},
  },'system',{system_scope:true});
  const html=await renderPage(server,'/src/modules/accounts/PermissionEditor.vue',session,{inline:true,account:{id:1,name:'الإدارة',permissions:['backup.view']}},async controls=>{
   await controls.loadCatalog();assert.deepEqual(Array.from(controls.permissions.value),[]);
   controls.name.value='طباعة';controls.toggle('printers.manage',true);controls.module.value=controls.groups.value[0];await controls.save();
  });
  assert.deepEqual(calls,[{account_id:1,context:'profile'}]);assert.deepEqual(writes,[{id:1,payload:{name:'طباعة',permissions:['printers.manage']}}]);
  assert.ok(html.includes('إدارة الطابعات'));assert.ok(!html.includes('نسخ ممنوعة'));assert.ok(!html.includes('مقيدة من الأعلى'));assert.ok(!html.includes('disabled type="checkbox"'));
 }finally{await server.close();}
});

test('actual account permission edits send only visible keys and use the version from the scoped catalog',async()=>{
 const server=await serverFixture();try{
  let body;const session=mockSession(['account.permissions','printers.manage'],{
   permissionCatalog:async(signal,parameters)=>{assert.deepEqual(parameters,{account_id:44,context:'account'});return {data:[{key:'printers.manage',label:'إدارة الطابعات',group:'printers',dependencies:[]}],meta:{account_version:9,grantable_permissions:['printers.manage']}};},
   account:()=>assert.fail('The catalog supplies the target metadata'),setAccountPermissions:async(id,payload)=>{assert.equal(id,44);body=payload;},
  });
  await renderPage(server,'/src/modules/accounts/PermissionEditor.vue',session,{account:{id:44,name:'وكيل',version:2,permissions:['printers.manage','hidden.key']}},async controls=>{await controls.loadCatalog();controls.reason.value='تعديل';await controls.save();});
  assert.deepEqual(body,{permissions:['printers.manage'],version:9,reason:'تعديل'});
 }finally{await server.close();}
});

test('a name-only staff editor hides role/scope controls and makes no profile or account lookup read',async()=>{
 const server=await serverFixture();try{
  const session=mockSession(['staff.update'],{profiles:()=>assert.fail('staff.role was not granted'),accounts:()=>assert.fail('account.view was not granted'),request:()=>assert.fail('staff.scope was not granted')});
  const html=await renderPage(server,'/src/modules/accounts/StaffDialog.vue',session,{account:{id:44,name:'وكيل'},staff:{id:12,name:'الموظف',permission_profile_id:3,permission_profile_name:'نوعه الحالي',scope_roots:[44]}},controls=>controls.loadProfiles());
  assert.ok(html.includes('اسم الموظف'));assert.ok(!html.includes('staff-profile'));assert.ok(!html.includes('نطاق الوصول'));assert.ok(!html.includes('الحسابات المكلف بها'));
 }finally{await server.close();}
});

test('staff creation reads only active assignable profiles without requiring profile-directory permission',async()=>{
 const server=await serverFixture();try{
  const calls=[],session=mockSession(['staff.create'],{profiles:async(id,parameters)=>{calls.push({id,parameters});return {data:[{id:4,name:'طباعة',status:'active',abilities:{assign:true}},{id:5,name:'معطل',status:'disabled'},{id:6,name:'أقوى',status:'active',abilities:{assign:false}}],meta:{current_page:1,last_page:1}};},accounts:()=>assert.fail('No account.view grant')});
  const html=await renderPage(server,'/src/modules/accounts/StaffDialog.vue',session,{account:{id:44,name:'وكيل'}},controls=>{assert.equal(controls.form.include_descendants,false,'creation must not widen a limited employee scope');return controls.loadProfiles();});
  assert.equal(calls.length,1);assert.equal(calls[0].id,44);assert.equal(calls[0].parameters.assignable,1);
  assert.ok(html.includes('طباعة'));assert.ok(!html.includes('أقوى'));assert.ok(!html.includes('>معطل<'));assert.ok(!html.includes('نطاق الوصول'));
 }finally{await server.close();}
});

test('actual POS history rejects a remembered Topup filter before cache hydration when digital access is absent',async()=>{
 const server=await serverFixture();try{
  const paths=[],session=mockSession(['sales.view'],{
   peekViewState:()=>({filters:{kind:'topup'},showFilters:true,pages:1}),peekRequest:path=>{paths.push(path);return null;},request:()=>assert.fail('Rendering must not read protected data'),
  },'pos');
  const sales={identity:ref(session.state.identity),can:session.can,state:{busy:false}};
  const html=await renderPage(server,'/src/modules/digital/POSHistory.vue',session,{sales});
  assert.ok(!html.includes('<option value="topup"'));assert.ok(!html.includes('>الخدمة<'));assert.ok(!paths.some(path=>path.includes('/history?')&&path.includes('kind=topup')));
  assert.ok(paths.some(path=>path.includes('/history?')&&path.includes('kind=all')));
 }finally{await server.close();}
});

test('actual digital-only POS discards a cached print-request tab and never hydrates stock or print-request history',async()=>{
 const server=await serverFixture();try{
  const paths=[],session=mockSession(['digital.view'],{peekViewState:()=>({tab:'requests'}),peekRequest:path=>{paths.push(path);return null;},request:()=>assert.fail('SSR must not read'),mutate:()=>assert.fail('No financial mutations')},'pos');
  let tab;const html=await renderPage(server,'/src/modules/sales/SalesPage.vue',session,{},controls=>{tab=controls.tab.value;});
  assert.equal(tab,'sales');assert.ok(html.includes('pos-history'));assert.ok(!html.includes('طلبات معالجة الطباعة'));assert.ok(!html.includes('اتجاه الطلبات'));
  assert.ok(!paths.some(path=>path.includes('/sales?')||path.includes('reprint-requests')));
 }finally{await server.close();}
});

test('staff-row server abilities hide stronger, self and protected actions even when the actor has action grants',async()=>{
 const server=await serverFixture();try{
  const session=mockSession(['staff.view','staff.update','staff.toggle','staff.password_reset'],{setStaffStatus:()=>assert.fail('A denied staff action must not be sent')});
  const row=(id,abilities,user_id=id)=>({id,user_id,kind:'employee',name:'موظف '+id,status:'active',version:1,abilities});
  const denied=row(11,{update:false,toggle:false,password_reset:false}),granted=row(12,{update:true,toggle:true,password_reset:true});
  const html=await renderPage(server,'/src/modules/accounts/StaffPage.vue',session,{},async controls=>{
   controls.state.rows=[denied,granted,row(7,{update:true,toggle:true,password_reset:true})];
   controls.modal.value={kind:'status',staff:denied};await assert.rejects(controls.statusAction({reason:'اختبار'}));controls.modal.value=null;
  });
  assert.equal((html.match(/>تعديل<\/button>/g)||[]).length,1);
  assert.equal((html.match(/>إعادة كلمة المرور<\/button>/g)||[]).length,1);
  assert.equal((html.match(/>إيقاف<\/button>/g)||[]).length,1);
 }finally{await server.close();}
});

test('a delegated system employee cannot see or submit approval of their own stock return',async()=>{
 const server=await serverFixture();try{
  const session=mockSession(['exports.view','exports.approve'],{request:()=>assert.fail('A denied approval cannot issue an API request')},'system',{system_scope:false});
  const own={id:11,batch_id:1,status:'pending',creator_id:7,reason:'إرجاع خاص بي',quantity:1,version:1},peer={...own,id:12,creator_id:8,reason:'إرجاع زميل'};
  const html=await renderPage(server,'/src/modules/stock/StockWorkspace.vue',session,{},async controls=>{
   controls.state.rows=[own,peer];assert.equal(controls.canReviewWithdrawal(own),false);assert.equal(controls.canReviewWithdrawal(peer),true);
   await controls.withdrawal(own,'approve');assert.equal(controls.state.saving,false);
  },'exports');
  assert.equal((html.match(/>اعتماد الإرجاع<\/button>/g)||[]).length,1);
  assert.equal((html.match(/>رفض<\/button>/g)||[]).length,1);
 }finally{await server.close();}
});

test('report filter authority hides unavailable product and sale controls and clears their remembered values',async()=>{
 const options={sections:[{id:'audit',title:'التدقيق'}],accounts:[],account_kinds:{main_agent:1,sub_agent:1},products:[],providers:[],cities:['بغداد'],filters:{products:false,providers:false,status:false,accounts:true,cities:true}};
 assert.deepEqual(reportFilterFlags(options),{products:false,providers:false,accounts:true,cities:true,status:false});
 const filters={kind:'inventory',product_id:9,provider_id:8,status:'Printed',agent_id:44,pos_id:'',city:'بغداد',currency:'IQD'};
 const normalized=normalizeReportFilters(filters,options);assert.equal(normalized.kind,'all');assert.equal(normalized.product_id,'');assert.equal(normalized.provider_id,'');assert.equal(normalized.status,'');assert.equal(normalized.agent_id,44);
 assert.equal(reportFilterFlags({sections:[{id:'sales-services'}]}).status,false,'digital statuses are not stock print statuses');
 const server=await serverFixture();try{
  const picker=(await server.ssrLoadModule('/src/modules/finance/AccountPicker.vue')).default,original=picker.setup,contexts=[];
  picker.setup=(props,context)=>{contexts.push(props.parameters);return original(props,context);};
  const session=mockSession(['reports.view','reports.audit'],{request:()=>assert.fail('Report controls cannot read accounts without opening the selector')});
  const html=await renderPage(server,'/src/modules/reports/ReportSettings.vue',session,{filters,options},controls=>assert.equal(controls.draft.product_id,''));
  assert.ok(!html.includes('>الفئة<'));assert.ok(!html.includes('>الشركة<'));assert.ok(!html.includes('حالة البيع'));assert.ok(!html.includes('الفئة والشركة يرشحان'));
  assert.equal(contexts.length,3);assert.ok(contexts.every(parameters=>parameters.context==='reports'));
  picker.setup=original;
 }finally{await server.close();}
});
