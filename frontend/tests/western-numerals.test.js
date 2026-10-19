import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {renderToString} from '@vue/server-renderer';
import {createMemoryHistory,createRouter} from 'vue-router';
import * as Vue from 'vue';
import {parse,compileScript} from '@vue/compiler-sfc';
import {westernDigits} from '../src/shared/numbers.js';
import {cleanMoney,minor,money,time} from '../src/modules/finance/finance-model.js';
import {clean,format} from '../src/modules/catalog/money-input.js';
import {money as catalogMoney} from '../src/modules/catalog/catalog-model.js';
import {formatNumber,reportCell,workbookSections,printableReport} from '../src/modules/reports/report-model.js';
import {activityDate} from '../src/modules/dashboard/activity-model.js';
import {notificationGroups} from '../src/modules/notifications/pos-notifications.js';
import {printableTime} from '../src/modules/stock/stock-model.js';
import {companyTime} from '../src/modules/company/company-model.js';
import {mapTime} from '../src/modules/maps/maps-model.js';
import {date as backupDate} from '../src/modules/backups/backup-model.js';
import {prepareLanguage,translate,translateKnown} from '../src/modules/preferences/translate.js';

const localizedDigits=/[٠-٩۰-۹]/;
const timestamp='2026-10-19T09:25:00Z';

test('numeric money inputs and report fields accept Arabic and Persian digits and display Western digits',()=>{
  assert.equal(westernDigits('٠١٢٣٤٥٦٧٨٩ ۰۱۲۳۴۵۶۷۸۹'),'0123456789 0123456789');
  for(const raw of ['١٬٢٣٤٫٥٠','۱٬۲۳۴٫۵۰']){
    assert.equal(cleanMoney(raw),'1234.50');assert.equal(clean(raw),'1234.50');
    assert.equal(minor(raw),123450n);assert.equal(money(raw),'1,234.50');
    assert.equal(format(raw),'1,234.50');assert.equal(catalogMoney(raw),'1,234.5');
    assert.equal(formatNumber(raw),'1,234.5');assert.equal(reportCell(raw,{type:'money'}),'1,234.5');
  }
  const recordName='شركة ١٢';
  assert.equal(reportCell(recordName,{type:'text'}),recordName);
  assert.equal(formatNumber(recordName),recordName);
});

test('system dates and notification date groups retain their locale with Western digits',()=>{
  for(const render of [time,printableTime,companyTime,mapTime,backupDate,value=>reportCell(value,{type:'date'})]){
    const output=render(timestamp);assert.ok(!localizedDigits.test(output),output);assert.ok(output.includes('2026'),output);
  }
  for(const language of ['ar','en','ckb']){
    const output=activityDate(timestamp,language);assert.ok(!localizedDigits.test(output),output);assert.match(output,/19/);
    const group=notificationGroups([{id:1,time:timestamp}],{language,now:new Date('2026-10-25T12:00:00Z')})[0];
    assert.ok(!localizedDigits.test(group.label),group.label);assert.ok(group.label.includes('2026'));
  }
});

test('known interface translations normalize their numbers while record names and unknown text stay intact',async()=>{
  await prepareLanguage('ckb');
  for(const source of ['أحدث 30 عملية ضمن نطاق حسابك','راجع الأسعار؛ يسمح بـ500 فئة كحد أقصى لكل عملية.','عرض أول 20 سجلًا مرتبطًا.']){
    const output=translate(source,'ckb');assert.notEqual(output,source);assert.ok(!localizedDigits.test(output),output);
    assert.ok(!localizedDigits.test(translateKnown(source,'ckb')));
  }
  const name='مكتبة ١٢٣';
  assert.equal(translate(name,'ckb'),name);assert.equal(translateKnown(name,'ckb'),name);
  assert.ok(translate(`بحث: ${name}`,'ckb',[name]).endsWith(name));
});

test('report exports preserve exact Western monetary values and leave customer names unchanged',()=>{
  const name='حساب ١٢٣',data={currency:'IQD',timezone:'Asia/Baghdad',generated_at:timestamp,sections:[{title:'الأرصدة',available:true,columns:[{key:'name',label:'الاسم',type:'text'},{key:'amount',label:'الرصيد',type:'money'},{key:'time',label:'التاريخ',type:'date'}],rows:[{name,amount:'٩٠٠٧١٩٩٢٥٤٧٤٠٩٩٫٣١',time:timestamp}]}]};
  const section=workbookSections(data)[0];assert.equal(section.rows[0][0],name);assert.equal(section.rows[0][1],'900719925474099.31');
  assert.ok(!localizedDigits.test(section.rows[0][2]));assert.ok(!localizedDigits.test(section.note));
  const html=printableReport(data);assert.ok(html.includes('900719925474099.31'));assert.ok(html.includes(name));
});

test('phone report cells and exports normalize digits without rewriting phone records or names',()=>{
  const row={name:'حساب ١٢٣',phone:'+٩٦٤ ٧٧٠ ١٢٣ ٤٥٦٧'},column={key:'phone',label:'الهاتف',type:'text'};
  const data={currency:'IQD',timezone:'Asia/Baghdad',generated_at:timestamp,sections:[{id:'network-pos',title:'نقاط البيع',available:true,columns:[{key:'name',label:'الاسم',type:'text'},column],rows:[row]}]};
  assert.equal(reportCell(row.phone,column),'+964 770 123 4567');assert.equal(workbookSections(data)[0].rows[0][1],'+964 770 123 4567');
  assert.equal(workbookSections(data)[0].rows[0][0],row.name);assert.equal(row.phone,'+٩٦٤ ٧٧٠ ١٢٣ ٤٥٦٧');
});

