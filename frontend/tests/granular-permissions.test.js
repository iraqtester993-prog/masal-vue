import test from 'node:test';
import assert from 'node:assert/strict';
import {permissionDependencies,changePermission,grantablePermissions} from '../src/modules/accounts/account-model.js';
import {quickActions} from '../src/layouts/quick-actions.js';
import {routeAllowed} from '../src/router/access.js';
import {navigationGroups} from '../src/layouts/navigation.js';

const identity={user:{id:1},account:{id:1,type:'system',status:'active'},membership:{kind:'employee'}};
const session=permissions=>({state:{identity},can:key=>permissions.includes(key)});

test('permission editor uses server dependencies instead of assuming a module view',()=>{
 const catalog=[{key:'integrations.balance',dependencies:['digital.view']},{key:'digital.view',dependencies:[]}];
 assert.deepEqual(permissionDependencies('integrations.balance',true,catalog),['digital.view']);
 assert.deepEqual(changePermission([], 'integrations.balance',true,['account.view','digital.view','integrations.balance'],true,catalog),['digital.view','integrations.balance']);
});

test('backup, printing and security choices add usable dependencies and remove dependent actions',()=>{
 assert.deepEqual(permissionDependencies('backup.restore'),['backup.view']);
 assert.deepEqual(permissionDependencies('security.sales'),['security.view']);
 assert.deepEqual(permissionDependencies('security.policies'),['security.view']);
 const catalog=[{key:'digital.print',dependencies:['digital.view','digital.receipt','data.pin']}];
 const available=['account.view','digital.view','digital.receipt','data.pin','digital.print'];
 const selected=changePermission([], 'digital.print',true,available,true,catalog);
 assert.ok(available.filter(key=>key!=='account.view').every(key=>selected.includes(key)));
 assert.ok(!selected.includes('account.view'),'the profile must not silently grant unrelated account navigation');
 assert.ok(!changePermission(selected,'data.pin',false,available,true,catalog).includes('digital.print'));
});

test('employee profiles cannot offer owner-only controls but keep delegated printer and provider rights',()=>{
 const catalog=[{key:'backup.restore',employee_allowed:false},{key:'printers.manage',employee_allowed:true},{key:'integrations.credentials',employee_allowed:true}];
 const keys=catalog.map(row=>row.key);
 assert.deepEqual(grantablePermissions(catalog,keys,()=>true,true).map(row=>row.key),['printers.manage','integrations.credentials']);
 assert.equal(grantablePermissions(catalog,keys,()=>true,false).length,3);
});

test('application settings route and navigation require at least one explicit capability',()=>{
 const route={name:'app-settings',path:'/app-settings',meta:{requiresAuth:true,navigationId:'app-settings',accountTypes:['system'],anyPermissions:['app.settings','printers.manage']}};
 assert.equal(routeAllowed(route,session([])),false);
 assert.equal(routeAllowed(route,session(['company.edit'])),false);
 assert.equal(routeAllowed(route,session(['printers.manage'])),true);
 assert.equal(routeAllowed(route,{state:{identity:{...identity,account:{...identity.account,type:'main_agent'}}},can:()=>true}),false);
 const visible=permissions=>navigationGroups({routes:[route],identity,can:session(permissions).can}).flatMap(group=>group.items.map(item=>item.id));
 assert.deepEqual(visible([]),[]);
 assert.deepEqual(visible(['company.edit']),[]);
 assert.deepEqual(visible(['app.settings']),['app-settings']);
});

test('quick sale requires the existing sales view and never relies on the missing sell view',()=>{
 const pos={account:{type:'pos',status:'active'}};
 const actions=permissions=>quickActions(pos,key=>permissions.includes(key)).map(row=>row.id);
 assert.deepEqual(actions(['sales.view','sell.create']),['sell']);
 assert.deepEqual(actions(['sell.view','sell.create']),[]);
});
