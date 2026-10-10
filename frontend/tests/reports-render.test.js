import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createSSRApp,h,reactive } from 'vue';
import { createRouter,createMemoryHistory } from 'vue-router';
import { renderToString } from '@vue/server-renderer';

test('Topup home card shows balance without spending captions and keeps agent names inside details',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const dashboard=(await server.ssrLoadModule('/src/modules/dashboard/DashboardPage.vue')).default;
    const card={key:'digital:topup',title:'Topup',remaining:'125000.00',value:'7500.00',unit:'د.ع',note:'المصروف لدى الشركة',balanceRows:[{agent:2,name:'اسم الوكيل في التفاصيل',remaining:'125000.00',spent:'7500.00'}],balance_rows_total:1,rows:[],available:true,destination:'digital'};
    let calls=0;
    const session={state:reactive({identity:{account:{id:1,type:'system'},membership:{kind:'owner'}}}),can:()=>true,api:{request:async(path)=>{assert.equal(path,'/dashboard/summary?currency=IQD');calls++;return{data:{cards:[card],chart:[],regions:[]}};}}};
    const router=createRouter({history:createMemoryHistory(),routes:[{path:'/',name:'dashboard',component:{render:()=>null}}]});await router.push('/');
    const app=createSSRApp({render:()=>h(dashboard)});app.provide(portalContextKey,{session,portal:{id:'admin'},config:{}});app.use(router);
    const html=await renderToString(app);assert.equal(calls,1);assert.ok(html.includes('125,000'));assert.ok(!html.includes('7,500'));assert.ok(!html.includes('المبلغ المصروف'));assert.ok(!html.includes('المصروف لدى الشركة')); assert.ok(html.includes('عرض التفاصيل'));assert.ok(!html.includes(card.balanceRows[0].name));assert.ok(!html.includes('معرّف الشركة'));
  }finally{await server.close();}
});

test('actual reports/dashboard pages and report table load scoped API data and render with real portal context',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const reports=(await server.ssrLoadModule('/src/modules/reports/ReportsPage.vue')).default;
    const dashboard=(await server.ssrLoadModule('/src/modules/dashboard/DashboardPage.vue')).default;
    const details=(await server.ssrLoadModule('/src/modules/reports/ReportDetails.vue')).default;
    const {createReportApi}=await server.ssrLoadModule('/src/modules/reports/report-api.js');
    for(const type of ['system','main_agent','pos']){
      const calls=[];const section={id:'sales',title:'سجل المبيعات التفصيلي',available:true,count:1,snapshot:false,columns:[{key:'id',label:'رقم العملية',type:'text',source_available:true},{key:'sales',label:'المبيعات',type:'money',source_available:true}]};
      const permissions=['reports.view','reports.sales','reports.export','reports.print','sales.view','dashboard.view'];
      const session={state:reactive({identity:{user:{id:1},account:{id:12,type,name:'حساب فعلي'},membership:{kind:'owner'},permissions}}),can:key=>permissions.includes(key),api:{request:async(path)=>{
        calls.push(path);
        if(path==='/reports/options')return{data:{accounts:[],products:[],providers:[],cities:[],currencies:['IQD','USD'],groups:[{id:'sales',title:'المبيعات والأرباح',note:'المبيعات والطباعة والأسعار',prefixes:['sales','prices'],icon:'reports'}],sections:[section]}};
        if(path.startsWith('/reports/summary?'))return{data:{sections:[section]}};
        if(path.startsWith('/reports/sections/sales/rows?'))return{data:[{row_key:'71',id:71,sales:'1234.56'}],columns:section.columns,meta:{current_page:1,last_page:1,total:1}};
        if(path==='/dashboard/summary?currency=IQD')return{data:{cards:[{key:'sales',title:'مبيعات اليوم',value:'1234.56',unit:'د.ع',note:'اليوم',icon:'sales',available:true,rows:[],destination:'sales',parameters:{}}],chart:[{day:'2026-10-06',label:'10/06',value:'1234.56',transactions:1,today:true}],regions:[],currency:'IQD'}};
        throw new Error('Unexpected API request '+path);
      },mutate:()=>{throw new Error('Render must not mutate data');}}};
      for(const [component,props,label] of [[reports,{},'المبيعات والأرباح'],[dashboard,{},'1,234.56'],[details,{section,filters:{currency:'IQD'},api:createReportApi(session.api)},'1,234.56']]){
        const router=createRouter({history:createMemoryHistory(),routes:[{path:'/reports',name:'reports',component:{render:()=>null},meta:{permission:'reports.view'}},{path:'/sales',name:'sales',component:{render:()=>null},meta:{permission:'sales.view'}}]});await router.push('/reports');
        const app=createSSRApp({render:()=>h(component,props)});app.provide(portalContextKey,{session,portal:{id:type==='system'?'admin':type==='pos'?'pos':'agents'},config:{}});app.use(router);
        const html=await renderToString(app);assert.ok(html.includes(label),type+' must render '+label);assert.ok(!html.includes('undefined'));assert.ok(!html.includes('NaN'));
      }
      assert.ok(calls.some(path=>path.startsWith('/reports/sections/sales/rows?')));assert.ok(calls.includes('/dashboard/summary?currency=IQD'));
      assert.ok(!calls.some(path=>path.startsWith('/reports/summary?')),'opening the report groups must not count all sections');
    }
  }finally{await server.close();}
});

test('Topup balances render independently with unknown balances and timestamps, without treating funds as sales',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try{
    const component=(await server.ssrLoadModule('/src/modules/dashboard/DashboardBalances.vue')).default;
    const rows=[{agent:1,name:'وكيل أول',remaining:'100000.00',updatedAt:'2026-10-07T11:25:00Z'},{agent:2,name:'وكيل ثان',remaining:null,updatedAt:null},{agent:3,name:'وكيل ثالث',remaining:'0.00',updatedAt:'2026-10-07T11:25:00Z'}];
    const html=await renderToString(createSSRApp({render:()=>h(component,{rows,total:8})}));
    assert.ok(html.includes('100,000'));assert.ok(html.includes('لم يُجلب بعد'));assert.ok(html.includes('0 د.ع'));assert.ok(html.includes('آخر جلب:'));assert.ok(html.includes('عرض أول 3 حسابات'));assert.ok(!html.includes('NaN'));
    assert.equal((html.match(/لم يُجلب بعد/g)||[]).length,1);
  }finally{await server.close();}
});

test('dashboard balance timestamps interpret SQL UTC values in Baghdad time',async()=>{
 const {balanceTime}=await import('../src/modules/dashboard/dashboard-time.js');
 assert.equal(balanceTime('2026-10-07 11:25:00'),balanceTime('2026-10-07T11:25:00Z'));
 assert.equal(balanceTime(null),'—');
});
