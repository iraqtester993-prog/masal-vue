import test from 'node:test';
import assert from 'node:assert/strict';
import {reactive} from 'vue';
import {COMPANY_SECTIONS,displayProfile,profilePayload,safeHttps,sectionVisible,moveCompanyItem} from '../src/modules/company/company-model.js';
import {createCompanyApi} from '../src/modules/company/company-api.js';

const profile={name:'شركة فعلية',logo:{id:'logo-uuid',url:'/api/v1/company/assets/logo-uuid'},slides:[{title:'عرض',image:{id:'slide-uuid',url:'/api/v1/company/assets/slide-uuid'},visible:true}],activities:[],offers:[],projects:[],social:[],about:'عن الشركة',visibility:Object.fromEntries(COMPANY_SECTIONS.map(([key])=>[key,true]))};
test('company preserves seven original sections, converts reactive image metadata without cloning errors, and filters real visibility',()=>{
  assert.deepEqual(COMPANY_SECTIONS.map(([key])=>key),['slides','about','activities','offers','projects','social','care']);
  const source=reactive(profile),display=displayProfile(source),payload=profilePayload(source);
  assert.equal(display.logo,'/api/v1/company/assets/logo-uuid');assert.equal(display.slides[0].image,'/api/v1/company/assets/slide-uuid');assert.equal(payload.logo,'logo-uuid');assert.equal(payload.slides[0].image,'slide-uuid');assert.equal(source.logo.id,'logo-uuid');
  assert.equal(sectionVisible(display,'about'),true);assert.equal(sectionVisible(display,'activities'),false);assert.equal(sectionVisible({...display,visibility:{care:false}},'care'),false);
  assert.equal(safeHttps('javascript:alert(1)'), '');assert.equal(safeHttps('http://example.com'),'');assert.equal(safeHttps('https://user:secret@example.com'),'');assert.equal(safeHttps('https://example.com/a'),'https://example.com/a');
});
test('ordering preserves exact items and rejects invalid movement',()=>{const a=[{title:'أ'},{title:'ب'}];assert.equal(moveCompanyItem(a,0,-1),false);assert.equal(moveCompanyItem(a,0,1),true);assert.deepEqual(a.map(x=>x.title),['ب','أ']);});
test('company API uses public content and real private mutations with revision, separate assets and paginated inquiries',async()=>{
  const calls=[],api=createCompanyApi({request:async(...args)=>calls.push(['get',...args]),mutate:async(...args)=>calls.push(['mutation',...args])});
  await api.publicProfile();await api.profile();await api.save({version:3,profile:profilePayload(profile)});await api.inquiry({name:'زائر',contact:'x',message:'طلب',idempotency_key:'uuid',website_honeypot:''});await api.inquiries({page:2,status:'new',q:''});await api.review(22,{version:2,status:'followed'});
  assert.equal(calls[0][1],'/company/public');assert.equal(calls[1][1],'/company/profile');assert.equal(calls[2][2],'PUT');assert.equal(calls[2][3].version,3);assert.equal(calls[3][2],'POST');assert.equal(calls[4][1],'/company/inquiries?page=2&status=new');assert.equal(calls[5][1],'/company/inquiries/22');assert.deepEqual(calls[5][3],{version:2,status:'followed'});
});
