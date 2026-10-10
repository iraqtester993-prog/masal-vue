import test from 'node:test';
import assert from 'node:assert/strict';
import {Fragment,h,createSSRApp} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {numberTables} from '../src/shared/components/numbered-table.js';
import {localizeVueSource} from '../src/modules/preferences/ui-localization-plugin.js';

test('table sequences survive fragment rows, pagination and empty-state colspans without changing row identity or actions',async()=>{
 const click=()=>{},row=h('tr',{key:'record-99'},[h('td','Business name'),h('td',[h('button',{onClick:click},'Action')])]);
 const table=h('table',[h('thead',[h('tr',[h('th','Name'),h('th','Action')])]),h('tbody',[h(Fragment,[row,h('tr',[h('td',{colspan:2},'No more rows')])])])]);
 const result=numberTables([table],26,'No.')[0];
 const numbered=result.children[1].children[0].children[0];assert.equal(numbered.key,'record-99');assert.equal(numbered.children[2].children[0].props.onClick,click);
 const html=await renderToString(createSSRApp({render:()=>result}));assert.match(html,/<th scope="col" class="table-sequence">No\.<\/th>/);assert.match(html,/<td class="table-sequence">26<\/td>/);assert.match(html,/colspan="3"/);assert.ok(!html.includes('>27<'));assert.ok(html.includes('Business name'));
 const next=await renderToString(createSSRApp({render:()=>numberTables([table],51,'No.')[0]}));assert.ok(next.includes('>51<'));assert.ok(!next.includes('>26<'));
});

test('computed navigation captions, component labels and nested status expressions translate while business names stay original',()=>{
 const source=`<script setup>const tabs=computed(()=>[{id:'one',name:admin?'الفواتير':'المحفظة'}]);const rows=liveRecords;const labels={topup:'تعبئة رصيد'};</script><template><button v-for="item in tabs">{{item.name}}</button><FormField label="كلمة المرور" hint="التسلسل"/><p>{{kind==='one'?labels[row.type]:stateNames[row.status]}}</p><b>{{row.name}}</b></template>`;
 const result=localizeVueSource(source);assert.ok(result.includes('__masalUiTr(item.name)'));assert.ok(result.includes(':label='));assert.ok(result.includes('__masalUiTr(labels[row.type])'));assert.ok(result.includes('__masalUiTr(stateNames[row.status])'));assert.ok(result.includes('{{row.name}}'));
});