test('support links, account details and phone inputs display English digits while preserving account names and source values',async()=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
  try{
    const Links=(await server.ssrLoadModule('/src/modules/support/SupportPhoneLinks.vue')).default,Field=(await server.ssrLoadModule('/src/modules/accounts/FormField.vue')).default,Details=(await server.ssrLoadModule('/src/modules/accounts/AccountDetailsDialog.vue')).default;
    const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
    const account={id:2,type:'main_agent',name:'حساب ١٢٣',phone:'۰۷۷۰۱۲۳۴۵۶۷',status:'active'},phones=[{label:'دعم ١',number:account.phone}];
    const links=await renderToString(Vue.createSSRApp({render:()=>Vue.h(Links,{name:account.name,phones})}));
    assert.ok(links.includes('href="tel:07701234567"'));assert.ok(links.includes('<bdi>07701234567</bdi>'));assert.ok(links.includes(account.name));assert.ok(links.includes(phones[0].label));
    const field=await renderToString(Vue.createSSRApp({render:()=>Vue.h(Field,{name:'phone',label:'الهاتف',type:'tel',modelValue:account.phone})}));assert.ok(field.includes('value="07701234567"'));assert.ok(field.includes('lang="en"'));
    const session={state:Vue.reactive({identity:{account:{id:1,type:'system'},user:{id:1},membership:{kind:'owner'}}}),can:()=>false,api:{}};
    const inspected={...Details,setup(props,context){const controls=Details.setup(props,context);Vue.onServerPrefetch(()=>{controls.data.value=account;});return controls;}};
    const router=createRouter({history:createMemoryHistory(),routes:[{path:'/agents',name:'agents',component:{render:()=>null}}]});await router.push('/agents');
    const app=Vue.createSSRApp({render:()=>Vue.h(inspected,{account})});app.use(router);app.provide(portalContextKey,{session});const html=await renderToString(app);
    assert.ok(html.includes('07701234567'));assert.ok(html.includes(account.name));assert.ok(!html.includes(account.phone));
    assert.equal(account.phone,'۰۷۷۰۱۲۳۴۵۶۷');assert.equal(phones[0].number,account.phone);
  }finally{await server.close();}
});

test('the date picker renders Western day numbers and emits ISO dates in Arabic, English and Kurdish',async()=>{
  const source=await readFile(new URL('../src/shared/components/AppDateField.vue',import.meta.url),'utf8');
  const parsed=parse(source);assert.deepEqual(parsed.errors,[]);
  let compiled=compileScript(parsed.descriptor,{id:'western-date-picker',inlineTemplate:true}).content;
  compiled=compiled.replace(/^import\s+{([^}]+)}\s+from\s+['"]vue['"];?/gm,(_,names)=>`const {${names.replace(/\s+as\s+/g,':')}}=Vue;`).replace(/^import .*;?\r?$/gm,'').replace('export default','globalThis.Component=');
  let preferences;
  const context={Vue,usePreferences:()=>preferences,document:{addEventListener(){},removeEventListener(){}}};
  runInNewContext(compiled,context);
  const node=(tag,text='')=>({tag,text,props:{},children:[],parent:null});
  const host=Vue.createRenderer({createElement:tag=>node(tag),createText:text=>node('#text',text),createComment:text=>node('#comment',text),setText:(el,text)=>el.text=text,setElementText:(el,text)=>{el.text=text;el.children=[];},patchProp:(el,key,previous,value)=>el.props[key]=value,parentNode:el=>el.parent,nextSibling:el=>el.parent?.children[el.parent.children.indexOf(el)+1]||null,insert(el,parent,anchor=null){if(el.parent)el.parent.children.splice(el.parent.children.indexOf(el),1);el.parent=parent;const index=anchor?parent.children.indexOf(anchor):-1;if(index<0)parent.children.push(el);else parent.children.splice(index,0,el);},remove(el){if(el.parent)el.parent.children.splice(el.parent.children.indexOf(el),1);el.parent=null;}});
  const all=el=>[el,...el.children.flatMap(all)],text=el=>el.text+el.children.map(text).join('');
  for(const language of ['ar','en','ckb']){
    preferences={state:Vue.reactive({language})};const root=node('root'),emitted=[];
    const app=host.createApp(context.Component,{modelValue:'2026-10-19','onUpdate:modelValue':value=>emitted.push(value)});app.mount(root);
    try{
      const trigger=all(root).find(el=>el.props.class==='app-date-trigger');assert.ok(text(trigger).includes('2026'));assert.ok(!localizedDigits.test(text(trigger)));
      trigger.props.onClick();await Vue.nextTick();
      const days=all(root).filter(el=>el.tag==='button'&&/^2026-10-\d{2}$/.test(el.props['aria-label']||''));
      assert.equal(days.length,31);assert.deepEqual(days.map(text),Array.from({length:31},(_,index)=>String(index+1)));
      days[18].props.onClick();await Vue.nextTick();assert.deepEqual(emitted,['2026-10-19']);
    }finally{app.unmount();}
  }
});
