import test from 'node:test';
import assert from 'node:assert/strict';
import {createListViewState} from '../src/shared/api/list-view-state.js';
import {createApiClient} from '../src/shared/api/client.js';
import {useListNavigation} from '../src/shared/api/list-navigation.js';

test('returning to a saved list preserves filters while a late read cannot restore its old scroll',async t=>{
 const oldDocument=globalThis.document;globalThis.document={scrollingElement:{scrollTop:0}};
 t.after(()=>{if(oldDocument===undefined)delete globalThis.document;else globalThis.document=oldDocument;});
 const saved={page:3,filters:{q:'saved'},scroll:850};let remembered;
 const view=useListNavigation({peekViewState:()=>saved,viewStateScope:()=>1,rememberViewState:(key,value)=>remembered=value},'accounts');
 assert.equal(view.saved.page,3);assert.equal(view.saved.filters.q,'saved');await view.restoreScroll();
 assert.equal(document.scrollingElement.scrollTop,0);
 document.scrollingElement.scrollTop=350;view.remember({page:3,filters:{q:'saved'},scroll:850});
 assert.deepEqual(remembered,{page:3,filters:{q:'saved'}});
});

test('list preferences are bounded, cloned and an outgoing account cannot save into a new scope',()=>{
 const views=createListViewState({limit:2}),oldScope=views.scope();
 views.remember('categories',{q:'bundle',page:2},oldScope);
 const restored=views.peek('categories');restored.page=9;assert.equal(views.peek('categories').page,2);
 views.remember('distribution',{target:'2'});views.remember('available',{page:3});assert.equal(views.peek('categories'),null);
 views.clear();views.remember('outgoing',{q:'private'},oldScope);assert.equal(views.peek('outgoing'),null);
});

test('business writes invalidate Topup data while preserving list position; logout, revocation and account changes clear both',async t=>{
 const oldDocument=globalThis.document;globalThis.document={cookie:'XSRF-TOKEN=test'};t.after(()=>{if(oldDocument===undefined)delete globalThis.document;else globalThis.document=oldDocument;});
 let forbidden=false;
 t.mock.method(globalThis,'fetch',async()=>forbidden?Response.json({}, {status:403}):Response.json({data:[{id:1}]}));
 const api=createApiClient('/api/v1');api.setReadScope('account:1');const previousScope=api.viewStateScope();
 api.rememberViewState('topup-categories',{q:'bundle',page:2,scroll:450},previousScope);
 for(const path of ['/topup/categories?page=2&q=bundle','/topup/distribution?target_account_id=2','/topup/available','/digital/connections?main_account_id=2'])await api.request(path);
 assert.ok(api.peekRequest('/topup/categories?q=bundle&page=2'));
 assert.equal(api.peekRequest('/digital/connections?main_account_id=3'),null,'another account filter cannot borrow a balance');
 await api.mutate('/topup/distribution/2','PUT',{});
 assert.equal(api.peekRequest('/topup/available'),null);assert.equal(api.peekRequest('/digital/connections?main_account_id=2'),null);
 assert.deepEqual(api.peekViewState('topup-categories'),{q:'bundle',page:2,scroll:450});
 api.setReadScope('account:2');assert.equal(api.peekViewState('topup-categories'),null);
 api.rememberViewState('topup-categories',{q:'old'},previousScope);assert.equal(api.peekViewState('topup-categories'),null);
 api.rememberViewState('topup-categories',{q:'new'});await api.request('/topup/available');forbidden=true;
 await assert.rejects(api.request('/auth/me'),{status:403});assert.equal(api.peekViewState('topup-categories'),null);assert.equal(api.peekRequest('/topup/available'),null);
 forbidden=false;api.rememberViewState('topup-categories',{q:'before logout'});await api.logout();assert.equal(api.peekViewState('topup-categories'),null);
});
