import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive} from 'vue';
import {createRouter,createMemoryHistory} from 'vue-router';
import {renderToString} from '@vue/server-renderer';
test('actual original company public page and System editor render API content without legacy runtime or browser storage',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js'),page=(await server.ssrLoadModule('/src/modules/company/CompanyPage.vue')).default;
    const profile={name:'شركة فعلية',tagline:'عنوان فعلي',about:'نص الشركة',phone:'07700000000',email:'care@example.com',address:'بغداد',website:'https://example.com',whatsapp:'9647700000000',hours:'9-5',logo:null,slides:[{title:'عرض حقيقي',caption:'عرض حقيقي',description:'تفاصيل من السيرفر',image:null,link:'',visible:true}],activities:[],offers:[],projects:[],social:[{name:'منصة',url:'https://example.com/social'}],visibility:{slides:true,about:true,activities:true,offers:true,projects:true,social:true,care:true}};
    for(const management of [false,true]){
      const calls=[],session={state:reactive({identity:management?{user:{id:1},account:{id:1,type:'system'},permissions:['company.edit']}:null}),can:key=>management&&key==='company.edit',api:{request:async(path)=>{calls.push(path);return{data:{profile,version:1,published_at:'2026-10-06T10:00:00Z'}};},mutate:()=>{throw new Error('Rendering must not publish or submit requests');}}};
      const router=createRouter({history:createMemoryHistory(),routes:[{path:'/company',name:'company',component:{render:()=>null}}]});await router.push('/company');
      const app=createSSRApp({render:()=>h(page,{management,publicPage:!management})});app.provide(portalContextKey,{session});app.use(router);
      const html=await renderToString(app);assert.ok(html.includes('شركة فعلية'));assert.ok(!html.includes('undefined'));assert.ok(!html.includes('NaN'));assert.deepEqual(calls,[management?'/company/profile':'/company/public']);
      if(management){assert.ok(html.includes('site-editor-tabs'));assert.ok(html.includes('طلبات العملاء'));assert.ok(html.includes('إظهار القسم'));assert.ok(html.includes('حفظ'));}
      else{assert.ok(html.includes('عرض حقيقي'));assert.ok(html.includes('تفاصيل من السيرفر'));assert.ok(html.includes('https://example.com/social'));assert.ok(html.includes('website_honeypot'));assert.ok(!html.includes('رجوع إلى الصفحة السابقة'));assert.ok(!html.includes('site-editor-tabs'));}
    }
  }finally{await server.close();}
});
