import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createSSRApp, h, reactive } from 'vue';
import { createRouter, createMemoryHistory } from 'vue-router';
import { renderToString } from '@vue/server-renderer';
import { allowedReportRoute, reportDestination, reportColumnHelp, reportSourceNotes } from '../src/modules/reports/report-navigation.js';
import { workbookSections, printableReport } from '../src/modules/reports/report-model.js';

const routes = [
  ['digital','/digital','digital.view'], ['integrations','/integrations','integrations.view',['system'],['owner']],
  ['deletedAccounts','/deleted-accounts','agents.archiveView',['system']], ['map','/map','map.view'],
  ['accountTime','/account-times','security.policies',['system'],['owner']], ['sales','/sales','sales.view'],
].map(([name,path,permission,accountTypes,membershipKinds])=>({name,path,meta:{permission,accountTypes,membershipKinds},component:{render:()=>null}}));
const identity=(type='system',kind='owner')=>({account:{id:12,type},membership:{kind},user:{id:1}});
const all=()=>true;

test('original report destination uses the actual canonical routes and obeys route role/grant gates',()=>{
  const admin=identity();
  assert.equal(reportDestination('network-archive',routes,admin,all).to.name,'deletedAccounts');
  assert.equal(reportDestination('network-presence',routes,admin,all,{agent_id:31,q:'بغداد'}).to.query.branch_id,31);
  assert.equal(reportDestination('users-times',routes,admin,all).to.name,'accountTime');
  assert.equal(reportDestination('network-archive',routes,identity('main_agent'),all),null);
  assert.equal(reportDestination('users-times',routes,identity('system','employee'),all),null);
  assert.equal(reportDestination('sales-services',routes,identity('pos'),()=>false),null);
  assert.equal(allowedReportRoute(routes,'missing',admin,all),null);
  assert.equal(allowedReportRoute(routes,'sales',null,all),null);
  assert.equal(reportDestination('operations-integrations',routes,identity('system','employee'),all).to.name,'digital');
  assert.equal(reportDestination('operations-integrations',routes,identity('system','employee'),all).to.query.tab,'log');
  assert.equal(reportDestination('operations-integrations',routes,admin,all).to.name,'integrations');
  assert.equal(reportDestination('operations-integrations',routes,admin,key=>key!=='integrations.edit').to.name,'digital');
  assert.equal(reportDestination('operations-integrations',routes,identity('sub_agent'),all).to.query.tab,'settings');
});

test('digital report deep links retain eligible filters and source notes stay in screen and complete exports',()=>{
  const destination=reportDestination('sales-services',routes,identity('main_agent'),all,{agent_id:12,pos_id:23,provider_id:99,product_id:42,from:'2026-10-01',to:'2026-10-06',status:'review',currency:'USD',city:'بغداد'});
  assert.deepEqual(destination.to,{name:'digital',query:{from:'2026-10-01',to:'2026-10-06',product_id:42,status:'review',account_id:23,main_account_id:12,tab:'log'}});
  const columns=[{key:'cost',label:'الكلفة',type:'money',source_note:'السعر في الكتالوج ليس تسوية فعلية.'},{key:'expiry',label:'الانتهاء',reason:'لا يوجد تاريخ انتهاء من الشركة.',source_note:'السعر في الكتالوج ليس تسوية فعلية.'}];
  assert.equal(reportColumnHelp(columns[0]),columns[0].source_note);
  assert.equal(reportSourceNotes(columns).length,2);
  const data={currency:'IQD',timezone:'Asia/Baghdad',generated_at:'2026-10-06T00:00:00Z',sections:[{title:'الخدمات',available:true,columns,rows:Array.from({length:24},()=>({cost:null,expiry:null}))}]};
  const exported=workbookSections(data)[0];
  assert.equal(exported.rows.length,24);assert.equal(exported.rows[0][0],'—');
  assert.ok(exported.note.includes(columns[0].source_note));assert.ok(exported.note.includes(columns[1].reason));
  assert.equal(exported.note.split(columns[0].source_note).length-1,1);
  assert.ok(printableReport(data).includes(columns[0].source_note));
});

test('actual original dashboard digital details and report sources render protected nulls with valid portal context',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false},appType:'custom'});
  try {
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const dashboard=(await server.ssrLoadModule('/src/modules/dashboard/DashboardDetails.vue')).default;
    const report=(await server.ssrLoadModule('/src/modules/reports/ReportDetails.vue')).default;
    const render=async(component,props,type='main_agent',permissions=['digital.view','data.cost','reports.view'])=>{
      const session={state:reactive({identity:identity(type)}),can:key=>permissions.includes(key)};
      const router=createRouter({history:createMemoryHistory(),routes});await router.push('/digital');
      const app=createSSRApp({render:()=>h(component,props)});app.provide(portalContextKey,{session,portal:{id:type==='pos'?'pos':'agents'},config:{}});app.use(router);return renderToString(app);
    };
    const detail={title:'Topup',value:null,unit:'د.ع',note:'المصروف لدى الشركة',explanation:'التكلفة غير موثقة برد الشركة.',available:true,destination:'digital',parameters:{provider:'topup',tab:'log'},rows:[],balanceRows:[{agent:12,name:'الوكيل الفعلي',spent:null,remaining:'9876543.21'}],balance_rows_total:1};
    const html=await render(dashboard,{detail});
    assert.ok(html.includes('المبلغ المصروف'));assert.ok(html.includes('الوكيل الفعلي'));assert.ok(html.includes('عرض حساب الوكيل'));assert.ok(html.includes('التكلفة غير موثقة'));assert.ok(html.includes('الانتقال إلى الصفحة'));assert.ok(!html.includes('9876543.21'));
    const denied=await render(dashboard,{detail},'main_agent',[]);assert.ok(!denied.includes('المبلغ المصروف'));assert.ok(!denied.includes('عرض حساب الوكيل'));assert.ok(!denied.includes('الانتقال إلى الصفحة'));
    const pos=await render(dashboard,{detail:{...detail,balanceRows:undefined,note:'المبيعات الناجحة',rows:[{name:'عملية فعلية',value:'5000.02',unit:'د.ع',note:'ناجحة'}]}},'pos',['digital.view','data.cost']);
    assert.ok(pos.includes('5,000.02'));assert.ok(!pos.includes('المبلغ المصروف'));assert.ok(!pos.includes('الوكيل الفعلي'));
    let calls=0;const columns=[{key:'cost',label:'الكلفة',type:'money',source_note:'قيمة الكتالوج ليست تسوية فعلية.'},{key:'expiry',label:'الانتهاء',source_available:false,reason:'الشركة لم ترسل تاريخ انتهاء.'}];
    const section={id:'sales-services',title:'الخدمات الرقمية',columns};
    const reportsHtml=await render(report,{section,filters:{},api:{rows:async()=>{calls++;return{data:[{row_key:'1',cost:null,expiry:null}],columns,meta:{total:1,last_page:1}};}}});
    assert.equal(calls,1);assert.ok(reportsHtml.includes(columns[0].source_note));assert.ok(reportsHtml.includes(columns[1].reason));assert.ok(reportsHtml.includes('الانتقال إلى الصفحة'));assert.ok(!reportsHtml.includes('undefined'));assert.ok(!reportsHtml.includes('NaN'));
  } finally {await server.close();}
});
